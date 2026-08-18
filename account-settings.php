<?php
session_start();
require_once 'lib/Auth.php';
require_once 'config/database.php';

$auth = new Auth();

// ── Auth guard ────────────────────────────────────────────────
if (!isset($_COOKIE['session_token'])) {
    header('Location: login.php');
    exit();
}
$session = $auth->verifySession($_COOKIE['session_token']);
if (!$session) {
    setcookie('session_token', '', time() - 3600, '/');
    header('Location: login.php');
    exit();
}

// ── URL helper ────────────────────────────────────────────────
$appBasePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (in_array($appBasePath, ['/', '.', '\\'])) { $appBasePath = ''; }
$assetUrl = fn(string $p): string => ($appBasePath ?: '') . '/' . ltrim($p, '/');

// ── Fetch fresh user data ─────────────────────────────────────
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, email, full_name, avatar, provider, email_verified, role, status, created_at, last_login, last_ip, login_count, plan, credits, billing_email, billing_name, billing_phone FROM users WHERE id = ?");
$stmt->bind_param("i", $session['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: logout.php');
    exit();
}

// ── Handle POST actions ───────────────────────────────────────
$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Profile update ────────────────────────────────────────
    if ($action === 'update_profile') {
        $fullName = trim(strip_tags($_POST['full_name'] ?? ''));
        $billingEmail = trim($_POST['billing_email'] ?? '');
        $billingName  = trim(strip_tags($_POST['billing_name'] ?? ''));
        $billingPhone = trim(strip_tags($_POST['billing_phone'] ?? ''));

        if (empty($fullName)) {
            $flash = ['type' => 'error', 'msg' => 'Display name cannot be empty.'];
        } elseif (strlen($fullName) > 100) {
            $flash = ['type' => 'error', 'msg' => 'Display name must be 100 characters or less.'];
        } elseif (!empty($billingEmail) && !filter_var($billingEmail, FILTER_VALIDATE_EMAIL)) {
            $flash = ['type' => 'error', 'msg' => 'Billing email address is invalid.'];
        } else {
            $upStmt = $conn->prepare("UPDATE users SET full_name = ?, billing_email = ?, billing_name = ?, billing_phone = ? WHERE id = ?");
            // billing_* columns may not exist yet — catch gracefully
            if ($upStmt) {
                $billingEmailVal = $billingEmail ?: null;
                $billingNameVal  = $billingName  ?: null;
                $billingPhoneVal = $billingPhone ?: null;
                $upStmt->bind_param("ssssi", $fullName, $billingEmailVal, $billingNameVal, $billingPhoneVal, $session['user_id']);
                $upStmt->execute();
                $upStmt->close();
            } else {
                // Fallback: update only full_name
                $upStmt2 = $conn->prepare("UPDATE users SET full_name = ? WHERE id = ?");
                $upStmt2->bind_param("si", $fullName, $session['user_id']);
                $upStmt2->execute();
                $upStmt2->close();
            }
            $flash = ['type' => 'success', 'msg' => 'Profile updated successfully.'];
            $user['full_name']     = $fullName;
            $user['billing_email'] = $billingEmail;
            $user['billing_name']  = $billingName;
            $user['billing_phone'] = $billingPhone;
        }
    }

    // ── Password change ───────────────────────────────────────
    elseif ($action === 'change_password') {
        if ($user['provider'] !== 'local' && $user['provider'] !== null && $user['provider'] !== '') {
            $flash = ['type' => 'error', 'msg' => 'Password cannot be changed for social login accounts.'];
        } else {
            $currentPw  = $_POST['current_password']  ?? '';
            $newPw      = $_POST['new_password']       ?? '';
            $confirmPw  = $_POST['confirm_password']   ?? '';

            // Fetch password hash
            $pwStmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
            $pwStmt->bind_param("i", $session['user_id']);
            $pwStmt->execute();
            $pwRow = $pwStmt->get_result()->fetch_assoc();
            $pwStmt->close();

            if (!password_verify($currentPw, $pwRow['password_hash'] ?? '')) {
                $flash = ['type' => 'error', 'msg' => 'Current password is incorrect.'];
            } elseif (strlen($newPw) < 8) {
                $flash = ['type' => 'error', 'msg' => 'New password must be at least 8 characters.'];
            } elseif ($newPw !== $confirmPw) {
                $flash = ['type' => 'error', 'msg' => 'New passwords do not match.'];
            } elseif (password_verify($newPw, $pwRow['password_hash'])) {
                $flash = ['type' => 'error', 'msg' => 'New password must be different from your current password.'];
            } else {
                $newHash   = password_hash($newPw, PASSWORD_DEFAULT);
                $hashStmt  = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $hashStmt->bind_param("si", $newHash, $session['user_id']);
                $hashStmt->execute();
                $hashStmt->close();
                $flash = ['type' => 'success', 'msg' => 'Password changed successfully.'];
            }
        }
    }

    // ── Notification preferences ──────────────────────────────
    elseif ($action === 'update_notifications') {
        // Stored as JSON in users.notification_prefs (add column if needed)
        $prefs = [
            'domain_available'  => isset($_POST['notif_domain_available']),
            'domain_expiring'   => isset($_POST['notif_domain_expiring']),
            'billing'           => isset($_POST['notif_billing']),
            'security'          => isset($_POST['notif_security']),
            'marketing'         => isset($_POST['notif_marketing']),
        ];
        $json = json_encode($prefs);

        // Try to save — column may not exist yet
        $notifStmt = $conn->prepare("UPDATE users SET notification_prefs = ? WHERE id = ?");
        if ($notifStmt) {
            $notifStmt->bind_param("si", $json, $session['user_id']);
            $notifStmt->execute();
            $notifStmt->close();
        }
        $flash = ['type' => 'success', 'msg' => 'Notification preferences saved.'];
    }

    // ── Delete account ────────────────────────────────────────
    elseif ($action === 'delete_account') {
        $confirmText = trim($_POST['confirm_text'] ?? '');
        if ($confirmText !== 'DELETE') {
            $flash = ['type' => 'error', 'msg' => 'Type DELETE to confirm account deletion.'];
        } else {
            // Anonymise rather than hard-delete to preserve referential integrity
            $anonEmail = 'deleted_' . $session['user_id'] . '_' . time() . '@deleted.invalid';
            $delStmt   = $conn->prepare("UPDATE users SET email = ?, full_name = 'Deleted User', password_hash = '', avatar = NULL, status = 'deleted', email_verified = 0 WHERE id = ?");
            $delStmt->bind_param("si", $anonEmail, $session['user_id']);
            $delStmt->execute();
            $delStmt->close();

            // Invalidate all sessions
            $sessStmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?");
            if ($sessStmt) { $sessStmt->bind_param("i", $session['user_id']); $sessStmt->execute(); $sessStmt->close(); }

            setcookie('session_token', '', time() - 3600, '/');
            $conn->close();
            header('Location: index.php?deleted=1');
            exit();
        }
    }
}

$conn->close();

// ── Display meta ──────────────────────────────────────────────
$userName     = trim($user['full_name'] ?? '') ?: explode('@', $user['email'])[0];
$firstName    = explode(' ', $userName)[0];
$initials     = strtoupper(substr($userName, 0, 1) . (strpos($userName, ' ') !== false ? substr($userName, strpos($userName, ' ') + 1, 1) : ''));
$userPlan     = $user['plan']    ?? 'free';
$credits      = $user['credits'] ?? 10;
$isLocalAuth  = ($user['provider'] ?? 'local') === 'local' || empty($user['provider']);
$providerName = match($user['provider'] ?? 'local') {
    'google'   => 'Google',
    'github'   => 'GitHub',
    'facebook' => 'Facebook',
    default    => 'Email',
};

$watchlistCount = 0;
$alertCount     = 0;

// Notification prefs default
$notifPrefs = [
    'domain_available' => true,
    'domain_expiring'  => true,
    'billing'          => true,
    'security'         => true,
    'marketing'        => false,
];
if (!empty($user['notification_prefs'])) {
    $decoded = json_decode($user['notification_prefs'], true);
    if (is_array($decoded)) $notifPrefs = array_merge($notifPrefs, $decoded);
}

$activePage = 'settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Settings — CheckDomain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;700;800&family=DM+Mono:wght@400;500&family=Instrument+Serif:ital@1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #0A0B0E;
  --bg2:       #111318;
  --bg3:       #181C24;
  --bg4:       #1E2230;
  --border:    rgba(255,255,255,0.06);
  --border2:   rgba(255,255,255,0.11);
  --text:      #E9E7DF;
  --text2:     #8A8880;
  --text3:     #454340;
  --green:     #1D9E75;
  --green2:    #14C48A;
  --green-bg:  rgba(29,158,117,0.1);
  --amber:     #EF9F27;
  --amber-bg:  rgba(239,159,39,0.1);
  --coral:     #E8593C;
  --coral-bg:  rgba(232,89,60,0.1);
  --purple:    #7F77DD;
  --purple-bg: rgba(127,119,221,0.1);
  --blue:      #4A90D9;
  --blue-bg:   rgba(74,144,217,0.1);
  --mono:      'DM Mono', monospace;
  --display:   'Syne', sans-serif;
  --serif:     'Instrument Serif', serif;
  --sb-width:  224px;
}

html { scroll-behavior: smooth; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--display);
  min-height: 100vh;
  display: flex;
  overflow-x: hidden;
}
body::before {
  content: '';
  position: fixed; inset: 0;
  background-image:
    linear-gradient(rgba(29,158,117,0.02) 1px, transparent 1px),
    linear-gradient(90deg, rgba(29,158,117,0.02) 1px, transparent 1px);
  background-size: 52px 52px;
  pointer-events: none; z-index: 0;
}

/* ── Layout ─────────────────────────────────────────── */
.main { margin-left: var(--sb-width); flex: 1; position: relative; z-index: 1; min-height: 100vh; }

/* ── Topbar ─────────────────────────────────────────── */
.topbar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 15px 28px; border-bottom: 1px solid var(--border);
  backdrop-filter: blur(12px); background: rgba(10,11,14,0.85);
  position: sticky; top: 0; z-index: 40; gap: 14px;
}
.topbar-left  { display: flex; align-items: center; gap: 12px; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.mobile-menu-btn {
  display: none; align-items: center; justify-content: center;
  width: 34px; height: 34px; border-radius: 8px;
  background: var(--bg2); border: 1px solid var(--border);
  color: var(--text2); font-size: 16px; cursor: pointer;
}
.breadcrumb { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--text3); }
.breadcrumb a { color: var(--text2); text-decoration: none; transition: color 0.15s; }
.breadcrumb a:hover { color: var(--text); }
.breadcrumb-sep { color: var(--text3); }
.breadcrumb-current { color: var(--text); }
.topbar-btn {
  display: flex; align-items: center; justify-content: center;
  width: 33px; height: 33px; border-radius: 8px;
  background: var(--bg2); border: 1px solid var(--border);
  color: var(--text2); font-size: 14px; cursor: pointer;
  text-decoration: none; transition: border-color 0.15s, color 0.15s;
}
.topbar-btn:hover { border-color: var(--border2); color: var(--text); }

/* ── Page content ───────────────────────────────────── */
.content { padding: 28px 28px 60px; }
.page-title { font-family: var(--serif); font-style: italic; font-size: 26px; color: var(--text); margin-bottom: 4px; }
.page-sub   { font-size: 13px; color: var(--text2); margin-bottom: 28px; }

/* ── Flash ──────────────────────────────────────────── */
.flash {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px; border-radius: 10px;
  font-size: 13px; margin-bottom: 22px;
}
.flash.success { background: var(--green-bg); border: 1px solid rgba(29,158,117,0.25); color: var(--green2); }
.flash.error   { background: var(--coral-bg); border: 1px solid rgba(232,89,60,0.25);   color: var(--coral); }

/* ── Two-column layout ──────────────────────────────── */
.settings-layout { display: grid; grid-template-columns: 220px 1fr; gap: 22px; align-items: start; }

/* ── Settings nav ───────────────────────────────────── */
.settings-nav {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 14px; overflow: hidden;
  position: sticky; top: 76px;
}
.settings-nav-header {
  padding: 14px 16px 10px; font-size: 10px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.14em; color: var(--text3);
  border-bottom: 1px solid var(--border);
}
.settings-nav-link {
  display: flex; align-items: center; gap: 9px;
  padding: 10px 16px; font-size: 13px; color: var(--text2);
  text-decoration: none; cursor: pointer;
  transition: background 0.12s, color 0.12s;
  border-bottom: 1px solid var(--border);
  background: none; border-left: none; border-right: none; border-top: none;
  width: 100%; text-align: left; font-family: var(--display);
}
.settings-nav-link:last-child { border-bottom: none; }
.settings-nav-link:hover { background: var(--bg3); color: var(--text); }
.settings-nav-link.active { background: var(--green-bg); color: var(--green2); }
.settings-nav-link.danger { color: var(--coral); }
.settings-nav-link.danger:hover { background: var(--coral-bg); }
.settings-nav-icon { font-size: 13px; flex-shrink: 0; width: 15px; text-align: center; }

/* ── Panels ─────────────────────────────────────────── */
.panel { display: none; }
.panel.active { display: block; }

/* ── Card ───────────────────────────────────────────── */
.card {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 14px; margin-bottom: 18px; overflow: hidden;
}
.card-header {
  padding: 16px 22px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.card-title { font-size: 13px; font-weight: 700; color: var(--text); }
.card-subtitle { font-size: 12px; color: var(--text2); margin-top: 2px; }
.card-body { padding: 22px; }

/* ── Avatar section ─────────────────────────────────── */
.avatar-section { display: flex; align-items: center; gap: 20px; margin-bottom: 26px; }
.avatar-lg {
  width: 68px; height: 68px; border-radius: 50%;
  background: linear-gradient(135deg, var(--green), var(--purple));
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; font-weight: 700; color: #fff;
  flex-shrink: 0; font-family: var(--display);
  position: relative;
}
.avatar-lg img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
.avatar-info-name { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 3px; }
.avatar-info-email { font-size: 12px; color: var(--text2); font-family: var(--mono); margin-bottom: 6px; }
.provider-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em;
  padding: 2px 8px; border-radius: 4px;
  background: var(--bg3); border: 1px solid var(--border); color: var(--text3);
}

/* ── Form fields ────────────────────────────────────── */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.form-row.single { grid-template-columns: 1fr; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-label {
  font-size: 11px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.1em; color: var(--text3);
}
.form-label span { color: var(--coral); margin-left: 2px; }
.form-input {
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: 8px; padding: 9px 13px;
  font-family: var(--mono); font-size: 13px; color: var(--text);
  outline: none; transition: border-color 0.2s; width: 100%;
}
.form-input::placeholder { color: var(--text3); }
.form-input:focus { border-color: var(--green); }
.form-input:disabled { opacity: 0.45; cursor: not-allowed; }
.form-hint { font-size: 11px; color: var(--text3); }
.form-hint a { color: var(--green); text-decoration: none; }
.form-hint a:hover { color: var(--green2); }

/* Password strength */
.pw-strength { height: 3px; border-radius: 2px; background: var(--border); margin-top: 6px; overflow: hidden; }
.pw-strength-fill { height: 100%; border-radius: 2px; transition: width 0.3s, background 0.3s; }

/* ── Form footer ────────────────────────────────────── */
.form-footer {
  display: flex; align-items: center; justify-content: flex-end;
  gap: 10px; padding-top: 18px;
  border-top: 1px solid var(--border); margin-top: 6px;
}
.btn-save {
  display: flex; align-items: center; gap: 7px;
  background: var(--green); color: #fff; border: none;
  border-radius: 8px; padding: 9px 22px;
  font-family: var(--display); font-size: 12px; font-weight: 700;
  cursor: pointer; transition: background 0.2s;
}
.btn-save:hover { background: var(--green2); }
.btn-save:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-cancel {
  background: none; border: 1px solid var(--border2);
  border-radius: 8px; padding: 9px 18px;
  font-family: var(--display); font-size: 12px; color: var(--text2);
  cursor: pointer; transition: all 0.15s;
}
.btn-cancel:hover { background: var(--bg3); color: var(--text); }

/* ── Plan card ──────────────────────────────────────── */
.current-plan-card {
  display: flex; align-items: center; gap: 16px;
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: 10px; padding: 16px 18px; margin-bottom: 18px;
}
.plan-icon {
  width: 42px; height: 42px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.pi-free   { background: var(--bg4); color: var(--text2); }
.pi-pro    { background: var(--green-bg); color: var(--green2); }
.pi-elite  { background: var(--purple-bg); color: var(--purple); }
.plan-details { flex: 1; }
.plan-name-lg  { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
.plan-desc-sm  { font-size: 12px; color: var(--text2); }
.plan-cta-sm {
  background: var(--green); color: #fff; border: none;
  border-radius: 7px; padding: 8px 16px;
  font-family: var(--display); font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.06em;
  cursor: pointer; text-decoration: none; white-space: nowrap;
  transition: background 0.2s; display: inline-block;
}
.plan-cta-sm:hover { background: var(--green2); }

/* Credits bar */
.credits-display {
  background: var(--bg3); border: 1px solid var(--border);
  border-radius: 10px; padding: 14px 18px; margin-bottom: 18px;
}
.credits-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.credits-label { font-size: 12px; color: var(--text2); }
.credits-value { font-family: var(--mono); font-size: 13px; color: var(--text); font-weight: 700; }
.credits-bar-wrap { height: 4px; background: var(--border); border-radius: 2px; overflow: hidden; }
.credits-bar-fill { height: 100%; border-radius: 2px; transition: width 0.6s ease; }

/* Feature list */
.feature-list { display: flex; flex-direction: column; gap: 9px; }
.feature-item {
  display: flex; align-items: center; gap: 9px;
  font-size: 13px; color: var(--text2);
}
.feature-item i { font-size: 11px; flex-shrink: 0; }
.feature-item.on  i { color: var(--green2); }
.feature-item.off i { color: var(--text3); }
.feature-item.off   { color: var(--text3); }

/* ── Toggle switches ────────────────────────────────── */
.toggle-list { display: flex; flex-direction: column; gap: 0; }
.toggle-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 0; border-bottom: 1px solid var(--border);
  gap: 16px;
}
.toggle-item:last-child { border-bottom: none; }
.toggle-label { font-size: 13px; color: var(--text); font-weight: 500; }
.toggle-desc  { font-size: 11px; color: var(--text3); margin-top: 2px; }
.toggle-switch { position: relative; width: 38px; height: 20px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-track {
  position: absolute; inset: 0; background: var(--bg4);
  border: 1px solid var(--border2); border-radius: 20px;
  cursor: pointer; transition: background 0.2s;
}
.toggle-switch input:checked + .toggle-track { background: var(--green); border-color: var(--green); }
.toggle-track::before {
  content: ''; position: absolute;
  width: 14px; height: 14px; border-radius: 50%;
  background: var(--text3); top: 2px; left: 2px;
  transition: transform 0.2s, background 0.2s;
}
.toggle-switch input:checked + .toggle-track::before {
  transform: translateX(18px); background: #fff;
}

/* ── Sessions ───────────────────────────────────────── */
.session-item {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 0; border-bottom: 1px solid var(--border);
}
.session-item:last-child { border-bottom: none; }
.session-icon {
  width: 36px; height: 36px; border-radius: 8px;
  background: var(--bg3); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; color: var(--text2); flex-shrink: 0;
}
.session-info { flex: 1; min-width: 0; }
.session-device { font-size: 13px; color: var(--text); }
.session-meta   { font-size: 11px; color: var(--text3); margin-top: 2px; font-family: var(--mono); }
.session-badge {
  font-size: 9px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
  padding: 2px 7px; border-radius: 4px;
  background: var(--green-bg); color: var(--green2);
}
.session-revoke {
  background: none; border: 1px solid var(--border);
  border-radius: 6px; padding: 5px 10px;
  font-family: var(--display); font-size: 11px; color: var(--text3);
  cursor: pointer; transition: all 0.15s;
}
.session-revoke:hover { background: var(--coral-bg); border-color: rgba(232,89,60,0.25); color: var(--coral); }

/* ── Danger zone ────────────────────────────────────── */
.danger-card {
  background: var(--bg2); border: 1px solid rgba(232,89,60,0.18);
  border-radius: 14px; overflow: hidden; margin-bottom: 18px;
}
.danger-header {
  padding: 16px 22px; border-bottom: 1px solid rgba(232,89,60,0.12);
  background: rgba(232,89,60,0.04);
}
.danger-title { font-size: 13px; font-weight: 700; color: var(--coral); }
.danger-sub   { font-size: 12px; color: var(--text2); margin-top: 2px; }
.danger-body  { padding: 22px; display: flex; flex-direction: column; gap: 14px; }
.danger-item  {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; flex-wrap: wrap;
}
.danger-item-info { font-size: 13px; color: var(--text); margin-bottom: 3px; font-weight: 500; }
.danger-item-desc { font-size: 12px; color: var(--text2); line-height: 1.5; }
.btn-danger {
  background: none; border: 1px solid rgba(232,89,60,0.35);
  border-radius: 8px; padding: 8px 16px;
  font-family: var(--display); font-size: 12px; color: var(--coral);
  cursor: pointer; transition: all 0.15s; white-space: nowrap; flex-shrink: 0;
}
.btn-danger:hover { background: var(--coral-bg); border-color: var(--coral); }

/* ── Delete modal ───────────────────────────────────── */
.modal-overlay {
  position: fixed; inset: 0; z-index: 200;
  background: rgba(0,0,0,0.65); backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
  opacity: 0; pointer-events: none; transition: opacity 0.2s;
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal {
  background: var(--bg2); border: 1px solid rgba(232,89,60,0.25);
  border-radius: 16px; padding: 28px; max-width: 400px; width: 90%;
  transform: scale(0.95); transition: transform 0.2s;
}
.modal-overlay.open .modal { transform: scale(1); }
.modal-icon {
  width: 44px; height: 44px; border-radius: 12px;
  background: var(--coral-bg); display: flex; align-items: center; justify-content: center;
  font-size: 18px; color: var(--coral); margin-bottom: 14px;
}
.modal-title { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.modal-body  { font-size: 13px; color: var(--text2); line-height: 1.6; margin-bottom: 18px; }
.modal-confirm-input {
  background: var(--bg3); border: 1px solid rgba(232,89,60,0.3);
  border-radius: 8px; padding: 9px 13px;
  font-family: var(--mono); font-size: 13px; color: var(--text);
  outline: none; width: 100%; margin-bottom: 18px;
  transition: border-color 0.2s;
}
.modal-confirm-input:focus { border-color: var(--coral); }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
.modal-cancel-btn {
  background: none; border: 1px solid var(--border2);
  border-radius: 8px; padding: 8px 18px;
  font-family: var(--display); font-size: 12px; color: var(--text2);
  cursor: pointer; transition: all 0.15s;
}
.modal-cancel-btn:hover { background: var(--bg3); color: var(--text); }
.modal-delete-btn {
  background: var(--coral); color: #fff; border: none;
  border-radius: 8px; padding: 8px 18px;
  font-family: var(--display); font-size: 12px; font-weight: 700;
  cursor: pointer; transition: opacity 0.15s;
}
.modal-delete-btn:hover { opacity: 0.85; }

/* ── Toast ──────────────────────────────────────────── */
.toast {
  position: fixed; bottom: 28px; right: 28px; z-index: 999;
  background: var(--bg3); border: 1px solid var(--border2);
  border-radius: 10px; padding: 12px 18px;
  font-size: 13px; color: var(--text);
  box-shadow: 0 8px 32px rgba(0,0,0,0.4);
  transform: translateY(20px); opacity: 0;
  transition: all 0.3s ease; max-width: 320px;
  display: flex; align-items: center; gap: 9px;
}
.toast.show { transform: translateY(0); opacity: 1; }
.toast.success { border-color: rgba(29,158,117,0.3); }
.toast.error   { border-color: rgba(232,89,60,0.3); }

/* ── Responsive ─────────────────────────────────────── */
@media (max-width: 900px) {
  .settings-layout { grid-template-columns: 1fr; }
  .settings-nav { position: static; display: flex; flex-wrap: wrap; border-radius: 10px; }
  .settings-nav-header { display: none; }
  .settings-nav-link { border-bottom: none; border-right: 1px solid var(--border); flex: 1; min-width: 0; justify-content: center; padding: 10px 8px; font-size: 11px; }
  .settings-nav-link:last-child { border-right: none; }
  .settings-nav-icon { display: none; }
  .form-row { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
  .main { margin-left: 0; }
  .mobile-menu-btn { display: flex; }
  .content { padding: 20px 16px 50px; }
}

/* Sidebar overlay */
.sidebar-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,0.6); z-index: 49;
}
.sidebar-overlay.show { display: block; }
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
        <span class="breadcrumb-sep"><i class="fas fa-chevron-right" style="font-size:9px;"></i></span>
        <span class="breadcrumb-current">Account Settings</span>
      </div>
    </div>
    <div class="topbar-right">
      <a href="<?= htmlspecialchars($assetUrl('dashboard.php')) ?>" class="topbar-btn" title="Dashboard">
        <i class="fas fa-th-large"></i>
      </a>
    </div>
  </div>

  <div class="content">

    <div class="page-title">Account Settings.</div>
    <div class="page-sub">Manage your profile, security, plan, and preferences.</div>

    <!-- Flash message -->
    <?php if (!empty($flash['msg'])): ?>
    <div class="flash <?= $flash['type'] ?>">
      <i class="fas <?= $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <div class="settings-layout">

      <!-- ── Settings nav ────────────────────────── -->
      <div class="settings-nav">
        <div class="settings-nav-header">Settings</div>
        <button class="settings-nav-link active" onclick="switchPanel('profile', this)">
          <span class="settings-nav-icon"><i class="fas fa-user"></i></span> Profile
        </button>
        <button class="settings-nav-link" onclick="switchPanel('security', this)">
          <span class="settings-nav-icon"><i class="fas fa-lock"></i></span> Security
        </button>
        <button class="settings-nav-link" onclick="switchPanel('plan', this)">
          <span class="settings-nav-icon"><i class="fas fa-bolt"></i></span> Plan &amp; Billing
        </button>
        <button class="settings-nav-link" onclick="switchPanel('notifications', this)">
          <span class="settings-nav-icon"><i class="fas fa-bell"></i></span> Notifications
        </button>
        <button class="settings-nav-link" onclick="switchPanel('sessions', this)">
          <span class="settings-nav-icon"><i class="fas fa-desktop"></i></span> Sessions
        </button>
        <button class="settings-nav-link danger" onclick="switchPanel('danger', this)">
          <span class="settings-nav-icon"><i class="fas fa-triangle-exclamation"></i></span> Danger zone
        </button>
      </div>

      <!-- ── Panels ──────────────────────────────── -->
      <div class="panels-wrap">

        <!-- ─────────── PROFILE ─────────────────── -->
        <div class="panel active" id="panel-profile">
          <div class="card">
            <div class="card-header">
              <div>
                <div class="card-title">Public profile</div>
                <div class="card-subtitle">Your display name and avatar visible across CheckDomain.</div>
              </div>
            </div>
            <div class="card-body">

              <!-- Avatar row -->
              <div class="avatar-section">
                <div class="avatar-lg">
                  <?php if (!empty($user['avatar'])): ?>
                  <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Avatar">
                  <?php else: ?>
                  <?= htmlspecialchars($initials) ?>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="avatar-info-name"><?= htmlspecialchars($userName) ?></div>
                  <div class="avatar-info-email"><?= htmlspecialchars($user['email']) ?></div>
                  <span class="provider-badge">
                    <i class="fas <?= $isLocalAuth ? 'fa-envelope' : 'fa-link' ?>" style="font-size:9px;"></i>
                    <?= htmlspecialchars($providerName) ?> account
                  </span>
                </div>
              </div>

              <form method="POST" id="profileForm">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-row">
                  <div class="form-group">
                    <label class="form-label" for="full_name">Display name <span>*</span></label>
                    <input class="form-input" type="text" id="full_name" name="full_name"
                           value="<?= htmlspecialchars($user['full_name'] ?? '') ?>"
                           placeholder="Your full name" maxlength="100" required>
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="email_display">Email address</label>
                    <input class="form-input" type="email" id="email_display"
                           value="<?= htmlspecialchars($user['email']) ?>" disabled>
                    <span class="form-hint">
                      <?php if ($user['email_verified']): ?>
                        <i class="fas fa-check-circle" style="color:var(--green2);font-size:10px;"></i> Verified
                      <?php else: ?>
                        <i class="fas fa-exclamation-circle" style="color:var(--amber);font-size:10px;"></i> Not verified — <a href="resend-verification.php">resend email</a>
                      <?php endif; ?>
                    </span>
                  </div>
                </div>

                <div class="form-row single">
                  <div class="form-group">
                    <label class="form-label">Member since</label>
                    <input class="form-input" type="text"
                           value="<?= date('F j, Y', strtotime($user['created_at'])) ?>" disabled>
                  </div>
                </div>

                <div class="form-footer">
                  <div style="font-size:11px;color:var(--text3);margin-right:auto;">
                    Last login: <?= $user['last_login'] ? date('M j, Y · H:i', strtotime($user['last_login'])) : 'Never' ?>
                  </div>
                  <button type="submit" class="btn-save">
                    <i class="fas fa-check" style="font-size:11px;"></i> Save changes
                  </button>
                </div>
              </form>
            </div>
          </div>

          <!-- Billing info -->
          <div class="card">
            <div class="card-header">
              <div>
                <div class="card-title">Billing information</div>
                <div class="card-subtitle">Used on invoices sent to your email.</div>
              </div>
            </div>
            <div class="card-body">
              <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                <!-- carry profile fields silently -->
                <input type="hidden" name="full_name" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">

                <div class="form-row">
                  <div class="form-group">
                    <label class="form-label" for="billing_name">Billing name</label>
                    <input class="form-input" type="text" id="billing_name" name="billing_name"
                           value="<?= htmlspecialchars($user['billing_name'] ?? '') ?>"
                           placeholder="Name on invoice">
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="billing_email">Billing email</label>
                    <input class="form-input" type="email" id="billing_email" name="billing_email"
                           value="<?= htmlspecialchars($user['billing_email'] ?? '') ?>"
                           placeholder="billing@company.com">
                    <span class="form-hint">Leave blank to use your account email.</span>
                  </div>
                </div>
                <div class="form-row single">
                  <div class="form-group">
                    <label class="form-label" for="billing_phone">Phone number</label>
                    <input class="form-input" type="tel" id="billing_phone" name="billing_phone"
                           value="<?= htmlspecialchars($user['billing_phone'] ?? '') ?>"
                           placeholder="+234 800 000 0000">
                  </div>
                </div>

                <div class="form-footer">
                  <button type="submit" class="btn-save">
                    <i class="fas fa-check" style="font-size:11px;"></i> Save billing info
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- ─────────── SECURITY ─────────────────── -->
        <div class="panel" id="panel-security">
          <div class="card">
            <div class="card-header">
              <div>
                <div class="card-title">Change password</div>
                <div class="card-subtitle">
                  <?php if (!$isLocalAuth): ?>
                    You signed in via <?= htmlspecialchars($providerName) ?> — password management is handled there.
                  <?php else: ?>
                    Use a strong password of at least 8 characters.
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="card-body">
              <?php if (!$isLocalAuth): ?>
              <div style="display:flex;align-items:center;gap:10px;background:var(--bg3);border:1px solid var(--border);border-radius:9px;padding:14px 16px;font-size:13px;color:var(--text2);">
                <i class="fas fa-info-circle" style="color:var(--blue);font-size:14px;flex-shrink:0;"></i>
                Your account uses <?= htmlspecialchars($providerName) ?> for authentication. To change your password, visit your <?= htmlspecialchars($providerName) ?> account settings.
              </div>
              <?php else: ?>
              <form method="POST" id="passwordForm">
                <input type="hidden" name="action" value="change_password">
                <div class="form-row single">
                  <div class="form-group">
                    <label class="form-label" for="current_password">Current password <span>*</span></label>
                    <input class="form-input" type="password" id="current_password" name="current_password"
                           placeholder="••••••••" autocomplete="current-password" required>
                  </div>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label class="form-label" for="new_password">New password <span>*</span></label>
                    <input class="form-input" type="password" id="new_password" name="new_password"
                           placeholder="••••••••" autocomplete="new-password" oninput="checkPwStrength(this.value)" required>
                    <div class="pw-strength"><div class="pw-strength-fill" id="pwStrengthBar" style="width:0%"></div></div>
                    <span class="form-hint" id="pwStrengthLabel"></span>
                  </div>
                  <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm new password <span>*</span></label>
                    <input class="form-input" type="password" id="confirm_password" name="confirm_password"
                           placeholder="••••••••" autocomplete="new-password" oninput="checkPwMatch()" required>
                    <span class="form-hint" id="pwMatchLabel"></span>
                  </div>
                </div>
                <div class="form-footer">
                  <button type="submit" class="btn-save">
                    <i class="fas fa-lock" style="font-size:11px;"></i> Update password
                  </button>
                </div>
              </form>
              <?php endif; ?>
            </div>
          </div>

          <!-- Two-factor placeholder -->
          <div class="card">
            <div class="card-header">
              <div>
                <div class="card-title">Two-factor authentication</div>
                <div class="card-subtitle">Add a second layer of security to your account.</div>
              </div>
              <span style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;background:var(--amber-bg);color:var(--amber);border-radius:4px;padding:2px 7px;">Coming soon</span>
            </div>
            <div class="card-body" style="display:flex;align-items:center;gap:14px;">
              <div style="width:40px;height:40px;border-radius:10px;background:var(--amber-bg);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">🔐</div>
              <div style="font-size:13px;color:var(--text2);line-height:1.6;">
                Two-factor authentication via authenticator app or SMS will be available soon. You'll be notified when it launches.
              </div>
            </div>
          </div>
        </div>

        <!-- ─────────── PLAN & BILLING ───────────── -->
        <div class="panel" id="panel-plan">

          <!-- Current plan -->
          <div class="card">
            <div class="card-header">
              <div>
                <div class="card-title">Current plan</div>
                <div class="card-subtitle">Your active subscription and credit balance.</div>
              </div>
            </div>
            <div class="card-body">
              <?php
              $planIcon  = match($userPlan) { 'pro' => 'fa-bolt', 'elite' => 'fa-crown', default => 'fa-user' };
              $planColor = match($userPlan) { 'pro' => 'pi-pro', 'elite' => 'pi-elite', default => 'pi-free' };
              $planPrice = match($userPlan) { 'pro' => '$9/mo', 'elite' => '$29/mo', default => 'Free' };
              $planCredit= match($userPlan) { 'pro' => 100, 'elite' => 500, default => 10 };
              $creditPct = min(100, round(($credits / $planCredit) * 100));
              $creditBar = $creditPct > 50 ? 'var(--green)' : ($creditPct > 20 ? 'var(--amber)' : 'var(--coral)');
              ?>
              <div class="current-plan-card">
                <div class="plan-icon <?= $planColor ?>"><i class="fas <?= $planIcon ?>"></i></div>
                <div class="plan-details">
                  <div class="plan-name-lg"><?= ucfirst($userPlan) ?> Plan · <?= $planPrice ?></div>
                  <div class="plan-desc-sm">
                    <?php if ($userPlan === 'free'): ?>
                      Basic availability checks. Upgrade for WHOIS, alerts, and backorders.
                    <?php elseif ($userPlan === 'pro'): ?>
                      WHOIS lookups, expiry alerts, backorder placement, dead-site detection.
                    <?php else: ?>
                      Full access including broker service and bulk lookups.
                    <?php endif; ?>
                  </div>
                </div>
                <?php if ($userPlan === 'free'): ?>
                <a href="<?= htmlspecialchars($assetUrl('billing.php?plan=pro')) ?>" class="plan-cta-sm">Upgrade → Pro</a>
                <?php else: ?>
                <a href="<?= htmlspecialchars($assetUrl('billing.php')) ?>" class="plan-cta-sm" style="background:var(--bg3);color:var(--text2);border:1px solid var(--border);">Manage billing</a>
                <?php endif; ?>
              </div>

              <!-- Credits -->
              <div class="credits-display">
                <div class="credits-row">
                  <span class="credits-label"><i class="fas fa-bolt" style="color:var(--amber);margin-right:5px;font-size:11px;"></i> Credits remaining</span>
                  <span class="credits-value"><?= $credits ?> <span style="color:var(--text3);font-weight:400;">/ <?= $planCredit ?></span></span>
                </div>
                <div class="credits-bar-wrap">
                  <div class="credits-bar-fill" style="width:<?= $creditPct ?>%;background:<?= $creditBar ?>;"></div>
                </div>
                <?php if ($creditPct < 20): ?>
                <div style="font-size:11px;color:var(--coral);margin-top:7px;">
                  <i class="fas fa-exclamation-circle" style="font-size:10px;"></i>
                  Running low — <a href="<?= htmlspecialchars($assetUrl('billing.php?topup=1')) ?>" style="color:var(--amber);text-decoration:none;">top up credits</a> to keep searching.
                </div>
                <?php endif; ?>
              </div>

              <!-- Feature list -->
              <?php
              $features = [
                ['WHOIS deep lookup',      $userPlan !== 'free'],
                ['Expiry &amp; drop alerts', $userPlan !== 'free'],
                ['Backorder placement',     $userPlan !== 'free'],
                ['Dead-site detection',     $userPlan !== 'free'],
                ['Broker service',          $userPlan === 'elite'],
                ['Bulk domain lookup',      $userPlan === 'elite'],
              ];
              ?>
              <div class="feature-list" style="margin-top:18px;">
                <?php foreach ($features as [$label, $on]): ?>
                <div class="feature-item <?= $on ? 'on' : 'off' ?>">
                  <i class="fas <?= $on ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                  <?= $label ?>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

          <!-- Billing history placeholder -->
          <div class="card">
            <div class="card-header">
              <div class="card-title">Payment history</div>
              <a href="<?= htmlspecialchars($assetUrl('billing.php#history')) ?>"
                 style="font-size:11px;color:var(--green);text-decoration:none;">View all →</a>
            </div>
            <div class="card-body" style="text-align:center;padding:36px 22px;color:var(--text3);font-size:13px;">
              <i class="fas fa-receipt" style="font-size:22px;opacity:0.4;display:block;margin-bottom:10px;"></i>
              <?= $userPlan === 'free' ? 'No payments yet. Upgrade to see invoices here.' : 'Payment history will appear here.' ?>
            </div>
          </div>
        </div>

        <!-- ─────────── NOTIFICATIONS ─────────────── -->
        <div class="panel" id="panel-notifications">
          <div class="card">
            <div class="card-header">
              <div>
                <div class="card-title">Email notifications</div>
                <div class="card-subtitle">Choose which emails CheckDomain sends you.</div>
              </div>
            </div>
            <div class="card-body">
              <form method="POST">
                <input type="hidden" name="action" value="update_notifications">
                <div class="toggle-list">

                  <div class="toggle-item">
                    <div>
                      <div class="toggle-label">Domain becomes available</div>
                      <div class="toggle-desc">Alert when a watched domain drops and becomes available.</div>
                    </div>
                    <label class="toggle-switch">
                      <input type="checkbox" name="notif_domain_available" <?= $notifPrefs['domain_available'] ? 'checked' : '' ?>>
                      <span class="toggle-track"></span>
                    </label>
                  </div>

                  <div class="toggle-item">
                    <div>
                      <div class="toggle-label">Domain expiring soon</div>
                      <div class="toggle-desc">Reminder 30 days before a watched domain expires.</div>
                    </div>
                    <label class="toggle-switch">
                      <input type="checkbox" name="notif_domain_expiring" <?= $notifPrefs['domain_expiring'] ? 'checked' : '' ?>>
                      <span class="toggle-track"></span>
                    </label>
                  </div>

                  <div class="toggle-item">
                    <div>
                      <div class="toggle-label">Billing &amp; invoices</div>
                      <div class="toggle-desc">Receipts, renewal reminders, and failed payment notices.</div>
                    </div>
                    <label class="toggle-switch">
                      <input type="checkbox" name="notif_billing" <?= $notifPrefs['billing'] ? 'checked' : '' ?>>
                      <span class="toggle-track"></span>
                    </label>
                  </div>

                  <div class="toggle-item">
                    <div>
                      <div class="toggle-label">Security alerts</div>
                      <div class="toggle-desc">New login from unrecognised device or location.</div>
                    </div>
                    <label class="toggle-switch">
                      <input type="checkbox" name="notif_security" <?= $notifPrefs['security'] ? 'checked' : '' ?>>
                      <span class="toggle-track"></span>
                    </label>
                  </div>

                  <div class="toggle-item">
                    <div>
                      <div class="toggle-label">Tips &amp; product updates</div>
                      <div class="toggle-desc">Occasional emails about new features and domain tips.</div>
                    </div>
                    <label class="toggle-switch">
                      <input type="checkbox" name="notif_marketing" <?= $notifPrefs['marketing'] ? 'checked' : '' ?>>
                      <span class="toggle-track"></span>
                    </label>
                  </div>

                </div>
                <div class="form-footer">
                  <button type="submit" class="btn-save">
                    <i class="fas fa-check" style="font-size:11px;"></i> Save preferences
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- ─────────── SESSIONS ──────────────────── -->
        <div class="panel" id="panel-sessions">
          <div class="card">
            <div class="card-header">
              <div>
                <div class="card-title">Active sessions</div>
                <div class="card-subtitle">Devices currently signed in to your account.</div>
              </div>
            </div>
            <div class="card-body" style="padding-top:6px;padding-bottom:6px;">
              <div class="session-item">
                <div class="session-icon"><i class="fas fa-desktop"></i></div>
                <div class="session-info">
                  <div class="session-device">This device</div>
                  <div class="session-meta">
                    <?= htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ? substr($_SERVER['HTTP_USER_AGENT'], 0, 60) . '…' : 'Unknown browser') ?>
                    · <?= htmlspecialchars($user['last_ip'] ?? 'Unknown IP') ?>
                  </div>
                </div>
                <span class="session-badge">Current</span>
              </div>
            </div>
            <div style="padding:14px 22px;border-top:1px solid var(--border);">
              <form method="POST" action="logout.php" style="display:inline;">
                <input type="hidden" name="all_sessions" value="1">
                <button type="submit" class="btn-danger" style="font-size:12px;">
                  <i class="fas fa-sign-out-alt" style="font-size:10px;"></i> Sign out all other sessions
                </button>
              </form>
            </div>
          </div>

          <!-- Login stats -->
          <div class="card">
            <div class="card-header">
              <div class="card-title">Login statistics</div>
            </div>
            <div class="card-body">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div style="background:var(--bg3);border:1px solid var(--border);border-radius:9px;padding:14px 16px;">
                  <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.12em;color:var(--text3);margin-bottom:6px;">Total logins</div>
                  <div style="font-size:22px;font-weight:800;font-family:var(--mono);color:var(--text);"><?= number_format($user['login_count'] ?? 0) ?></div>
                </div>
                <div style="background:var(--bg3);border:1px solid var(--border);border-radius:9px;padding:14px 16px;">
                  <div style="font-size:10px;text-transform:uppercase;letter-spacing:0.12em;color:var(--text3);margin-bottom:6px;">Last active</div>
                  <div style="font-size:13px;font-weight:700;font-family:var(--mono);color:var(--text);line-height:1.3;">
                    <?= $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : '—' ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ─────────── DANGER ZONE ───────────────── -->
        <div class="panel" id="panel-danger">

          <div class="danger-card">
            <div class="danger-header">
              <div class="danger-title"><i class="fas fa-triangle-exclamation" style="margin-right:7px;"></i>Danger zone</div>
              <div class="danger-sub">Actions here are permanent and cannot be undone.</div>
            </div>
            <div class="danger-body">

              <div class="danger-item" style="padding-bottom:14px;border-bottom:1px solid rgba(232,89,60,0.1);">
                <div>
                  <div class="danger-item-info">Clear watchlist</div>
                  <div class="danger-item-desc">Remove all domains from your watchlist. You will stop receiving alerts for all of them.</div>
                </div>
                <button class="btn-danger" onclick="confirmClearWatchlist()">Clear watchlist</button>
              </div>

              <div class="danger-item" style="padding-bottom:14px;border-bottom:1px solid rgba(232,89,60,0.1);">
                <div>
                  <div class="danger-item-info">Cancel subscription</div>
                  <div class="danger-item-desc">Cancel your Pro or Elite plan. You keep access until the end of the billing period, then revert to Free.</div>
                </div>
                <a href="<?= htmlspecialchars($assetUrl('billing.php?cancel=1')) ?>"
                   class="btn-danger"
                   style="text-decoration:none;display:inline-block;">
                   <?= $userPlan === 'free' ? 'No active plan' : 'Cancel plan' ?>
                </a>
              </div>

              <div class="danger-item">
                <div>
                  <div class="danger-item-info">Delete account</div>
                  <div class="danger-item-desc">Permanently delete your account and all associated data. Your subscription will be canceled immediately. <strong style="color:var(--text);">This cannot be undone.</strong></div>
                </div>
                <button class="btn-danger" onclick="openDeleteModal()">Delete account</button>
              </div>

            </div>
          </div>
        </div>

      </div><!-- /.panels-wrap -->
    </div><!-- /.settings-layout -->
  </div><!-- /.content -->
</main>

<!-- ── Delete account modal ────────────────────────── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <div class="modal-icon"><i class="fas fa-triangle-exclamation"></i></div>
    <div class="modal-title">Delete your account?</div>
    <div class="modal-body">
      This will <strong style="color:var(--text);">permanently delete</strong> your account, watchlist, search history, and cancel any active subscription. You will lose access immediately.<br><br>
      Type <strong style="color:var(--coral);font-family:var(--mono);">DELETE</strong> to confirm.
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="delete_account">
      <input class="modal-confirm-input" type="text" name="confirm_text"
             id="deleteConfirmInput" placeholder="Type DELETE here" autocomplete="off"
             oninput="document.getElementById('deleteSubmitBtn').disabled = this.value !== 'DELETE'">
      <div class="modal-actions">
        <button type="button" class="modal-cancel-btn" onclick="closeDeleteModal()">Cancel</button>
        <button type="submit" class="modal-delete-btn" id="deleteSubmitBtn" disabled>Delete my account</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <i class="fas fa-check-circle" id="toastIcon" style="font-size:14px;flex-shrink:0;color:var(--green2);"></i>
  <span id="toastText"></span>
</div>

<script>
// ── Panel switching ────────────────────────────────────────
function switchPanel(id, btn) {
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.settings-nav-link').forEach(b => b.classList.remove('active'));
  document.getElementById('panel-' + id).classList.add('active');
  btn.classList.add('active');

  // Hash for deep-linking
  history.replaceState(null, '', '#' + id);
}

// Deep link on load
window.addEventListener('DOMContentLoaded', () => {
  const hash = location.hash.replace('#', '');
  const validPanels = ['profile','security','plan','notifications','sessions','danger'];
  if (hash && validPanels.includes(hash)) {
    const btn = document.querySelector(`[onclick="switchPanel('${hash}', this)"]`);
    if (btn) switchPanel(hash, btn);
  }
});

// ── Password strength ──────────────────────────────────────
function checkPwStrength(pw) {
  const bar   = document.getElementById('pwStrengthBar');
  const label = document.getElementById('pwStrengthLabel');
  if (!bar) return;

  let score = 0;
  if (pw.length >= 8)  score++;
  if (pw.length >= 12) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;

  const levels = [
    { w: '20%',  bg: 'var(--coral)',  txt: 'Too weak' },
    { w: '40%',  bg: 'var(--coral)',  txt: 'Weak' },
    { w: '60%',  bg: 'var(--amber)',  txt: 'Fair' },
    { w: '80%',  bg: 'var(--amber)',  txt: 'Good' },
    { w: '100%', bg: 'var(--green2)', txt: 'Strong' },
  ];
  const lvl = levels[Math.min(score, 4)];
  bar.style.width      = pw.length > 0 ? lvl.w  : '0%';
  bar.style.background = lvl.bg;
  label.textContent    = pw.length > 0 ? lvl.txt : '';
  label.style.color    = lvl.bg;
}

function checkPwMatch() {
  const pw1   = document.getElementById('new_password')?.value;
  const pw2   = document.getElementById('confirm_password')?.value;
  const label = document.getElementById('pwMatchLabel');
  if (!label || !pw2) return;
  if (pw2.length === 0) { label.textContent = ''; return; }
  label.textContent = pw1 === pw2 ? '✓ Passwords match' : '✗ Passwords do not match';
  label.style.color = pw1 === pw2 ? 'var(--green2)' : 'var(--coral)';
}

// ── Delete modal ───────────────────────────────────────────
function openDeleteModal()  { document.getElementById('deleteModal').classList.add('open'); }
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('open'); }
document.getElementById('deleteModal').addEventListener('click', e => {
  if (e.target === e.currentTarget) closeDeleteModal();
});

// ── Clear watchlist ────────────────────────────────────────
async function confirmClearWatchlist() {
  if (!confirm('Remove all domains from your watchlist? This cannot be undone.')) return;
  try {
    const APP_BASE = <?= json_encode($appBasePath ?? '') ?>;
    const res  = await fetch(`${APP_BASE}/api/watchlist-domain.php`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ clear_all: true })
    });
    const data = await res.json();
    showToast(data.success ? 'Watchlist cleared.' : (data.message || 'Failed to clear watchlist.'),
              data.success ? 'success' : 'error');
  } catch {
    showToast('Network error.', 'error');
  }
}

// ── Toast ──────────────────────────────────────────────────
function showToast(msg, type = 'success') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  icon.className   = `fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}`;
  icon.style.color = type === 'error' ? 'var(--coral)' : 'var(--green2)';
  t.className = `toast show ${type}`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 3400);
}

// ── Mobile sidebar ─────────────────────────────────────────
function openSidebar()  {
  document.getElementById('cdSidebar').classList.add('open');
  document.getElementById('sidebarOverlay').classList.add('show');
}
function closeSidebar() {
  document.getElementById('cdSidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('show');
}
</script>

</body>
</html>