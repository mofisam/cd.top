<?php
require_once 'config/database.php';
require_once 'includes/error_handler.php';

trackPageView($_SERVER['REQUEST_URI'] ?? 'terms');

$pageTitle = 'Terms of Service — CheckDomain';
$pageDescription = 'The terms governing your use of CheckDomain.';
$showHeaderHero = false;

require_once 'includes/header.php';
?>

<section class="py-10 md:py-14">
  <div class="max-w-3xl mx-auto text-left">
    <h1 class="mt-5 text-3xl font-extrabold leading-tight text-white md:text-5xl">Terms of Service</h1>
    <p class="mt-3 text-sm text-slate-400">Last updated: <?php echo date('F j, Y'); ?></p>

    <div class="glass-card mt-8 p-6 md:p-8 space-y-8 text-sm leading-7 text-slate-300">

      <div>
        <h2 class="text-lg font-bold text-white mb-2">1. Acceptance of Terms</h2>
        <p>By creating an account or using CheckDomain, you agree to these Terms of Service. If you do not agree, please do not use the service.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">2. The Service</h2>
        <p>CheckDomain provides domain availability checking, WHOIS lookups, watchlist monitoring, expiry alerts, backorder placement, and dead-site detection. We do not guarantee that any backorder attempt will succeed, as domain drops and registrations depend on registry timing outside our control.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">3. Accounts &amp; Plans</h2>
        <p>CheckDomain offers Free, Pro, and Elite plans with different credit allowances and feature access. Credits are consumed per action (search, WHOIS lookup, dead-site scan, backorder placement) as described on the platform. Unused credits do not roll over between billing cycles unless stated otherwise.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">4. Billing</h2>
        <p>Paid plans are billed in Dollars ($) via Paystack on a recurring basis according to your selected billing cycle. You may cancel at any time; access continues until the end of the current billing period. Refunds are handled on a case-by-case basis.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">5. Acceptable Use</h2>
        <p>You agree not to use CheckDomain to scrape, abuse, or overload third-party WHOIS or registry systems, attempt to circumvent rate limits or credit systems, or use the service for any unlawful purpose. We reserve the right to suspend accounts that violate these terms.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">6. No Guarantee of Domain Acquisition</h2>
        <p>Watchlist alerts, backorder placement, and dead-site detection are tools to help you act faster — they do not guarantee that you will successfully register or acquire any specific domain. Domain availability changes are controlled by registries and registrars, not CheckDomain.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">7. Limitation of Liability</h2>
        <p>CheckDomain is provided "as is" without warranties of any kind. We are not liable for any loss arising from missed domain acquisitions, inaccurate third-party WHOIS data, or service interruptions.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">8. Changes to These Terms</h2>
        <p>We may update these terms from time to time. Continued use of CheckDomain after changes take effect constitutes acceptance of the revised terms.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">9. Contact</h2>
        <p>Questions about these terms? <a href="<?php echo htmlspecialchars($assetUrl('contact.php'), ENT_QUOTES, 'UTF-8'); ?>" class="text-sky-400 hover:text-sky-300 underline">Contact us</a>.</p>
      </div>

    </div>
  </div>
</section>

<?php require_once 'includes/footer.php'; ?>