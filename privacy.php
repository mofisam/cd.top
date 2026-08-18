<?php
require_once 'config/database.php';
require_once 'includes/error_handler.php';

trackPageView($_SERVER['REQUEST_URI'] ?? 'privacy');

$pageTitle = 'Privacy Policy — CheckDomain';
$pageDescription = 'How CheckDomain collects, uses, and protects your data.';
$showHeaderHero = false;

require_once 'includes/header.php';
?>

<section class="py-10 md:py-14">
  <div class="max-w-3xl mx-auto text-left">

    <h1 class="mt-5 text-3xl font-extrabold leading-tight text-white md:text-5xl">Privacy Policy</h1>
    <p class="mt-3 text-sm text-slate-400">Last updated: <?php echo date('F j, Y'); ?></p>

    <div class="glass-card mt-8 p-6 md:p-8 space-y-8 text-sm leading-7 text-slate-300">

      <div>
        <h2 class="text-lg font-bold text-white mb-2">1. Overview</h2>
        <p>CheckDomain ("we", "us", "our") provides domain availability search, WHOIS lookup, watchlist monitoring, backorder placement, and dead-site detection services. This policy explains what data we collect, why we collect it, and how we protect it.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">2. Information We Collect</h2>
        <ul class="space-y-2">
          <li><i class="fas fa-circle text-[5px] text-sky-400 mr-2 align-middle"></i><span class="font-semibold text-slate-200">Account data</span> — email, name, and password hash when you register.</li>
          <li><i class="fas fa-circle text-[5px] text-sky-400 mr-2 align-middle"></i><span class="font-semibold text-slate-200">Usage data</span> — domains you search, watchlist entries, scan history, and credit activity, used to provide the service and improve it.</li>
          <li><i class="fas fa-circle text-[5px] text-sky-400 mr-2 align-middle"></i><span class="font-semibold text-slate-200">Billing data</span> — payments are processed by Paystack; we store transaction references and status, not your full card details.</li>
          <li><i class="fas fa-circle text-[5px] text-sky-400 mr-2 align-middle"></i><span class="font-semibold text-slate-200">Technical data</span> — IP address, browser, device type, and page views, used for analytics, fraud prevention, and rate limiting.</li>
        </ul>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">3. How We Use Your Data</h2>
        <p>We use collected data to operate and improve CheckDomain, deliver domain alerts and watchlist notifications, process payments and manage subscriptions, prevent abuse and enforce rate limits, and respond to support requests.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">4. Data Sharing</h2>
        <p>We do not sell your personal data. We share data only with service providers necessary to run CheckDomain — such as Paystack for payment processing and WHOIS data providers for domain lookups — and only to the extent required to deliver the service.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">5. Data Retention</h2>
        <p>Search and scan history is retained according to your plan's history window. Account data is retained while your account is active and deleted upon request, subject to legal or billing record requirements.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">6. Your Rights</h2>
        <p>You can access, correct, or request deletion of your account data at any time from your account settings, or by contacting us.</p>
      </div>

      <div>
        <h2 class="text-lg font-bold text-white mb-2">7. Contact</h2>
        <p>Questions about this policy? <a href="<?php echo htmlspecialchars($assetUrl('contact.php'), ENT_QUOTES, 'UTF-8'); ?>" class="text-sky-400 hover:text-sky-300 underline">Contact us</a>.</p>
      </div>

    </div>
  </div>
</section>

<?php require_once 'includes/footer.php'; ?>