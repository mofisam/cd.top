<?php
require_once 'config/database.php';
require_once 'includes/error_handler.php';

trackPageView($_SERVER['REQUEST_URI'] ?? 'about');

$pageTitle = 'About checkdomain.top - Domain Search and Availability Alerts';
$pageDescription = 'Learn how checkdomain.top helps people find available domain names, monitor taken domains, and act quickly when the right name becomes available.';
$showHeaderHero = false;

require_once 'includes/header.php';
?>

      <section class="py-10 md:py-14">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-center">
          <div class="max-w-3xl text-left">
            <div class="hero-chip inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium text-sky-100">
              <i class="fas fa-compass text-green-300"></i>
              Built for founders, creators, and teams choosing a name
            </div>
            <h1 class="mt-5 text-4xl font-extrabold leading-tight tracking-normal text-white md:text-6xl">
              We help you find the right domain before it slips away.
            </h1>
            <p class="mt-5 max-w-2xl text-base leading-7 text-slate-300 md:text-lg">
              A good domain can shape how people remember your brand. checkdomain.top makes it easier to search, compare, save, and monitor domain names without jumping between different tools.
            </p>
            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
              <a href="<?php echo htmlspecialchars($assetUrl('index.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-primary inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-semibold text-white">
                <i class="fas fa-search"></i>
                Start Searching
              </a>
              <a href="<?php echo htmlspecialchars($assetUrl('contact.php'), ENT_QUOTES, 'UTF-8'); ?>" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-600 bg-slate-950/50 px-5 py-3 text-sm font-semibold text-slate-200 transition hover:border-sky-400/50 hover:text-sky-100">
                <i class="fas fa-envelope"></i>
                Contact Us
              </a>
            </div>
          </div>

          <div class="glass-card p-5 text-left">
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">What We Solve</p>
            <h2 class="mt-2 text-2xl font-bold text-white">Domain search should feel fast, clear, and useful.</h2>
            <p class="mt-4 text-sm leading-6 text-slate-300">
              Most people lose time checking the same names repeatedly, forgetting alternatives, or discovering too late that a better domain became available. We are here to reduce that friction.
            </p>
            <div class="mt-5 grid gap-3 text-sm">
              <div class="rounded-lg bg-slate-900/70 p-3">
                <span class="font-semibold text-sky-200">Search</span>
                <p class="mt-1 text-slate-400">Check availability for the names you are considering.</p>
              </div>
              <div class="rounded-lg bg-slate-900/70 p-3">
                <span class="font-semibold text-green-200">Compare</span>
                <p class="mt-1 text-slate-400">Explore useful alternatives when your first choice is taken.</p>
              </div>
              <div class="rounded-lg bg-slate-900/70 p-3">
                <span class="font-semibold text-amber-200">Monitor</span>
                <p class="mt-1 text-slate-400">Pin domains and get ready to act when availability changes.</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="mx-auto grid max-w-5xl grid-cols-1 gap-4 md:grid-cols-3">
        <div class="feature-card rounded-lg p-5 text-left">
          <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-sky-500/15 text-sky-200">
            <i class="fas fa-bolt"></i>
          </div>
          <h3 class="mt-4 text-lg font-semibold text-white">Instant Clarity</h3>
          <p class="mt-2 text-sm leading-6 text-slate-400">
            You enter a domain idea, and we quickly show whether it is available or already registered.
          </p>
        </div>

        <div class="feature-card rounded-lg p-5 text-left">
          <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-green-500/15 text-green-200">
            <i class="fas fa-thumbtack"></i>
          </div>
          <h3 class="mt-4 text-lg font-semibold text-white">Saved Opportunities</h3>
          <p class="mt-2 text-sm leading-6 text-slate-400">
            If a name is taken, you can pin it and keep it on your radar instead of checking manually again and again.
          </p>
        </div>

        <div class="feature-card rounded-lg p-5 text-left">
          <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-purple-500/15 text-purple-200">
            <i class="fas fa-lightbulb"></i>
          </div>
          <h3 class="mt-4 text-lg font-semibold text-white">Better Alternatives</h3>
          <p class="mt-2 text-sm leading-6 text-slate-400">
            When a domain is unavailable, we help you think through nearby options so the search does not stop there.
          </p>
        </div>
      </section>

      <section class="mx-auto mt-8 max-w-5xl rounded-lg border border-slate-700/60 bg-slate-950/45 p-5 text-left backdrop-blur md:p-7">
        <div class="grid gap-8 md:grid-cols-[260px_minmax(0,1fr)]">
          <div>
            <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Our Promise</p>
            <h2 class="mt-2 text-2xl font-bold text-white">We are here to make naming less stressful.</h2>
          </div>
          <div class="space-y-4 text-sm leading-6 text-slate-300">
            <p>
              Choosing a domain often happens at the exact moment a project is starting to feel real. That can be exciting, but it can also be frustrating when the perfect name is already taken.
            </p>
            <p>
              checkdomain.top is designed to give you a calmer workflow: check names, see the result clearly, review alternatives, and save the domains that matter. We want to help you move from uncertainty to action faster.
            </p>
            <p>
              As we grow, we are building toward smarter alerts, better monitoring, and practical tools that help you protect your brand from the beginning.
            </p>
          </div>
        </div>
      </section>

      <section class="mx-auto mt-8 max-w-5xl rounded-lg border border-sky-400/20 bg-sky-950/20 p-5 text-left backdrop-blur md:flex md:items-center md:justify-between md:gap-6">
        <div>
          <h2 class="text-xl font-bold text-white">Have a name in mind?</h2>
          <p class="mt-2 text-sm text-slate-300">Start with one search. If it is taken, we will help you explore the next best path.</p>
        </div>
        <a href="<?php echo htmlspecialchars($assetUrl('index.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-secondary mt-4 inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-semibold text-white md:mt-0">
          <i class="fas fa-arrow-right"></i>
          Check a Domain
        </a>
      </section>

      <?php require_once 'includes/footer.php'; ?>

</body>
</html>
