<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to browser
ini_set('log_errors', 1);

// Before outputting JSON, clean any buffered content:
ob_clean(); // Instead of ob_end_clean()

session_start();
require_once 'lib/Auth.php';
require_once 'config/database.php';

$auth = new Auth();

// ── Auth guard ─────────────────────────────────────────────
if (!isset($_COOKIE['session_token'])) { header('Location: login.php'); exit(); }
$session = $auth->verifySession($_COOKIE['session_token']);
if (!$session) {
    setcookie('session_token', '', time() - 3600, '/');
    header('Location: login.php');
    exit();
}

// ── URL helper ─────────────────────────────────────────────
$appBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (in_array($appBasePath, ['/', '.', '\\'])) { $appBasePath = ''; }
$assetUrl = fn(string $p): string => ($appBasePath ?: '') . '/' . ltrim($p, '/');

$creditCost = 2; // credits per domain scan

// ── DB setup ───────────────────────────────────────────────
$conn = getDBConnection();

$conn->query("
    CREATE TABLE IF NOT EXISTS dead_site_scans (
        id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id         INT  NOT NULL,
        domain_name     VARCHAR(253)     NOT NULL,
        tld             VARCHAR(63)      NOT NULL,

        -- HTTP check result
        http_status     SMALLINT         NULL     COMMENT 'HTTP status code returned (0 = no response)',
        response_time_ms INT UNSIGNED    NULL     COMMENT 'Response time in ms',
        final_url       VARCHAR(512)     NULL     COMMENT 'URL after redirects',
        redirect_count  TINYINT UNSIGNED NULL,
        ssl_valid       TINYINT(1)       NULL     COMMENT 'NULL = not checked, 1 = valid, 0 = invalid/expired',
        ssl_expiry_date DATE             NULL,
        server_header   VARCHAR(255)     NULL,
        content_type    VARCHAR(128)     NULL,

        -- Dead site signals
        site_status     ENUM(
                            'live',             -- 200-299, site is up
                            'redirect',         -- 301/302 permanent redirect
                            'error_4xx',        -- client error (403, 404, etc.)
                            'error_5xx',        -- server error
                            'no_response',      -- timeout / connection refused
                            'dns_fail',         -- DNS resolution failed
                            'ssl_error',        -- SSL/TLS error
                            'parked',           -- detected parking page
                            'for_sale',         -- domain for-sale page detected
                            'dead'              -- clearly dead / inactive
                        ) NOT NULL DEFAULT 'no_response',
        is_dead         TINYINT(1)       NOT NULL DEFAULT 0,
        dead_score      TINYINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '0-100, higher = more likely dead/acquirable',

        -- Content signals (from page body)
        has_content     TINYINT(1)       NOT NULL DEFAULT 0  COMMENT '1 = actual content detected',
        is_parked       TINYINT(1)       NOT NULL DEFAULT 0,
        is_for_sale     TINYINT(1)       NOT NULL DEFAULT 0,
        page_title      VARCHAR(255)     NULL,

        credits_spent   TINYINT UNSIGNED NOT NULL DEFAULT 2,
        scanned_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        INDEX idx_dss_user   (user_id),
        INDEX idx_dss_domain (domain_name),
        INDEX idx_dss_status (site_status),
        INDEX idx_dss_dead   (is_dead),
        INDEX idx_dss_date   (scanned_at),
        CONSTRAINT fk_dss_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Fetch user ─────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, email, full_name, plan, credits FROM users WHERE id = ?");
$stmt->bind_param("i", $session['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { header('Location: logout.php'); exit(); }

$userPlan  = $user['plan']    ?? 'free';
$credits   = (int)($user['credits'] ?? 0);
$canScan   = ($userPlan !== 'free');

// ── Handle AJAX ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    ob_start();

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    // ── Single scan ─────────────────────────────────────────
    if ($action === 'scan') {
        if (!$canScan) {
            ob_end_clean(); echo json_encode(['success'=>false,'requiresUpgrade'=>true,'message'=>'Dead site scanning requires a Pro plan.']); exit();
        }

        $raw    = strtolower(trim($input['domain'] ?? ''));
        $raw    = preg_replace('#^https?://(www\.)?#', '', $raw);
        $domain = rtrim($raw, '/');

        if (!$domain || !str_contains($domain, '.') ||
            !preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Enter a valid domain name.']); exit();
        }

        // Cache — if scanned in last 6 hours, return cached
        $cacheStmt = $conn->prepare("
            SELECT * FROM dead_site_scans
            WHERE domain_name=? AND scanned_at > DATE_SUB(NOW(), INTERVAL 6 HOUR)
            ORDER BY scanned_at DESC LIMIT 1
        ");
        $cacheStmt->bind_param("s", $domain);
        $cacheStmt->execute();
        $cached = $cacheStmt->get_result()->fetch_assoc();
        $cacheStmt->close();

        if ($cached) {
            $cached['from_cache']  = true;
            $cached['cached_age']  = round((time() - strtotime($cached['scanned_at'])) / 60) . 'm ago';
            ob_end_clean();
            echo json_encode(['success'=>true,'data'=>$cached,'credits_remaining'=>$credits]);
            exit();
        }

        if ($credits < $creditCost) {
            ob_end_clean();
            echo json_encode(['success'=>false,'insufficientCredits'=>true,'message'=>"Not enough credits. Scan costs {$creditCost} credits. You have {$credits}."]); exit();
        }

        // Deduct credits atomically
        $deductStmt = $conn->prepare("UPDATE users SET credits = credits - ? WHERE id=? AND credits >= ?");
        $deductStmt->bind_param("iii", $creditCost, $session['user_id'], $creditCost);
        $deductStmt->execute();
        if ($deductStmt->affected_rows === 0) {
            $deductStmt->close();
            ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Credit deduction failed.']); exit();
        }
        $deductStmt->close();
        $creditsAfter = $credits - $creditCost;

        // Run scan
        $result = runDeadSiteScan($domain);

        // Persist result
        $tld = implode('.', array_slice(explode('.', $domain), 1));
        $insStmt = $conn->prepare("
            INSERT INTO dead_site_scans
              (user_id, domain_name, tld, http_status, response_time_ms, final_url,
               redirect_count, ssl_valid, ssl_expiry_date, server_header, content_type,
               site_status, is_dead, dead_score, has_content, is_parked, is_for_sale,
               page_title, credits_spent)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $insStmt->bind_param("issiisissssiiiiiisi",
            $session['user_id'], $domain, $tld,
            $result['http_status'], $result['response_time_ms'], $result['final_url'],
            $result['redirect_count'], $result['ssl_valid'], $result['ssl_expiry_date'],
            $result['server_header'], $result['content_type'],
            $result['site_status'], $result['is_dead'], $result['dead_score'],
            $result['has_content'], $result['is_parked'], $result['is_for_sale'],
            $result['page_title'], $creditCost
        );
        $insStmt->execute();
        $insStmt->close();

        // Ledger
        $balStmt = $conn->prepare("SELECT credits FROM users WHERE id=?");
        $balStmt->bind_param("i", $session['user_id']); $balStmt->execute();
        $balAfter = (int)($balStmt->get_result()->fetch_assoc()['credits'] ?? $creditsAfter);
        $balStmt->close();
        $ledger = $conn->prepare("INSERT INTO credit_ledger (user_id, delta, balance_after, type, domain_name, note) VALUES (?,?,?,'dead_site_scan',?,?)");
        if ($ledger) {
            $delta = -$creditCost; $note = "Dead-site scan: {$domain}";
            $ledger->bind_param("iiiss", $session['user_id'], $delta, $balAfter, $domain, $note);
            $ledger->execute(); $ledger->close();
        }

        $result['from_cache'] = false;
        ob_end_clean();
        echo json_encode(['success'=>true,'data'=>$result,'credits_remaining'=>$creditsAfter]);
        exit();
    }

    // ── Scan watchlist batch ────────────────────────────────
    if ($action === 'scan_watchlist') {
        if (!$canScan) {
            ob_end_clean(); echo json_encode(['success'=>false,'requiresUpgrade'=>true,'message'=>'Requires Pro plan.']); exit();
        }
        // Return list of watchlist domains for client-side sequential scanning
        $wlStmt = $conn->prepare("SELECT domain_name FROM pinned_domains WHERE user_id=? AND status='active' ORDER BY pinned_at DESC LIMIT 20");
        $wlStmt->bind_param("i", $session['user_id']); $wlStmt->execute();
        $wlResult = $wlStmt->get_result();
        $domains = [];
        while ($row = $wlResult->fetch_assoc()) $domains[] = $row['domain_name'];
        $wlStmt->close();
        ob_end_clean();
        echo json_encode(['success'=>true,'domains'=>$domains,'credit_cost'=>$creditCost,'credits'=>$credits]);
        exit();
    }

    ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit();
}

// ── Fetch scan history ──────────────────────────────────────
$filter = in_array($_GET['filter'] ?? '', ['dead','live','all']) ? $_GET['filter'] : 'all';
$whereMap = ['dead'=>"is_dead=1",'live'=>"is_dead=0",'all'=>"1=1"];

$histStmt = $conn->prepare("
    SELECT * FROM dead_site_scans
    WHERE user_id=? AND {$whereMap[$filter]}
    ORDER BY scanned_at DESC
    LIMIT 40
");
$histStmt->bind_param("i", $session['user_id']);
$histStmt->execute();
$histResult = $histStmt->get_result();
$history = [];
while ($row = $histResult->fetch_assoc()) $history[] = $row;
$histStmt->close();

// ── Stats ────────────────────────────────────────────────────
$statsStmt = $conn->prepare("
    SELECT
        COUNT(*) as total,
        SUM(is_dead=1) as dead_count,
        SUM(is_parked=1) as parked_count,
        SUM(is_for_sale=1) as for_sale_count,
        SUM(site_status='live') as live_count
    FROM dead_site_scans WHERE user_id=?
");
$statsStmt->bind_param("i", $session['user_id']);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

// ── Watchlist domains for batch scan ─────────────────────────
$wlStmt = $conn->prepare("SELECT domain_name FROM pinned_domains WHERE user_id=? AND status='active' ORDER BY pinned_at DESC LIMIT 20");
$wlStmt->bind_param("i", $session['user_id']); $wlStmt->execute();
$wlResult = $wlStmt->get_result();
$watchlistDomains = [];
while ($row = $wlResult->fetch_assoc()) $watchlistDomains[] = $row['domain_name'];
$wlStmt->close();
$watchlistCount = count($watchlistDomains);

$alertCount = 0;
$alStmt = $conn->prepare("SELECT COUNT(*) as c FROM domain_alerts WHERE user_id=? AND status='unread'");
if ($alStmt) { $alStmt->bind_param("i", $session['user_id']); $alStmt->execute(); $alertCount = (int)$alStmt->get_result()->fetch_assoc()['c']; $alStmt->close(); }

$conn->close();

// ── Display meta ─────────────────────────────────────────────
$userName  = trim($user['full_name'] ?? '') ?: explode('@', $user['email'])[0];
$firstName = explode(' ', $userName)[0];
$initials  = strtoupper(substr($userName,0,1).(strpos($userName,' ')!==false?substr($userName,strpos($userName,' ')+1,1):''));

$activePage = 'dead-sites';
$prefill    = htmlspecialchars(preg_replace('#^https?://(www\.)?#','',trim($_GET['domain'] ?? '')), ENT_QUOTES);

// ── Status display helpers ────────────────────────────────────
$siteMeta = [
    'live'        => ['icon'=>'fa-check-circle',  'color'=>'--green2',  'bg'=>'--green-bg',   'label'=>'Live',          'dead'=>false],
    'redirect'    => ['icon'=>'fa-external-link-alt','color'=>'--blue',  'bg'=>'--blue-bg',    'label'=>'Redirect',      'dead'=>false],
    'error_4xx'   => ['icon'=>'fa-exclamation-triangle','color'=>'--amber','bg'=>'--amber-bg', 'label'=>'Client Error',  'dead'=>true],
    'error_5xx'   => ['icon'=>'fa-server',         'color'=>'--coral',   'bg'=>'--coral-bg',   'label'=>'Server Error',  'dead'=>true],
    'no_response' => ['icon'=>'fa-wifi',            'color'=>'--coral',   'bg'=>'--coral-bg',   'label'=>'No Response',   'dead'=>true],
    'dns_fail'    => ['icon'=>'fa-skull',           'color'=>'--coral',   'bg'=>'--coral-bg',   'label'=>'DNS Failed',    'dead'=>true],
    'ssl_error'   => ['icon'=>'fa-lock',            'color'=>'--amber',   'bg'=>'--amber-bg',   'label'=>'SSL Error',     'dead'=>false],
    'parked'      => ['icon'=>'fa-parking',         'color'=>'--amber',   'bg'=>'--amber-bg',   'label'=>'Parked',        'dead'=>true],
    'for_sale'    => ['icon'=>'fa-tag',             'color'=>'--purple',  'bg'=>'--purple-bg',  'label'=>'For Sale',      'dead'=>true],
    'dead'        => ['icon'=>'fa-skull-crossbones','color'=>'--coral',   'bg'=>'--coral-bg',   'label'=>'Dead',          'dead'=>true],
];

function timeAgo(string $ts): string {
    $diff = time() - strtotime($ts);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return round($diff/60).'m ago';
    if ($diff < 86400)  return round($diff/3600).'h ago';
    if ($diff < 604800) return round($diff/86400).'d ago';
    return date('M j, Y', strtotime($ts));
}

// ═══════════════════════════════════════════════════════════
// DEAD SITE SCAN ENGINE
// ═══════════════════════════════════════════════════════════
function runDeadSiteScan(string $domain): array {
    $result = [
        'domain_name'      => $domain,
        'http_status'      => 0,
        'response_time_ms' => 0,
        'final_url'        => null,
        'redirect_count'   => 0,
        'ssl_valid'        => null,
        'ssl_expiry_date'  => null,
        'server_header'    => null,
        'content_type'     => null,
        'site_status'      => 'no_response',
        'is_dead'          => 1,
        'dead_score'       => 80,
        'has_content'      => 0,
        'is_parked'        => 0,
        'is_for_sale'      => 0,
        'page_title'       => null,
    ];

    $url = 'https://' . $domain;
    $start = microtime(true);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CheckDomainBot/2.0)',
        CURLOPT_HEADER         => false,
        CURLOPT_NOBODY         => false,
    ]);

    $body     = curl_exec($ch);
    $info     = curl_getinfo($ch);
    $errno    = curl_errno($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    $elapsed = (int)round((microtime(true) - $start) * 1000);
    $result['response_time_ms'] = $elapsed;

    // DNS failure
    if (in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY])) {
        $result['site_status'] = 'dns_fail';
        $result['dead_score']  = 95;
        return $result;
    }

    // SSL error — try HTTP fallback
    if (in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION]) || $errno === 35) {
        $result['ssl_valid'] = 0;
        // Try HTTP
        $chHttp = curl_init();
        curl_setopt_array($chHttp, [
            CURLOPT_URL            => 'http://' . $domain,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CheckDomainBot/2.0)',
        ]);
        $body2 = curl_exec($chHttp);
        $info2 = curl_getinfo($chHttp);
        curl_close($chHttp);
        if ($info2['http_code'] > 0) {
            $body = $body2;
            $info = $info2;
            $result['site_status'] = 'ssl_error';
            $result['dead_score']  = 30;
        } else {
            $result['site_status'] = 'ssl_error';
            $result['dead_score']  = 60;
            return $result;
        }
    }

    // Timeout / no response
    if ($errno === CURLE_OPERATION_TIMEDOUT || $info['http_code'] === 0) {
        $result['site_status'] = 'no_response';
        $result['dead_score']  = 85;
        return $result;
    }

    $httpCode = (int)$info['http_code'];
    $result['http_status']    = $httpCode;
    $result['final_url']      = $info['url'] ?? $url;
    $result['redirect_count'] = (int)($info['redirect_count'] ?? 0);
    $result['server_header']  = null; // populated below via separate HEAD

    // Grab headers separately
    $chHead = curl_init();
    curl_setopt_array($chHead, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_NOBODY         => true,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CheckDomainBot/2.0)',
    ]);
    $headerStr = curl_exec($chHead);
    curl_close($chHead);

    if ($headerStr) {
        if (preg_match('/^Server:\s*(.+)$/im', $headerStr, $m)) $result['server_header'] = trim($m[1]);
        if (preg_match('/^Content-Type:\s*(.+)$/im', $headerStr, $m)) $result['content_type'] = trim(explode(';',$m[1])[0]);
    }

    // HTTP status classification
    if ($httpCode >= 200 && $httpCode < 300) {
        $result['site_status'] = 'live';
        $result['is_dead']     = 0;
        $result['dead_score']  = 5;
    } elseif ($httpCode >= 301 && $httpCode <= 308) {
        $result['site_status'] = 'redirect';
        $result['is_dead']     = 0;
        $result['dead_score']  = 15;
    } elseif ($httpCode >= 400 && $httpCode < 500) {
        $result['site_status'] = 'error_4xx';
        $result['is_dead']     = 1;
        $result['dead_score']  = $httpCode === 404 ? 70 : 55;
    } elseif ($httpCode >= 500) {
        $result['site_status'] = 'error_5xx';
        $result['is_dead']     = 1;
        $result['dead_score']  = 50;
    }

    // Content analysis
    if ($body) {
        $bodyLower = strtolower(substr($body, 0, 8000));

        // Extract title
        if (preg_match('/<title[^>]*>([^<]{1,200})<\/title>/i', $body, $tm)) {
            $result['page_title'] = trim(html_entity_decode(strip_tags($tm[1])));
        }

        // Parked domain signals
        $parkedSignals = [
            'buy this domain','domain for sale','this domain is for sale',
            'sedo.com','dan.com','afternic','godaddy parked',
            'this domain may be for sale','domain is registered and ready to use',
            'namecheap parked','hugedomains','undeveloped.com','squadhelp',
            'parkingcrew','domain parking','parked domain',
        ];
        foreach ($parkedSignals as $sig) {
            if (str_contains($bodyLower, $sig)) {
                $result['is_parked']   = 1;
                $result['is_for_sale'] = str_contains($bodyLower, 'sale') || str_contains($bodyLower, 'buy') ? 1 : 0;
                $result['site_status'] = 'parked';
                $result['is_dead']     = 1;
                $result['dead_score']  = 85;
                break;
            }
        }

        // For-sale page signals
        $saleSignals = ['make an offer','buy now','inquire about this domain','acquire this domain','contact us about this domain'];
        foreach ($saleSignals as $sig) {
            if (str_contains($bodyLower, $sig)) {
                $result['is_for_sale'] = 1;
                $result['site_status'] = 'for_sale';
                $result['is_dead']     = 1;
                $result['dead_score']  = max($result['dead_score'], 80);
                break;
            }
        }

        // Has real content?
        $textContent = strip_tags($body);
        $textContent = preg_replace('/\s+/', ' ', $textContent);
        $wordCount = str_word_count($textContent);
        $result['has_content'] = ($wordCount > 50 && !$result['is_parked']) ? 1 : 0;

        // Dead site signals
        if (!$result['is_parked'] && !$result['is_for_sale']) {
            $deadSignals = ['this site can\'t be reached','err_name_not_resolved',
                'coming soon','under construction','website coming soon',
                'site is currently unavailable','temporarily down',
                'we\'ll be back soon'];
            foreach ($deadSignals as $sig) {
                if (str_contains($bodyLower, $sig)) {
                    $result['site_status'] = 'dead';
                    $result['is_dead']     = 1;
                    $result['dead_score']  = max($result['dead_score'], 75);
                    break;
                }
            }
        }

        // Boost dead score if no content and not live
        if (!$result['has_content'] && $result['site_status'] !== 'live') {
            $result['dead_score'] = min(100, $result['dead_score'] + 20);
        }
    }

    return $result;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dead Site Detection — CheckDomain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;700;800&family=DM+Mono:wght@400;500&family=Instrument+Serif:ital@1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
    --bg:#0A0B0E;--bg2:#111318;--bg3:#181C24;--bg4:#1E2230;
    --border:rgba(255,255,255,0.06);--border2:rgba(255,255,255,0.11);
    --text:#E9E7DF;--text2:#8A8880;--text3:#454340;
    --green:#1D9E75;--green2:#14C48A;--green-bg:rgba(29,158,117,0.1);
    --amber:#EF9F27;--amber-bg:rgba(239,159,39,0.1);
    --coral:#E8593C;--coral-bg:rgba(232,89,60,0.1);
    --purple:#7F77DD;--purple-bg:rgba(127,119,221,0.1);
    --blue:#4A90D9;--blue-bg:rgba(74,144,217,0.1);
    --mono:'DM Mono',monospace;--display:'Syne',sans-serif;
    --serif:'Instrument Serif',serif;--sb-width:224px;
    }
    html{scroll-behavior:smooth}
    body{background:var(--bg);color:var(--text);font-family:var(--display);min-height:100vh;display:flex;overflow-x:hidden}
    body::before{content:'';position:fixed;inset:0;
    background-image:linear-gradient(rgba(29,158,117,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(29,158,117,.02) 1px,transparent 1px);
    background-size:52px 52px;pointer-events:none;z-index:0}

    /* ── Layout ─── */
    .main{margin-left:var(--sb-width);flex:1;position:relative;z-index:1;min-height:100vh}
    .topbar{display:flex;align-items:center;justify-content:space-between;padding:15px 28px;border-bottom:1px solid var(--border);backdrop-filter:blur(12px);background:rgba(10,11,14,0.85);position:sticky;top:0;z-index:40;gap:14px}
    .topbar-left{display:flex;align-items:center;gap:12px}
    .topbar-right{display:flex;align-items:center;gap:10px}
    .mobile-menu-btn{display:none;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--text2);font-size:16px;cursor:pointer}
    .breadcrumb{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text3)}
    .breadcrumb a{color:var(--text2);text-decoration:none;transition:color .15s}
    .breadcrumb a:hover{color:var(--text)}
    .topbar-btn{display:flex;align-items:center;justify-content:center;width:33px;height:33px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--text2);font-size:14px;cursor:pointer;text-decoration:none;transition:border-color .15s,color .15s}
    .topbar-btn:hover{border-color:var(--border2);color:var(--text)}
    .credits-pill{display:flex;align-items:center;gap:6px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-family:var(--mono);font-size:12px;color:var(--text2);white-space:nowrap}
    .credits-pill b{color:var(--amber);font-weight:700}
    .content{padding:28px 28px 60px}

    /* ── Header ─── */
    .page-header{margin-bottom:24px}
    .page-eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.16em;color:var(--text3);margin-bottom:5px}
    .page-title{font-family:var(--serif);font-style:italic;font-size:28px;color:var(--text);margin-bottom:5px}
    .page-sub{font-size:13px;color:var(--text2);line-height:1.6}
    .page-sub em{color:var(--green);font-style:normal;font-family:var(--mono)}

    /* ── Upgrade gate ─── */
    .upgrade-gate{background:linear-gradient(135deg,rgba(29,158,117,.07),rgba(127,119,221,.05));border:1px solid rgba(29,158,117,.2);border-radius:14px;padding:28px 24px;text-align:center;margin-bottom:24px}
    .gate-icon{font-size:28px;margin-bottom:12px}
    .gate-title{font-size:16px;font-weight:800;color:var(--text);margin-bottom:6px}
    .gate-sub{font-size:13px;color:var(--text2);max-width:420px;margin:0 auto 18px;line-height:1.6}
    .gate-cta{display:inline-flex;align-items:center;gap:7px;background:var(--green);color:#fff;border:none;border-radius:9px;padding:10px 24px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;text-transform:uppercase;letter-spacing:.06em}
    .gate-cta:hover{background:var(--green2)}

    /* ── Stats row ─── */
    .stats-row{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:24px}
    .stat-chip{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:12px 14px;display:flex;align-items:center;gap:10px}
    .stat-chip-icon{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
    .sci-all{background:var(--bg3);color:var(--text2)}
    .sci-coral{background:var(--coral-bg);color:var(--coral)}
    .sci-amber{background:var(--amber-bg);color:var(--amber)}
    .sci-purple{background:var(--purple-bg);color:var(--purple)}
    .sci-green{background:var(--green-bg);color:var(--green2)}
    .stat-chip-num{font-size:18px;font-weight:800;font-family:var(--mono);color:var(--text);line-height:1}
    .stat-chip-lbl{font-size:10px;color:var(--text2);margin-top:1px}

    /* ── Search hero ─── */
    .search-hero{background:var(--bg2);border:1px solid var(--border2);border-radius:16px;padding:22px 24px;margin-bottom:24px}
    .search-hero-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--text3);margin-bottom:10px;display:flex;align-items:center;gap:6px}
    .search-row{display:flex;gap:10px;align-items:center;margin-bottom:10px}
    .search-input-wrap{flex:1;position:relative;min-width:0}
    .search-input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:12px 16px 12px 42px;font-family:var(--mono);font-size:14px;color:var(--text);outline:none;transition:border-color .2s}
    .search-input::placeholder{color:var(--text3)}
    .search-input:focus{border-color:var(--green)}
    .search-input:disabled{opacity:.45;cursor:not-allowed}
    .search-input-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:13px;pointer-events:none}
    .search-btn{display:flex;align-items:center;gap:8px;background:var(--coral);color:#fff;border:none;border-radius:10px;padding:12px 22px;font-family:var(--display);font-size:13px;font-weight:700;cursor:pointer;transition:background .2s;white-space:nowrap;flex-shrink:0}
    .search-btn:hover{background:#c94830}
    .search-btn:disabled{opacity:.5;cursor:not-allowed}
    .search-hint{display:flex;align-items:center;gap:12px;font-size:11px;color:var(--text3);flex-wrap:wrap}
    .cost-pill{background:var(--amber-bg);color:var(--amber);font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px}
    .cache-pill{background:var(--green-bg);color:var(--green2);font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px}

    /* ── Batch scan card ─── */
    .batch-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:18px 22px;margin-bottom:24px}
    .batch-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px}
    .batch-title{font-size:12px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px}
    .batch-meta{font-size:11px;color:var(--text2)}
    .batch-btn{display:flex;align-items:center;gap:6px;background:var(--bg3);border:1px solid var(--border2);border-radius:8px;padding:8px 16px;font-family:var(--display);font-size:11px;font-weight:700;color:var(--text2);cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.05em}
    .batch-btn:hover{background:var(--bg4);color:var(--text)}
    .batch-btn:disabled{opacity:.5;cursor:not-allowed}
    .batch-btn.running{border-color:rgba(29,158,117,.3);color:var(--green2)}
    .batch-progress{margin-top:12px;display:none}
    .batch-progress.visible{display:block}
    .batch-progress-bar-wrap{height:4px;background:var(--border);border-radius:2px;overflow:hidden;margin-bottom:8px}
    .batch-progress-fill{height:100%;background:var(--green);border-radius:2px;transition:width .4s ease;width:0%}
    .batch-progress-label{font-size:11px;color:var(--text2);font-family:var(--mono)}
    .batch-results-list{display:flex;flex-direction:column;gap:5px;margin-top:10px;max-height:240px;overflow-y:auto}
    .batch-result-row{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border);font-size:12px}
    .batch-result-row:last-child{border-bottom:none}
    .batch-result-domain{font-family:var(--mono);color:var(--text);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .batch-result-icon{width:20px;height:20px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0}
    .bri-scanning{background:var(--bg4);color:var(--text3);animation:pulse 1.2s infinite}
    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
    .bri-live{background:var(--green-bg);color:var(--green2)}
    .bri-dead{background:var(--coral-bg);color:var(--coral)}

    /* ── Loading state ─── */
    .loading-state{display:none;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:40px 24px;background:var(--bg2);border:1px solid var(--border);border-radius:14px;margin-bottom:24px}
    .loading-state.visible{display:flex}
    .loading-spinner{width:36px;height:36px;border:3px solid var(--border);border-top-color:var(--coral);border-radius:50%;animation:spin .8s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
    .loading-domain{font-family:var(--mono);font-size:13px;color:var(--text2)}

    /* ── Result card ─── */
    .result-panel{display:none;margin-bottom:24px}
    .result-panel.visible{display:block}

    .result-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden}
    .result-card.is-dead{border-color:rgba(232,89,60,.2)}
    .result-card.is-live{border-color:rgba(29,158,117,.2)}

    .rc-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
    .rc-domain-title{font-family:var(--mono);font-size:18px;font-weight:700;color:var(--text)}
    .rc-domain-title span{color:var(--text3);font-weight:400}
    .rc-badges{display:flex;align-items:center;gap:7px;margin-top:6px;flex-wrap:wrap}
    .status-badge{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;padding:4px 10px;border-radius:5px;display:inline-flex;align-items:center;gap:5px}
    .sb-dot{width:6px;height:6px;border-radius:50%;background:currentColor;animation:pulse 2s infinite}

    .rc-score-wrap{flex-shrink:0;text-align:center}
    .dead-score-ring{width:64px;height:64px;position:relative}
    .dead-score-ring svg{width:64px;height:64px;transform:rotate(-90deg)}
    .dead-score-ring circle{fill:none;stroke-width:5;stroke-linecap:round}
    .dsr-bg{stroke:var(--border)}
    .dsr-fill{transition:stroke-dashoffset .8s ease}
    .dead-score-value{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-family:var(--mono);font-size:14px;font-weight:800;line-height:1}
    .dead-score-label{font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:.08em;margin-top:4px}

    /* Data grid */
    .rc-data-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-top:1px solid var(--border)}
    .rc-data-cell{padding:14px 18px;border-right:1px solid var(--border);border-bottom:1px solid var(--border)}
    .rc-data-cell:nth-child(4n){border-right:none}
    .rc-data-cell:nth-last-child(-n+4){border-bottom:none}
    .rd-label{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);margin-bottom:4px}
    .rd-value{font-size:12px;font-family:var(--mono);color:var(--text);word-break:break-all}
    .rd-value.na{color:var(--text3);font-family:var(--display);font-style:italic;font-size:11px}
    .rd-value.good{color:var(--green2)}
    .rd-value.warn{color:var(--amber)}
    .rd-value.bad{color:var(--coral)}

    /* Signals section */
    .rc-signals{padding:16px 22px;border-top:1px solid var(--border)}
    .rc-signals-title{font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:var(--text3);margin-bottom:10px}
    .signals-row{display:flex;flex-wrap:wrap;gap:7px}
    .signal-chip{display:flex;align-items:center;gap:5px;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600}
    .sc-green{background:var(--green-bg);color:var(--green2)}
    .sc-amber{background:var(--amber-bg);color:var(--amber)}
    .sc-coral{background:var(--coral-bg);color:var(--coral)}
    .sc-grey{background:var(--bg4);color:var(--text3)}

    /* Action bar */
    .rc-actions{padding:14px 22px;border-top:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .rc-action-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:7px;font-family:var(--display);font-size:11px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .15s;border:none;text-transform:uppercase;letter-spacing:.05em}
    .rab-coral{background:var(--coral-bg);color:var(--coral)}
    .rab-coral:hover{background:rgba(232,89,60,.2)}
    .rab-amber{background:var(--amber-bg);color:var(--amber)}
    .rab-amber:hover{background:rgba(239,159,39,.2)}
    .rab-green{background:var(--green-bg);color:var(--green2)}
    .rab-green:hover{background:rgba(29,158,117,.2)}
    .rab-default{background:var(--bg3);color:var(--text2);border:1px solid var(--border)}
    .rab-default:hover{background:var(--bg4);color:var(--text)}

    /* ── History table ─── */
    .history-wrap{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-top:28px}
    .history-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .history-title{font-size:12px;font-weight:700;color:var(--text)}
    .filter-tabs{display:flex;gap:2px;background:var(--bg3);border-radius:6px;padding:3px}
    .ftab{padding:4px 11px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;color:var(--text3);background:none;border:none;font-family:var(--display);transition:all .12s;text-decoration:none;display:block}
    .ftab:hover{color:var(--text);background:var(--bg4)}
    .ftab.active{background:var(--bg2);color:var(--text)}

    .ht-head{display:grid;grid-template-columns:1fr 110px 90px 80px 70px 80px;padding:9px 20px;background:var(--bg3);border-bottom:1px solid var(--border)}
    .ht-th{font-size:10px;text-transform:uppercase;letter-spacing:.11em;color:var(--text3);font-weight:600}
    .ht-th.right{text-align:right}
    .ht-row{display:grid;grid-template-columns:1fr 110px 90px 80px 70px 80px;padding:11px 20px;border-bottom:1px solid var(--border);align-items:center;cursor:pointer;transition:background .12s}
    .ht-row:last-child{border-bottom:none}
    .ht-row:hover{background:var(--bg3)}
    .ht-domain{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--text)}
    .ht-domain-tld{color:var(--text3);font-weight:400}
    .ht-sub{font-size:10px;color:var(--text3);margin-top:1px}
    .ht-status{display:flex;align-items:center}
    .ht-status-pill{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:2px 7px;border-radius:4px}
    .hsp-dead{background:var(--coral-bg);color:var(--coral)}
    .hsp-live{background:var(--green-bg);color:var(--green2)}
    .hsp-parked{background:var(--amber-bg);color:var(--amber)}
    .hsp-redirect{background:var(--blue-bg);color:var(--blue)}
    .hsp-other{background:var(--bg4);color:var(--text3)}
    .ht-score{font-family:var(--mono);font-size:11px;text-align:right}
    .ht-time{font-family:var(--mono);font-size:10px;color:var(--text3)}
    .ht-http{font-family:var(--mono);font-size:11px;color:var(--text2)}
    .hist-empty{text-align:center;padding:40px 20px;color:var(--text3);font-size:12px}

    /* ── Toast ─── */
    .toast{position:fixed;bottom:28px;right:28px;z-index:999;background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:12px 18px;font-size:13px;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.4);transform:translateY(20px);opacity:0;transition:all .3s ease;max-width:340px;display:flex;align-items:center;gap:9px}
    .toast.show{transform:translateY(0);opacity:1}
    .toast.success{border-color:rgba(29,158,117,.3)}
    .toast.error{border-color:rgba(232,89,60,.3)}

    /* ── Responsive ─── */
    @media(max-width:1100px){.stats-row{grid-template-columns:repeat(3,1fr)}.rc-data-grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:900px){
    .ht-head,.ht-row{grid-template-columns:1fr 90px 70px 70px}
    .ht-th:nth-child(4),.ht-row>*:nth-child(4),.ht-th:nth-child(6),.ht-row>*:nth-child(6){display:none}
    }
    @media(max-width:768px){
    .main{margin-left:0}.mobile-menu-btn{display:flex}
    .content{padding:20px 16px 50px}
    .stats-row{grid-template-columns:repeat(2,1fr)}
    .stats-row .stat-chip:last-child{grid-column:1/-1}
    .search-row{flex-direction:column;align-items:stretch}
    .rc-data-grid{grid-template-columns:1fr 1fr}
    .ht-head,.ht-row{grid-template-columns:1fr 80px 70px}
    .ht-th:nth-child(3),.ht-row>*:nth-child(3){display:none}
    .credits-pill{display:none}
    }
    @media(max-width:480px){.stats-row{grid-template-columns:1fr 1fr}}
    .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:49}
    .sidebar-overlay.show{display:block}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<?php require_once 'includes/sidebar.php'; ?>

<main class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
      <div class="breadcrumb">
        <a href="<?= htmlspecialchars($assetUrl('dashboard.php')) ?>">Dashboard</a>
        <span style="color:var(--text3);font-size:9px;"><i class="fas fa-chevron-right"></i></span>
        <span style="color:var(--text);">Dead Sites</span>
      </div>
    </div>
    <div class="topbar-right">
      <div class="credits-pill" id="creditsPill">
        <i class="fas fa-bolt" style="color:var(--amber);font-size:11px;"></i>
        <b id="creditsDisplay"><?= $credits ?></b> credits
      </div>
      <a href="<?= htmlspecialchars($assetUrl('billing.php?topup=1')) ?>" class="topbar-btn" title="Top up">
        <i class="fas fa-plus"></i>
      </a>
    </div>
  </div>

  <div class="content">

    <!-- Page header -->
    <div class="page-header">
      <div class="page-eyebrow">Domain intelligence</div>
      <div class="page-title">Dead Site Detection.</div>
      <div class="page-sub">
        Check if a domain's website is alive, dead, parked, or for sale.
        Find acquisition opportunities in domains with inactive sites.
        <?php if ($canScan): ?>
          Each scan costs <em><?= $creditCost ?> credits</em>. Results cached for 6 hours.
        <?php endif; ?>
      </div>
    </div>

    <!-- Upgrade gate -->
    <?php if (!$canScan): ?>
    <div class="upgrade-gate">
      <div class="gate-icon">💀</div>
      <div class="gate-title">Dead site detection requires Pro</div>
      <div class="gate-sub">
        Upgrade to Pro to check if any domain's website is live, dead, parked, or actively for sale — and instantly backorder the ones with inactive sites.
      </div>
      <a href="<?= htmlspecialchars($assetUrl('billing.php?plan=pro')) ?>" class="gate-cta">
        <i class="fas fa-bolt" style="font-size:10px;"></i> Upgrade to Pro — ₦9,000/mo
      </a>
    </div>
    <?php endif; ?>

    <!-- Stats row -->
    <div class="stats-row">
      <?php
      $chips = [
        ['num'=>(int)$stats['total'],     'lbl'=>'Total scanned', 'icon'=>'fa-search',           'cls'=>'sci-all'],
        ['num'=>(int)$stats['dead_count'],'lbl'=>'Dead/inactive', 'icon'=>'fa-skull-crossbones', 'cls'=>'sci-coral'],
        ['num'=>(int)$stats['parked_count'],'lbl'=>'Parked',      'icon'=>'fa-parking',          'cls'=>'sci-amber'],
        ['num'=>(int)$stats['for_sale_count'],'lbl'=>'For sale',  'icon'=>'fa-tag',              'cls'=>'sci-purple'],
        ['num'=>(int)$stats['live_count'],'lbl'=>'Live sites',    'icon'=>'fa-check-circle',     'cls'=>'sci-green'],
      ];
      foreach ($chips as $c): ?>
      <div class="stat-chip">
        <div class="stat-chip-icon <?= $c['cls'] ?>"><i class="fas <?= $c['icon'] ?>"></i></div>
        <div><div class="stat-chip-num"><?= $c['num'] ?></div><div class="stat-chip-lbl"><?= $c['lbl'] ?></div></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Batch scan card -->
    <?php if ($canScan && $watchlistCount > 0): ?>
    <div class="batch-card">
      <div class="batch-header">
        <div class="batch-title">
          <i class="fas fa-layer-group" style="color:var(--green2);font-size:12px;"></i>
          Scan your watchlist
        </div>
        <span class="batch-meta"><?= $watchlistCount ?> domain<?= $watchlistCount!==1?'s':'' ?> · <?= $watchlistCount * $creditCost ?> credits total</span>
        <button class="batch-btn" id="batchBtn" onclick="startBatchScan()">
          <i class="fas fa-play" style="font-size:10px;"></i> Scan all watchlist domains
        </button>
      </div>
      <div class="batch-progress" id="batchProgress">
        <div class="batch-progress-bar-wrap"><div class="batch-progress-fill" id="batchFill"></div></div>
        <div class="batch-progress-label" id="batchLabel">Starting…</div>
        <div class="batch-results-list" id="batchResultsList"></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Single scan hero -->
    <div class="search-hero">
      <div class="search-hero-label">
        <i class="fas fa-skull" style="color:var(--coral);"></i>
        Check a domain
      </div>
      <div class="search-row">
        <div class="search-input-wrap">
          <i class="fas fa-globe search-input-icon"></i>
          <input class="search-input" type="text" id="scanInput"
                 placeholder="<?= $canScan ? 'Enter domain — e.g. oldsite.com, inactivebrand.ng' : 'Requires Pro plan' ?>"
                 value="<?= $prefill ?>"
                 <?= !$canScan ? 'disabled' : '' ?>
                 autocomplete="off" maxlength="253"
                 onkeydown="if(event.key==='Enter')runScan()">
        </div>
        <button class="search-btn" id="scanBtn" onclick="runScan()" <?= !$canScan ? 'disabled' : '' ?>>
          <i class="fas fa-skull" style="font-size:11px;"></i> Check site
        </button>
      </div>
      <div class="search-hint">
        <span><span class="cost-pill"><?= $creditCost ?> credits</span> per scan</span>
        <span><span class="cache-pill">FREE</span> if cached within 6 hours</span>
        <span><i class="fas fa-info-circle"></i> Checks HTTP status, content, SSL, parking pages &amp; for-sale signals</span>
        <?php if ($credits < $creditCost && $canScan): ?>
        <span style="color:var(--coral);">
          <i class="fas fa-exclamation-triangle"></i>
          Low credits — <a href="<?= htmlspecialchars($assetUrl('billing.php?topup=1')) ?>" style="color:var(--amber);text-decoration:none;">top up</a>.
        </span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Loading -->
    <div class="loading-state" id="loadingState">
      <div class="loading-spinner"></div>
      <div class="loading-domain" id="loadingDomain"></div>
      <div style="font-size:11px;color:var(--text3);">Connecting to server, analysing response…</div>
    </div>

    <!-- Result panel -->
    <div class="result-panel" id="resultPanel"></div>

    <!-- History table -->
    <div class="history-wrap">
      <div class="history-header">
        <span class="history-title">
          <i class="fas fa-history" style="color:var(--green2);margin-right:6px;font-size:12px;"></i>
          Scan history
        </span>
        <div class="filter-tabs">
          <?php foreach (['all'=>'All','dead'=>'Dead / Parked','live'=>'Live'] as $f=>$lbl): ?>
          <a href="?filter=<?= $f ?>" class="ftab <?= $filter===$f?'active':'' ?>"><?= $lbl ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (!empty($history)): ?>
      <div class="ht-head">
        <div class="ht-th">Domain</div>
        <div class="ht-th">Site status</div>
        <div class="ht-th">HTTP</div>
        <div class="ht-th">Response</div>
        <div class="ht-th right">Dead score</div>
        <div class="ht-th">Scanned</div>
      </div>
      <?php foreach ($history as $h):
        $sm    = $siteMeta[$h['site_status']] ?? $siteMeta['no_response'];
        $parts = explode('.', $h['domain_name']);
        $sld   = $parts[0];
        $tldP  = '.' . implode('.', array_slice($parts, 1));
        $pillClass = match(true) {
            $h['is_dead'] && $h['is_parked'] => 'hsp-parked',
            $h['is_dead']                    => 'hsp-dead',
            $h['site_status'] === 'redirect' => 'hsp-redirect',
            $h['site_status'] === 'live'     => 'hsp-live',
            default                          => 'hsp-other',
        };
        $scoreColor = (int)$h['dead_score'] >= 70 ? 'var(--coral)' : ((int)$h['dead_score'] >= 40 ? 'var(--amber)' : 'var(--green2)');
      ?>
      <div class="ht-row" onclick="quickScan('<?= htmlspecialchars($h['domain_name'], ENT_QUOTES) ?>')">
        <div>
          <div class="ht-domain"><?= htmlspecialchars($sld) ?><span class="ht-domain-tld"><?= htmlspecialchars($tldP) ?></span></div>
          <?php if ($h['page_title']): ?>
          <div class="ht-sub"><?= htmlspecialchars(substr($h['page_title'],0,40)) ?></div>
          <?php endif; ?>
        </div>
        <div class="ht-status">
          <span class="ht-status-pill <?= $pillClass ?>"><?= $sm['label'] ?></span>
        </div>
        <div class="ht-http" style="<?= (int)$h['http_status']>=400?'color:var(--coral)':((int)$h['http_status']>=300?'color:var(--blue)':'') ?>">
          <?= $h['http_status'] ?: '—' ?>
        </div>
        <div class="ht-time" style="color:var(--text2);">
          <?= $h['response_time_ms'] ? $h['response_time_ms'].'ms' : '—' ?>
        </div>
        <div class="ht-score right" style="color:<?= $scoreColor ?>">
          <?= (int)$h['dead_score'] ?>
        </div>
        <div class="ht-time"><?= timeAgo($h['scanned_at']) ?></div>
      </div>
      <?php endforeach; ?>

      <?php else: ?>
      <div class="hist-empty">
        <i class="fas fa-search" style="font-size:20px;margin-bottom:10px;display:block;opacity:.3;"></i>
        <?= $canScan ? 'Your scan history will appear here.' : 'Upgrade to Pro to start scanning domains.' ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</main>

<!-- Toast -->
<div class="toast" id="toast">
  <i class="fas fa-check-circle" id="toastIcon" style="font-size:14px;flex-shrink:0;color:var(--green2);"></i>
  <span id="toastText"></span>
</div>

<script>
const API_URL   = window.location.pathname;
const APP_BASE  = <?= json_encode($appBasePath ?? '') ?>;
const CAN_SCAN  = <?= $canScan ? 'true' : 'false' ?>;
const WATCHLIST = <?= json_encode($watchlistDomains) ?>;

// ── Single scan ────────────────────────────────────────────
async function runScan(domainOverride) {
  if (!CAN_SCAN) return;
  const input = document.getElementById('scanInput');
  const btn   = document.getElementById('scanBtn');
  let val = domainOverride || input.value.trim().toLowerCase()
              .replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
  if (!val) { input.focus(); return; }
  if (!val.includes('.')) val += '.com';
  if (!domainOverride) input.value = val;

  showLoading(val);
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:11px;"></i> Scanning…';

  try {
    const res  = await post({ action: 'scan', domain: val });
    const data = await res.json();
    hideLoading();

    if (data.success) {
      renderResult(data.data);
      if (!data.data.from_cache) updateCredits(data.credits_remaining);
      if (data.data.from_cache) showToast('Loaded from cache — no credits used.', 'success');
    } else if (data.requiresUpgrade) {
      showToast('Dead site scanning requires a Pro plan.', 'error');
    } else if (data.insufficientCredits) {
      showToast(data.message, 'error');
      setTimeout(() => window.location.href = APP_BASE + '/billing.php?topup=1', 2000);
    } else {
      showToast(data.message || 'Scan failed.', 'error');
    }
  } catch {
    hideLoading();
    showToast('Network error. Try again.', 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-skull" style="font-size:11px;"></i> Check site';
  }
}

function quickScan(domain) {
  const input = document.getElementById('scanInput');
  input.value = domain;
  window.scrollTo({ top: 0, behavior: 'smooth' });
  runScan(domain);
}

// ── Batch scan ─────────────────────────────────────────────
let batchRunning = false;
async function startBatchScan() {
  if (!CAN_SCAN || batchRunning) return;
  if (!WATCHLIST.length) { showToast('No domains in watchlist.', 'error'); return; }

  const cost = WATCHLIST.length * <?= $creditCost ?>;
  const cur  = parseInt(document.getElementById('creditsDisplay')?.textContent || '0');
  if (cur < cost && !confirm(`This will use up to ${cost} credits (you have ${cur}). Continue?`)) return;

  batchRunning = true;
  const btn  = document.getElementById('batchBtn');
  const prog = document.getElementById('batchProgress');
  const fill = document.getElementById('batchFill');
  const lbl  = document.getElementById('batchLabel');
  const list = document.getElementById('batchResultsList');

  btn.classList.add('running');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:10px;"></i> Scanning…';
  prog.classList.add('visible');
  list.innerHTML = '';

  for (let i = 0; i < WATCHLIST.length; i++) {
    const domain = WATCHLIST[i];
    lbl.textContent = `Scanning ${domain} (${i+1}/${WATCHLIST.length})…`;
    fill.style.width = Math.round(((i) / WATCHLIST.length) * 100) + '%';

    // Add scanning row
    const row = document.createElement('div');
    row.className = 'batch-result-row';
    row.id = 'brow-' + i;
    row.innerHTML = `<div class="batch-result-icon bri-scanning"><i class="fas fa-spinner fa-spin"></i></div><span class="batch-result-domain">${escHtml(domain)}</span><span style="font-size:11px;color:var(--text3);">scanning…</span>`;
    list.appendChild(row);
    list.scrollTop = list.scrollHeight;

    try {
      const res  = await post({ action: 'scan', domain });
      const data = await res.json();
      const r = data.data;

      const isDead = r?.is_dead;
      row.innerHTML = `
        <div class="batch-result-icon ${isDead ? 'bri-dead' : 'bri-live'}">
          <i class="fas ${isDead ? 'fa-skull' : 'fa-check'}"></i>
        </div>
        <span class="batch-result-domain">${escHtml(domain)}</span>
        <span style="font-size:11px;color:${isDead ? 'var(--coral)' : 'var(--green2)'};">
          ${r?.site_status?.replace('_',' ') || 'unknown'} ${r?.http_status ? '('+r.http_status+')' : ''}
        </span>
        ${isDead ? `<a href="${APP_BASE}/backorders.php?domain=${encodeURIComponent(domain)}" style="font-size:10px;font-family:var(--display);font-weight:700;color:var(--amber);margin-left:auto;text-decoration:none;text-transform:uppercase;">Backorder</a>` : ''}
      `;
      if (data.credits_remaining !== undefined) updateCredits(data.credits_remaining);
    } catch {
      row.innerHTML = `<div class="batch-result-icon bri-dead"><i class="fas fa-times"></i></div><span class="batch-result-domain">${escHtml(domain)}</span><span style="font-size:11px;color:var(--text3);">Error</span>`;
    }

    await new Promise(r => setTimeout(r, 400)); // small delay between scans
  }

  fill.style.width = '100%';
  lbl.textContent = `Done — ${WATCHLIST.length} domain${WATCHLIST.length!==1?'s':''} scanned.`;
  btn.classList.remove('running');
  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-redo" style="font-size:10px;"></i> Scan again';
  batchRunning = false;
}

// ── Render result card ─────────────────────────────────────
function renderResult(d) {
  const panel  = document.getElementById('resultPanel');
  panel.classList.add('visible');

  const parts  = (d.domain_name || '').split('.');
  const sld    = parts[0];
  const tld    = '.' + parts.slice(1).join('.');
  const isDead = !!d.is_dead;
  const score  = parseInt(d.dead_score || 0);

  // Score ring
  const r = 27, circ = 2 * Math.PI * r;
  const dashOffset = circ - (score / 100) * circ;
  const scoreColor = score >= 70 ? '#E8593C' : score >= 40 ? '#EF9F27' : '#14C48A';

  // Status badge
  const sm = <?= json_encode($siteMeta) ?>;
  const s  = sm[d.site_status] || sm['no_response'];

  // Signals
  const signals = [];
  if (d.has_content)  signals.push({cls:'sc-green', icon:'fa-check-circle', txt:'Has content'});
  if (!d.has_content && isDead) signals.push({cls:'sc-coral', icon:'fa-ban', txt:'No content'});
  if (d.is_parked)    signals.push({cls:'sc-amber', icon:'fa-parking', txt:'Parked page'});
  if (d.is_for_sale)  signals.push({cls:'sc-coral', icon:'fa-tag', txt:'For sale signals'});
  if (d.ssl_valid===1) signals.push({cls:'sc-green', icon:'fa-lock', txt:'SSL valid'});
  if (d.ssl_valid===0) signals.push({cls:'sc-amber', icon:'fa-unlock', txt:'SSL invalid'});
  if (d.redirect_count > 0) signals.push({cls:'sc-grey', icon:'fa-exchange-alt', txt:`${d.redirect_count} redirect${d.redirect_count!==1?'s':''}`});
  if (d.from_cache) signals.push({cls:'sc-grey', icon:'fa-clock', txt:`Cached ${d.cached_age||''}`});

  // Actions
  const actions = [];
  if (isDead) {
    actions.push(`<a href="${APP_BASE}/backorders.php?domain=${encodeURIComponent(d.domain_name)}" class="rc-action-btn rab-coral"><i class="fas fa-clock" style="font-size:10px;"></i> Backorder domain</a>`);
    actions.push(`<button class="rc-action-btn rab-amber" onclick="watchDomain('${escHtml(d.domain_name)}')"><i class="fas fa-bookmark" style="font-size:10px;"></i> Add to watchlist</button>`);
  }
  actions.push(`<a href="${APP_BASE}/whois.php?domain=${encodeURIComponent(d.domain_name)}" class="rc-action-btn rab-default"><i class="fas fa-search" style="font-size:10px;"></i> WHOIS lookup</a>`);
  actions.push(`<button class="rc-action-btn rab-green" onclick="quickScan('${escHtml(d.domain_name)}')"><i class="fas fa-redo" style="font-size:10px;"></i> Re-scan</button>`);

  panel.innerHTML = `
    <div class="result-card ${isDead?'is-dead':'is-live'}">
      <div class="rc-header">
        <div>
          <div class="rc-domain-title">${escHtml(sld)}<span>${escHtml(tld)}</span></div>
          <div class="rc-badges">
            <span class="status-badge" style="background:var(${s.bg});color:var(${s.color});">
              <span class="sb-dot"></span>
              ${s.label}
            </span>
            ${d.page_title ? `<span style="font-size:11px;color:var(--text3);font-family:var(--mono);">"${escHtml(d.page_title.substring(0,50))}"</span>` : ''}
          </div>
        </div>
        <div class="rc-score-wrap">
          <div class="dead-score-ring">
            <svg viewBox="0 0 64 64">
              <circle class="dsr-bg" cx="32" cy="32" r="${r}" stroke-dasharray="${circ}" stroke-dashoffset="0"/>
              <circle class="dsr-fill" cx="32" cy="32" r="${r}"
                stroke="${scoreColor}"
                stroke-dasharray="${circ}"
                stroke-dashoffset="${dashOffset}"/>
            </svg>
            <div class="dead-score-value" style="color:${scoreColor}">${score}</div>
          </div>
          <div class="dead-score-label">Dead score</div>
        </div>
      </div>

      <div class="rc-data-grid">
        <div class="rc-data-cell">
          <div class="rd-label">HTTP status</div>
          <div class="rd-value ${d.http_status>=400?'bad':d.http_status>=300?'warn':'good'}">${d.http_status||'No response'}</div>
        </div>
        <div class="rc-data-cell">
          <div class="rd-label">Response time</div>
          <div class="rd-value ${d.response_time_ms>3000?'warn':''}">${d.response_time_ms?d.response_time_ms+'ms':'—'}</div>
        </div>
        <div class="rc-data-cell">
          <div class="rd-label">Final URL</div>
          <div class="rd-value">${d.final_url ? `<a href="${escHtml(d.final_url)}" target="_blank" rel="noopener" style="color:var(--blue);text-decoration:none;">${escHtml(d.final_url.substring(0,38))}…</a>` : '<span class="na">—</span>'}</div>
        </div>
        <div class="rc-data-cell">
          <div class="rd-label">Server</div>
          <div class="rd-value ${!d.server_header?'na':''}">${d.server_header||'Not detected'}</div>
        </div>
        <div class="rc-data-cell">
          <div class="rd-label">SSL</div>
          <div class="rd-value ${d.ssl_valid===1?'good':d.ssl_valid===0?'bad':'na'}">${d.ssl_valid===1?'Valid':d.ssl_valid===0?'Invalid / Error':'Not checked'}</div>
        </div>
        <div class="rc-data-cell">
          <div class="rd-label">Content type</div>
          <div class="rd-value ${!d.content_type?'na':''}">${d.content_type||'Not detected'}</div>
        </div>
        <div class="rc-data-cell">
          <div class="rd-label">Redirects</div>
          <div class="rd-value">${d.redirect_count||'0'}</div>
        </div>
        <div class="rc-data-cell">
          <div class="rd-label">Has content</div>
          <div class="rd-value ${d.has_content?'good':'bad'}">${d.has_content?'Yes':'No'}</div>
        </div>
      </div>

      ${signals.length ? `
      <div class="rc-signals">
        <div class="rc-signals-title">Signals detected</div>
        <div class="signals-row">${signals.map(s=>`<span class="signal-chip ${s.cls}"><i class="fas ${s.icon}" style="font-size:10px;"></i>${s.txt}</span>`).join('')}</div>
      </div>` : ''}

      <div class="rc-actions">${actions.join('')}</div>
    </div>`;
}

// ── Watch domain ───────────────────────────────────────────
async function watchDomain(domain) {
  try {
    const res  = await fetch(APP_BASE + '/api/watchlist-domain.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ domain })
    });
    const data = await res.json();
    showToast(data.success ? `${domain} added to watchlist.` : (data.message||'Failed.'), data.success?'success':'error');
  } catch { showToast('Network error.', 'error'); }
}

// ── Helpers ────────────────────────────────────────────────
function showLoading(domain) {
  document.getElementById('loadingDomain').textContent = 'Scanning ' + domain + '…';
  document.getElementById('loadingState').classList.add('visible');
  document.getElementById('resultPanel').classList.remove('visible');
  document.getElementById('resultPanel').innerHTML = '';
}
function hideLoading() { document.getElementById('loadingState').classList.remove('visible'); }

function updateCredits(val) {
  const el = document.getElementById('creditsDisplay');
  if (el) el.textContent = val;
}

function post(body) {
  return fetch(API_URL, {
    method:'POST',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body: JSON.stringify(body)
  });
}

function escHtml(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showToast(msg, type='success') {
  const t=document.getElementById('toast'), icon=document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  icon.className = `fas ${type==='error'?'fa-exclamation-circle':'fa-check-circle'}`;
  icon.style.color = type==='error'?'var(--coral)':'var(--green2)';
  t.className = `toast show ${type}`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 3800);
}

function openSidebar()  { document.getElementById('cdSidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('show'); }
function closeSidebar() { document.getElementById('cdSidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('show'); }

// Auto-run if prefill
window.addEventListener('DOMContentLoaded', () => {
  const input = document.getElementById('scanInput');
  if (input?.value.trim() && CAN_SCAN) setTimeout(runScan, 300);
});

document.getElementById('scanInput')?.addEventListener('keydown', e => { if (e.key==='Enter') runScan(); });
</script>

</body>
</html>