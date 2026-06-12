<?php
/**
 * includes/sidebar.php
 *
 * Drop-in sidebar for all authenticated dashboard pages.
 *
 * REQUIRES before include:
 *   - $user        array   from $auth->getUserById($session['user_id'])
 *   - $appBasePath string  e.g. '/web/checkdomain'  (set by header.php or dashboard bootstrap)
 *
 * OPTIONAL before include:
 *   - $activePage  string  'dashboard' | 'search' | 'watchlist' | 'alerts' | 'backorders'
 *                          | 'whois' | 'broker' | 'dead-sites' | 'billing' | 'settings'
 *   - $watchlistCount int  badge count on Watchlist link
 *   - $alertCount     int  badge count on Alerts link
 *
 * To use on a page:
 *   <?php
 *   $activePage = 'watchlist';      // optional, defaults to 'dashboard'
 *   require_once 'includes/sidebar.php';
 *   ?>
 */

// ── Defaults ────────────────────────────────────────────────────────────────
$activePage    = $activePage    ?? 'dashboard';
$watchlistCount = $watchlistCount ?? 0;
$alertCount    = $alertCount    ?? 0;

// ── User meta ────────────────────────────────────────────────────────────────
$sbUserName = trim($user['full_name'] ?? '') ?: explode('@', $user['email'] ?? 'U')[0];
$sbFirst    = explode(' ', $sbUserName)[0];
$sbInitials = strtoupper(
    substr($sbUserName, 0, 1) .
    (strpos($sbUserName, ' ') !== false
        ? substr($sbUserName, strpos($sbUserName, ' ') + 1, 1)
        : '')
);

// plan / credits — gracefully absent until schema is extended
$sbPlan    = $user['plan']    ?? 'free'; // free | pro | elite
$sbCredits = $user['credits'] ?? 10;

$sbPlanLabel = match($sbPlan) {
    'pro'   => 'Pro plan',
    'elite' => 'Elite plan',
    default => 'Free plan',
};
$sbPlanCredits = match($sbPlan) {
    'pro'   => 100,
    'elite' => 500,
    default => 10,
};
$sbCreditsPercent = min(100, (int) round(($sbCredits / $sbPlanCredits) * 100));

// ── URL helper ───────────────────────────────────────────────────────────────
$appBasePath = $appBasePath ?? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (in_array($appBasePath, ['/', '.', '\\'])) { $appBasePath = ''; }
$sbUrl = fn(string $p): string => ($appBasePath ?: '') . '/' . ltrim($p, '/');

// ── Active helper ─────────────────────────────────────────────────────────────
$isActive = fn(string $page): string => $activePage === $page ? 'active' : '';

// ── Nav structure ─────────────────────────────────────────────────────────────
$mainNav = [
    ['id' => 'dashboard',  'label' => 'Dashboard',  'icon' => 'fa-th-large',    'href' => 'dashboard.php'],
    ['id' => 'search',     'label' => 'Search',     'icon' => 'fa-search',      'href' => 'index.php'],
    ['id' => 'watchlist',  'label' => 'Watchlist',  'icon' => 'fa-bookmark',    'href' => 'watchlist.php',  'badge' => $watchlistCount, 'badgeClass' => 'green'],
    ['id' => 'alerts',     'label' => 'Alerts',     'icon' => 'fa-bell',        'href' => 'alerts.php',     'badge' => $alertCount],
    ['id' => 'backorders', 'label' => 'Backorders', 'icon' => 'fa-clock',       'href' => 'backorders.php'],
];

$servicesNav = [
    ['id' => 'whois',      'label' => 'WHOIS Lookup',   'icon' => 'fa-file-alt',    'href' => 'whois.php'],
    ['id' => 'broker',     'label' => 'Broker Service', 'icon' => 'fa-handshake',   'href' => 'broker.php',   'pro' => true],
    ['id' => 'dead-sites', 'label' => 'Dead Sites',     'icon' => 'fa-skull',       'href' => 'dead-sites.php'],
    ['id' => 'billing',    'label' => 'Billing',        'icon' => 'fa-credit-card', 'href' => 'billing.php'],
];
?>
<!-- ═══════════════════════════════════════════════════
     SIDEBAR  —  includes/sidebar.php
═══════════════════════════════════════════════════ -->
<aside class="cd-sidebar" id="cdSidebar" aria-label="Main navigation">

  <!-- Logo -->
  <a href="<?= htmlspecialchars($sbUrl('index.php')) ?>" class="cd-sb-logo">
    
      <img src="<?php echo htmlspecialchars($assetUrl('images/logo.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="checkdomain.top logo" height="20px" >
    
    <span class="cd-sb-logo-text">CheckDomain</span>
  </a>

  <!-- Main nav -->
  <div class="cd-sb-section-label">Main</div>
  <nav class="cd-sb-nav" aria-label="Main">
    <?php foreach ($mainNav as $item): ?>
    <a href="<?= htmlspecialchars($sbUrl($item['href'])) ?>"
       class="cd-sb-link <?= $isActive($item['id']) ?>"
       <?= $activePage === $item['id'] ? 'aria-current="page"' : '' ?>>
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas <?= $item['icon'] ?>"></i></span>
      <?= htmlspecialchars($item['label']) ?>
      <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
        <span class="cd-sb-badge <?= $item['badgeClass'] ?? '' ?>"><?= (int)$item['badge'] ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Services nav -->
  <div class="cd-sb-section-label">Services</div>
  <nav class="cd-sb-nav" aria-label="Services">
    <?php foreach ($servicesNav as $item): ?>
    <a href="<?= htmlspecialchars($sbUrl($item['href'])) ?>"
       class="cd-sb-link <?= $isActive($item['id']) ?> <?= (!empty($item['pro']) && $sbPlan === 'free') ? 'cd-sb-locked' : '' ?>"
       <?= $activePage === $item['id'] ? 'aria-current="page"' : '' ?>
       <?= (!empty($item['pro']) && $sbPlan === 'free') ? 'title="Upgrade to Pro to unlock"' : '' ?>>
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas <?= $item['icon'] ?>"></i></span>
      <?= htmlspecialchars($item['label']) ?>
      <?php if (!empty($item['pro']) && $sbPlan === 'free'): ?>
        <span class="cd-sb-pro-lock" aria-label="Pro feature"><i class="fas fa-lock"></i></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- Divider -->
  <div class="cd-sb-divider" role="separator"></div>

  <!-- System nav -->
  <nav class="cd-sb-nav" aria-label="Account">
    <a href="<?= htmlspecialchars($sbUrl('account-settings.php')) ?>"
       class="cd-sb-link <?= $isActive('settings') ?>">
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-cog"></i></span>
      Settings
    </a>
    <a href="<?= htmlspecialchars($sbUrl('logout.php')) ?>" class="cd-sb-link cd-sb-link--danger">
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-sign-out-alt"></i></span>
      Logout
    </a>
  </nav>

  <!-- Bottom -->
  <div class="cd-sb-bottom">

    <!-- Credits bar (always visible) -->
    <div class="cd-sb-credits">
      <div class="cd-sb-credits-row">
        <span class="cd-sb-credits-label">Credits</span>
        <span class="cd-sb-credits-value"><?= $sbCredits ?> <span class="cd-sb-credits-max">/ <?= $sbPlanCredits ?></span></span>
      </div>
      <div class="cd-sb-credits-bar-wrap" role="progressbar"
           aria-valuenow="<?= $sbCreditsPercent ?>" aria-valuemin="0" aria-valuemax="100"
           aria-label="Credits remaining">
        <div class="cd-sb-credits-bar-fill <?= $sbCreditsPercent < 20 ? 'low' : ($sbCreditsPercent < 50 ? 'mid' : '') ?>"
             style="width:<?= $sbCreditsPercent ?>%"></div>
      </div>
    </div>

    <!-- Upgrade strip — only for free users -->
    <?php if ($sbPlan === 'free'): ?>
    <a href="<?= htmlspecialchars($sbUrl('billing.php?plan=pro')) ?>" class="cd-sb-upgrade">
      <div class="cd-sb-upgrade-label">Current plan · Free</div>
      <div class="cd-sb-upgrade-title">↑ Upgrade to Pro</div>
      <div class="cd-sb-upgrade-sub">100 credits, WHOIS, backorder alerts</div>
    </a>
    <?php endif; ?>

    <!-- User card -->
    <a href="<?= htmlspecialchars($sbUrl('account-settings.php')) ?>" class="cd-sb-user">
      <div class="cd-sb-avatar" aria-hidden="true"><?= htmlspecialchars($sbInitials) ?></div>
      <div class="cd-sb-user-info">
        <div class="cd-sb-user-name"><?= htmlspecialchars($sbFirst) ?></div>
        <div class="cd-sb-user-plan"><?= htmlspecialchars($sbPlanLabel) ?></div>
      </div>
      <span class="cd-sb-user-caret" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
    </a>

  </div>
</aside>

<!-- ═══════════════════════════════════════════════════
     SIDEBAR STYLES  (scoped with cd- prefix)
═══════════════════════════════════════════════════ -->
<style>
/* ── Variables (mirrors dashboard design system) ───────── */
.cd-sidebar {
  --sb-bg:         #111318;
  --sb-border:     rgba(255,255,255,0.06);
  --sb-border2:    rgba(255,255,255,0.11);
  --sb-text:       #E9E7DF;
  --sb-text2:      #8A8880;
  --sb-text3:      #454340;
  --sb-bg3:        #181C24;
  --sb-green:      #1D9E75;
  --sb-green2:     #14C48A;
  --sb-green-bg:   rgba(29,158,117,0.1);
  --sb-amber:      #EF9F27;
  --sb-amber-bg:   rgba(239,159,39,0.1);
  --sb-coral:      #E8593C;
  --sb-coral-bg:   rgba(232,89,60,0.1);
  --sb-purple:     #7F77DD;
  --sb-width:      224px;
  --sb-display:    'Syne', sans-serif;
  --sb-mono:       'DM Mono', monospace;
}

/* ── Layout ─────────────────────────────────────────────── */
.cd-sidebar {
  width: var(--sb-width);
  flex-shrink: 0;
  background: var(--sb-bg);
  border-right: 1px solid var(--sb-border);
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0; left: 0; bottom: 0;
  z-index: 50;
  padding: 22px 0 20px;
  overflow-y: auto;
  overflow-x: hidden;
  transition: transform 0.25s ease;
  scrollbar-width: thin;
  scrollbar-color: var(--sb-border2) transparent;
}
.cd-sidebar::-webkit-scrollbar { width: 3px; }
.cd-sidebar::-webkit-scrollbar-thumb { background: var(--sb-border2); border-radius: 2px; }

/* ── Logo ───────────────────────────────────────────────── */
.cd-sb-logo {
  display: flex; align-items: center; gap: 10px;
  padding: 0 20px 20px;
  border-bottom: 1px solid var(--sb-border);
  margin-bottom: 18px;
  text-decoration: none;
}
.cd-sb-logo-mark {
  width: 28px; height: 28px; border-radius: 7px;
  background: var(--sb-green-bg);
  border: 1px solid rgba(29,158,117,0.25);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; color: var(--sb-green2);
}
.cd-sb-logo-mark svg { width: 14px; height: 14px; }
.cd-sb-logo-text {
  font-size: 10px; font-weight: 800; letter-spacing: 0.12em;
  text-transform: uppercase; color: var(--sb-text);
  font-family: var(--sb-display);
}

/* ── Section labels ─────────────────────────────────────── */
.cd-sb-section-label {
  font-size: 10px; font-weight: 500; letter-spacing: 0.15em;
  text-transform: uppercase; color: var(--sb-text3);
  padding: 0 20px; margin-bottom: 5px;
  font-family: var(--sb-display);
}

/* ── Nav ────────────────────────────────────────────────── */
.cd-sb-nav {
  display: flex; flex-direction: column; gap: 1px;
  padding: 0 10px; margin-bottom: 18px;
}
.cd-sb-link {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 11px; border-radius: 7px;
  font-size: 13px; color: var(--sb-text2);
  text-decoration: none; cursor: pointer;
  transition: background 0.12s, color 0.12s;
  position: relative; font-family: var(--sb-display);
  white-space: nowrap; overflow: hidden;
}
.cd-sb-link:hover { background: var(--sb-bg3); color: var(--sb-text); }
.cd-sb-link.active {
  background: var(--sb-green-bg); color: var(--sb-green2);
}
.cd-sb-link.active::before {
  content: '';
  position: absolute; left: 0; top: 18%; bottom: 18%;
  width: 2px; border-radius: 0 2px 2px 0;
  background: var(--sb-green2);
}
.cd-sb-link.cd-sb-locked { opacity: 0.45; }
.cd-sb-link.cd-sb-locked:hover { background: none; color: var(--sb-text2); cursor: not-allowed; }
.cd-sb-link--danger { color: var(--sb-text3); }
.cd-sb-link--danger:hover { background: var(--sb-coral-bg); color: var(--sb-coral); }

.cd-sb-icon {
  font-size: 13px; flex-shrink: 0;
  width: 16px; text-align: center;
}

/* ── Badges ─────────────────────────────────────────────── */
.cd-sb-badge {
  margin-left: auto; font-size: 10px; font-weight: 700;
  background: var(--sb-amber-bg); color: var(--sb-amber);
  border-radius: 4px; padding: 1px 6px;
  font-family: var(--sb-mono); flex-shrink: 0;
}
.cd-sb-badge.green { background: var(--sb-green-bg); color: var(--sb-green2); }

.cd-sb-pro-lock {
  margin-left: auto; font-size: 10px; color: var(--sb-text3);
  flex-shrink: 0;
}

/* ── Divider ────────────────────────────────────────────── */
.cd-sb-divider {
  height: 1px; background: var(--sb-border);
  margin: 4px 20px 14px;
}

/* ── Bottom stack ───────────────────────────────────────── */
.cd-sb-bottom {
  margin-top: auto;
  padding: 0 10px;
  display: flex; flex-direction: column; gap: 8px;
}

/* Credits bar */
.cd-sb-credits {
  padding: 10px 12px;
  background: var(--sb-bg3);
  border: 1px solid var(--sb-border);
  border-radius: 8px;
}
.cd-sb-credits-row {
  display: flex; justify-content: space-between; align-items: baseline;
  margin-bottom: 7px;
}
.cd-sb-credits-label {
  font-size: 10px; text-transform: uppercase; letter-spacing: 0.12em;
  color: var(--sb-text3); font-family: var(--sb-display);
}
.cd-sb-credits-value {
  font-size: 12px; font-weight: 700; color: var(--sb-text);
  font-family: var(--sb-mono);
}
.cd-sb-credits-max { font-weight: 400; color: var(--sb-text3); }
.cd-sb-credits-bar-wrap {
  height: 3px; background: var(--sb-border);
  border-radius: 2px; overflow: hidden;
}
.cd-sb-credits-bar-fill {
  height: 100%; border-radius: 2px;
  background: var(--sb-green);
  transition: width 0.6s ease;
}
.cd-sb-credits-bar-fill.mid  { background: var(--sb-amber); }
.cd-sb-credits-bar-fill.low  { background: var(--sb-coral); }

/* Upgrade strip */
.cd-sb-upgrade {
  display: block;
  border-radius: 9px;
  background: linear-gradient(135deg, rgba(29,158,117,0.1), rgba(127,119,221,0.06));
  border: 1px solid rgba(29,158,117,0.18);
  padding: 11px 13px;
  text-decoration: none;
  transition: border-color 0.2s;
}
.cd-sb-upgrade:hover { border-color: rgba(29,158,117,0.38); }
.cd-sb-upgrade-label {
  font-size: 10px; color: var(--sb-text3);
  text-transform: uppercase; letter-spacing: 0.12em;
  margin-bottom: 3px; font-family: var(--sb-display);
}
.cd-sb-upgrade-title {
  font-size: 12px; font-weight: 700;
  color: var(--sb-green2); margin-bottom: 2px;
  font-family: var(--sb-display);
}
.cd-sb-upgrade-sub { font-size: 11px; color: var(--sb-text2); }

/* User card */
.cd-sb-user {
  display: flex; align-items: center; gap: 9px;
  padding: 9px 11px; border-radius: 8px;
  background: var(--sb-bg3); border: 1px solid var(--sb-border);
  text-decoration: none; cursor: pointer;
  transition: border-color 0.15s;
}
.cd-sb-user:hover { border-color: var(--sb-border2); }
.cd-sb-avatar {
  width: 30px; height: 30px; border-radius: 50%;
  background: linear-gradient(135deg, var(--sb-green), var(--sb-purple));
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: #fff;
  flex-shrink: 0; font-family: var(--sb-display);
}
.cd-sb-user-info { flex: 1; min-width: 0; }
.cd-sb-user-name {
  font-size: 12px; font-weight: 500; color: var(--sb-text);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  font-family: var(--sb-display);
}
.cd-sb-user-plan { font-size: 10px; color: var(--sb-green); font-family: var(--sb-mono); }
.cd-sb-user-caret { font-size: 10px; color: var(--sb-text3); flex-shrink: 0; }

/* ── Mobile ─────────────────────────────────────────────── */
@media (max-width: 768px) {
  .cd-sidebar { transform: translateX(-100%); }
  .cd-sidebar.open { transform: translateX(0); }
}
</style>