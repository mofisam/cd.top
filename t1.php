<?php
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

// ── WHOIS API key ──────────────────────────────────────────
$whoisApiKey = defined('WHOIS_API_KEY') ? WHOIS_API_KEY : 'at_Tum0rTrVQkxRo3NjRDgRxeWiJwXTN';
$creditCost  = 3; // credits per WHOIS lookup

// ── DB setup ───────────────────────────────────────────────
$conn = getDBConnection();

// Create whois_lookups cache table
$conn->query("
    CREATE TABLE IF NOT EXISTS whois_lookups (
        id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
        user_id         INT  NOT NULL,
        domain_name     VARCHAR(253)     NOT NULL,
        tld             VARCHAR(63)      NOT NULL,
        credits_spent   TINYINT UNSIGNED NOT NULL DEFAULT 3,
        -- Parsed fields
        registrar       VARCHAR(255)     NULL,
        registrar_url   VARCHAR(512)     NULL,
        registrant_name VARCHAR(255)     NULL,
        registrant_org  VARCHAR(255)     NULL,
        registrant_country VARCHAR(10)   NULL,
        registrant_email VARCHAR(320)    NULL,
        created_date    DATE             NULL,
        updated_date    DATE             NULL,
        expiry_date     DATE             NULL,
        status          VARCHAR(512)     NULL  COMMENT 'Space-separated EPP status codes',
        nameservers     TEXT             NULL  COMMENT 'JSON array',
        dnssec          VARCHAR(64)      NULL,
        is_available    TINYINT(1)       NOT NULL DEFAULT 0,
        raw_response    MEDIUMTEXT       NULL,
        source          ENUM('api','socket','cache') NOT NULL DEFAULT 'api',
        looked_up_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_wl_user   (user_id),
        INDEX idx_wl_domain (domain_name),
        INDEX idx_wl_date   (looked_up_at),
        CONSTRAINT fk_wl_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Fetch user ─────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, email, full_name, plan, credits FROM users WHERE id = ?");
$stmt->bind_param("i", $session['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { header('Location: logout.php'); exit(); }

$userPlan   = $user['plan']    ?? 'free';
$credits    = (int)($user['credits'] ?? 0);
$canWhois   = ($userPlan !== 'free');

// ── Handle AJAX WHOIS lookup ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    ob_start();

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? 'lookup';

    if ($action === 'lookup') {
        if (!$canWhois) {
            ob_end_clean();
            echo json_encode(['success'=>false,'requiresUpgrade'=>true,'message'=>'WHOIS lookups require a Pro plan.']);
            exit();
        }
        if ($credits < $creditCost) {
            ob_end_clean();
            echo json_encode(['success'=>false,'insufficientCredits'=>true,'message'=>"Not enough credits. WHOIS costs {$creditCost} credits. You have {$credits}."]); exit();
        }

        $raw    = strtolower(trim($input['domain'] ?? ''));
        $raw    = preg_replace('#^https?://(www\.)?#', '', $raw);
        $domain = rtrim($raw, '/');

        if (!$domain || !str_contains($domain, '.') ||
            !preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Enter a valid domain name.']);
            exit();
        }

        $parts = explode('.', $domain);
        $tld   = implode('.', array_slice($parts, 1));

        // ── Check cache (< 24 hours old) ──────────────────
        $cacheStmt = $conn->prepare("
            SELECT * FROM whois_lookups
            WHERE domain_name = ? AND looked_up_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY looked_up_at DESC LIMIT 1
        ");
        $cacheStmt->bind_param("s", $domain);
        $cacheStmt->execute();
        $cached = $cacheStmt->get_result()->fetch_assoc();
        $cacheStmt->close();

        if ($cached) {
            // Serve from cache — no credit deduction
            $cached['nameservers'] = json_decode($cached['nameservers'] ?? '[]', true);
            $cached['from_cache']  = true;
            $cached['cached_age']  = round((time() - strtotime($cached['looked_up_at'])) / 60) . 'm ago';
            ob_end_clean();
            echo json_encode(['success'=>true,'data'=>$cached,'credits_remaining'=>$credits]);
            exit();
        }

        // ── Run live WHOIS lookup ─────────────────────────
        $result = runWhoisLookup($domain, $whoisApiKey);

        if (!$result['success']) {
            ob_end_clean();
            echo json_encode($result);
            exit();
        }

        $data = $result['data'];

        // ── Deduct credits ─────────────────────────────────
        $deductStmt = $conn->prepare("UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?");
        $deductStmt->bind_param("iii", $creditCost, $session['user_id'], $creditCost);
        $deductStmt->execute();
        if ($deductStmt->affected_rows === 0) {
            $deductStmt->close();
            ob_end_clean();
            echo json_encode(['success'=>false,'message'=>'Credit deduction failed. Please try again.']);
            exit();
        }
        $deductStmt->close();
        $creditsAfter = $credits - $creditCost;

        // ── Save to whois_lookups ─────────────────────────
        $nsJson = json_encode($data['nameservers'] ?? []);
        $insStmt = $conn->prepare("
            INSERT INTO whois_lookups
              (user_id, domain_name, tld, credits_spent, registrar, registrar_url,
               registrant_name, registrant_org, registrant_country, registrant_email,
               created_date, updated_date, expiry_date, status, nameservers, dnssec,
               is_available, raw_response, source)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $insStmt->bind_param("sssssssssssssssssss",
            // NOTE: bind_param first arg is types string, values follow
            ...[
                $session['user_id'], $domain, $tld, $creditCost,
                $data['registrar'] ?? null, $data['registrar_url'] ?? null,
                $data['registrant_name'] ?? null, $data['registrant_org'] ?? null,
                $data['registrant_country'] ?? null, $data['registrant_email'] ?? null,
                $data['created_date'] ?? null, $data['updated_date'] ?? null,
                $data['expiry_date'] ?? null,
                is_array($data['status'] ?? null) ? implode(' ', $data['status']) : ($data['status'] ?? null),
                $nsJson, $data['dnssec'] ?? null,
                (int)($data['is_available'] ?? 0),
                $data['raw'] ?? null,
                $data['source'] ?? 'api',
            ]
        );
        // Rebuild with correct bind types
        $insStmt->close();
        $insStmt2 = $conn->prepare("
            INSERT INTO whois_lookups
              (user_id, domain_name, tld, credits_spent, registrar, registrar_url,
               registrant_name, registrant_org, registrant_country, registrant_email,
               created_date, updated_date, expiry_date, status, nameservers, dnssec,
               is_available, raw_response, source)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $isAvailInt = (int)($data['is_available'] ?? 0);
        $statusStr  = is_array($data['status'] ?? null) ? implode(' ', $data['status']) : ($data['status'] ?? null);
        $insStmt2->bind_param("isssssssssssssssiss",
            $session['user_id'], $domain, $tld, $creditCost,
            $data['registrar'] ?? null, $data['registrar_url'] ?? null,
            $data['registrant_name'] ?? null, $data['registrant_org'] ?? null,
            $data['registrant_country'] ?? null, $data['registrant_email'] ?? null,
            $data['created_date'] ?? null, $data['updated_date'] ?? null,
            $data['expiry_date'] ?? null,
            $statusStr, $nsJson, $data['dnssec'] ?? null,
            $isAvailInt, $data['raw'] ?? null, $data['source'] ?? 'api'
        );
        $insStmt2->execute();
        $insStmt2->close();

        // ── Credit ledger entry ───────────────────────────
        $balStmt = $conn->prepare("SELECT credits FROM users WHERE id=?");
        $balStmt->bind_param("i", $session['user_id']);
        $balStmt->execute();
        $balAfter = (int)($balStmt->get_result()->fetch_assoc()['credits'] ?? $creditsAfter);
        $balStmt->close();

        $ledgerStmt = $conn->prepare("INSERT INTO credit_ledger (user_id, delta, balance_after, type, domain_name, note) VALUES (?,?,?,'whois_lookup',?,?)");
        if ($ledgerStmt) {
            $delta = -$creditCost;
            $note  = "WHOIS lookup: {$domain}";
            $ledgerStmt->bind_param("iiiss", $session['user_id'], $delta, $balAfter, $domain, $note);
            $ledgerStmt->execute();
            $ledgerStmt->close();
        }

        $data['from_cache']       = false;
        $data['nameservers']      = $data['nameservers'] ?? [];
        ob_end_clean();
        echo json_encode(['success'=>true,'data'=>$data,'credits_remaining'=>$creditsAfter]);
        exit();
    }

    ob_end_clean();
    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit();
}

// ── Fetch lookup history ───────────────────────────────────
$histStmt = $conn->prepare("
    SELECT id, domain_name, registrar, expiry_date, is_available, source, looked_up_at, credits_spent
    FROM whois_lookups
    WHERE user_id = ?
    ORDER BY looked_up_at DESC
    LIMIT 20
");
$histStmt->bind_param("i", $session['user_id']);
$histStmt->execute();
$histResult = $histStmt->get_result();
$history = [];
while ($row = $histResult->fetch_assoc()) { $history[] = $row; }
$histStmt->close();

// ── Sidebar counts ─────────────────────────────────────────
$watchStmt = $conn->prepare("SELECT COUNT(*) as c FROM pinned_domains WHERE user_id=? AND status='active'");
$watchStmt->bind_param("i", $session['user_id']); $watchStmt->execute();
$watchlistCount = (int)$watchStmt->get_result()->fetch_assoc()['c']; $watchStmt->close();

$alertCount = 0;
$alStmt = $conn->prepare("SELECT COUNT(*) as c FROM domain_alerts WHERE user_id=? AND status='unread'");
if ($alStmt) { $alStmt->bind_param("i", $session['user_id']); $alStmt->execute(); $alertCount = (int)$alStmt->get_result()->fetch_assoc()['c']; $alStmt->close(); }

$conn->close();

// ── User meta ──────────────────────────────────────────────
$userName  = trim($user['full_name'] ?? '') ?: explode('@', $user['email'])[0];
$firstName = explode(' ', $userName)[0];
$initials  = strtoupper(substr($userName,0,1).(strpos($userName,' ')!==false?substr($userName,strpos($userName,' ')+1,1):''));
$activePage = 'whois';

// ── Pre-fill from query string ─────────────────────────────
$prefill = htmlspecialchars(preg_replace('#^https?://(www\.)?#','', trim($_GET['domain'] ?? '')), ENT_QUOTES);

// ═══════════════════════════════════════════════════════════
// WHOIS LOOKUP FUNCTIONS
// ═══════════════════════════════════════════════════════════
function runWhoisLookup(string $domain, string $apiKey): array {
    // Try WhoisXML API first if key is real
    if ($apiKey && $apiKey !== 'at_Tum0rTrVQkxRo3NjRDgRxeWiJwXTN') {
        $apiResult = whoisXmlApiLookup($domain, $apiKey);
        if ($apiResult) return ['success'=>true,'data'=>$apiResult];
    }
    // Fallback: raw socket WHOIS
    $socketResult = rawSocketWhois($domain);
    if ($socketResult) return ['success'=>true,'data'=>$socketResult];

    return ['success'=>false,'message'=>'Could not fetch WHOIS data. Please try again.'];
}

function whoisXmlApiLookup(string $domain, string $apiKey): ?array {
    $url = 'https://www.whoisxmlapi.com/whoisserver/WhoisService?' . http_build_query([
        'apiKey'       => $apiKey,
        'domainName'   => $domain,
        'outputFormat' => 'JSON',
        'da'           => 2,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'checkdomain/2.0',
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$body) return null;

    $json = json_decode($body, true);
    if (!$json || !isset($json['WhoisRecord'])) return null;

    $r = $json['WhoisRecord'];

    if (isset($r['dataError']) && $r['dataError'] === 'Not found') {
        return ['is_available'=>true,'domain'=>$domain,'source'=>'api','raw'=>$body];
    }

    $registrant = $r['registrant'] ?? [];
    $ns = [];
    if (!empty($r['nameServers']['hostNames'])) $ns = $r['nameServers']['hostNames'];
    elseif (!empty($r['nameServers']['rawText'])) {
        $ns = array_filter(array_map('trim', explode("\n", $r['nameServers']['rawText'])));
    }

    $statusList = [];
    if (!empty($r['status'])) {
        $statusList = is_array($r['status']) ? $r['status'] : array_filter(array_map('trim', explode(' ', $r['status'])));
    }

    return [
        'is_available'      => false,
        'domain'            => $domain,
        'registrar'         => $r['registrarName'] ?? null,
        'registrar_url'     => $r['registrarIANAID'] ? "https://www.iana.org/assignments/registrar-ids/{$r['registrarIANAID']}" : null,
        'registrant_name'   => $registrant['name'] ?? null,
        'registrant_org'    => $registrant['organization'] ?? null,
        'registrant_country'=> $registrant['country'] ?? null,
        'registrant_email'  => $registrant['email'] ?? null,
        'created_date'      => normalizeDate($r['createdDateNormalized'] ?? $r['createdDate'] ?? null),
        'updated_date'      => normalizeDate($r['updatedDateNormalized'] ?? $r['updatedDate'] ?? null),
        'expiry_date'       => normalizeDate($r['expiresDateNormalized'] ?? $r['expiresDate'] ?? null),
        'status'            => $statusList,
        'nameservers'       => array_values(array_unique(array_filter(array_map('strtolower', (array)$ns)))),
        'dnssec'            => $r['dnssec'] ?? null,
        'source'            => 'api',
        'raw'               => substr($r['rawText'] ?? '', 0, 8000),
    ];
}

function rawSocketWhois(string $domain): ?array {
    $parts = explode('.', $domain);
    $tld   = strtolower(end($parts));

    $servers = [
        'com'=>'whois.verisign-grs.com','net'=>'whois.verisign-grs.com',
        'org'=>'whois.pir.org','io'=>'whois.nic.io','co'=>'whois.nic.co',
        'dev'=>'whois.nic.dev','app'=>'whois.nic.app','ai'=>'whois.nic.ai',
        'info'=>'whois.afilias.net','biz'=>'whois.neulevel.biz',
        'ng'=>'whois.nic.net.ng','com.ng'=>'whois.nic.net.ng',
        'uk'=>'whois.nic.uk','co.uk'=>'whois.nic.uk',
        'de'=>'whois.denic.de','fr'=>'whois.afnic.fr',
        'ca'=>'whois.cira.ca','au'=>'whois.auda.org.au',
        'in'=>'whois.registry.in','xyz'=>'whois.nic.xyz',
        'online'=>'whois.nic.online','site'=>'whois.nic.site',
        'tech'=>'whois.nic.tech','store'=>'whois.nic.store',
    ];

    // Try multi-part TLD first (e.g. com.ng)
    $tldKey = null;
    if (count($parts) >= 3) {
        $multiTld = strtolower($parts[count($parts)-2] . '.' . $parts[count($parts)-1]);
        if (isset($servers[$multiTld])) $tldKey = $multiTld;
    }
    if (!$tldKey && isset($servers[$tld])) $tldKey = $tld;
    if (!$tldKey) return null;

    $server = $servers[$tldKey];
    $sock   = @fsockopen($server, 43, $errno, $errstr, 10);
    if (!$sock) return null;

    fwrite($sock, $domain . "\r\n");
    $raw = '';
    while (!feof($sock)) $raw .= fgets($sock, 512);
    fclose($sock);

    if (!$raw) return null;

    $available = (bool)preg_match('/No match|NOT FOUND|is available|Status: free|No entries found/i', $raw);

    if ($available) return ['is_available'=>true,'domain'=>$domain,'source'=>'socket','raw'=>substr($raw,0,4000)];

    $extract = fn($pattern) => (preg_match($pattern, $raw, $m)) ? trim($m[1]) : null;

    $ns = [];
    preg_match_all('/Name Server:\s*([^\s\n]+)/i', $raw, $nsMatches);
    if (!empty($nsMatches[1])) $ns = array_unique(array_map('strtolower', $nsMatches[1]));

    $statusRaw = [];
    preg_match_all('/Domain Status:\s*([^\n]+)/i', $raw, $stMatches);
    if (!empty($stMatches[1])) {
        foreach ($stMatches[1] as $s) {
            $clean = trim(explode(' ', trim($s))[0]);
            if ($clean) $statusRaw[] = $clean;
        }
    }

    return [
        'is_available'       => false,
        'domain'             => $domain,
        'registrar'          => $extract('/Registrar:\s*([^\n]+)/i'),
        'registrar_url'      => $extract('/Registrar URL:\s*([^\n]+)/i'),
        'registrant_name'    => $extract('/Registrant Name:\s*([^\n]+)/i'),
        'registrant_org'     => $extract('/Registrant Org(?:anization)?:\s*([^\n]+)/i'),
        'registrant_country' => $extract('/Registrant Country:\s*([^\n]+)/i'),
        'registrant_email'   => $extract('/Registrant Email:\s*([^\n]+)/i'),
        'created_date'       => normalizeDate($extract('/Creation Date:\s*([^\n]+)/i')),
        'updated_date'       => normalizeDate($extract('/Updated Date:\s*([^\n]+)/i')),
        'expiry_date'        => normalizeDate($extract('/Expir(?:y|ation) Date:\s*([^\n]+)/i') ?? $extract('/Registry Expiry Date:\s*([^\n]+)/i')),
        'status'             => array_unique($statusRaw),
        'nameservers'        => array_values($ns),
        'dnssec'             => $extract('/DNSSEC:\s*([^\n]+)/i'),
        'source'             => 'socket',
        'raw'                => substr($raw, 0, 6000),
    ];
}

function normalizeDate(?string $d): ?string {
    if (!$d) return null;
    $d = trim(preg_replace('/T\d{2}:\d{2}:\d{2}.*$/', '', $d));
    $ts = strtotime($d);
    return $ts ? date('Y-m-d', $ts) : null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WHOIS Lookup — CheckDomain</title>
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

/* ── Topbar ─── */
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

/* ── Content ─── */
.content{padding:28px 28px 60px}
.page-title{font-family:var(--serif);font-style:italic;font-size:26px;color:var(--text);margin-bottom:4px}
.page-sub{font-size:13px;color:var(--text2);margin-bottom:24px}
.page-sub em{color:var(--green);font-style:normal;font-family:var(--mono)}

/* ── Upgrade gate ─── */
.upgrade-gate{background:linear-gradient(135deg,rgba(29,158,117,.07),rgba(127,119,221,.05));border:1px solid rgba(29,158,117,.2);border-radius:14px;padding:28px 24px;text-align:center;margin-bottom:28px}
.gate-icon{font-size:28px;margin-bottom:12px}
.gate-title{font-size:16px;font-weight:800;color:var(--text);margin-bottom:6px}
.gate-sub{font-size:13px;color:var(--text2);max-width:400px;margin:0 auto 18px;line-height:1.6}
.gate-cta{display:inline-flex;align-items:center;gap:7px;background:var(--green);color:#fff;border:none;border-radius:9px;padding:10px 24px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;text-transform:uppercase;letter-spacing:.06em}
.gate-cta:hover{background:var(--green2)}

/* ── Search bar ─── */
.search-hero{background:var(--bg2);border:1px solid var(--border2);border-radius:16px;padding:22px 24px;margin-bottom:24px}
.search-hero-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--text3);margin-bottom:10px;display:flex;align-items:center;gap:6px}
.search-row{display:flex;gap:10px;align-items:center}
.search-input-wrap{flex:1;position:relative;min-width:0}
.search-input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:12px 16px 12px 42px;font-family:var(--mono);font-size:14px;color:var(--text);outline:none;transition:border-color .2s}
.search-input::placeholder{color:var(--text3)}
.search-input:focus{border-color:var(--green)}
.search-input:disabled{opacity:.45;cursor:not-allowed}
.search-input-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:13px;pointer-events:none}
.search-btn{display:flex;align-items:center;gap:8px;background:var(--green);color:#fff;border:none;border-radius:10px;padding:12px 22px;font-family:var(--display);font-size:13px;font-weight:700;cursor:pointer;transition:background .2s;white-space:nowrap;flex-shrink:0}
.search-btn:hover{background:var(--green2)}
.search-btn:disabled{opacity:.5;cursor:not-allowed}
.search-hint{display:flex;align-items:center;gap:14px;margin-top:10px;font-size:11px;color:var(--text3);flex-wrap:wrap}
.search-hint i{font-size:10px}
.cost-pill{background:var(--amber-bg);color:var(--amber);font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px}
.cache-pill{background:var(--green-bg);color:var(--green2);font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px}

/* ── Loading state ─── */
.loading-state{display:none;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:48px 24px;background:var(--bg2);border:1px solid var(--border);border-radius:14px;margin-bottom:24px}
.loading-state.visible{display:flex}
.loading-spinner{width:40px;height:40px;border:3px solid var(--border);border-top-color:var(--green);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading-domain{font-family:var(--mono);font-size:13px;color:var(--text2)}
.loading-steps{display:flex;flex-direction:column;gap:6px;text-align:center}
.loading-step{font-size:11px;color:var(--text3);transition:color .3s}
.loading-step.active{color:var(--green2)}
.loading-step.done{color:var(--text3)}
.loading-step.done::before{content:'✓ '}

/* ── Result panel ─── */
.result-panel{display:none;margin-bottom:24px}
.result-panel.visible{display:block}

/* Available banner */
.available-banner{background:linear-gradient(135deg,rgba(29,158,117,.1),rgba(20,196,138,.05));border:1px solid rgba(29,158,117,.25);border-radius:14px;padding:22px 24px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.avail-icon{width:52px;height:52px;border-radius:13px;background:var(--green-bg);border:1px solid rgba(29,158,117,.2);display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--green2);flex-shrink:0}
.avail-info{flex:1}
.avail-domain{font-family:var(--mono);font-size:20px;font-weight:700;color:var(--text);margin-bottom:4px}
.avail-label{font-size:13px;color:var(--green2);font-weight:600;margin-bottom:6px}
.avail-sub{font-size:12px;color:var(--text2)}
.avail-actions{display:flex;gap:8px;flex-wrap:wrap}
.avail-cta{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .15s;border:none;text-transform:uppercase;letter-spacing:.05em}
.cta-primary{background:var(--green);color:#fff}
.cta-primary:hover{background:var(--green2)}
.cta-secondary{background:var(--bg3);color:var(--text2);border:1px solid var(--border2)!important}
.cta-secondary:hover{background:var(--bg4);color:var(--text)}

/* WHOIS result card */
.whois-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.whois-card-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.whois-domain-title{font-family:var(--mono);font-size:16px;font-weight:700;color:var(--text)}
.whois-domain-title span{color:var(--text3);font-weight:400}
.whois-meta-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.whois-badge{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;padding:3px 8px;border-radius:4px}
.wb-taken{background:var(--amber-bg);color:var(--amber)}
.wb-available{background:var(--green-bg);color:var(--green2)}
.wb-cache{background:var(--bg4);color:var(--text3)}
.wb-api{background:var(--blue-bg);color:var(--blue)}
.wb-socket{background:var(--purple-bg);color:var(--purple)}
.whois-header-actions{display:flex;gap:7px}
.icon-btn{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;background:none;border:1px solid var(--border);color:var(--text3);font-size:12px;cursor:pointer;transition:all .13s;text-decoration:none}
.icon-btn:hover{background:var(--bg3);border-color:var(--border2);color:var(--text)}

/* Sections */
.whois-sections{display:grid;grid-template-columns:1fr 1fr;gap:0;border-top:1px solid var(--border)}
.whois-section{padding:20px 22px;border-right:1px solid var(--border);border-bottom:1px solid var(--border)}
.whois-section:nth-child(even){border-right:none}
.whois-section:nth-last-child(-n+2){border-bottom:none}
.whois-section-title{font-size:10px;text-transform:uppercase;letter-spacing:.14em;color:var(--text3);font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.whois-section-title i{font-size:11px}
.whois-fields{display:flex;flex-direction:column;gap:9px}
.wf-row{display:flex;flex-direction:column;gap:2px}
.wf-label{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--text3)}
.wf-value{font-size:12px;font-family:var(--mono);color:var(--text);word-break:break-all}
.wf-value.na{color:var(--text3);font-style:italic;font-family:var(--display)}
.wf-value.expiring{color:var(--coral)}
.wf-value.expiring-soon{color:var(--amber)}
.wf-value.available-val{color:var(--green2)}

/* Status codes */
.status-tags{display:flex;flex-wrap:wrap;gap:5px;margin-top:4px}
.status-tag{font-size:9px;font-family:var(--mono);padding:2px 7px;border-radius:3px;background:var(--bg4);color:var(--text3)}
.status-tag.active-status{background:var(--green-bg);color:var(--green2)}

/* Nameservers */
.ns-list{display:flex;flex-direction:column;gap:5px;margin-top:4px}
.ns-item{font-size:11px;font-family:var(--mono);color:var(--text2);display:flex;align-items:center;gap:6px}
.ns-item::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--green);flex-shrink:0}

/* Expiry bar */
.expiry-bar-section{padding:16px 22px;border-top:1px solid var(--border);background:var(--bg3)}
.expiry-bar-label{display:flex;justify-content:space-between;font-size:11px;color:var(--text3);font-family:var(--mono);margin-bottom:6px}
.expiry-bar-wrap{height:5px;background:var(--border);border-radius:3px;overflow:hidden}
.expiry-bar-fill{height:100%;border-radius:3px;transition:width .8s ease}

/* Raw WHOIS */
.raw-section{padding:16px 22px;border-top:1px solid var(--border)}
.raw-toggle{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text2);cursor:pointer;background:none;border:none;font-family:var(--display);padding:0;transition:color .15s}
.raw-toggle:hover{color:var(--text)}
.raw-toggle i{font-size:11px;transition:transform .2s}
.raw-toggle.open i{transform:rotate(180deg)}
.raw-content{display:none;margin-top:12px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:14px;font-family:var(--mono);font-size:11px;color:var(--text2);line-height:1.7;white-space:pre-wrap;word-break:break-word;max-height:320px;overflow-y:auto}
.raw-content.open{display:block}

/* ── Actions bar ─── */
.result-actions{display:flex;align-items:center;gap:8px;padding:14px 22px;border-top:1px solid var(--border);flex-wrap:wrap}
.result-action-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:8px;font-family:var(--display);font-size:11px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .15s;border:none;text-transform:uppercase;letter-spacing:.05em}
.rab-green{background:var(--green-bg);color:var(--green2)}
.rab-green:hover{background:rgba(29,158,117,.2)}
.rab-amber{background:var(--amber-bg);color:var(--amber)}
.rab-amber:hover{background:rgba(239,159,39,.2)}
.rab-coral{background:var(--coral-bg);color:var(--coral)}
.rab-coral:hover{background:rgba(232,89,60,.2)}
.rab-default{background:var(--bg3);color:var(--text2);border:1px solid var(--border)}
.rab-default:hover{background:var(--bg4);color:var(--text)}

/* ── History table ─── */
.history-wrap{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-top:28px}
.history-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.history-title{font-size:12px;font-weight:700;color:var(--text)}
.ht-head{display:grid;grid-template-columns:1fr 160px 120px 90px 80px;padding:9px 20px;background:var(--bg3);border-bottom:1px solid var(--border)}
.ht-th{font-size:10px;text-transform:uppercase;letter-spacing:.11em;color:var(--text3);font-weight:600}
.ht-th.right{text-align:right}
.ht-row{display:grid;grid-template-columns:1fr 160px 120px 90px 80px;padding:12px 20px;border-bottom:1px solid var(--border);align-items:center;transition:background .12s;cursor:pointer}
.ht-row:last-child{border-bottom:none}
.ht-row:hover{background:var(--bg3)}
.ht-domain{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--text)}
.ht-domain-tld{color:var(--text3);font-weight:400}
.ht-registrar{font-size:11px;color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ht-date{font-family:var(--mono);font-size:11px;color:var(--text2)}
.ht-status-pill{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:2px 7px;border-radius:4px;white-space:nowrap}
.hsp-taken{background:var(--amber-bg);color:var(--amber)}
.hsp-available{background:var(--green-bg);color:var(--green2)}
.ht-credits{font-family:var(--mono);font-size:11px;color:var(--text3);text-align:right}
.hist-empty{text-align:center;padding:36px 20px;color:var(--text3);font-size:12px}

/* ── Toast ─── */
.toast{position:fixed;bottom:28px;right:28px;z-index:999;background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:12px 18px;font-size:13px;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.4);transform:translateY(20px);opacity:0;transition:all .3s ease;max-width:340px;display:flex;align-items:center;gap:9px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{border-color:rgba(29,158,117,.3)}
.toast.error{border-color:rgba(232,89,60,.3)}

/* ── Responsive ─── */
@media(max-width:900px){
  .whois-sections{grid-template-columns:1fr}
  .whois-section{border-right:none}
  .whois-section:nth-child(n){border-bottom:1px solid var(--border)}
  .whois-section:last-child{border-bottom:none}
  .ht-head,.ht-row{grid-template-columns:1fr 100px 80px}
  .ht-th:nth-child(2),.ht-row>*:nth-child(2),.ht-th:nth-child(5),.ht-row>*:nth-child(5){display:none}
}
@media(max-width:768px){
  .main{margin-left:0}.mobile-menu-btn{display:flex}
  .content{padding:20px 16px 50px}
  .search-row{flex-direction:column;align-items:stretch}
  .credits-pill{display:none}
  .ht-head,.ht-row{grid-template-columns:1fr 80px}
  .ht-th:nth-child(3),.ht-row>*:nth-child(3){display:none}
}
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
        <span style="color:var(--text);">WHOIS Lookup</span>
      </div>
    </div>
    <div class="topbar-right">
      <div class="credits-pill" id="creditsPill">
        <i class="fas fa-bolt" style="color:var(--amber);font-size:11px;"></i>
        <b id="creditsDisplay"><?= $credits ?></b> credits
      </div>
      <a href="<?= htmlspecialchars($assetUrl('billing.php?topup=1')) ?>" class="topbar-btn" title="Top up credits">
        <i class="fas fa-plus"></i>
      </a>
    </div>
  </div>

  <div class="content">

    <div class="page-title">WHOIS Lookup.</div>
    <div class="page-sub">
      Deep registrar data, expiry dates, nameservers, and ownership records.
      <?php if ($canWhois): ?>
        Each lookup costs <em><?= $creditCost ?> credits</em>. Results cached for 24 hours — repeat lookups are free.
      <?php endif; ?>
    </div>

    <!-- Upgrade gate -->
    <?php if (!$canWhois): ?>
    <div class="upgrade-gate">
      <div class="gate-icon">🔍</div>
      <div class="gate-title">WHOIS requires a Pro plan</div>
      <div class="gate-sub">Upgrade to unlock deep WHOIS data — registrar, expiry date, nameservers, registrant details, and EPP status codes for any domain.</div>
      <a href="<?= htmlspecialchars($assetUrl('billing.php?plan=pro')) ?>" class="gate-cta">
        <i class="fas fa-bolt" style="font-size:10px;"></i> Upgrade to Pro — ₦9,000/mo
      </a>
    </div>
    <?php endif; ?>

    <!-- Search hero -->
    <div class="search-hero">
      <div class="search-hero-label">
        <i class="fas fa-search" style="color:var(--green2);"></i>
        Domain lookup
      </div>
      <div class="search-row">
        <div class="search-input-wrap">
          <i class="fas fa-globe search-input-icon"></i>
          <input class="search-input" type="text" id="whoisInput"
                 placeholder="<?= $canWhois ? 'Enter any domain — e.g. techlaunch.com, mybrand.ng' : 'Requires Pro plan' ?>"
                 value="<?= $prefill ?>"
                 <?= !$canWhois ? 'disabled' : '' ?>
                 autocomplete="off" maxlength="253"
                 onkeydown="if(event.key==='Enter')runLookup()">
        </div>
        <button class="search-btn" id="searchBtn" onclick="runLookup()" <?= !$canWhois ? 'disabled' : '' ?>>
          <i class="fas fa-search" style="font-size:11px;"></i> Lookup
        </button>
      </div>
      <div class="search-hint">
        <span><span class="cost-pill"><?= $creditCost ?> credits</span> per lookup</span>
        <span><span class="cache-pill">FREE</span> if cached within 24 hours</span>
        <span><i class="fas fa-info-circle"></i> Works for .com .net .org .io .ng .co.uk .de .fr and 20+ more TLDs</span>
        <?php if ($credits < $creditCost && $canWhois): ?>
        <span style="color:var(--coral);">
          <i class="fas fa-exclamation-triangle"></i>
          Low credits — <a href="<?= htmlspecialchars($assetUrl('billing.php?topup=1')) ?>" style="color:var(--amber);text-decoration:none;">top up</a> to run lookups.
        </span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Loading state -->
    <div class="loading-state" id="loadingState">
      <div class="loading-spinner"></div>
      <div class="loading-domain" id="loadingDomain"></div>
      <div class="loading-steps">
        <div class="loading-step" id="step1">Connecting to WHOIS server…</div>
        <div class="loading-step" id="step2">Parsing registration data…</div>
        <div class="loading-step" id="step3">Building result…</div>
      </div>
    </div>

    <!-- Result panel -->
    <div class="result-panel" id="resultPanel"></div>

    <!-- Lookup history -->
    <?php if (!empty($history)): ?>
    <div class="history-wrap">
      <div class="history-header">
        <span class="history-title"><i class="fas fa-history" style="color:var(--green2);margin-right:6px;font-size:12px;"></i> Lookup history</span>
        <span style="font-size:11px;color:var(--text3);font-family:var(--mono);">Last <?= count($history) ?> lookups</span>
      </div>
      <div class="ht-head">
        <div class="ht-th">Domain</div>
        <div class="ht-th">Registrar</div>
        <div class="ht-th">Expires</div>
        <div class="ht-th">Status</div>
        <div class="ht-th right">Credits</div>
      </div>
      <?php foreach ($history as $h):
        $domParts = explode('.', $h['domain_name']);
        $domSld   = $domParts[0];
        $domTld   = '.' . implode('.', array_slice($domParts, 1));
        $isAvail  = (bool)$h['is_available'];
        $expiry   = $h['expiry_date'];
        $expiryTs = $expiry ? strtotime($expiry) : null;
        $daysLeft = $expiryTs ? (int)ceil(($expiryTs - time()) / 86400) : null;
      ?>
      <div class="ht-row" onclick="quickLoad('<?= htmlspecialchars($h['domain_name'], ENT_QUOTES) ?>')">
        <div>
          <div class="ht-domain"><?= htmlspecialchars($domSld) ?><span class="ht-domain-tld"><?= htmlspecialchars($domTld) ?></span></div>
          <div style="font-size:10px;color:var(--text3);margin-top:2px;"><?= date('M j, Y · H:i', strtotime($h['looked_up_at'])) ?></div>
        </div>
        <div class="ht-registrar"><?= htmlspecialchars($h['registrar'] ?? '—') ?></div>
        <div class="ht-date" style="<?= $daysLeft !== null && $daysLeft < 30 ? 'color:var(--coral)' : '' ?>">
          <?php if ($expiry): ?>
            <?= date('M j, Y', $expiryTs) ?>
            <?php if ($daysLeft !== null && $daysLeft >= 0 && $daysLeft < 60): ?>
            <span style="font-size:9px;color:<?= $daysLeft < 30 ? 'var(--coral)' : 'var(--amber)' ?>">· <?= $daysLeft ?>d</span>
            <?php endif; ?>
          <?php else: ?><span style="color:var(--text3);">—</span><?php endif; ?>
        </div>
        <div>
          <span class="ht-status-pill <?= $isAvail ? 'hsp-available' : 'hsp-taken' ?>">
            <?= $isAvail ? 'Available' : 'Registered' ?>
          </span>
        </div>
        <div class="ht-credits">−<?= (int)$h['credits_spent'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php elseif ($canWhois): ?>
    <div class="history-wrap">
      <div class="history-header"><span class="history-title">Lookup history</span></div>
      <div class="hist-empty">
        <i class="fas fa-history" style="font-size:20px;margin-bottom:10px;display:block;opacity:.3;"></i>
        Your WHOIS lookups will appear here.
      </div>
    </div>
    <?php endif; ?>

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
const CAN_WHOIS = <?= $canWhois ? 'true' : 'false' ?>;

// ── Run lookup ─────────────────────────────────────────────
async function runLookup() {
  if (!CAN_WHOIS) return;
  const input = document.getElementById('whoisInput');
  const btn   = document.getElementById('searchBtn');
  let   val   = input.value.trim().toLowerCase().replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
  if (!val) { input.focus(); return; }
  if (!val.includes('.')) val += '.com';

  showLoading(val);

  btn.disabled  = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:11px;"></i> Looking up…';

  try {
    const res  = await fetch(API_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body:    JSON.stringify({ action: 'lookup', domain: val }),
    });
    const data = await res.json();

    hideLoading();

    if (data.success) {
      renderResult(data.data, val);
      if (!data.data.from_cache) {
        const credEl = document.getElementById('creditsDisplay');
        if (credEl) credEl.textContent = data.credits_remaining;
      }
      if (data.data.from_cache) showToast('Loaded from cache — no credits deducted.', 'success');
    } else if (data.requiresUpgrade) {
      showToast('WHOIS lookups require a Pro plan.', 'error');
    } else if (data.insufficientCredits) {
      showToast(data.message, 'error');
      setTimeout(() => window.location.href = APP_BASE + '/billing.php?topup=1', 2000);
    } else {
      showToast(data.message || 'Lookup failed. Try again.', 'error');
    }
  } catch {
    hideLoading();
    showToast('Network error. Please try again.', 'error');
  } finally {
    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-search" style="font-size:11px;"></i> Lookup';
  }
}

// ── Quick load from history ────────────────────────────────
function quickLoad(domain) {
  const input = document.getElementById('whoisInput');
  input.value = domain;
  input.focus();
  runLookup();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Loading animation ──────────────────────────────────────
let stepTimer;
function showLoading(domain) {
  document.getElementById('loadingDomain').textContent = 'Looking up ' + domain + '…';
  document.getElementById('loadingState').classList.add('visible');
  document.getElementById('resultPanel').classList.remove('visible');
  document.getElementById('resultPanel').innerHTML = '';

  const steps = ['step1','step2','step3'];
  let i = 0;
  steps.forEach(s => { const el = document.getElementById(s); el.classList.remove('active','done'); });

  function tick() {
    if (i > 0) document.getElementById(steps[i-1]).classList.replace('active','done');
    if (i < steps.length) {
      document.getElementById(steps[i]).classList.add('active');
      i++;
      stepTimer = setTimeout(tick, 800);
    }
  }
  tick();
}
function hideLoading() {
  clearTimeout(stepTimer);
  document.getElementById('loadingState').classList.remove('visible');
}

// ── Render WHOIS result ────────────────────────────────────
function renderResult(d, domain) {
  const panel = document.getElementById('resultPanel');
  panel.classList.add('visible');

  if (d.is_available) {
    panel.innerHTML = renderAvailable(d, domain);
    return;
  }
  panel.innerHTML = renderWhoisCard(d, domain);
}

function renderAvailable(d, domain) {
  return `
    <div class="available-banner">
      <div class="avail-icon"><i class="fas fa-check-circle"></i></div>
      <div class="avail-info">
        <div class="avail-domain">${escHtml(domain)}</div>
        <div class="avail-label">✓ Domain is available!</div>
        <div class="avail-sub">This domain is not currently registered. You can register it right now.</div>
      </div>
      <div class="avail-actions">
        <a href="${APP_BASE}/index.php?q=${encodeURIComponent(domain)}" class="avail-cta cta-primary">
          <i class="fas fa-shopping-cart" style="font-size:10px;"></i> Register now
        </a>
        <button class="avail-cta cta-secondary" onclick="watchDomain('${escHtml(domain)}')">
          <i class="fas fa-bookmark" style="font-size:10px;"></i> Watch it
        </button>
      </div>
    </div>`;
}

function renderWhoisCard(d, domain) {
  const parts  = domain.split('.');
  const sld    = parts[0];
  const tld    = '.' + parts.slice(1).join('.');
  const src    = d.source === 'api' ? 'API' : d.source === 'socket' ? 'Socket' : 'Cache';
  const srcCls = d.source === 'api' ? 'wb-api' : d.source === 'socket' ? 'wb-socket' : 'wb-cache';

  // Expiry calculations
  let expiryHtml = '<span class="wf-value na">Not available</span>';
  let expiryBarHtml = '';
  if (d.expiry_date) {
    const exTs    = new Date(d.expiry_date).getTime() / 1000;
    const now     = Date.now() / 1000;
    const crTs    = d.created_date ? new Date(d.created_date).getTime() / 1000 : now - 31536000;
    const daysLeft = Math.ceil((exTs - now) / 86400);
    const totalDays = (exTs - crTs) / 86400;
    const pct     = Math.max(0, Math.min(100, Math.round(((now - crTs) / (exTs - crTs)) * 100)));
    const barColor = daysLeft < 30 ? 'var(--coral)' : daysLeft < 90 ? 'var(--amber)' : 'var(--green)';
    const valCls   = daysLeft < 30 ? 'expiring' : daysLeft < 90 ? 'expiring-soon' : '';

    expiryHtml = `<span class="wf-value ${valCls}">${d.expiry_date}${daysLeft >= 0 ? ` <span style="font-size:10px;color:${barColor}">(${daysLeft}d left)</span>` : ''}</span>`;

    expiryBarHtml = `
      <div class="expiry-bar-section">
        <div class="expiry-bar-label">
          <span>${d.created_date || 'Created'}</span>
          <span>${daysLeft >= 0 ? daysLeft + ' days remaining' : 'Expired'}</span>
          <span>${d.expiry_date}</span>
        </div>
        <div class="expiry-bar-wrap">
          <div class="expiry-bar-fill" style="width:${pct}%;background:${barColor};"></div>
        </div>
      </div>`;
  }

  // Status tags
  const statusList = Array.isArray(d.status) ? d.status : (d.status ? d.status.split(' ') : []);
  const statusTagsHtml = statusList.length > 0
    ? `<div class="status-tags">${statusList.map(s => `<span class="status-tag ${s.startsWith('client') ? 'active-status' : ''}">${escHtml(s)}</span>`).join('')}</div>`
    : '<span class="wf-value na">Not available</span>';

  // Nameservers
  const ns = Array.isArray(d.nameservers) ? d.nameservers : [];
  const nsHtml = ns.length > 0
    ? `<div class="ns-list">${ns.map(n => `<div class="ns-item">${escHtml(n)}</div>`).join('')}</div>`
    : '<span class="wf-value na">Not available</span>';

  return `
    <div class="whois-card">
      <div class="whois-card-header">
        <div>
          <div class="whois-domain-title">${escHtml(sld)}<span>${escHtml(tld)}</span></div>
          <div class="whois-meta-row" style="margin-top:5px;">
            <span class="whois-badge wb-taken">Registered</span>
            <span class="whois-badge ${srcCls}">${src}</span>
            ${d.from_cache ? `<span class="whois-badge wb-cache">Cached · ${d.cached_age || ''}</span>` : ''}
          </div>
        </div>
        <div class="whois-header-actions">
          <button class="icon-btn" onclick="copyWhois()" title="Copy all data"><i class="fas fa-copy"></i></button>
          <a href="${APP_BASE}/index.php?q=${encodeURIComponent(domain)}" class="icon-btn" title="Check availability"><i class="fas fa-search"></i></a>
        </div>
      </div>

      <div class="whois-sections">
        <div class="whois-section">
          <div class="whois-section-title"><i class="fas fa-building" style="color:var(--blue);"></i> Registrar</div>
          <div class="whois-fields">
            <div class="wf-row">
              <div class="wf-label">Registrar name</div>
              ${d.registrar ? `<div class="wf-value">${escHtml(d.registrar)}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
            ${d.registrar_url ? `<div class="wf-row"><div class="wf-label">Registrar URL</div><div class="wf-value"><a href="${escHtml(d.registrar_url)}" target="_blank" rel="noopener" style="color:var(--green2);text-decoration:none;">${escHtml(d.registrar_url.replace(/^https?:\/\//,'').substring(0,40))}</a></div></div>` : ''}
          </div>
        </div>

        <div class="whois-section">
          <div class="whois-section-title"><i class="fas fa-calendar" style="color:var(--amber);"></i> Dates</div>
          <div class="whois-fields">
            <div class="wf-row">
              <div class="wf-label">Created</div>
              ${d.created_date ? `<div class="wf-value">${d.created_date}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
            <div class="wf-row">
              <div class="wf-label">Last updated</div>
              ${d.updated_date ? `<div class="wf-value">${d.updated_date}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
            <div class="wf-row">
              <div class="wf-label">Expiry date</div>
              ${expiryHtml}
            </div>
          </div>
        </div>

        <div class="whois-section">
          <div class="whois-section-title"><i class="fas fa-user" style="color:var(--purple);"></i> Registrant</div>
          <div class="whois-fields">
            <div class="wf-row">
              <div class="wf-label">Name</div>
              ${d.registrant_name ? `<div class="wf-value">${escHtml(d.registrant_name)}</div>` : '<div class="wf-value na">Redacted for privacy</div>'}
            </div>
            <div class="wf-row">
              <div class="wf-label">Organisation</div>
              ${d.registrant_org ? `<div class="wf-value">${escHtml(d.registrant_org)}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
            <div class="wf-row">
              <div class="wf-label">Country</div>
              ${d.registrant_country ? `<div class="wf-value">${escHtml(d.registrant_country)}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
          </div>
        </div>

        <div class="whois-section">
          <div class="whois-section-title"><i class="fas fa-shield-alt" style="color:var(--green2);"></i> Status &amp; Security</div>
          <div class="whois-fields">
            <div class="wf-row">
              <div class="wf-label">Domain status</div>
              ${statusTagsHtml}
            </div>
            <div class="wf-row" style="margin-top:6px;">
              <div class="wf-label">DNSSEC</div>
              ${d.dnssec ? `<div class="wf-value">${escHtml(d.dnssec)}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
          </div>
        </div>

        <div class="whois-section" style="grid-column:1/-1;">
          <div class="whois-section-title"><i class="fas fa-server" style="color:var(--coral);"></i> Nameservers</div>
          ${nsHtml}
        </div>
      </div>

      ${expiryBarHtml}

      <div class="result-actions">
        <button class="result-action-btn rab-amber" onclick="watchDomain('${escHtml(domain)}')">
          <i class="fas fa-bookmark" style="font-size:10px;"></i> Add to watchlist
        </button>
        <button class="result-action-btn rab-coral" onclick="backorderDomain('${escHtml(domain)}')">
          <i class="fas fa-clock" style="font-size:10px;"></i> Place backorder
        </button>
        <button class="result-action-btn rab-default" onclick="copyWhois()">
          <i class="fas fa-copy" style="font-size:10px;"></i> Copy data
        </button>
        <a href="${APP_BASE}/index.php?q=${encodeURIComponent(domain)}" class="result-action-btn rab-green">
          <i class="fas fa-search" style="font-size:10px;"></i> Full domain check
        </a>
      </div>

      <div class="raw-section">
        <button class="raw-toggle" id="rawToggle" onclick="toggleRaw()">
          <i class="fas fa-chevron-down"></i> Raw WHOIS response
        </button>
        <pre class="raw-content" id="rawContent">${escHtml(d.raw || 'No raw data available.')}</pre>
      </div>

    </div>`;
}

// ── Toggle raw WHOIS ───────────────────────────────────────
function toggleRaw() {
  const toggle  = document.getElementById('rawToggle');
  const content = document.getElementById('rawContent');
  if (!toggle || !content) return;
  const open = content.classList.toggle('open');
  toggle.classList.toggle('open', open);
}

// ── Watch domain ───────────────────────────────────────────
async function watchDomain(domain) {
  try {
    const res  = await fetch(APP_BASE + '/api/watchlist-domain.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ domain }),
    });
    const data = await res.json();
    showToast(data.success ? `${domain} added to watchlist.` : (data.message || 'Failed to add.'),
              data.success ? 'success' : 'error');
  } catch {
    showToast('Network error.', 'error');
  }
}

// ── Backorder domain ───────────────────────────────────────
function backorderDomain(domain) {
  window.location.href = APP_BASE + '/backorders.php?domain=' + encodeURIComponent(domain);
}

// ── Copy WHOIS data ────────────────────────────────────────
function copyWhois() {
  const raw = document.getElementById('rawContent');
  const text = raw ? raw.textContent : document.getElementById('resultPanel').innerText;
  navigator.clipboard.writeText(text)
    .then(() => showToast('WHOIS data copied to clipboard.'))
    .catch(() => showToast('Could not copy.', 'error'));
}

// ── Helpers ────────────────────────────────────────────────
function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showToast(msg, type = 'success') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  icon.className   = `fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}`;
  icon.style.color = type === 'error' ? 'var(--coral)' : 'var(--green2)';
  t.className = `toast show ${type}`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 3800);
}

function openSidebar()  { document.getElementById('cdSidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('show'); }
function closeSidebar() { document.getElementById('cdSidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('show'); }

// ── Auto-run if prefill present ────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
  const input = document.getElementById('whoisInput');
  if (input?.value.trim() && CAN_WHOIS) {
    setTimeout(runLookup, 300);
  }
});
</script>

</body>
</html>