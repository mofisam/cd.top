<?php
/**
 * admin/includes/sidebar.php
 *
 * Drop-in admin sidebar.
 * Requires before include:
 *   - getDBConnection() available (config/database.php already loaded by page)
 *   - Admin auth already checked
 *
 * Optional — set before including to highlight a nav link:
 *   $activePage = 'stats';   // matches the keys in $navGroups below
 */

$currentPage = $activePage ?? basename($_SERVER['PHP_SELF'], '.php');

// ── Live badge counts ──────────────────────────────────────
$conn2 = getDBConnection();

$unreadMessages  = 0;
$unreadAlerts    = 0;
$pendingBackorders  = 0;
$pendingBroker   = 0;
$newUsers        = 0;
$pendingRefunds  = 0;

$safeFetch = function(string $sql) use ($conn2): int {
    $r = @$conn2->query($sql);
    return $r ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
};

$unreadMessages    = $safeFetch("SELECT COUNT(*) as c FROM contact_messages WHERE status='unread'");
$newUsers          = $safeFetch("SELECT COUNT(*) as c FROM users WHERE DATE(created_at) >= CURDATE() - INTERVAL 7 DAY");
$pendingBackorders = $safeFetch("SELECT COUNT(*) as c FROM backorders WHERE status IN ('pending','watching')");
$pendingBroker     = $safeFetch("SELECT COUNT(*) as c FROM broker_requests WHERE status IN ('submitted','researching','outreach','negotiating')");
$pendingRefunds    = $safeFetch("SELECT COUNT(*) as c FROM refunds WHERE status='pending'");
$pendingDomainReports = $safeFetch("SELECT COUNT(*) as c FROM domain_reports WHERE status='pending'");
$unreadAlerts      = $safeFetch("SELECT COUNT(*) as c FROM domain_alerts WHERE status='unread'");

// Total unread badge for topbar
$totalBadge = $unreadMessages + $pendingBroker + $pendingRefunds;

$conn2->close();

// ── Admin user info ────────────────────────────────────────
$adminUsername = $_SESSION['admin_username'] ?? 'Admin';
$adminRole     = $_SESSION['admin_role']     ?? 'admin';
$adminInitial  = strtoupper(substr($adminUsername, 0, 1));

// ── Nav groups ─────────────────────────────────────────────
$navGroups = [

    'Overview' => [
        ['id'=>'stats',            'label'=>'Dashboard',       'icon'=>'fa-tachometer-alt', 'href'=>'stats.php'],
        ['id'=>'activity',         'label'=>'Activity log',    'icon'=>'fa-history',        'href'=>'activity.php'],
    ],

    'Users & Revenue' => [
        ['id'=>'users',            'label'=>'Users',           'icon'=>'fa-users',          'href'=>'users.php',       'badge'=>$newUsers,    'badge_class'=>'green', 'badge_title'=>'New this week'],
        ['id'=>'subscriptions',    'label'=>'Subscriptions',   'icon'=>'fa-credit-card',    'href'=>'subscriptions.php'],
        ['id'=>'payments',         'label'=>'Payments',        'icon'=>'fa-money-bill-wave', 'href'=>'payments.php'],
        ['id'=>'credit-packages',  'label'=>'Credit top-ups',  'icon'=>'fa-coins',           'href'=>'credit-packages.php'],
        ['id'=>'refunds',          'label'=>'Refunds',         'icon'=>'fa-undo',           'href'=>'refunds.php',     'badge'=>$pendingRefunds, 'badge_class'=>'amber'],
        ['id'=>'promo-codes',      'label'=>'Promo codes',     'icon'=>'fa-tags',           'href'=>'promo-codes.php'],
    ],

    'Domain Services' => [
        ['id'=>'backorders',       'label'=>'Backorders',      'icon'=>'fa-clock',          'href'=>'backorders.php',  'badge'=>$pendingBackorders, 'badge_class'=>'amber'],
        ['id'=>'broker',           'label'=>'Broker requests', 'icon'=>'fa-handshake',      'href'=>'broker.php',      'badge'=>$pendingBroker, 'badge_class'=>'coral'],
        ['id'=>'domains',          'label'=>'Watchlist',       'icon'=>'fa-bookmark',       'href'=>'watchlist.php'],
        ['id'=>'dead-sites',       'label'=>'Dead site scans', 'icon'=>'fa-skull',          'href'=>'dead-sites.php'],
        ['id'=>'whois',            'label'=>'WHOIS lookups',   'icon'=>'fa-search',         'href'=>'whois.php'],
        ['id'=>'domain-reports',   'label'=>'Domain Reports',  'icon'=>'fa-file-lines',     'href'=>'domain-reports.php', 'badge'=>$pendingDomainReports, 'badge_class'=>'amber'],
        ['id'=>'alerts',           'label'=>'Alerts',          'icon'=>'fa-bell',           'href'=>'alerts.php',      'badge'=>$unreadAlerts, 'badge_class'=>'amber'],
    ],

    'Analytics' => [
        ['id'=>'search-analytics', 'label'=>'Search analytics','icon'=>'fa-chart-line',     'href'=>'search-analytics.php'],
        ['id'=>'page-views',       'label'=>'Page views',      'icon'=>'fa-eye',            'href'=>'page-views.php'],
        ['id'=>'subscribers',      'label'=>'Subscribers',     'icon'=>'fa-at',             'href'=>'subscribers.php'],
    ],

    'Communications' => [
        ['id'=>'messages',         'label'=>'Messages',        'icon'=>'fa-envelope',       'href'=>'messages.php',    'badge'=>$unreadMessages, 'badge_class'=>'coral'],
        ['id'=>'email-templates',  'label'=>'Email templates', 'icon'=>'fa-file-alt',       'href'=>'email-templates.php'],
    ],

    'System' => [
        ['id'=>'settings',         'label'=>'Settings',        'icon'=>'fa-cog',            'href'=>'settings.php'],
        ['id'=>'backup-database',  'label'=>'Database backup', 'icon'=>'fa-database',       'href'=>'backup-database.php'],
        ['id'=>'export',           'label'=>'Export data',     'icon'=>'fa-download',       'href'=>'export.php'],
    ],

];

$badgeColors = [
    'green' => 'background:rgba(29,158,117,0.15);color:#14C48A;',
    'amber' => 'background:rgba(239,159,39,0.15);color:#EF9F27;',
    'coral' => 'background:rgba(232,89,60,0.15);color:#E8593C;',
    'blue'  => 'background:rgba(74,144,217,0.15);color:#4A90D9;',
];
?>
<style>
/* ── Admin sidebar design system ──────────────────── */
:root{
  --adm-bg:     #080B12;
  --adm-bg2:    #0D1117;
  --adm-bg3:    #131924;
  --adm-border: rgba(59,130,246,0.12);
  --adm-border2:rgba(59,130,246,0.25);
  --adm-text:   #E2E8F0;
  --adm-text2:  #64748B;
  --adm-text3:  #334155;
  --adm-blue:   #3B82F6;
  --adm-blue2:  #60A5FA;
  --adm-blue-bg:rgba(59,130,246,0.1);
  --adm-green:  #10B981;
  --adm-amber:  #F59E0B;
  --adm-coral:  #EF4444;
  --adm-sb-w:   256px;
}

/* ── Sidebar shell ─────────────────────────────────── */
#adminSidebar{
  width: var(--adm-sb-w);
  position: fixed;
  top: 0; left: 0; bottom: 0;
  z-index: 55;
  display: flex;
  flex-direction: column;
  background: var(--adm-bg2);
  border-right: 1px solid var(--adm-border);
  transition: transform .28s cubic-bezier(.4,0,.2,1);
  overflow: hidden;
}
#adminSidebar.collapsed{ transform: translateX(-100%); }

/* Scrollable nav area */
#adminSidebarNav{
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 8px 0 20px;
  scrollbar-width: thin;
  scrollbar-color: rgba(59,130,246,.2) transparent;
}
#adminSidebarNav::-webkit-scrollbar{ width: 3px; }
#adminSidebarNav::-webkit-scrollbar-thumb{ background: rgba(59,130,246,.2); border-radius: 2px; }

/* ── Logo ──────────────────────────────────────────── */
.adm-logo{
  display: flex; align-items: center; gap: 10px;
  padding: 18px 18px 16px;
  border-bottom: 1px solid var(--adm-border);
  margin-bottom: 6px;
  text-decoration: none;
}
.adm-logo-mark{
  width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--adm-blue), #06B6D4);
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; color: #fff;
}
.adm-logo-name{ font-size: 14px; font-weight: 800; color: var(--adm-text); letter-spacing: .04em; font-family: 'Inter', sans-serif; }
.adm-logo-sub { font-size: 10px; color: var(--adm-text2); margin-top: 1px; letter-spacing: .06em; text-transform: uppercase; }

/* Total unread badge in logo area */
.adm-total-badge{
  margin-left: auto; flex-shrink: 0;
  min-width: 20px; height: 20px;
  background: var(--adm-coral);
  color: #fff;
  font-size: 10px; font-weight: 700;
  border-radius: 10px; padding: 0 6px;
  display: flex; align-items: center; justify-content: center;
}

/* ── Section labels ────────────────────────────────── */
.adm-section{
  font-size: 9px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .14em;
  color: var(--adm-text3);
  padding: 14px 18px 4px;
  margin-top: 4px;
}

/* ── Nav links ─────────────────────────────────────── */
.adm-nav{ padding: 0 8px; display: flex; flex-direction: column; gap: 1px; }

.adm-link{
  display: flex; align-items: center; gap: 9px;
  padding: 8px 10px; border-radius: 7px;
  font-size: 13px; font-family: 'Inter', sans-serif;
  color: var(--adm-text2);
  text-decoration: none;
  cursor: pointer;
  position: relative;
  transition: background .12s, color .12s;
  border-left: 2px solid transparent;
  white-space: nowrap;
}
.adm-link:hover{ background: var(--adm-blue-bg); color: var(--adm-text); }
.adm-link.active{
  background: var(--adm-blue-bg);
  color: var(--adm-blue2);
  border-left-color: var(--adm-blue);
}
.adm-link-icon{
  width: 16px; text-align: center;
  font-size: 13px; flex-shrink: 0;
}
.adm-link-label{ flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }

/* Badges */
.adm-badge{
  flex-shrink: 0; min-width: 18px; height: 18px;
  border-radius: 9px; padding: 0 5px;
  font-size: 10px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}

/* ── Divider ───────────────────────────────────────── */
.adm-divider{ height: 1px; background: var(--adm-border); margin: 8px 18px; }

/* ── Bottom user card ──────────────────────────────── */
.adm-bottom{
  padding: 10px;
  border-top: 1px solid var(--adm-border);
  flex-shrink: 0;
}
.adm-user-card{
  display: flex; align-items: center; gap: 9px;
  padding: 9px 10px; border-radius: 8px;
  background: var(--adm-bg3);
  border: 1px solid var(--adm-border);
  margin-bottom: 6px;
}
.adm-avatar{
  width: 30px; height: 30px; border-radius: 50%;
  background: linear-gradient(135deg, var(--adm-blue), #06B6D4);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.adm-user-name{ font-size: 12px; font-weight: 600; color: var(--adm-text); font-family: 'Inter', sans-serif; }
.adm-user-role{ font-size: 10px; color: var(--adm-blue2); text-transform: capitalize; }
.adm-logout{
  display: flex; align-items: center; justify-content: center; gap: 6px;
  width: 100%; padding: 7px;
  background: none; border: 1px solid rgba(239,68,68,.2);
  border-radius: 7px; color: #EF4444;
  font-size: 11px; font-weight: 700; font-family: 'Inter', sans-serif;
  text-transform: uppercase; letter-spacing: .06em;
  cursor: pointer; text-decoration: none;
  transition: all .15s;
}
.adm-logout:hover{ background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.4); }

/* ── Mobile overlay ────────────────────────────────── */
#adminSidebarOverlay{
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.65);
  backdrop-filter: blur(2px);
  z-index: 54;
}
#adminSidebarOverlay.show{ display: block; }

/* ── Mobile toggle button ──────────────────────────── */
#adminMobileToggle{
  display: none;
  position: fixed; top: 14px; left: 14px;
  z-index: 56;
  width: 36px; height: 36px; border-radius: 9px;
  background: var(--adm-bg2);
  border: 1px solid var(--adm-border2);
  color: var(--adm-text2); font-size: 15px;
  cursor: pointer; align-items: center; justify-content: center;
  transition: all .15s;
}
#adminMobileToggle:hover{ background: var(--adm-blue-bg); color: var(--adm-blue2); }

/* ── Main content offset ───────────────────────────── */
.main-content{ margin-left: var(--adm-sb-w); }

/* ── Responsive ────────────────────────────────────── */
@media(max-width:768px){
  #adminSidebar{ transform: translateX(-100%); }
  #adminSidebar.open{ transform: translateX(0); }
  #adminMobileToggle{ display: flex; }
  .main-content{ margin-left: 0 !important; }
}
</style>

<!-- Mobile overlay -->
<div id="adminSidebarOverlay" onclick="adminSidebarClose()"></div>

<!-- Mobile toggle -->
<button id="adminMobileToggle" onclick="adminSidebarOpen()" aria-label="Open menu">
  <i class="fas fa-bars"></i>
</button>

<!-- ═══════════════════════════════════════
     ADMIN SIDEBAR
═══════════════════════════════════ -->
<aside id="adminSidebar" aria-label="Admin navigation">

  <!-- Logo -->
  <a href="stats.php" class="adm-logo">
    <div class="adm-logo-mark">
      <i class="fas fa-globe" style="font-size:13px;"></i>
    </div>
    <div>
      <div class="adm-logo-name">CheckDomain</div>
      <div class="adm-logo-sub">Admin panel</div>
    </div>
    <?php if ($totalBadge > 0): ?>
    <span class="adm-total-badge"><?= $totalBadge ?></span>
    <?php endif; ?>
  </a>

  <!-- Scrollable nav -->
  <div id="adminSidebarNav">

    <?php foreach ($navGroups as $groupLabel => $links): ?>

    <div class="adm-section"><?= htmlspecialchars($groupLabel) ?></div>
    <nav class="adm-nav" aria-label="<?= htmlspecialchars($groupLabel) ?>">

      <?php foreach ($links as $link):
        $isActive = ($currentPage === $link['id'] || $currentPage === basename($link['href'], '.php'));
        $badge    = (int)($link['badge'] ?? 0);
        $bStyle   = $badgeColors[$link['badge_class'] ?? 'blue'] ?? $badgeColors['blue'];
      ?>
      <a href="<?= htmlspecialchars($link['href']) ?>"
         class="adm-link <?= $isActive ? 'active' : '' ?>"
         <?= $isActive ? 'aria-current="page"' : '' ?>>
        <span class="adm-link-icon" aria-hidden="true"><i class="fas <?= $link['icon'] ?>"></i></span>
        <span class="adm-link-label"><?= htmlspecialchars($link['label']) ?></span>
        <?php if ($badge > 0): ?>
        <span class="adm-badge" style="<?= $bStyle ?>" title="<?= htmlspecialchars($link['badge_title'] ?? '') ?>">
          <?= $badge ?>
        </span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>

    </nav>

    <?php endforeach; ?>

  </div>

  <!-- Bottom: user card + logout -->
  <div class="adm-bottom">
    <div class="adm-user-card">
      <div class="adm-avatar"><?= htmlspecialchars($adminInitial) ?></div>
      <div>
        <div class="adm-user-name"><?= htmlspecialchars($adminUsername) ?></div>
        <div class="adm-user-role"><?= htmlspecialchars($adminRole) ?></div>
      </div>
    </div>
    <a href="logout.php" class="adm-logout">
      <i class="fas fa-sign-out-alt" style="font-size:11px;"></i> Sign out
    </a>
  </div>

</aside>

<script>
function adminSidebarOpen() {
  document.getElementById('adminSidebar').classList.add('open');
  document.getElementById('adminSidebarOverlay').classList.add('show');
}
function adminSidebarClose() {
  document.getElementById('adminSidebar').classList.remove('open');
  document.getElementById('adminSidebarOverlay').classList.remove('show');
}
window.addEventListener('resize', () => {
  if (window.innerWidth > 768) adminSidebarClose();
});
</script>
