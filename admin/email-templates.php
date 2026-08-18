<?php
require_once 'auth_check.php';
$adminUser = checkAdminAuth();
require_once '../config/database.php';

$conn       = getDBConnection();
$activePage = 'email-templates';

// ── Auto-create table ─────────────────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS email_templates (
        id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        slug        VARCHAR(64)     NOT NULL COMMENT 'machine-readable identifier',
        name        VARCHAR(128)    NOT NULL COMMENT 'display name',
        description VARCHAR(255)   NULL,
        subject     VARCHAR(255)   NOT NULL,
        html_body   MEDIUMTEXT     NOT NULL,
        text_body   TEXT           NULL     COMMENT 'plain-text fallback',
        variables   TEXT           NULL     COMMENT 'JSON array of available variable names',
        is_active   TINYINT(1)     NOT NULL DEFAULT 1,
        is_system   TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'system templates cannot be deleted',
        last_edited_by  INT UNSIGNED NULL,
        created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$conn->query("
    CREATE TABLE IF NOT EXISTS email_send_log (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        template_id INT UNSIGNED    NULL,
        recipient   VARCHAR(320)   NOT NULL,
        subject     VARCHAR(255)   NOT NULL,
        status      ENUM('sent','failed') NOT NULL DEFAULT 'sent',
        error       VARCHAR(512)   NULL,
        sent_by     INT UNSIGNED   NULL COMMENT 'admin_users.id — NULL = system',
        sent_at     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_log_template (template_id),
        KEY idx_log_sent     (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Seed default system templates if table is empty ───────────
$existing = (int)($conn->query("SELECT COUNT(*) as c FROM email_templates")?->fetch_assoc()['c'] ?? 0);
if ($existing === 0) {
    $systemTemplates = [
        [
            'slug'        => 'welcome',
            'name'        => 'Welcome email',
            'description' => 'Sent to new users after registration',
            'subject'     => 'Welcome to CheckDomain, {{first_name}}! 🎉',
            'variables'   => '["first_name","email","dashboard_url","site_name","site_url"]',
            'is_system'   => 1,
            'html_body'   => '<div style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Arial,sans-serif;background:#fff;">
  <div style="background:linear-gradient(135deg,#0D1117,#1D9E75);padding:40px 30px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:28px;letter-spacing:-.5px;">Welcome to CheckDomain</h1>
    <p style="color:rgba(255,255,255,.7);margin:8px 0 0;font-size:14px;">Your domain intelligence platform</p>
  </div>
  <div style="padding:36px 32px;background:#f9fafb;">
    <p style="font-size:16px;color:#111;">Hi <strong>{{first_name}}</strong> 👋</p>
    <p style="color:#374151;line-height:1.7;">Thanks for joining CheckDomain. Your account is ready — start checking domain availability, monitoring watchlists, and tracking expiring names.</p>
    <div style="margin:28px 0;text-align:center;">
      <a href="{{dashboard_url}}" style="background:#1D9E75;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:700;font-size:14px;display:inline-block;">Go to your dashboard →</a>
    </div>
    <table style="width:100%;border-collapse:separate;border-spacing:8px;">
      <tr>
        <td style="background:#fff;border:1px solid #E5E7EB;border-radius:8px;padding:16px;text-align:center;width:33%;">
          <div style="font-size:24px;">🔍</div>
          <div style="font-size:12px;color:#6B7280;margin-top:4px;">Search domains</div>
        </td>
        <td style="background:#fff;border:1px solid #E5E7EB;border-radius:8px;padding:16px;text-align:center;width:33%;">
          <div style="font-size:24px;">📌</div>
          <div style="font-size:12px;color:#6B7280;margin-top:4px;">Watchlist alerts</div>
        </td>
        <td style="background:#fff;border:1px solid #E5E7EB;border-radius:8px;padding:16px;text-align:center;width:33%;">
          <div style="font-size:24px;">⚡</div>
          <div style="font-size:12px;color:#6B7280;margin-top:4px;">10 free credits</div>
        </td>
      </tr>
    </table>
  </div>
  <div style="padding:20px 32px;background:#F3F4F6;text-align:center;">
    <p style="font-size:12px;color:#9CA3AF;margin:0;">© {{site_name}} · <a href="{{site_url}}" style="color:#1D9E75;text-decoration:none;">{{site_url}}</a></p>
  </div>
</div>',
            'text_body'   => "Hi {{first_name}},\n\nWelcome to CheckDomain! Your account is ready.\n\nDashboard: {{dashboard_url}}\n\n{{site_name}}",
        ],
        [
            'slug'        => 'domain_available',
            'name'        => 'Domain available alert',
            'description' => 'Sent when a watched domain becomes available',
            'subject'     => '🟢 {{domain_name}} is now available!',
            'variables'   => '["first_name","domain_name","register_url","watchlist_url","site_name","site_url"]',
            'is_system'   => 1,
            'html_body'   => '<div style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Arial,sans-serif;background:#fff;">
  <div style="background:linear-gradient(135deg,#065F46,#1D9E75);padding:36px 30px;text-align:center;">
    <div style="font-size:36px;margin-bottom:8px;">🎯</div>
    <h1 style="color:#fff;margin:0;font-size:24px;">Domain Available!</h1>
  </div>
  <div style="padding:36px 32px;background:#f9fafb;">
    <p style="font-size:16px;color:#111;">Hi <strong>{{first_name}}</strong>,</p>
    <p style="color:#374151;line-height:1.7;">A domain you were watching just became available:</p>
    <div style="background:#fff;border:2px solid #1D9E75;border-radius:12px;padding:24px;text-align:center;margin:24px 0;">
      <div style="font-family:monospace;font-size:22px;font-weight:800;color:#111;">{{domain_name}}</div>
      <div style="background:#D1FAE5;color:#065F46;font-size:11px;font-weight:700;padding:3px 10px;border-radius:9999px;display:inline-block;margin-top:8px;text-transform:uppercase;">Available now</div>
    </div>
    <p style="color:#6B7280;font-size:13px;text-align:center;">Act quickly — domains can be registered by anyone at any time.</p>
    <div style="text-align:center;margin:24px 0;">
      <a href="{{register_url}}" style="background:#1D9E75;color:#fff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:700;font-size:14px;display:inline-block;margin-right:8px;">Register now →</a>
      <a href="{{watchlist_url}}" style="background:#F3F4F6;color:#374151;text-decoration:none;padding:14px 20px;border-radius:8px;font-weight:600;font-size:13px;display:inline-block;">View watchlist</a>
    </div>
  </div>
  <div style="padding:20px 32px;background:#F3F4F6;text-align:center;">
    <p style="font-size:12px;color:#9CA3AF;margin:0;">© {{site_name}} · <a href="{{site_url}}" style="color:#1D9E75;text-decoration:none;">Manage alerts</a></p>
  </div>
</div>',
            'text_body'   => "Hi {{first_name}},\n\n{{domain_name}} is now AVAILABLE!\n\nRegister it: {{register_url}}\n\n{{site_name}}",
        ],
        [
            'slug'        => 'expiry_alert',
            'name'        => 'Domain expiry alert',
            'description' => 'Sent when a watched domain is expiring soon',
            'subject'     => '⏰ {{domain_name}} expires in {{days_left}} days',
            'variables'   => '["first_name","domain_name","days_left","expiry_date","whois_url","backorder_url","site_name","site_url"]',
            'is_system'   => 1,
            'html_body'   => '<div style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Arial,sans-serif;background:#fff;">
  <div style="background:linear-gradient(135deg,#92400E,#EF9F27);padding:36px 30px;text-align:center;">
    <div style="font-size:36px;margin-bottom:8px;">⏰</div>
    <h1 style="color:#fff;margin:0;font-size:24px;">Expiring Soon</h1>
  </div>
  <div style="padding:36px 32px;background:#f9fafb;">
    <p style="font-size:16px;color:#111;">Hi <strong>{{first_name}}</strong>,</p>
    <p style="color:#374151;line-height:1.7;">A domain on your watchlist is expiring soon:</p>
    <div style="background:#fff;border:2px solid #EF9F27;border-radius:12px;padding:24px;text-align:center;margin:24px 0;">
      <div style="font-family:monospace;font-size:22px;font-weight:800;color:#111;">{{domain_name}}</div>
      <div style="margin-top:10px;">
        <span style="background:#FEF3C7;color:#92400E;font-size:12px;font-weight:700;padding:4px 12px;border-radius:9999px;">Expires {{expiry_date}} · {{days_left}} days left</span>
      </div>
    </div>
    <p style="color:#374151;line-height:1.7;">After expiry there\'s typically a <strong>30–45 day redemption period</strong> before the domain drops. This is your opportunity to place a backorder.</p>
    <div style="text-align:center;margin:24px 0;">
      <a href="{{backorder_url}}" style="background:#EF9F27;color:#fff;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:700;font-size:14px;display:inline-block;margin-right:8px;">Place backorder →</a>
      <a href="{{whois_url}}" style="background:#F3F4F6;color:#374151;text-decoration:none;padding:14px 20px;border-radius:8px;font-weight:600;font-size:13px;display:inline-block;">WHOIS lookup</a>
    </div>
  </div>
  <div style="padding:20px 32px;background:#F3F4F6;text-align:center;">
    <p style="font-size:12px;color:#9CA3AF;margin:0;">© {{site_name}} · <a href="{{site_url}}" style="color:#1D9E75;text-decoration:none;">Manage alerts</a></p>
  </div>
</div>',
            'text_body'   => "Hi {{first_name}},\n\n{{domain_name}} expires in {{days_left}} days ({{expiry_date}}).\n\nPlace a backorder: {{backorder_url}}\n\n{{site_name}}",
        ],
        [
            'slug'        => 'payment_receipt',
            'name'        => 'Payment receipt',
            'description' => 'Sent after a successful payment or subscription',
            'subject'     => 'Your receipt for {{plan_name}} — CheckDomain',
            'variables'   => '["first_name","email","plan_name","amount","billing_date","next_billing_date","invoice_number","billing_url","site_name","site_url"]',
            'is_system'   => 1,
            'html_body'   => '<div style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Arial,sans-serif;background:#fff;">
  <div style="background:#111318;padding:30px;text-align:center;">
    <h1 style="color:#fff;margin:0;font-size:22px;letter-spacing:-.3px;">CheckDomain</h1>
    <p style="color:#4ADE80;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;margin:4px 0 0;">Payment confirmed</p>
  </div>
  <div style="padding:36px 32px;">
    <p style="font-size:15px;color:#111;">Hi <strong>{{first_name}}</strong>,</p>
    <p style="color:#374151;">Your payment has been processed successfully. Here is your receipt.</p>
    <table style="width:100%;border-collapse:collapse;margin:24px 0;background:#F9FAFB;border-radius:10px;overflow:hidden;">
      <tr style="border-bottom:1px solid #E5E7EB;">
        <td style="padding:14px 16px;color:#6B7280;font-size:13px;">Invoice</td>
        <td style="padding:14px 16px;font-weight:600;text-align:right;font-family:monospace;">{{invoice_number}}</td>
      </tr>
      <tr style="border-bottom:1px solid #E5E7EB;">
        <td style="padding:14px 16px;color:#6B7280;font-size:13px;">Plan</td>
        <td style="padding:14px 16px;font-weight:600;text-align:right;">{{plan_name}}</td>
      </tr>
      <tr style="border-bottom:1px solid #E5E7EB;">
        <td style="padding:14px 16px;color:#6B7280;font-size:13px;">Amount paid</td>
        <td style="padding:14px 16px;font-weight:700;text-align:right;color:#1D9E75;font-size:16px;">{{amount}}</td>
      </tr>
      <tr style="border-bottom:1px solid #E5E7EB;">
        <td style="padding:14px 16px;color:#6B7280;font-size:13px;">Payment date</td>
        <td style="padding:14px 16px;font-weight:600;text-align:right;font-family:monospace;">{{billing_date}}</td>
      </tr>
      <tr>
        <td style="padding:14px 16px;color:#6B7280;font-size:13px;">Next billing</td>
        <td style="padding:14px 16px;font-weight:600;text-align:right;font-family:monospace;">{{next_billing_date}}</td>
      </tr>
    </table>
    <div style="text-align:center;">
      <a href="{{billing_url}}" style="background:#111318;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;font-size:13px;display:inline-block;">View billing history →</a>
    </div>
  </div>
  <div style="padding:20px 32px;background:#F3F4F6;text-align:center;">
    <p style="font-size:12px;color:#9CA3AF;margin:0;">Questions? Reply to this email. © {{site_name}}</p>
  </div>
</div>',
            'text_body'   => "Hi {{first_name}},\n\nPayment confirmed.\n\nPlan: {{plan_name}}\nAmount: {{amount}}\nInvoice: {{invoice_number}}\nDate: {{billing_date}}\nNext billing: {{next_billing_date}}\n\nView billing: {{billing_url}}\n\n{{site_name}}",
        ],
        [
            'slug'        => 'password_reset',
            'name'        => 'Password reset',
            'description' => 'Sent when a user requests a password reset',
            'subject'     => 'Reset your CheckDomain password',
            'variables'   => '["first_name","reset_url","expiry_minutes","site_name","site_url"]',
            'is_system'   => 1,
            'html_body'   => '<div style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Arial,sans-serif;background:#fff;">
  <div style="background:#111318;padding:36px 30px;text-align:center;">
    <div style="font-size:36px;margin-bottom:8px;">🔐</div>
    <h1 style="color:#fff;margin:0;font-size:22px;">Password Reset</h1>
  </div>
  <div style="padding:36px 32px;background:#f9fafb;">
    <p style="font-size:16px;color:#111;">Hi <strong>{{first_name}}</strong>,</p>
    <p style="color:#374151;line-height:1.7;">We received a request to reset your password. Click the button below to set a new password. This link expires in <strong>{{expiry_minutes}} minutes</strong>.</p>
    <div style="text-align:center;margin:32px 0;">
      <a href="{{reset_url}}" style="background:#2563EB;color:#fff;text-decoration:none;padding:14px 36px;border-radius:8px;font-weight:700;font-size:15px;display:inline-block;">Reset my password →</a>
    </div>
    <p style="color:#6B7280;font-size:13px;text-align:center;">If you didn\'t request this, you can safely ignore this email. Your password won\'t change.</p>
    <div style="background:#FEF2F2;border:1px solid #FCA5A5;border-radius:8px;padding:14px 16px;margin-top:20px;">
      <p style="color:#DC2626;font-size:12px;margin:0;"><strong>Security tip:</strong> We will never ask for your password by email. If you\'re unsure, contact support.</p>
    </div>
  </div>
  <div style="padding:20px 32px;background:#F3F4F6;text-align:center;">
    <p style="font-size:12px;color:#9CA3AF;margin:0;">© {{site_name}} · <a href="{{site_url}}" style="color:#1D9E75;text-decoration:none;">{{site_url}}</a></p>
  </div>
</div>',
            'text_body'   => "Hi {{first_name}},\n\nReset your password here (expires in {{expiry_minutes}} minutes):\n{{reset_url}}\n\nIf you didn't request this, ignore this email.\n\n{{site_name}}",
        ],
        [
            'slug'        => 'broker_update',
            'name'        => 'Broker request update',
            'description' => 'Sent when the broker team posts an update on a request',
            'subject'     => 'Update on your broker request for {{domain_name}}',
            'variables'   => '["first_name","domain_name","status_label","update_message","broker_url","site_name","site_url"]',
            'is_system'   => 1,
            'html_body'   => '<div style="max-width:600px;margin:0 auto;font-family:\'Segoe UI\',Arial,sans-serif;background:#fff;">
  <div style="background:linear-gradient(135deg,#1E1B4B,#7F77DD);padding:36px 30px;text-align:center;">
    <div style="font-size:32px;margin-bottom:8px;">🤝</div>
    <h1 style="color:#fff;margin:0;font-size:22px;">Broker Update</h1>
  </div>
  <div style="padding:36px 32px;background:#f9fafb;">
    <p style="font-size:16px;color:#111;">Hi <strong>{{first_name}}</strong>,</p>
    <p style="color:#374151;line-height:1.7;">There&rsquo;s a new update on your broker request for:</p>
    <div style="background:#fff;border:2px solid #7F77DD;border-radius:12px;padding:20px;text-align:center;margin:20px 0;">
      <div style="font-family:monospace;font-size:20px;font-weight:800;color:#111;">{{domain_name}}</div>
      <div style="background:#EDE9FE;color:#5B21B6;font-size:11px;font-weight:700;padding:3px 10px;border-radius:9999px;display:inline-block;margin-top:8px;text-transform:uppercase;">{{status_label}}</div>
    </div>
    <div style="background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:18px;margin:20px 0;">
      <p style="color:#374151;font-size:14px;line-height:1.7;margin:0;">{{update_message}}</p>
    </div>
    <div style="text-align:center;margin-top:24px;">
      <a href="{{broker_url}}" style="background:#7F77DD;color:#fff;text-decoration:none;padding:13px 28px;border-radius:8px;font-weight:700;font-size:14px;display:inline-block;">View full request →</a>
    </div>
  </div>
  <div style="padding:20px 32px;background:#F3F4F6;text-align:center;">
    <p style="font-size:12px;color:#9CA3AF;margin:0;">© {{site_name}} · <a href="{{site_url}}" style="color:#7F77DD;text-decoration:none;">CheckDomain</a></p>
  </div>
</div>',
            'text_body'   => "Hi {{first_name}},\n\nUpdate on {{domain_name}} broker request:\n\nStatus: {{status_label}}\n\n{{update_message}}\n\nView request: {{broker_url}}\n\n{{site_name}}",
        ],
    ];

    $insT = $conn->prepare("
        INSERT INTO email_templates (slug, name, description, subject, html_body, text_body, variables, is_active, is_system)
        VALUES (?,?,?,?,?,?,?,1,?)
    ");
    foreach ($systemTemplates as $t) {
        $insT->bind_param("sssssssi",
            $t['slug'], $t['name'], $t['description'], $t['subject'],
            $t['html_body'], $t['text_body'], $t['variables'], $t['is_system']
        );
        $insT->execute();
    }
    $insT->close();
}

// ── Helpers ──────────────────────────────────────────────────
$flash = null;

// ── POST actions ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── Save / create template ────────────────────────────────
    if (in_array($action, ['save', 'create'])) {
        $tId          = (int)($_POST['template_id'] ?? 0);
        $slug         = strtolower(preg_replace('/[^a-z0-9_]/', '', str_replace([' ','-'], '_', trim($_POST['slug'] ?? ''))));
        $name         = substr(strip_tags(trim($_POST['name'] ?? '')), 0, 128);
        $description  = substr(strip_tags(trim($_POST['description'] ?? '')), 0, 255);
        $subject      = substr(trim($_POST['subject'] ?? ''), 0, 255);
        $htmlBody     = trim($_POST['html_body'] ?? '');
        $textBody     = trim($_POST['text_body'] ?? '');
        $isActive     = isset($_POST['is_active']) ? 1 : 0;

        if (!$slug || !$name || !$subject || !$htmlBody) {
            $flash = ['type'=>'err','msg'=>'Slug, name, subject and HTML body are required.']; goto done;
        }

        if ($action === 'save' && $tId) {
            // Check slug uniqueness
            $dup = $conn->prepare("SELECT id FROM email_templates WHERE slug=? AND id!=? LIMIT 1");
            $dup->bind_param("si", $slug, $tId); $dup->execute();
            if ($dup->get_result()->num_rows > 0) { $dup->close(); $flash = ['type'=>'err','msg'=>"Slug '$slug' already used by another template."]; goto done; }
            $dup->close();

            $upd = $conn->prepare("UPDATE email_templates SET slug=?,name=?,description=?,subject=?,html_body=?,text_body=?,is_active=?,last_edited_by=?,updated_at=NOW() WHERE id=?");
            $upd->bind_param("ssssssiii", $slug,$name,$description,$subject,$htmlBody,$textBody,$isActive,$adminUser['id'],$tId);
            $upd->execute(); $upd->close();
            logAdminActivity($adminUser['id'], 'EDIT_EMAIL_TEMPLATE', "Edited template: $slug");
            $flash = ['type'=>'ok','msg'=>"Template <strong>{$name}</strong> saved."];
        } else {
            $dup = $conn->prepare("SELECT id FROM email_templates WHERE slug=? LIMIT 1");
            $dup->bind_param("s", $slug); $dup->execute();
            if ($dup->get_result()->num_rows > 0) { $dup->close(); $flash = ['type'=>'err','msg'=>"Slug '$slug' already exists."]; goto done; }
            $dup->close();

            $ins = $conn->prepare("INSERT INTO email_templates (slug,name,description,subject,html_body,text_body,is_active,is_system,last_edited_by) VALUES (?,?,?,?,?,?,?,0,?)");
            $ins->bind_param("ssssssii", $slug,$name,$description,$subject,$htmlBody,$textBody,$isActive,$adminUser['id']);
            $ins->execute(); $ins->close();
            logAdminActivity($adminUser['id'], 'CREATE_EMAIL_TEMPLATE', "Created template: $slug");
            $flash = ['type'=>'ok','msg'=>"Template <strong>{$name}</strong> created."];
        }
    }

    // ── Toggle active ─────────────────────────────────────────
    elseif ($action === 'toggle') {
        $tId    = (int)($_POST['template_id'] ?? 0);
        $toggle = (int)($_POST['toggle'] ?? 0);
        $conn->prepare("UPDATE email_templates SET is_active=? WHERE id=?")->bind_param("ii",$toggle,$tId)->execute();
        $upd = $conn->prepare("UPDATE email_templates SET is_active=? WHERE id=?");
        $upd->bind_param("ii",$toggle,$tId); $upd->execute(); $upd->close();
        $flash = ['type'=>'ok','msg'=>"Template " . ($toggle ? 'activated' : 'deactivated') . "."];
    }

    // ── Delete (non-system only) ───────────────────────────────
    elseif ($action === 'delete') {
        $tId = (int)($_POST['template_id'] ?? 0);
        $chk = $conn->prepare("SELECT is_system, name FROM email_templates WHERE id=? LIMIT 1");
        $chk->bind_param("i",$tId); $chk->execute();
        $t = $chk->get_result()->fetch_assoc(); $chk->close();
        if ($t['is_system']) {
            $flash = ['type'=>'err','msg'=>'System templates cannot be deleted.'];
        } else {
            $conn->prepare("DELETE FROM email_templates WHERE id=? AND is_system=0")->bind_param("i",$tId)->execute();
            $del = $conn->prepare("DELETE FROM email_templates WHERE id=? AND is_system=0");
            $del->bind_param("i",$tId); $del->execute(); $del->close();
            logAdminActivity($adminUser['id'], 'DELETE_EMAIL_TEMPLATE', "Deleted template: ".($t['name']??'#'.$tId));
            $flash = ['type'=>'ok','msg'=>"Template deleted."];
        }
    }

    // ── Send test email ────────────────────────────────────────
    elseif ($action === 'send_test') {
        $tId      = (int)($_POST['template_id'] ?? 0);
        $testEmail = trim($_POST['test_email'] ?? '');
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $flash = ['type'=>'err','msg'=>'Enter a valid test email address.']; goto done;
        }

        $tStmt = $conn->prepare("SELECT subject, html_body FROM email_templates WHERE id=? LIMIT 1");
        $tStmt->bind_param("i", $tId); $tStmt->execute();
        $tmpl = $tStmt->get_result()->fetch_assoc(); $tStmt->close();

        if (!$tmpl) { $flash = ['type'=>'err','msg'=>'Template not found.']; goto done; }

        // Replace variables with sample values
        $sampleVars = [
            '{{first_name}}'       => 'Samuel',
            '{{email}}'            => $testEmail,
            '{{domain_name}}'      => 'mybrand.ng',
            '{{plan_name}}'        => 'Pro plan',
            '{{amount}}'           => '$9',
            '{{billing_date}}'     => date('M j, Y'),
            '{{next_billing_date}}'=> date('M j, Y', strtotime('+1 month')),
            '{{invoice_number}}'   => 'INV-'.date('Y').'-000042',
            '{{days_left}}'        => '14',
            '{{expiry_date}}'      => date('M j, Y', strtotime('+14 days')),
            '{{status_label}}'     => 'Negotiating',
            '{{update_message}}'   => 'We have made contact with the domain owner and are awaiting a response.',
            '{{expiry_minutes}}'   => '60',
            '{{reset_url}}'        => '#',
            '{{dashboard_url}}'    => '#',
            '{{register_url}}'     => '#',
            '{{backorder_url}}'    => '#',
            '{{whois_url}}'        => '#',
            '{{billing_url}}'      => '#',
            '{{watchlist_url}}'    => '#',
            '{{broker_url}}'       => '#',
            '{{site_name}}'        => 'CheckDomain',
            '{{site_url}}'         => 'https://checkdomain.ng',
        ];

        $subjectRendered = str_replace(array_keys($sampleVars), array_values($sampleVars), $tmpl['subject']);
        $htmlRendered    = str_replace(array_keys($sampleVars), array_values($sampleVars), $tmpl['html_body']);

        $sendResult = ['success' => false, 'error' => 'PHPMailer not configured'];
        if (file_exists('../includes/email.php')) {
            require_once '../includes/email.php';
            $sendResult = sendEmail($testEmail, '[TEST] ' . $subjectRendered, $htmlRendered);
        }

        // Log it
        $logStatus = $sendResult['success'] ? 'sent' : 'failed';
        $logErr    = $sendResult['error'] ?? null;
        $logStmt   = $conn->prepare("INSERT INTO email_send_log (template_id, recipient, subject, status, error, sent_by) VALUES (?,?,?,?,?,?)");
        $logStmt->bind_param("issssi", $tId, $testEmail, $subjectRendered, $logStatus, $logErr, $adminUser['id']);
        $logStmt->execute(); $logStmt->close();

        $flash = $sendResult['success']
            ? ['type'=>'ok',  'msg'=>"Test email sent to <strong>{$testEmail}</strong>."]
            : ['type'=>'warn','msg'=>"Email sending failed: ".htmlspecialchars($sendResult['error']??'Unknown error').". Log entry saved."];
    }

    done:
}

// ── AJAX: render preview HTML ─────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'preview') {
    $tId   = (int)($_GET['id'] ?? 0);
    $tStmt = $conn->prepare("SELECT html_body FROM email_templates WHERE id=? LIMIT 1");
    $tStmt->bind_param("i",$tId); $tStmt->execute();
    $html  = $tStmt->get_result()->fetch_assoc()['html_body'] ?? '';
    $tStmt->close();
    $conn->close();

    $vars = ['{{first_name}}'=>'Samuel','{{email}}'=>'user@example.com','{{domain_name}}'=>'mybrand.ng','{{plan_name}}'=>'Pro plan','{{amount}}'=>'$9','{{billing_date}}'=>date('M j, Y'),'{{next_billing_date}}'=>date('M j, Y',strtotime('+1 month')),'{{invoice_number}}'=>'INV-2025-000042','{{days_left}}'=>'14','{{expiry_date}}'=>date('M j, Y',strtotime('+14 days')),'{{status_label}}'=>'Negotiating','{{update_message}}'=>'We have made contact with the domain owner.','{{expiry_minutes}}'=>'60','{{reset_url}}'=>'#','{{dashboard_url}}'=>'#','{{register_url}}'=>'#','{{backorder_url}}'=>'#','{{whois_url}}'=>'#','{{billing_url}}'=>'#','{{watchlist_url}}'=>'#','{{broker_url}}'=>'#','{{site_name}}'=>'CheckDomain','{{site_url}}'=>'https://checkdomain.ng'];
    echo str_replace(array_keys($vars), array_values($vars), $html);
    exit();
}

// ── Fetch all templates ────────────────────────────────────────
$templates = [];
$tRows = $conn->query("
    SELECT t.*, a.username as last_edited_name,
           (SELECT COUNT(*) FROM email_send_log l WHERE l.template_id=t.id) as send_count,
           (SELECT COUNT(*) FROM email_send_log l WHERE l.template_id=t.id AND l.status='sent') as sent_count
    FROM email_templates t
    LEFT JOIN admin_users a ON a.id=t.last_edited_by
    ORDER BY t.is_system DESC, t.name ASC
");
while ($r = $tRows->fetch_assoc()) $templates[] = $r;

// Stats
$statTemplates = count($templates);
$statActive    = count(array_filter($templates, fn($t) => $t['is_active']));
$statSentTotal = (int)($conn->query("SELECT COALESCE(SUM(send_count),0) as v FROM (SELECT (SELECT COUNT(*) FROM email_send_log l WHERE l.template_id=t.id) as send_count FROM email_templates t) sub")?->fetch_assoc()['v'] ?? 0);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Email Templates — CheckDomain Admin</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0F172A;font-family:'Inter',sans-serif;overflow-x:hidden;color:#fff}
.stat-card{background:linear-gradient(135deg,rgba(30,58,138,.3),rgba(16,185,129,.1));backdrop-filter:blur(10px);border:1px solid rgba(59,130,246,.3);transition:all .3s}
.stat-card:hover{transform:translateY(-2px);border-color:rgba(16,185,129,.5)}
.main-content{transition:margin-left .3s}

/* Modals */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);z-index:100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-backdrop.open{opacity:1;pointer-events:all}
.modal-box{background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:1rem;padding:1.75rem;width:90%;transform:scale(.96);transition:transform .2s;max-height:92vh;overflow-y:auto}
.modal-backdrop.open .modal-box{transform:scale(1)}

/* Flash */
.flash-ok  {background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#34D399}
.flash-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#FCD34D}
.flash-err {background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.3); color:#FCA5A5}

/* Template cards */
.tpl-card{background:#1E293B;border:1px solid rgba(71,85,105,.6);border-radius:14px;transition:border-color .15s,transform .15s;overflow:hidden}
.tpl-card:hover{border-color:rgba(59,130,246,.4);transform:translateY(-1px)}
.tpl-card.selected{border-color:#3B82F6;box-shadow:0 0 0 1px #3B82F6}
.tpl-card.inactive{opacity:.6}

/* Code editor */
.code-editor{background:#0D1117;border:1px solid rgba(71,85,105,.7);border-radius:.75rem;font-family:'DM Mono',monospace;font-size:.8rem;color:#E2E8F0;line-height:1.7;padding:14px 16px;width:100%;resize:vertical;outline:none;transition:border-color .2s;tab-size:2}
.code-editor:focus{border-color:#3B82F6}

/* Variable chips */
.var-chip{display:inline-flex;align-items:center;background:#1E3A5F;border:1px solid rgba(59,130,246,.3);border-radius:5px;padding:2px 8px;font-family:'DM Mono',monospace;font-size:.7rem;color:#93C5FD;cursor:pointer;transition:all .13s;margin:2px}
.var-chip:hover{background:rgba(59,130,246,.3);color:#fff}

/* Toggle */
.toggle-wrap{position:relative;width:40px;height:22px;flex-shrink:0}
.toggle-wrap input{opacity:0;width:0;height:0}
.toggle-track{position:absolute;inset:0;background:#334155;border-radius:22px;cursor:pointer;transition:background .2s;border:1px solid rgba(71,85,105,.8)}
.toggle-wrap input:checked+.toggle-track{background:#10B981;border-color:#10B981}
.toggle-track::before{content:'';position:absolute;width:16px;height:16px;border-radius:50%;background:#fff;top:2px;left:2px;transition:transform .2s}
.toggle-wrap input:checked+.toggle-track::before{transform:translateX(18px)}

/* Preview frame */
#previewFrame{width:100%;height:480px;border:none;border-radius:8px;background:#fff}

/* Inputs */
.inp{background:rgba(51,65,85,.8);border:1px solid rgba(71,85,105,1);border-radius:.5rem;padding:.5rem .75rem;color:#fff;width:100%;font-size:.875rem;transition:border-color .2s;outline:none}
.inp:focus{border-color:#3B82F6}
.inp::placeholder{color:#64748B}
.form-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#64748B;margin-bottom:.25rem;display:block}
.form-hint{font-size:.68rem;color:#475569;margin-top:.2rem}
.btn-primary  {background:#2563EB;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:#1D4ED8}
.btn-secondary{background:#334155;color:#CBD5E1;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-secondary:hover{background:#475569}
.btn-danger   {background:#DC2626;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-danger:hover{background:#B91C1C}
.btn-green    {background:#059669;color:#fff;border:none;border-radius:.5rem;padding:.5rem 1.25rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-green:hover{background:#047857}
.btn-sm{padding:.3rem .75rem!important;font-size:.75rem!important}

.badge{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;font-weight:600;padding:2px 7px;border-radius:9999px;white-space:nowrap}
.b-active  {background:rgba(16,185,129,.15);color:#34D399}
.b-inactive{background:rgba(107,114,128,.2);color:#9CA3AF}
.b-system  {background:rgba(59,130,246,.15); color:#93C5FD}
.b-custom  {background:rgba(168,85,247,.15); color:#C4B5FD}

::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:rgba(15,23,42,.5);border-radius:10px}
::-webkit-scrollbar-thumb{background:#3B82F6;border-radius:10px}
::-webkit-scrollbar-thumb:hover{background:#60A5FA}
@media(max-width:768px){.main-content{margin-left:0!important}.p-8{padding:1rem}.hide-mobile{display:none!important}}
</style>
</head>
<body class="text-white">

<?php include_once 'includes/sidebar.php'; ?>

<div class="main-content" style="margin-left:16rem;">
<div class="p-4 md:p-8">

  <!-- Flash -->
  <?php if ($flash): ?>
  <div class="flash-<?= $flash['type'] ?> rounded-xl px-4 py-3 mb-6 flex items-start gap-3 text-sm">
    <i class="fas <?= $flash['type']==='ok'?'fa-check-circle':($flash['type']==='warn'?'fa-exclamation-triangle':'fa-times-circle') ?> mt-0.5 flex-shrink-0"></i>
    <span><?= $flash['msg'] ?></span>
  </div>
  <?php endif; ?>

  <!-- Header -->
  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold">Email Templates</h1>
      <p class="text-gray-400 text-sm mt-1">
        <?= $statTemplates ?> template<?= $statTemplates!==1?'s':'' ?> · <?= $statActive ?> active
      </p>
    </div>
    <div class="flex flex-wrap gap-3">
      <button onclick="openModal('createModal')" class="btn-primary flex items-center gap-2 text-sm">
        <i class="fas fa-plus text-xs"></i> New template
      </button>
    </div>
  </div>

  <!-- Stats -->
  <div class="grid grid-cols-3 gap-4 mb-8">
    <?php foreach ([
      ['lbl'=>'Total templates','val'=>$statTemplates,'icon'=>'fa-file-alt','c'=>'blue'],
      ['lbl'=>'Active',         'val'=>$statActive,    'icon'=>'fa-check-circle','c'=>'green'],
      ['lbl'=>'Emails sent',    'val'=>number_format($statSentTotal),'icon'=>'fa-paper-plane','c'=>'purple'],
    ] as $c):
      $cmap=['blue'=>['bg'=>'bg-blue-500/20','t'=>'text-blue-400'],'green'=>['bg'=>'bg-green-500/20','t'=>'text-green-400'],'purple'=>['bg'=>'bg-purple-500/20','t'=>'text-purple-400']];
      $cl=$cmap[$c['c']]??$cmap['blue'];
    ?>
    <div class="stat-card rounded-xl p-4">
      <div class="flex justify-between items-start">
        <div>
          <p class="text-gray-400 text-xs"><?= $c['lbl'] ?></p>
          <p class="text-2xl font-bold mt-1 <?= $cl['t'] ?>"><?= $c['val'] ?></p>
        </div>
        <div class="w-9 h-9 <?= $cl['bg'] ?> rounded-full flex items-center justify-center flex-shrink-0">
          <i class="fas <?= $c['icon'] ?> <?= $cl['t'] ?> text-sm"></i>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Template cards grid -->
  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
    <?php foreach ($templates as $t):
      $vars = json_decode($t['variables'] ?? '[]', true) ?: [];
    ?>
    <div class="tpl-card <?= !$t['is_active'] ? 'inactive' : '' ?>">
      <!-- Card header -->
      <div class="flex items-start justify-between gap-3 p-5 border-b border-gray-700/50">
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap mb-1">
            <span class="font-bold text-white text-sm truncate"><?= htmlspecialchars($t['name']) ?></span>
            <span class="badge <?= $t['is_system'] ? 'b-system' : 'b-custom' ?>">
              <?= $t['is_system'] ? 'System' : 'Custom' ?>
            </span>
            <span class="badge <?= $t['is_active'] ? 'b-active' : 'b-inactive' ?>">
              <?= $t['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </div>
          <div class="font-mono text-blue-300 text-xs"><?= htmlspecialchars($t['slug']) ?></div>
          <?php if ($t['description']): ?>
          <div class="text-gray-400 text-xs mt-1 leading-relaxed"><?= htmlspecialchars($t['description']) ?></div>
          <?php endif; ?>
        </div>
        <!-- Active toggle -->
        <form method="POST" class="flex-shrink-0 mt-1">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
          <input type="hidden" name="toggle" value="<?= $t['is_active'] ? '0' : '1' ?>">
          <label class="toggle-wrap cursor-pointer" title="<?= $t['is_active']?'Deactivate':'Activate' ?>">
            <input type="checkbox" <?= $t['is_active']?'checked':'' ?> onchange="this.form.submit()">
            <span class="toggle-track"></span>
          </label>
        </form>
      </div>

      <!-- Subject preview -->
      <div class="px-5 py-3 bg-slate-900/40 border-b border-gray-700/40">
        <div class="text-gray-500 text-xs uppercase tracking-wide mb-0.5">Subject</div>
        <div class="text-gray-200 text-xs font-mono truncate"><?= htmlspecialchars($t['subject']) ?></div>
      </div>

      <!-- Variables -->
      <?php if (!empty($vars)): ?>
      <div class="px-5 py-3 border-b border-gray-700/40">
        <div class="text-gray-500 text-xs uppercase tracking-wide mb-1.5">Variables</div>
        <div class="flex flex-wrap gap-1">
          <?php foreach ($vars as $v): ?>
          <span class="var-chip" onclick="copyText('{{<?= $v ?>}}')" title="Click to copy">{{<?= $v ?>}}</span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Footer -->
      <div class="px-5 py-3 flex items-center justify-between gap-3">
        <div class="text-gray-600 text-xs">
          <?php if ($t['sent_count'] > 0): ?>
          <i class="fas fa-paper-plane text-xs mr-1"></i><?= number_format($t['sent_count']) ?> sent
          <?php endif; ?>
          <?php if ($t['last_edited_name']): ?>
          <span class="ml-2">· <?= htmlspecialchars($t['last_edited_name']) ?></span>
          <?php endif; ?>
        </div>
        <div class="flex gap-1.5">
          <!-- Preview -->
          <button onclick="openPreview(<?= (int)$t['id'] ?>, '<?= htmlspecialchars($t['name'],ENT_QUOTES) ?>')"
                  class="w-7 h-7 bg-green-500/20 hover:bg-green-500/30 rounded-lg flex items-center justify-center text-green-400 transition text-xs" title="Preview">
            <i class="fas fa-eye"></i>
          </button>
          <!-- Edit -->
          <button onclick="openEditModal(<?= htmlspecialchars(json_encode($t),ENT_QUOTES) ?>)"
                  class="w-7 h-7 bg-blue-500/20 hover:bg-blue-500/30 rounded-lg flex items-center justify-center text-blue-400 transition text-xs" title="Edit">
            <i class="fas fa-edit"></i>
          </button>
          <!-- Send test -->
          <button onclick="openTestModal(<?= (int)$t['id'] ?>, '<?= htmlspecialchars($t['name'],ENT_QUOTES) ?>')"
                  class="w-7 h-7 bg-amber-500/20 hover:bg-amber-500/30 rounded-lg flex items-center justify-center text-amber-400 transition text-xs" title="Send test">
            <i class="fas fa-paper-plane"></i>
          </button>
          <!-- Delete (non-system) -->
          <?php if (!$t['is_system']): ?>
          <button onclick="openDeleteModal(<?= (int)$t['id'] ?>, '<?= htmlspecialchars($t['name'],ENT_QUOTES) ?>')"
                  class="w-7 h-7 bg-red-500/20 hover:bg-red-500/30 rounded-lg flex items-center justify-center text-red-400 transition text-xs" title="Delete">
            <i class="fas fa-trash"></i>
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>
</div>

<!-- ═══════════════════════════════
     MODALS
═══════════════════════════════ -->

<!-- Edit modal -->
<div class="modal-backdrop" id="editModal">
  <div class="modal-box" style="max-width:820px;">
    <div class="flex items-center justify-between mb-5">
      <div>
        <h2 class="text-lg font-bold" id="editModalTitle">Edit template</h2>
        <div class="text-gray-400 text-xs mt-0.5" id="editModalSub"></div>
      </div>
      <button onclick="closeModal('editModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="editForm" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="template_id" id="ef-id">

      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Name <span class="text-red-400">*</span></label>
          <input class="inp" type="text" name="name" id="ef-name" maxlength="128" required>
        </div>
        <div>
          <label class="form-label">Slug <span class="text-red-400">*</span></label>
          <input class="inp font-mono" type="text" name="slug" id="ef-slug" maxlength="64" required
                 pattern="[a-z0-9_]+" title="lowercase letters, numbers and underscores only">
          <p class="form-hint">lowercase_with_underscores — used in code to reference this template</p>
        </div>
      </div>

      <div>
        <label class="form-label">Description</label>
        <input class="inp" type="text" name="description" id="ef-description" maxlength="255" placeholder="When is this email sent?">
      </div>

      <div>
        <label class="form-label">Subject line <span class="text-red-400">*</span></label>
        <input class="inp" type="text" name="subject" id="ef-subject" maxlength="255" required
               placeholder="e.g. Your domain {{domain_name}} is now available!">
      </div>

      <!-- Tabs: HTML / Plain text -->
      <div>
        <div class="flex gap-1 mb-2">
          <button type="button" onclick="switchTab('html')" id="tab-html"
                  class="px-4 py-1.5 rounded-t text-xs font-semibold bg-slate-700 text-white border-b-2 border-blue-500 transition">
            HTML body
          </button>
          <button type="button" onclick="switchTab('text')" id="tab-text"
                  class="px-4 py-1.5 rounded-t text-xs font-semibold bg-slate-900 text-gray-400 border-b-2 border-transparent transition">
            Plain text
          </button>
        </div>
        <div id="panel-html">
          <label class="form-label">HTML body <span class="text-red-400">*</span></label>
          <textarea class="code-editor" name="html_body" id="ef-html" rows="18" required
                    placeholder="Full HTML email body. Use {{variable_name}} for dynamic content."></textarea>
          <p class="form-hint">Use {{variable_name}} for dynamic content. Click a variable chip above to copy it.</p>
        </div>
        <div id="panel-text" class="hidden">
          <label class="form-label">Plain text fallback</label>
          <textarea class="code-editor" name="text_body" id="ef-text" rows="10"
                    placeholder="Plain text version shown in email clients that don't support HTML."></textarea>
        </div>
      </div>

      <!-- Variables display -->
      <div id="ef-vars-section" class="bg-slate-900/50 rounded-lg p-3">
        <div class="text-gray-500 text-xs uppercase tracking-wide mb-2">Available variables — click to insert into cursor position</div>
        <div id="ef-vars-chips"></div>
      </div>

      <div class="flex items-center gap-4 pt-1">
        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-300">
          <input type="checkbox" name="is_active" id="ef-active" class="w-4 h-4 accent-blue-500">
          Active (send using this template)
        </label>
      </div>

      <div class="flex gap-3 justify-end pt-3 border-t border-gray-700">
        <button type="button" onclick="previewFromEditor()" class="btn-secondary flex items-center gap-2">
          <i class="fas fa-eye text-xs"></i> Preview
        </button>
        <button type="button" onclick="closeModal('editModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary flex items-center gap-2">
          <i class="fas fa-save text-xs"></i> Save template
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Create modal (identical form, different action) -->
<div class="modal-backdrop" id="createModal">
  <div class="modal-box" style="max-width:820px;">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-bold">New email template</h2>
      <button onclick="closeModal('createModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="create">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="form-label">Name <span class="text-red-400">*</span></label>
          <input class="inp" type="text" name="name" required maxlength="128" placeholder="e.g. Subscription canceled">
        </div>
        <div>
          <label class="form-label">Slug <span class="text-red-400">*</span></label>
          <input class="inp font-mono" type="text" name="slug" required maxlength="64"
                 pattern="[a-z0-9_]+" placeholder="e.g. subscription_canceled"
                 oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9_]/g,'')">
        </div>
      </div>
      <div>
        <label class="form-label">Description</label>
        <input class="inp" type="text" name="description" maxlength="255" placeholder="When is this email sent?">
      </div>
      <div>
        <label class="form-label">Subject line <span class="text-red-400">*</span></label>
        <input class="inp" type="text" name="subject" maxlength="255" required
               placeholder="e.g. Your subscription to CheckDomain has been canceled">
      </div>
      <div>
        <label class="form-label">HTML body <span class="text-red-400">*</span></label>
        <textarea class="code-editor" name="html_body" rows="16" required
                  placeholder="<!DOCTYPE html>&#10;<html>&#10;  ...&#10;</html>"></textarea>
      </div>
      <div>
        <label class="form-label">Plain text fallback</label>
        <textarea class="code-editor" name="text_body" rows="5"
                  placeholder="Plain text version…"></textarea>
      </div>
      <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-300">
        <input type="checkbox" name="is_active" checked class="w-4 h-4 accent-blue-500"> Active
      </label>
      <div class="flex gap-3 justify-end pt-3 border-t border-gray-700">
        <button type="button" onclick="closeModal('createModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-primary flex items-center gap-2">
          <i class="fas fa-plus text-xs"></i> Create template
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Preview modal -->
<div class="modal-backdrop" id="previewModal">
  <div class="modal-box" style="max-width:680px;">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold" id="previewTitle">Preview</h2>
      <button onclick="closeModal('previewModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <div class="bg-slate-900 rounded-xl p-2 mb-2">
      <iframe id="previewFrame" sandbox="allow-same-origin"></iframe>
    </div>
    <p class="text-gray-600 text-xs text-center">Variables are replaced with sample values for preview.</p>
    <div class="flex justify-end mt-4">
      <button onclick="closeModal('previewModal')" class="btn-secondary btn-sm">Close</button>
    </div>
  </div>
</div>

<!-- Send test modal -->
<div class="modal-backdrop" id="testModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-amber-400"><i class="fas fa-paper-plane mr-2"></i>Send test email</h2>
      <button onclick="closeModal('testModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-400 text-sm mb-4">
      Send a test of <strong id="test-name" class="text-white"></strong> with sample variable values to an email address.
    </p>
    <form method="POST" class="flex flex-col gap-4">
      <input type="hidden" name="action" value="send_test">
      <input type="hidden" name="template_id" id="test-id">
      <div>
        <label class="form-label">Send to <span class="text-red-400">*</span></label>
        <input class="inp" type="email" name="test_email" id="test-email-input"
               placeholder="your@email.com" required>
        <p class="form-hint">Variables will be replaced with sample values (name=Samuel, domain=mybrand.ng, etc.)</p>
      </div>
      <div class="bg-blue-500/10 border border-blue-500/20 rounded-lg px-3 py-2 text-blue-300 text-xs">
        <i class="fas fa-info-circle mr-1"></i>
        This uses your configured SMTP settings from <code>config/smtp.php</code>. The send is logged in email_send_log.
      </div>
      <div class="flex gap-3 justify-end pt-2">
        <button type="button" onclick="closeModal('testModal')" class="btn-secondary">Cancel</button>
        <button type="submit" class="btn-amber flex items-center gap-2">
          <i class="fas fa-paper-plane text-xs"></i> Send test
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Delete modal -->
<div class="modal-backdrop" id="deleteModal">
  <div class="modal-box">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-bold text-red-400"><i class="fas fa-trash mr-2"></i>Delete template</h2>
      <button onclick="closeModal('deleteModal')" class="text-gray-400 hover:text-white"><i class="fas fa-times"></i></button>
    </div>
    <p class="text-gray-300 text-sm mb-5">
      Delete template "<span id="del-name" class="text-white font-medium"></span>"? This cannot be undone.
    </p>
    <form method="POST" class="flex gap-3 justify-end">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="template_id" id="del-id">
      <button type="button" onclick="closeModal('deleteModal')" class="btn-secondary">Cancel</button>
      <button type="submit" class="btn-danger flex items-center gap-2">
        <i class="fas fa-trash text-xs"></i> Delete
      </button>
    </form>
  </div>
</div>

<!-- Toast -->
<div id="toast" style="position:fixed;bottom:24px;right:24px;z-index:999;background:#1E293B;border:1px solid rgba(59,130,246,.3);border-radius:10px;padding:12px 18px;font-size:13px;color:#E2E8F0;box-shadow:0 8px 32px rgba(0,0,0,.5);transform:translateY(20px);opacity:0;transition:all .3s ease;display:flex;align-items:center;gap:9px;max-width:340px;">
  <i class="fas fa-check-circle" id="toastIcon" style="color:#10B981;flex-shrink:0;font-size:14px;"></i>
  <span id="toastText"></span>
</div>

<script>
// ── Modal helpers ─────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-backdrop').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ── Edit modal ────────────────────────────────────────────
const ALL_TEMPLATES = <?= json_encode(array_map(fn($t) => ['id'=>$t['id'],'variables'=>json_decode($t['variables']??'[]',true)], $templates)) ?>;

function openEditModal(t) {
  document.getElementById('editModalTitle').textContent = 'Edit — ' + esc(t.name);
  document.getElementById('editModalSub').textContent   = t.slug;
  document.getElementById('ef-id').value          = t.id;
  document.getElementById('ef-name').value        = t.name;
  document.getElementById('ef-slug').value        = t.slug;
  document.getElementById('ef-description').value = t.description || '';
  document.getElementById('ef-subject').value     = t.subject;
  document.getElementById('ef-html').value        = t.html_body;
  document.getElementById('ef-text').value        = t.text_body || '';
  document.getElementById('ef-active').checked    = !!+t.is_active;

  // Populate variable chips
  const vars = Array.isArray(t.variables) ? t.variables : (typeof t.variables === 'string' ? JSON.parse(t.variables||'[]') : []);
  const chipsEl = document.getElementById('ef-vars-chips');
  if (vars.length) {
    chipsEl.innerHTML = vars.map(v => `<span class="var-chip" onclick="insertVar('{{${v}}}')" title="Click to insert">{{${v}}}</span>`).join('');
    document.getElementById('ef-vars-section').style.display = '';
  } else {
    document.getElementById('ef-vars-section').style.display = 'none';
  }

  switchTab('html');
  openModal('editModal');
}

// ── Tab switcher ──────────────────────────────────────────
function switchTab(tab) {
  const htmlPanel = document.getElementById('panel-html');
  const textPanel = document.getElementById('panel-text');
  const btnHtml   = document.getElementById('tab-html');
  const btnText   = document.getElementById('tab-text');
  if (tab === 'html') {
    htmlPanel.classList.remove('hidden'); textPanel.classList.add('hidden');
    btnHtml.classList.add('bg-slate-700','text-white','border-blue-500');
    btnHtml.classList.remove('bg-slate-900','text-gray-400','border-transparent');
    btnText.classList.add('bg-slate-900','text-gray-400','border-transparent');
    btnText.classList.remove('bg-slate-700','text-white','border-blue-500');
  } else {
    textPanel.classList.remove('hidden'); htmlPanel.classList.add('hidden');
    btnText.classList.add('bg-slate-700','text-white','border-blue-500');
    btnText.classList.remove('bg-slate-900','text-gray-400','border-transparent');
    btnHtml.classList.add('bg-slate-900','text-gray-400','border-transparent');
    btnHtml.classList.remove('bg-slate-700','text-white','border-blue-500');
  }
}

// ── Insert variable at cursor ─────────────────────────────
function insertVar(varStr) {
  const ta = document.getElementById('ef-html');
  const start = ta.selectionStart;
  const end   = ta.selectionEnd;
  ta.value = ta.value.substring(0, start) + varStr + ta.value.substring(end);
  ta.focus();
  ta.setSelectionRange(start + varStr.length, start + varStr.length);
  showToast('Inserted: ' + varStr);
}

// ── Preview modal ─────────────────────────────────────────
function openPreview(id, name) {
  document.getElementById('previewTitle').textContent = 'Preview — ' + name;
  const frame = document.getElementById('previewFrame');
  frame.src = '?ajax=preview&id=' + id;
  openModal('previewModal');
}

function previewFromEditor() {
  const html = document.getElementById('ef-html')?.value || '';
  const sampleVars = {
    '{{first_name}}':'Samuel','{{email}}':'user@example.com','{{domain_name}}':'mybrand.ng',
    '{{plan_name}}':'Pro plan','{{amount}}':'$9','{{billing_date}}':new Date().toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}),
    '{{next_billing_date}}':new Date(Date.now()+2592000000).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}),
    '{{invoice_number}}':'INV-2025-000042','{{days_left}}':'14','{{expiry_date}}':'Aug 1, 2025',
    '{{status_label}}':'Negotiating','{{update_message}}':'We have contacted the owner.','{{expiry_minutes}}':'60',
    '{{reset_url}}':'#','{{dashboard_url}}':'#','{{register_url}}':'#','{{backorder_url}}':'#',
    '{{whois_url}}':'#','{{billing_url}}':'#','{{watchlist_url}}':'#','{{broker_url}}':'#',
    '{{site_name}}':'CheckDomain','{{site_url}}':'https://checkdomain.ng',
  };
  let rendered = html;
  for (const [k,v] of Object.entries(sampleVars)) {
    rendered = rendered.split(k).join(v);
  }
  const frame = document.getElementById('previewFrame');
  document.getElementById('previewTitle').textContent = 'Preview';
  frame.srcdoc = rendered;
  openModal('previewModal');
}

// ── Test modal ────────────────────────────────────────────
function openTestModal(id, name) {
  document.getElementById('test-id').value          = id;
  document.getElementById('test-name').textContent  = name;
  openModal('testModal');
}

// ── Delete modal ──────────────────────────────────────────
function openDeleteModal(id, name) {
  document.getElementById('del-id').value          = id;
  document.getElementById('del-name').textContent  = name;
  openModal('deleteModal');
}

// ── Copy to clipboard ─────────────────────────────────────
function copyText(text) {
  navigator.clipboard.writeText(text)
    .then(() => showToast('Copied: ' + text))
    .catch(() => showToast('Could not copy', 'err'));
}

// ── Auto-generate slug from name ──────────────────────────
document.querySelectorAll('input[name="name"]').forEach(nameInput => {
  const form    = nameInput.closest('form');
  const slugIn  = form?.querySelector('input[name="slug"]');
  if (!slugIn) return;
  nameInput.addEventListener('input', () => {
    if (slugIn.dataset.manual) return;
    slugIn.value = nameInput.value.toLowerCase().replace(/\s+/g,'_').replace(/[^a-z0-9_]/g,'');
  });
  slugIn.addEventListener('input', () => slugIn.dataset.manual = '1');
});

// ── Toast ─────────────────────────────────────────────────
function showToast(msg, type='ok') {
  const t=document.getElementById('toast'), icon=document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  const c={ok:'#10B981',warn:'#F59E0B',err:'#EF4444'};
  const i={ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-times-circle'};
  icon.className='fas '+(i[type]||'fa-info-circle'); icon.style.color=c[type]||'#10B981';
  t.style.transform='translateY(0)'; t.style.opacity='1';
  clearTimeout(t._t);
  t._t=setTimeout(()=>{t.style.transform='translateY(20px)';t.style.opacity='0';},4200);
}

function esc(s) {
  return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

<?php if ($flash): ?>
showToast(<?= json_encode(strip_tags($flash['msg'])) ?>, <?= json_encode($flash['type']) ?>);
<?php endif; ?>
</script>

</body>
</html>
