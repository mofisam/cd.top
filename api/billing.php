<?php
/**
 * api/billing.php
 * ────────────────────────────────────────────────────────────────
 * Handles all billing-related AJAX calls from billing.php:
 *   POST { action: 'verify'             }  — verify Paystack payment
 *   POST { action: 'validate_promo'     }  — check a promo code
 *   POST { action: 'cancel_subscription'}  — cancel active plan
 *
 * Also accepts Paystack webhook events (no session required).
 * ────────────────────────────────────────────────────────────────
 */

ob_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../lib/Auth.php';

// ── Paystack secret key ───────────────────────────────────────
$paystackSecret = $_ENV['PAYSTACK_SECRET_KEY'];

if (!$paystackSecret) {
    throw new RuntimeException('PAYSTACK_SECRET_KEY is not configured.');
}

// ── Webhook entry point (no auth needed) ─────────────────────
// Paystack sends POST with x-paystack-signature header
$isWebhook = isset($_SERVER['HTTP_X_PAYSTACK_SIGNATURE']);
if ($isWebhook) {
    handleWebhook($paystackSecret);
    exit();
}

// ── Authenticated AJAX ────────────────────────────────────────
session_start();
$auth = new Auth();

if (!isset($_COOKIE['session_token'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in.']);
    exit();
}
$session = $auth->verifySession($_COOKIE['session_token']);
if (!$session) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit();
}
$userId = $session['user_id'];

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

try {
    $conn = getDBConnection();

    switch ($action) {
        case 'verify':            handleVerify($conn, $userId, $input, $paystackSecret); break;
        case 'validate_promo':    handleValidatePromo($conn, $userId, $input);           break;
        case 'cancel_subscription': handleCancel($conn, $userId, $paystackSecret);      break;
        default:
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
    $conn->close();
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ═════════════════════════════════════════════════════════════
// ACTION: verify payment
// ═════════════════════════════════════════════════════════════
function handleVerify($conn, $userId, $input, $secret) {
    $reference = trim($input['reference'] ?? '');
    $type      = $input['type'] ?? 'subscription'; // subscription | credit_topup
    $meta      = $input['meta'] ?? null;           // planSlug or packageId
    $promoCode = trim($input['promo'] ?? '');

    if (!$reference) {
        ob_end_clean(); echo json_encode(['success'=>false,'message'=>'No payment reference.']); return;
    }

    // ── 1. Call Paystack verify endpoint ─────────────────────
    $ch = curl_init("https://api.paystack.co/transaction/verify/" . rawurlencode($reference));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$secret}", "Cache-Control: no-cache"],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        ob_end_clean(); echo json_encode(['success'=>false,'message'=>'Could not reach Paystack.']); return;
    }

    $ps = json_decode($body, true);
    if (!$ps['status'] || $ps['data']['status'] !== 'success') {
        ob_end_clean();
        echo json_encode(['success'=>false,'message'=>'Payment was not successful. Status: ' . ($ps['data']['status'] ?? 'unknown')]);
        return;
    }

    $txn       = $ps['data'];
    $amtKobo   = (int)$txn['amount'];
    $currency  = $txn['currency'] ?? 'NGN';
    $channel   = $txn['channel'] ?? null;
    $fees      = (int)($txn['fees'] ?? 0);
    $gwResp    = $txn['gateway_response'] ?? null;
    $auth      = $txn['authorization'] ?? [];
    $paidAt    = $txn['paid_at'] ?? null;
    $txnId     = (int)$txn['id'];

    // ── 2. Idempotency — skip if already processed ────────────
    $dup = $conn->prepare("SELECT id FROM payments WHERE paystack_reference = ? LIMIT 1");
    $dup->bind_param("s", $reference);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        $dup->close();
        ob_end_clean();
        echo json_encode(['success'=>true,'message'=>'Payment already processed.']);
        return;
    }
    $dup->close();

    // ── 3. Resolve promo discount ─────────────────────────────
    $discountKobo = 0;
    $promoId      = null;
    if ($promoCode) {
        $promoRow = getActivePromo($conn, $promoCode, $userId);
        if ($promoRow) {
            $promoId      = $promoRow['id'];
            $discountKobo = computeDiscount($promoRow, $amtKobo);
        }
    }

    // ── 4. Save payment row ────────────────────────────────────
    $description = $type === 'subscription' ? "Subscription · {$meta}" : "Credit top-up · package {$meta}";
    $charged     = $amtKobo - $discountKobo;

    $payStmt = $conn->prepare("
        INSERT INTO payments
          (user_id, type, amount_kobo, discount_kobo, amount_charged_kobo, currency,
           status, paystack_reference, paystack_transaction_id, gateway_response,
           channel, fees_kobo, ip_address, promo_code_id, description, paid_at, created_at)
        VALUES (?,?,?,?,?,?,'success',?,?,?,?,?,?,?,?,?,NOW())
    ");
    $ipAddr = getClientIP();
    $payStmt->bind_param(
        "issiiisssssisss",
        $userId, $type, $amtKobo, $discountKobo, $charged, $currency,
        $reference, $txnId, $gwResp,
        $channel, $fees, $ipAddr, $promoId, $description, $paidAt
    );
    $payStmt->execute();
    $paymentId = $conn->insert_id;
    $payStmt->close();

    // ── 5. Upsert Paystack authorization ──────────────────────
    $authId = null;
    if (!empty($auth['authorization_code']) && ($auth['reusable'] ?? false)) {
        $sig = $auth['signature'] ?? $auth['authorization_code'];
        $authStmt = $conn->prepare("
            INSERT INTO paystack_authorizations
              (user_id, authorization_code, card_type, last4, exp_month, exp_year, bin, bank, channel, signature, reusable, country_code, is_default)
            VALUES (?,?,?,?,?,?,?,?,?,?,1,?,1)
            ON DUPLICATE KEY UPDATE authorization_code=VALUES(authorization_code), is_active=1, updated_at=NOW()
        ");
        $authStmt->bind_param("ssssssssss s",
            $userId,
            $auth['authorization_code'],
            $auth['card_type']    ?? null,
            $auth['last4']        ?? null,
            $auth['exp_month']    ?? null,
            $auth['exp_year']     ?? null,
            $auth['bin']          ?? null,
            $auth['bank']         ?? null,
            $auth['channel']      ?? $channel,
            $sig,
            $auth['country_code'] ?? null
        );
        $authStmt->execute();
        $authId = $conn->insert_id ?: null;
        $authStmt->close();
    }

    // ── 6. Fulfil based on type ───────────────────────────────
    if ($type === 'subscription') {
        fulfillSubscription($conn, $userId, $paymentId, $authId, $meta, $input['meta2'] ?? 'monthly', $promoId, $txn);
    } elseif ($type === 'credit_topup') {
        fulfillCreditTopup($conn, $userId, $paymentId, (int)$meta);
    }

    // ── 7. Record promo use ───────────────────────────────────
    if ($promoId && $discountKobo > 0) {
        recordPromoUse($conn, $promoId, $userId, $paymentId, $discountKobo);
    }

    // ── 8. Build invoice ──────────────────────────────────────
    buildInvoice($conn, $userId, $paymentId, $amtKobo, $discountKobo, $currency, $description);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => $type === 'subscription'
            ? 'Subscription activated! Your plan has been upgraded.'
            : 'Credits added to your account!',
    ]);
}

// ─────────────────────────────────────────────────────────────
function fulfillSubscription($conn, $userId, $paymentId, $authId, $planSlug, $billingCycle, $promoId, $txn) {
    // Get plan
    $planStmt = $conn->prepare("SELECT id, credits_monthly, credits_signup FROM plans WHERE slug = ?");
    $planStmt->bind_param("s", $planSlug);
    $planStmt->execute();
    $plan = $planStmt->get_result()->fetch_assoc();
    $planStmt->close();
    if (!$plan) return;

    // Deactivate old subscriptions
    $conn->prepare("UPDATE subscriptions SET status='canceled', canceled_at=NOW() WHERE user_id=? AND status NOT IN ('canceled')")
         ->execute() && null;

    // Paystack subscription code from metadata (populated by webhook later; blank for now)
    $psCodes  = $txn['metadata']['custom_fields'] ?? [];
    $subCode  = $txn['subscription_code'] ?? null;

    // Period dates
    $now   = date('Y-m-d H:i:s');
    $end   = $billingCycle === 'yearly'
        ? date('Y-m-d H:i:s', strtotime('+1 year'))
        : date('Y-m-d H:i:s', strtotime('+1 month'));

    $subStmt = $conn->prepare("
        INSERT INTO subscriptions
          (user_id, plan_id, status, billing_cycle, paystack_subscription_code,
           authorization_id, current_period_start, current_period_end, next_billing_at, promo_code_id)
        VALUES (?,?,'active',?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          plan_id=VALUES(plan_id), status='active', billing_cycle=VALUES(billing_cycle),
          paystack_subscription_code=VALUES(paystack_subscription_code),
          current_period_start=VALUES(current_period_start),
          current_period_end=VALUES(current_period_end),
          next_billing_at=VALUES(next_billing_at), updated_at=NOW()
    ");
    $subStmt->bind_param("iisssisss",
        $userId, $plan['id'], $billingCycle, $subCode,
        $authId, $now, $end, $end, $promoId
    );
    $subStmt->execute();
    $subStmt->close();

    // Update users.plan and grant credits
    $creditsToAdd = $plan['credits_monthly'];

    // Check if first-ever subscription (grant signup bonus)
    $prevSubs = $conn->prepare("SELECT COUNT(*) as c FROM subscriptions WHERE user_id=? AND plan_id=?");
    $prevSubs->bind_param("ii", $userId, $plan['id']);
    $prevSubs->execute();
    $prevCount = $prevSubs->get_result()->fetch_assoc()['c'];
    $prevSubs->close();
    if ($prevCount <= 1 && $plan['credits_signup'] > 0) {
        $creditsToAdd += $plan['credits_signup'];
    }

    $updStmt = $conn->prepare("UPDATE users SET plan=?, credits=credits+? WHERE id=?");
    $updStmt->bind_param("sii", $planSlug, $creditsToAdd, $userId);
    $updStmt->execute();
    $updStmt->close();

    // Ledger
    appendLedger($conn, $userId, $creditsToAdd, 'plan_renewal', $paymentId, null, "Plan: {$planSlug}");
}

function fulfillCreditTopup($conn, $userId, $paymentId, $packageId) {
    $pkgStmt = $conn->prepare("SELECT credits, bonus_credits FROM credit_packages WHERE id=? AND is_active=1");
    $pkgStmt->bind_param("i", $packageId);
    $pkgStmt->execute();
    $pkg = $pkgStmt->get_result()->fetch_assoc();
    $pkgStmt->close();
    if (!$pkg) return;

    $total = $pkg['credits'] + ($pkg['bonus_credits'] ?? 0);

    $conn->prepare("UPDATE users SET credits=credits+? WHERE id=?")->bind_param("ii", $total, $userId) && null;
    $updStmt = $conn->prepare("UPDATE users SET credits=credits+? WHERE id=?");
    $updStmt->bind_param("ii", $total, $userId);
    $updStmt->execute();
    $updStmt->close();

    appendLedger($conn, $userId, $total, 'topup_purchase', $paymentId, null, "Package {$packageId}");
}

function appendLedger($conn, $userId, $delta, $type, $paymentId, $domain, $note) {
    // Get current balance
    $balStmt = $conn->prepare("SELECT credits FROM users WHERE id=?");
    $balStmt->bind_param("i", $userId);
    $balStmt->execute();
    $balAfter = (int)($balStmt->get_result()->fetch_assoc()['credits'] ?? 0);
    $balStmt->close();

    $ledger = $conn->prepare("
        INSERT INTO credit_ledger (user_id, delta, balance_after, type, payment_id, domain_name, note)
        VALUES (?,?,?,?,?,?,?)
    ");
    $ledger->bind_param("iiisiss", $userId, $delta, $balAfter, $type, $paymentId, $domain, $note);
    $ledger->execute();
    $ledger->close();
}

function buildInvoice($conn, $userId, $paymentId, $amtKobo, $discountKobo, $currency, $description) {
    // Fetch billing info
    $uStmt = $conn->prepare("SELECT email, full_name, billing_email, billing_name, billing_phone FROM users WHERE id=?");
    $uStmt->bind_param("i", $userId);
    $uStmt->execute();
    $u = $uStmt->get_result()->fetch_assoc();
    $uStmt->close();

    $invNum = 'INV-' . date('Y') . '-' . str_pad($paymentId, 6, '0', STR_PAD_LEFT);
    $total  = $amtKobo - $discountKobo;

    $invStmt = $conn->prepare("
        INSERT INTO invoices
          (user_id, payment_id, invoice_number, status, subtotal_kobo, discount_kobo,
           total_kobo, amount_paid_kobo, currency, billing_name, billing_email,
           billing_phone, paid_at)
        VALUES (?,?,?,'paid',?,?,?,?,?,?,?,?,NOW())
    ");
    $bName  = $u['billing_name']  ?: $u['full_name'];
    $bEmail = $u['billing_email'] ?: $u['email'];
    $bPhone = $u['billing_phone'] ?? null;
    $invStmt->bind_param("iisiiiiissss",
        $userId, $paymentId, $invNum,
        $amtKobo, $discountKobo, $total, $total, $currency,
        $bName, $bEmail, $bPhone
    );
    $invStmt->execute();
    $invId = $conn->insert_id;
    $invStmt->close();

    // Line item
    $lineStmt = $conn->prepare("INSERT INTO invoice_items (invoice_id, description, quantity, unit_price_kobo, amount_kobo) VALUES (?,?,1,?,?)");
    $lineStmt->bind_param("isii", $invId, $description, $amtKobo, $amtKobo);
    $lineStmt->execute();
    $lineStmt->close();
}

// ═════════════════════════════════════════════════════════════
// ACTION: validate promo code
// ═════════════════════════════════════════════════════════════
function handleValidatePromo($conn, $userId, $input) {
    $code = strtoupper(trim($input['code'] ?? ''));
    if (!$code) { ob_end_clean(); echo json_encode(['valid'=>false,'message'=>'No code provided.']); return; }

    $promo = getActivePromo($conn, $code, $userId);

    if (!$promo) {
        ob_end_clean();
        echo json_encode(['valid'=>false,'message'=>'Invalid, expired, or already used promo code.']);
        return;
    }

    $desc = match($promo['type']) {
        'percent_off'  => number_format($promo['value'], 0) . '% off your subscription',
        'amount_off'   => '₦' . number_format($promo['value'] / 100, 0) . ' off',
        'free_credits' => (int)$promo['value'] . ' free credits added to your account',
        'free_trial'   => (int)$promo['value'] . ' extra days of free trial',
        default        => 'Discount applied',
    };

    ob_end_clean();
    echo json_encode(['valid'=>true,'description'=>$desc,'type'=>$promo['type'],'value'=>$promo['value']]);
}

// ═════════════════════════════════════════════════════════════
// ACTION: cancel subscription
// ═════════════════════════════════════════════════════════════
function handleCancel($conn, $userId, $secret) {
    $subStmt = $conn->prepare("SELECT id, paystack_subscription_code, paystack_email_token FROM subscriptions WHERE user_id=? AND status IN ('active','trialing') LIMIT 1");
    $subStmt->bind_param("i", $userId);
    $subStmt->execute();
    $sub = $subStmt->get_result()->fetch_assoc();
    $subStmt->close();

    if (!$sub) {
        ob_end_clean();
        echo json_encode(['success'=>false,'message'=>'No active subscription found.']);
        return;
    }

    // Disable on Paystack if we have subscription code
    if (!empty($sub['paystack_subscription_code'])) {
        $payload = json_encode([
            'code'  => $sub['paystack_subscription_code'],
            'token' => $sub['paystack_email_token'] ?? '',
        ]);
        $ch = curl_init("https://api.paystack.co/subscription/disable");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$secret}",
                "Content-Type: application/json",
                "Cache-Control: no-cache",
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // Mark cancel_at_period_end locally
    $upd = $conn->prepare("UPDATE subscriptions SET cancel_at_period_end=1, canceled_at=NOW(), status='non_renewing' WHERE id=?");
    $upd->bind_param("i", $sub['id']);
    $upd->execute();
    $upd->close();

    ob_end_clean();
    echo json_encode(['success'=>true,'message'=>'Your subscription will not renew. You keep access until the end of the billing period.']);
}

// ═════════════════════════════════════════════════════════════
// WEBHOOK HANDLER
// ═════════════════════════════════════════════════════════════
function handleWebhook($secret) {
    $rawBody = file_get_contents('php://input');
    $sig     = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    $hash    = hash_hmac('sha512', $rawBody, $secret);

    if (!hash_equals($hash, $sig)) {
        http_response_code(401);
        ob_end_clean();
        echo json_encode(['error'=>'Invalid signature']);
        return;
    }

    $event = json_decode($rawBody, true);
    if (!$event) { http_response_code(200); ob_end_clean(); echo '{}'; return; }

    $eventType = $event['event'] ?? '';
    $data      = $event['data']  ?? [];
    $eventId   = $data['id'] ?? uniqid('evt_', true);
    $ref       = $data['reference'] ?? null;

    // Log webhook
    try {
        $conn = getDBConnection();

        $wh = $conn->prepare("
            INSERT IGNORE INTO webhooks_log
              (event_type, event_id, paystack_reference, payload, status, ip_address)
            VALUES (?,?,?,?,'received',?)
        ");
        $ip = getClientIP();
        $payloadJson = json_encode($event);
        $wh->bind_param("sssss", $eventType, $eventId, $ref, $payloadJson, $ip);
        $wh->execute();
        $whId = $conn->insert_id;
        $wh->close();

        // Process known events
        $processed = false;
        switch ($eventType) {
            case 'charge.success':
                // Already handled via verify callback; mark as processed
                $processed = true;
                break;

            case 'invoice.payment_failed':
                $subCode = $data['subscription']['subscription_code'] ?? null;
                if ($subCode) {
                    $conn->prepare("UPDATE subscriptions SET status='past_due', retry_count=retry_count+1, last_retry_at=NOW() WHERE paystack_subscription_code=?")
                         ->bind_param("s", $subCode) && null;
                    $upd = $conn->prepare("UPDATE subscriptions SET status='past_due', retry_count=retry_count+1, last_retry_at=NOW() WHERE paystack_subscription_code=?");
                    $upd->bind_param("s", $subCode);
                    $upd->execute();
                    $upd->close();
                }
                $processed = true;
                break;

            case 'subscription.disable':
            case 'subscription.not_renew':
                $subCode = $data['subscription_code'] ?? null;
                if ($subCode) {
                    $status = $eventType === 'subscription.disable' ? 'canceled' : 'non_renewing';
                    $upd = $conn->prepare("UPDATE subscriptions SET status=?, canceled_at=NOW() WHERE paystack_subscription_code=?");
                    $upd->bind_param("ss", $status, $subCode);
                    $upd->execute();
                    $upd->close();
                }
                $processed = true;
                break;

            case 'subscription.create':
                $subCode   = $data['subscription_code'] ?? null;
                $emailTok  = $data['email_token']       ?? null;
                if ($subCode && $ref) {
                    $upd = $conn->prepare("UPDATE subscriptions SET paystack_subscription_code=?, paystack_email_token=? WHERE user_id=(SELECT user_id FROM payments WHERE paystack_reference=? LIMIT 1)");
                    $upd->bind_param("sss", $subCode, $emailTok, $ref);
                    $upd->execute();
                    $upd->close();
                }
                $processed = true;
                break;
        }

        // Update log status
        $statusStr = $processed ? 'processed' : 'ignored';
        $conn->prepare("UPDATE webhooks_log SET status=?, processed_at=NOW() WHERE id=?")
             ->bind_param("si", $statusStr, $whId) && null;
        $upd = $conn->prepare("UPDATE webhooks_log SET status=?, processed_at=NOW() WHERE id=?");
        $upd->bind_param("si", $statusStr, $whId);
        $upd->execute();
        $upd->close();

        $conn->close();
    } catch (Exception $e) {
        // Log but don't surface — always return 200 to Paystack
    }

    http_response_code(200);
    ob_end_clean();
    echo json_encode(['status'=>'ok']);
}

// ═════════════════════════════════════════════════════════════
// HELPERS
// ═════════════════════════════════════════════════════════════
function getActivePromo($conn, $code, $userId) {
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        SELECT p.*
        FROM promo_codes p
        WHERE p.code = ?
          AND p.is_active = 1
          AND (p.valid_from  IS NULL OR p.valid_from  <= ?)
          AND (p.valid_until IS NULL OR p.valid_until >= ?)
          AND (p.max_uses    IS NULL OR p.uses_count  < p.max_uses)
        LIMIT 1
    ");
    $stmt->bind_param("sss", $code, $now, $now);
    $stmt->execute();
    $promo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$promo) return null;

    // one_per_user check
    if ($promo['one_per_user']) {
        $used = $conn->prepare("SELECT id FROM promo_code_uses WHERE promo_code_id=? AND user_id=? LIMIT 1");
        $used->bind_param("ii", $promo['id'], $userId);
        $used->execute();
        if ($used->get_result()->num_rows > 0) { $used->close(); return null; }
        $used->close();
    }

    return $promo;
}

function computeDiscount($promo, $amtKobo) {
    return match($promo['type']) {
        'percent_off' => (int)round($amtKobo * $promo['value'] / 100),
        'amount_off'  => min((int)$promo['value'], $amtKobo),
        default       => 0,
    };
}

function recordPromoUse($conn, $promoId, $userId, $paymentId, $discountKobo) {
    $ins = $conn->prepare("INSERT INTO promo_code_uses (promo_code_id, user_id, payment_id, discount_kobo) VALUES (?,?,?,?)");
    $ins->bind_param("iiii", $promoId, $userId, $paymentId, $discountKobo);
    $ins->execute();
    $ins->close();
    $conn->prepare("UPDATE promo_codes SET uses_count=uses_count+1 WHERE id=?")
         ->bind_param("i", $promoId) && null;
    $upd = $conn->prepare("UPDATE promo_codes SET uses_count=uses_count+1 WHERE id=?");
    $upd->bind_param("i", $promoId);
    $upd->execute();
    $upd->close();
}