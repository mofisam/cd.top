<?php
$cdHeaderTitle = $cdHeaderTitle ?? 'Dashboard';
$cdHeaderParent = $cdHeaderParent ?? 'Dashboard';
$cdHeaderParentHref = $cdHeaderParentHref ?? 'dashboard.php';
$cdHeaderShowCredits = $cdHeaderShowCredits ?? true;
$cdHeaderActions = $cdHeaderActions ?? '';
$cdHeaderCreditsLabel = $cdHeaderCreditsLabel ?? 'credits';
$cdHeaderCreditsId = $cdHeaderCreditsId ?? 'creditsDisplay';
$cdHeaderCreditValue = isset($cdHeaderCreditValue) ? (int)$cdHeaderCreditValue : (int)($credits ?? 0);
$cdHeaderAlertCount = isset($alertCount) ? (int)$alertCount : 0;
$cdHeaderUrl = isset($assetUrl) && is_callable($assetUrl)
    ? $assetUrl
    : (isset($url) && is_callable($url) ? $url : fn(string $p): string => $p);
?>
<div class="topbar">
  <div class="topbar-left tb-left">
    <button class="mobile-menu-btn mob-menu" type="button" onclick="window.openSidebar ? openSidebar() : (window.openSB && openSB())" title="Open menu">
      <i class="fas fa-bars"></i>
    </button>
    <div class="breadcrumb">
      <a href="<?= htmlspecialchars($cdHeaderUrl($cdHeaderParentHref)) ?>"><?= htmlspecialchars($cdHeaderParent) ?></a>
      <i class="fas fa-chevron-right"></i>
      <span><?= htmlspecialchars($cdHeaderTitle) ?></span>
    </div>
  </div>
  <div class="topbar-right tb-right">
    <?php if ($cdHeaderShowCredits): ?>
      <div class="credits-pill tb-credits" id="creditsPill">
        <i class="fas fa-bolt" style="color:var(--amber,var(--a));font-size:11px;"></i>
        <b id="<?= htmlspecialchars($cdHeaderCreditsId) ?>"><?= $cdHeaderCreditValue ?></b> <?= htmlspecialchars($cdHeaderCreditsLabel) ?>
      </div>
    <?php endif; ?>
    <a href="<?= htmlspecialchars($cdHeaderUrl('billing.php?topup=1')) ?>" class="topbar-btn tb-btn tb-icon" title="Top up credits">
      <i class="fas fa-plus"></i>
    </a>
    <a href="<?= htmlspecialchars($cdHeaderUrl('alerts.php')) ?>" class="topbar-btn tb-btn tb-icon" title="Alerts">
      <i class="fas fa-bell"></i>
      <?php if ($cdHeaderAlertCount > 0): ?><span class="tb-dot notif-dot"></span><?php endif; ?>
    </a>
    <a href="<?= htmlspecialchars($cdHeaderUrl('account-settings.php')) ?>" class="topbar-btn tb-btn tb-icon" title="Settings">
      <i class="fas fa-cog"></i>
    </a>
    <?= $cdHeaderActions ?>
  </div>
</div>
