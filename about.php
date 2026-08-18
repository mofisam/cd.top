<?php
require_once 'config/database.php';
require_once 'includes/error_handler.php';

trackPageView($_SERVER['REQUEST_URI'] ?? 'about');

$pageTitle = 'About CheckDomain — Domain Search, Monitoring & Acquisition';
$pageDescription = 'CheckDomain helps you search domain availability, monitor taken domains, place backorders on expiring names, and find dead or abandoned sites worth acquiring.';
$showHeaderHero = false;

require_once 'includes/header.php';
?>

      <section class="py-10 md:py-14">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-center">
          <div class="max-w-3xl text-left">
            <div class="hero-chip inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium text-sky-100">
              <i class="fas fa-compass text-green-300"></i>
              Built for founders, domain investors, and brand owners
            </div>
            <h1 class="mt-5 text-4xl font-extrabold leading-tight tracking-normal text-white md:text-6xl">
              We help you find, watch, and acquire the domains that matter.
            </h1>
            <p class="mt-5 max-w-2xl text-base leading-7 text-slate-300 md:text-lg">
              CheckDomain is more than an availability checker. We give you live WHOIS data, a watchlist that alerts you the moment a domain changes status, backorder placement for names approaching expiry, and dead-site detection to uncover abandoned domains worth acquiring.
            </p>
            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
              <a href="<?php echo htmlspecialchars($assetUrl('index.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-primary inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-semibold text-white">
                <i class="fas fa-search"></i>
                Start Searching
              </a>
              <a href="<?php echo htmlspecialchars($assetUrl('register.php'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-600 bg-slate-950/50 px-5 py-3 text-sm font-semibold text-slate-200 transition hover:border-sky-400/50 hover:text-sky-100">
                <i class="fas fa-user-plus"></i>
                Create Free Account
              </a>
            </div>
          </div>

          <div class="glass-card p-5 text-left">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">What We Solve</p>
            <h2 class="mt-2 text-2xl font-bold text-white">Domain hunting is slow. We make it systematic.</h2>
            <p class="mt-4 text-sm leading-6 text-slate-300">
              Most people check the same domain over and over, miss the exact moment a registration lapses, or never discover that a registered domain is actually dead and acquirable. CheckDomain turns that guesswork into a process.
            </p>
            <div class="mt-5 grid gap-3 text-sm">
              <div class="rounded-lg bg-slate-900/70 p-3">
                <span class="font-semibold text-sky-200">Search &amp; verify</span>
                <p class="mt-1 text-slate-400">Live availability checks backed by real WHOIS data — not cached guesses.</p>
              </div>
              <div class="rounded-lg bg-slate-900/70 p-3">
                <span class="font-semibold text-green-200">Watch &amp; get alerted</span>
                <p class="mt-1 text-slate-400">Pin any taken domain and we notify you the moment it drops or expires.</p>
              </div>
              <div class="rounded-lg bg-slate-900/70 p-3">
                <span class="font-semibold text-amber-200">Backorder &amp; acquire</span>
                <p class="mt-1 text-slate-400">Queue a backorder ahead of expiry, or scan for dead sites worth claiming.</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="mx-auto max-w-5xl">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 mb-4 text-left">What CheckDomain does</p>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
          <div class="feature-card rounded-lg p-5 text-left">
            <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-sky-500/15 text-sky-200">
              <i class="fas fa-magnifying-glass"></i>
            </div>
            <h3 class="mt-4 text-base font-semibold text-white">Live availability check</h3>
            <p class="mt-2 text-xs leading-6 text-slate-400">
              Type a name or full domain and get a real-time WHOIS-backed answer — available, taken, or expiring soon.
            </p>
          </div>

          <div class="feature-card rounded-lg p-5 text-left">
            <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-green-500/15 text-green-200">
              <i class="fas fa-bookmark"></i>
            </div>
            <h3 class="mt-4 text-base font-semibold text-white">Watchlist &amp; alerts</h3>
            <p class="mt-2 text-xs leading-6 text-slate-400">
              Pin a taken domain once. We monitor it and alert you the moment it becomes available, expires, or changes hands.
            </p>
          </div>

          <div class="feature-card rounded-lg p-5 text-left">
            <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-amber-500/15 text-amber-200">
              <i class="fas fa-clock-rotate-left"></i>
            </div>
            <h3 class="mt-4 text-base font-semibold text-white">Backorder placement</h3>
            <p class="mt-2 text-xs leading-6 text-slate-400">
              Found a domain expiring soon? Place a backorder and we'll attempt registration the second it drops.
            </p>
          </div>

          <div class="feature-card rounded-lg p-5 text-left">
            <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-purple-500/15 text-purple-200">
              <i class="fas fa-skull"></i>
            </div>
            <h3 class="mt-4 text-base font-semibold text-white">Dead site detection</h3>
            <p class="mt-2 text-xs leading-6 text-slate-400">
              We scan registered domains for signs of abandonment — parked pages, no content, expired SSL — so you can find acquisition targets others miss.
            </p>
          </div>
        </div>
      </section>

      <section class="mx-auto mt-8 max-w-5xl rounded-lg border border-slate-700/60 bg-slate-950/45 p-5 text-left backdrop-blur md:p-7">
        <div class="grid gap-8 md:grid-cols-[260px_minmax(0,1fr)]">
          <div>
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Our Promise</p>
            <h2 class="mt-2 text-2xl font-bold text-white">We are here to make domain hunting less stressful.</h2>
          </div>
          <div class="space-y-4 text-sm leading-6 text-slate-300">
            <p>
              Choosing a domain often happens at the exact moment a project is starting to feel real. That can be exciting, but it can also be frustrating when the perfect name is already taken — or worse, when you find out months later that it dropped and someone else grabbed it first.
            </p>
            <p>
              CheckDomain is designed to remove that uncertainty. Search a name, see the result clearly, watch it if it's taken, and let us handle the waiting. If you're hunting for acquisition opportunities rather than a single brand name, our dead-site scanner and backorder system are built specifically for that.
            </p>
            <p>
              Every plan starts free with enough credits to explore the platform. As you need more — deeper WHOIS data, unlimited watchlist slots, backorder placement, or dead-site scans — Pro and Elite unlock the full toolkit, including a broker service for domains you can't get any other way.
            </p>
          </div>
        </div>
      </section>

      <section class="mx-auto mt-8 max-w-5xl">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 mb-4 text-left">How it works</p>
        <div class="rounded-lg border border-slate-700/50 bg-slate-950/40 p-6 backdrop-blur">
          <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            <div class="flex gap-4 items-start text-left">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-500/15 text-blue-300 font-bold text-sm">1</div>
              <div>
                <p class="text-sm font-semibold text-white mb-1">Search</p>
                <p class="text-xs text-slate-400 leading-relaxed">Check any domain name in real time. We pull live WHOIS data, not stale results.</p>
              </div>
            </div>
            <div class="flex gap-4 items-start text-left">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-green-500/15 text-green-300 font-bold text-sm">2</div>
              <div>
                <p class="text-sm font-semibold text-white mb-1">Watch or act</p>
                <p class="text-xs text-slate-400 leading-relaxed">Available? Register it. Taken? Pin it to your watchlist or queue a backorder for when it expires.</p>
              </div>
            </div>
            <div class="flex gap-4 items-start text-left">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-purple-500/15 text-purple-300 font-bold text-sm">3</div>
              <div>
                <p class="text-sm font-semibold text-white mb-1">Get notified first</p>
                <p class="text-xs text-slate-400 leading-relaxed">We monitor everything you're watching and alert you the moment something changes — so you move before anyone else.</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="mx-auto mt-8 max-w-5xl rounded-lg border border-sky-400/20 bg-sky-950/20 p-5 text-left backdrop-blur md:flex md:items-center md:justify-between md:gap-6">
        <div>
          <h2 class="text-xl font-bold text-white">Have a name in mind?</h2>
          <p class="mt-2 text-sm text-slate-300">Start with one search. If it's taken, we'll help you watch it, backorder it, or find the next best option.</p>
        </div>
        <a href="<?php echo htmlspecialchars($assetUrl('index.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-secondary mt-4 inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-semibold text-white md:mt-0">
          <i class="fas fa-arrow-right"></i>
          Check a Domain
        </a>
      </section>

      <?php require_once 'includes/footer.php'; ?>

</body>
</html>