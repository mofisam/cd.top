<?php
require_once 'config/database.php';
require_once 'includes/error_handler.php';

trackPageView($_SERVER['REQUEST_URI'] ?? 'homepage');

$popularTLDs     = getPopularTLDs(12);
$popularSearches = getPopularSearches(5);

$pageTitle       = 'CheckDomain — Find, Monitor & Acquire Domain Names';
$pageDescription = 'Check domain availability instantly. Get WHOIS data, set expiry alerts, monitor taken domains, and place backorders — all in one place.';

require_once 'includes/header.php';
?>

<!-- ─────────────────────────────────────────────────
     MAIN SEARCH CARD
──────────────────────────────────────────────────── -->
<div class="glass-card mx-auto mt-2 max-w-5xl p-5 md:p-8">

  <!-- Card header -->
  <div class="flex flex-col gap-4 text-left md:flex-row md:items-center md:justify-between">
    <div class="flex items-center gap-4">
      <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-sky-500/10">
        <img src="<?php echo htmlspecialchars($assetUrl('images/logo.png'), ENT_QUOTES, 'UTF-8'); ?>"
             alt="CheckDomain logo" class="h-10 w-10 object-contain">
      </div>
      <div>
        <h2 class="text-xl font-bold text-white">Check domain availability</h2>
        <p class="text-sm text-slate-400 mt-0.5">Enter a full domain or a keyword — we'll try <span class="text-sky-300 font-mono">.com</span> first.</p>
      </div>
    </div>
    <div class="inline-flex w-fit items-center gap-2 rounded-full border border-green-400/25 bg-green-500/10 px-3 py-1.5 text-xs font-medium text-green-300">
      <i class="fas fa-circle text-[7px] animate-pulse"></i> Live lookup
    </div>
  </div>

  <!-- Search input -->
  <div class="mt-6 w-full relative">
    <div class="flex flex-col gap-3 rounded-xl border border-slate-700/70 bg-slate-950/50 p-3 sm:flex-row sm:items-center">
      <div class="relative w-full">
        <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
          <i class="fas fa-magnifying-glass text-blue-400 text-sm"></i>
        </div>
        <input type="text" id="domainInput"
          placeholder="mybrand.com, startup.io, or just mybrand"
          class="w-full bg-slate-900/80 border border-slate-700 rounded-lg py-4 pl-10 pr-4 text-white placeholder:text-gray-500 focus:outline-none input-glow font-mono text-sm"
          autocomplete="off">
        <div id="suggestionsContainer" class="suggestions-dropdown hidden"></div>
      </div>
      <button id="checkBtn"
        class="btn-primary text-white font-semibold px-7 py-4 rounded-lg transition-all flex items-center gap-2 w-full sm:w-auto justify-center shadow-lg whitespace-nowrap">
        <i class="fas fa-search text-sm"></i> Check Domain
      </button>
    </div>

    <!-- TLD quick-select -->
    <div class="mt-4 flex flex-wrap gap-2">
      <?php foreach ($popularTLDs as $tld):
        $tldValue = ltrim(strtolower($tld['tld']), '.'); ?>
      <span class="tld-badge" data-tld="<?php echo htmlspecialchars($tldValue, ENT_QUOTES, 'UTF-8'); ?>">
        .<?php echo htmlspecialchars($tldValue, ENT_QUOTES, 'UTF-8'); ?>
      </span>
      <?php endforeach; ?>
    </div>

    <!-- Captcha panel -->
    <div id="captchaPanel" class="hidden mt-4 max-w-md rounded-lg border border-yellow-500/40 bg-yellow-950/20 p-4 text-left">
      <label for="captchaAnswer" class="block text-xs font-semibold text-yellow-200 mb-2">
        Quick verification: <span id="captchaQuestion"></span>
      </label>
      <input type="text" id="captchaAnswer"
        class="w-full bg-slate-800/80 border border-yellow-500/40 rounded-lg py-2.5 px-4 text-white placeholder:text-gray-400 focus:outline-none focus:border-yellow-300 text-sm"
        placeholder="Answer">
      <p id="captchaMessage" class="text-xs text-yellow-100/80 mt-2"></p>
    </div>
  </div>

  <!-- Result container -->
  <div id="resultContainer" class="mt-8 transition-all duration-500">
    <div id="availabilityCard" class="hidden"></div>
    <div id="placeholderMsg" class="rounded-xl border border-dashed border-slate-700 bg-slate-950/35 px-5 py-10 text-center flex flex-col items-center gap-3">
      <i class="fas fa-globe text-3xl text-blue-400 opacity-70"></i>
      <span class="font-medium text-slate-300">Enter a domain name above to check availability</span>
      <span class="text-xs text-gray-500">Works with any extension — .com, .io, .ng, .co, and more</span>
    </div>
  </div>
</div>

<!-- ─────────────────────────────────────────────────
     WHAT YOU CAN DO  (4 feature cards)
──────────────────────────────────────────────────── -->
<div class="mx-auto mt-10 max-w-5xl">
  <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 mb-4">What CheckDomain does</p>
  <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4">

    <div class="feature-card rounded-xl p-5 text-left">
      <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-500/10 text-blue-400 text-lg mb-3">
        <i class="fas fa-search"></i>
      </div>
      <p class="text-sm font-semibold text-white">Domain availability</p>
      <p class="mt-1.5 text-xs text-slate-400 leading-relaxed">Instant live WHOIS lookup — know immediately if a domain is free, taken, or expiring soon.</p>
    </div>

    <div class="feature-card rounded-xl p-5 text-left">
      <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-green-500/10 text-green-400 text-lg mb-3">
        <i class="fas fa-bookmark"></i>
      </div>
      <p class="text-sm font-semibold text-white">Watchlist & alerts</p>
      <p class="mt-1.5 text-xs text-slate-400 leading-relaxed">Pin any taken domain to your watchlist and get notified the moment it drops, expires, or goes up for sale.</p>
    </div>

    <div class="feature-card rounded-xl p-5 text-left">
      <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-500/10 text-amber-400 text-lg mb-3">
        <i class="fas fa-clock-rotate-left"></i>
      </div>
      <p class="text-sm font-semibold text-white">Backorder placement</p>
      <p class="mt-1.5 text-xs text-slate-400 leading-relaxed">Queue a backorder on domains approaching expiry. We watch the drop window and attempt registration the moment it frees up.</p>
    </div>

    <div class="feature-card rounded-xl p-5 text-left">
      <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-500/10 text-purple-400 text-lg mb-3">
        <i class="fas fa-skull"></i>
      </div>
      <p class="text-sm font-semibold text-white">Dead site detection</p>
      <p class="mt-1.5 text-xs text-slate-400 leading-relaxed">Find registered domains with no real content — parked, abandoned, or for-sale pages worth targeting for acquisition.</p>
    </div>

  </div>
</div>

<!-- ─────────────────────────────────────────────────
     HOW IT WORKS  (3-step)
──────────────────────────────────────────────────── -->
<div class="mx-auto mt-10 max-w-5xl rounded-xl border border-slate-700/50 bg-slate-950/40 p-6 backdrop-blur">
  <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 mb-6">How it works</p>
  <div class="grid grid-cols-1 gap-6 md:grid-cols-3">

    <div class="flex gap-4 items-start">
      <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-500/15 text-blue-300 font-bold text-sm">1</div>
      <div>
        <p class="text-sm font-semibold text-white mb-1">Search any domain</p>
        <p class="text-xs text-slate-400 leading-relaxed">Type a name or full domain. We check availability in real time using live WHOIS data — no cached guesses.</p>
      </div>
    </div>

    <div class="flex gap-4 items-start">
      <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-green-500/15 text-green-300 font-bold text-sm">2</div>
      <div>
        <p class="text-sm font-semibold text-white mb-1">Pin it or act on it</p>
        <p class="text-xs text-slate-400 leading-relaxed">If it's free — register it. If taken — add it to your watchlist or place a backorder. We'll do the waiting for you.</p>
      </div>
    </div>

    <div class="flex gap-4 items-start">
      <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-purple-500/15 text-purple-300 font-bold text-sm">3</div>
      <div>
        <p class="text-sm font-semibold text-white mb-1">Get notified first</p>
        <p class="text-xs text-slate-400 leading-relaxed">When a watched domain drops or becomes available, you get an alert so you can move before anyone else does.</p>
      </div>
    </div>

  </div>
</div>

<!-- ─────────────────────────────────────────────────
     PLANS  (inline, lightweight)
──────────────────────────────────────────────────── -->
<div class="mx-auto mt-10 max-w-5xl" id="plans">
  <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 mb-4">Plans</p>
  <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">

    <!-- Free -->
    <div class="rounded-xl border border-slate-700/60 bg-slate-950/45 p-5 text-left backdrop-blur">
      <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Free</p>
      <p class="mt-2 text-2xl font-extrabold text-white font-mono">$0<span class="text-sm font-normal text-slate-400">/mo</span></p>
      <p class="mt-2 text-xs text-slate-400 leading-relaxed mb-4">For occasional checks — no credit card needed.</p>
      <ul class="space-y-2 text-xs text-slate-400">
        <li><i class="fas fa-check text-green-400 mr-2"></i>10 credits/month</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>5 watchlist domains</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>Basic availability check</li>
        <li><i class="fas fa-times text-slate-600 mr-2"></i>WHOIS lookup</li>
        <li><i class="fas fa-times text-slate-600 mr-2"></i>Expiry alerts</li>
        <li><i class="fas fa-times text-slate-600 mr-2"></i>Backorders</li>
      </ul>
      <a href="<?php echo htmlspecialchars($assetUrl('register.php'), ENT_QUOTES, 'UTF-8'); ?>"
         class="mt-5 block text-center rounded-lg border border-slate-700 bg-transparent py-2 text-xs font-semibold text-slate-300 hover:border-slate-500 hover:text-white transition">
        Get started free
      </a>
    </div>

    <!-- Pro (highlighted) -->
    <div class="rounded-xl border border-green-400/30 bg-gradient-to-b from-green-950/30 to-slate-950/60 p-5 text-left backdrop-blur relative">
      <span class="absolute top-3 right-3 rounded-full bg-green-500/15 border border-green-400/25 px-2.5 py-0.5 text-[10px] font-semibold text-green-300">Most popular</span>
      <p class="text-xs font-semibold uppercase tracking-widest text-green-400">Pro</p>
      <p class="mt-2 text-2xl font-extrabold text-white font-mono">$9<span class="text-sm font-normal text-slate-400">/mo</span></p>
      <p class="mt-2 text-xs text-slate-400 leading-relaxed mb-4">For serious domain hunters who can't afford to miss a drop.</p>
      <ul class="space-y-2 text-xs text-slate-400">
        <li><i class="fas fa-check text-green-400 mr-2"></i>100 credits/month</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>Unlimited watchlist</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>Full WHOIS deep lookup</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>Expiry &amp; drop alerts</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>Backorder placement</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>Dead site detection</li>
      </ul>
      <a href="<?php echo htmlspecialchars($assetUrl('register.php?plan=pro'), ENT_QUOTES, 'UTF-8'); ?>"
         class="btn-secondary mt-5 block text-center rounded-lg py-2 text-xs font-semibold text-white transition">
        Start with Pro →
      </a>
    </div>

    <!-- Elite -->
    <div class="rounded-xl border border-slate-700/60 bg-slate-950/45 p-5 text-left backdrop-blur">
      <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Elite</p>
      <p class="mt-2 text-2xl font-extrabold text-white font-mono">$29<span class="text-sm font-normal text-slate-400">/mo</span></p>
      <p class="mt-2 text-xs text-slate-400 leading-relaxed mb-4">Full access — including broker service for domains you can't get any other way.</p>
      <ul class="space-y-2 text-xs text-slate-400">
        <li><i class="fas fa-check text-green-400 mr-2"></i>500 credits/month</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>Everything in Pro</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>Domain broker service</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>Bulk domain lookup</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>Priority alert delivery</li>
        <li><i class="fas fa-check text-green-400 mr-2"></i>365-day search history</li>
      </ul>
      <a href="<?php echo htmlspecialchars($assetUrl('register.php?plan=elite'), ENT_QUOTES, 'UTF-8'); ?>"
         class="mt-5 block text-center rounded-lg border border-slate-700 bg-transparent py-2 text-xs font-semibold text-slate-300 hover:border-slate-500 hover:text-white transition">
        Get Elite →
      </a>
    </div>

  </div>
</div>

<!-- ─────────────────────────────────────────────────
     ALERT SIGNUP
──────────────────────────────────────────────────── -->
<div class="mx-auto mt-10 max-w-5xl rounded-xl border border-sky-400/20 bg-sky-950/20 p-6 backdrop-blur md:flex md:items-center md:justify-between md:gap-8">
  <div class="shrink-0">
    <h3 class="text-base font-bold text-white">Get domain drop alerts</h3>
    <p class="mt-1 text-sm text-slate-400 max-w-sm">Tell us your email. We'll notify you when a domain you're watching becomes available — before anyone else grabs it.</p>
  </div>
  <div class="mt-4 flex flex-col gap-3 md:mt-0 md:flex-1 sm:flex-row max-w-lg w-full">
    <input type="text" id="subscriberName" placeholder="Your name (optional)"
      class="flex-1 bg-slate-900/80 border border-slate-700 rounded-lg py-2.5 px-4 text-white placeholder:text-gray-500 focus:outline-none focus:border-blue-400 text-sm">
    <input type="email" id="subscriberEmail" placeholder="you@example.com"
      class="flex-1 bg-slate-900/80 border border-slate-700 rounded-lg py-2.5 px-4 text-white placeholder:text-gray-500 focus:outline-none focus:border-blue-400 text-sm">
    <button id="subscribeBtn"
      class="btn-secondary text-white font-semibold px-5 py-2.5 rounded-lg transition flex items-center gap-2 justify-center whitespace-nowrap">
      <i class="fas fa-bell"></i> Get alerts
    </button>
  </div>
</div>

<!-- ─────────────────────────────────────────────────
     QUICK TIPS
──────────────────────────────────────────────────── -->
<div class="mx-auto mt-8 max-w-5xl rounded-xl border border-slate-700/50 bg-slate-950/35 p-5 backdrop-blur text-left">
  <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500 mb-4 flex items-center gap-2">
    <i class="fas fa-lightbulb text-yellow-400"></i> Domain tips
  </h3>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5 text-xs text-slate-400 leading-relaxed">
    <div><i class="fas fa-check text-green-400 mr-2"></i>Short names register faster in memory — aim for under 12 characters.</div>
    <div><i class="fas fa-check text-green-400 mr-2"></i>Use keywords that match what your business actually does.</div>
    <div><i class="fas fa-check text-green-400 mr-2"></i>Avoid hyphens — they look untrustworthy and are hard to say aloud.</div>
    <div><i class="fas fa-check text-green-400 mr-2"></i>If <span class="font-mono text-sky-300">.com</span> is taken, check <span class="font-mono text-sky-300">.co</span>, <span class="font-mono text-sky-300">.io</span>, and <span class="font-mono text-sky-300">.ng</span> first.</div>
    <div><i class="fas fa-check text-green-400 mr-2"></i>Domains expiring soon are often your best acquisition window — backorder early.</div>
    <div><i class="fas fa-check text-green-400 mr-2"></i>When you find the right
     one — don't wait. Domains get snapped up fast.</div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>

<script>
const APP_BASE_PATH = <?php echo json_encode($appBasePath ?? ''); ?>;
const appUrl = (path) => `${APP_BASE_PATH}/${String(path).replace(/^\/+/, '')}`;

let watchlistDomains = new Map();
let suggestionsTimeout;

// ── Watchlist ────────────────────────────────────────────
async function loadWatchlistFromDatabase() {
  try {
    const res = await fetch(appUrl('api/watchlist-domain.php'));
    if (res.status === 401) { updateWatchlistBadge(); return; }
    const data = await parseJson(res);
    if (data.success && Array.isArray(data.domains)) {
      watchlistDomains = new Map(data.domains.map(d => [d.domain, d]));
      updateWatchlistBadge();
    }
  } catch(e) { console.warn(e); }
}

function updateWatchlistBadge() {
  let badge = document.getElementById('wlBadge');
  if (watchlistDomains.size > 0) {
    if (!badge) {
      badge = document.createElement('div');
      badge.id = 'wlBadge';
      badge.className = 'fixed bottom-20 left-4 bg-slate-900/80 backdrop-blur rounded-full px-3 py-1.5 text-xs border border-blue-500/60 z-30 flex items-center gap-2 cursor-pointer hover:scale-105 transition';
      badge.onclick = () => { window.location.href = appUrl('dashboard.php'); };
      document.body.appendChild(badge);
    }
    badge.innerHTML = `<i class="fas fa-bookmark text-green-400"></i> ${watchlistDomains.size} watchlist`;
  } else if (badge) { badge.remove(); }
}

async function addToWatchlist(domain) {
  const res = await fetch(appUrl('api/watchlist-domain.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ domain })
  });
  if (res.status === 401) {
    showToast('Login to save domains to your watchlist.', true);
    setTimeout(() => { window.location.href = appUrl('login.php'); }, 900);
    return { success: false };
  }
  return await parseJson(res);
}

// ── Utilities ────────────────────────────────────────────
async function parseJson(res) {
  const text = await res.text();
  try { return JSON.parse(text); }
  catch { throw new Error(`Unexpected server response from ${res.url}`); }
}

function showToast(msg, isError = false) {
  const t = document.getElementById('toastMsg');
  document.getElementById('toastText').innerText = msg;
  t.classList.remove('opacity-0');
  t.classList.add('opacity-100', 'pointer-events-auto');
  setTimeout(() => {
    t.classList.remove('opacity-100', 'pointer-events-auto');
    t.classList.add('opacity-0');
  }, 3200);
}

function esc(s) {
  if (!s) return 'N/A';
  return String(s).replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[m]));
}

function normalizeDomain(v) {
  let d = String(v||'').trim().toLowerCase().replace(/^https?:\/\//,'').split('/')[0];
  if (d && !d.includes('.')) d += '.com';
  return d;
}

function validateDomain(domain) {
  if (!domain) return { valid:false, message:'Please enter a domain name' };
  if (domain.length > 253) return { valid:false, message:'Domain name is too long' };
  if (/\s|[^a-z0-9.-]/i.test(domain)) return { valid:false, message:'Domain can only contain letters, numbers, dots and hyphens' };
  if (domain.startsWith('.') || domain.endsWith('.') || domain.includes('..')) return { valid:false, message:'Invalid dot placement' };
  const labels = domain.split('.');
  if (labels.length < 2) return { valid:false, message:'Domain must include a TLD (e.g. .com)' };
  for (const l of labels) {
    if (l.length < 1 || l.length > 63) return { valid:false, message:'Each label must be 1–63 characters' };
    if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i.test(l)) return { valid:false, message:'Labels cannot start or end with a hyphen' };
  }
  if (!/^[a-z]{2,63}$/i.test(labels[labels.length-1])) return { valid:false, message:'Invalid TLD' };
  return { valid:true };
}

// ── Suggestions ──────────────────────────────────────────
async function getSuggestions(query) {
  if (query.length < 2) { document.getElementById('suggestionsContainer').classList.add('hidden'); return; }
  try {
    const res = await fetch(appUrl(`api/suggestions.php?q=${encodeURIComponent(query)}&type=domains`));
    const data = await parseJson(res);
    if (data.success && data.suggestions.length > 0) showSuggestions(data.suggestions);
    else document.getElementById('suggestionsContainer').classList.add('hidden');
  } catch(e) { console.error(e); }
}

function showSuggestions(list) {
  const c = document.getElementById('suggestionsContainer');
  c.innerHTML = '';
  list.forEach(s => {
    const div = document.createElement('div');
    div.className = 'suggestion-item flex justify-between items-center';
    div.innerHTML = `<span class="font-mono">${esc(s.text)}</span><i class="fas fa-search text-blue-400 text-xs"></i>`;
    div.onclick = () => { document.getElementById('domainInput').value = s.text; c.classList.add('hidden'); performCheck(); };
    c.appendChild(div);
  });
  c.classList.remove('hidden');
}

// ── Captcha ──────────────────────────────────────────────
function showCaptcha(data) {
  document.getElementById('captchaQuestion').innerText = data.captcha?.question || 'Please answer';
  document.getElementById('captchaMessage').innerText = data.message || '';
  document.getElementById('captchaPanel').classList.remove('hidden');
  document.getElementById('captchaAnswer').focus();
}
function hideCaptcha() {
  document.getElementById('captchaPanel').classList.add('hidden');
  document.getElementById('captchaAnswer').value = '';
}

// ── Render result ─────────────────────────────────────────
function renderResult(domain, data) {
  const card = document.getElementById('availabilityCard');
  const ph   = document.getElementById('placeholderMsg');
  const norm = domain.toLowerCase();
  const inWl = watchlistDomains.has(norm);

  let html = '';

  if (data.available) {
    html = `
      <div class="result-card space-y-4 text-center">
        <div class="inline-flex items-center justify-center gap-3 text-green-300 bg-green-950/30 rounded-2xl py-4 px-6 border border-green-500/40 mx-auto">
          <i class="fas fa-check-circle text-3xl"></i>
          <span class="text-xl font-bold">${esc(domain)} is <span class="text-green-300">available!</span></span>
        </div>
        <p class="text-slate-300 text-sm">This domain is free to register right now. Don't wait — available domains get picked up fast.</p>
        <div class="flex justify-center gap-3 flex-wrap">
          <a href="https://www.namecheap.com/domains/registration/results/?domain=${encodeURIComponent(domain)}" target="_blank" rel="noopener"
             class="bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-500 hover:to-emerald-500 text-white px-6 py-2.5 rounded-xl text-sm font-semibold transition flex items-center gap-2">
            <i class="fas fa-shopping-cart"></i> Register now
          </a>
        </div>
        <div class="p-3 bg-blue-900/20 rounded-xl text-xs text-blue-300">
          <i class="fas fa-info-circle mr-1"></i> Consider registering multiple extensions to protect your brand — <span class="font-mono">.com</span>, <span class="font-mono">.co</span>, <span class="font-mono">.io</span>.
        </div>
      </div>`;
  } else {
    const base = norm.split('.')[0];
    const alts = [`${base}.io`,`${base}.co`,`${base}.net`,`get${base}.com`,`${base}hq.com`];
    const expiry   = data.expiryDate && data.expiryDate !== 'N/A'
      ? `<span class="text-xs text-amber-300 ml-1 font-mono">(expires ${esc(data.expiryDate)})</span>` : '';
    html = `
      <div class="result-card space-y-4">
        <div class="flex items-center gap-3 text-rose-300 bg-rose-950/30 rounded-2xl py-4 px-6 border border-rose-500/30">
          <i class="fas fa-lock text-2xl shrink-0"></i>
          <div class="text-left">
            <span class="text-lg font-bold">${esc(domain)} is taken</span>
            <p class="text-xs text-slate-400 mt-0.5">Registered and active — but you can monitor it.</p>
          </div>
        </div>

        <div class="bg-slate-800/50 rounded-xl p-5 text-left space-y-2.5 text-sm">
          <h3 class="font-semibold text-blue-300 text-xs uppercase tracking-wider flex items-center gap-2 mb-3"><i class="fas fa-file-alt"></i> Registration details</h3>
          <div class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-xs">
            <span class="text-slate-500">Registrar</span><span class="text-slate-200 font-mono break-all">${esc(data.registrar)}</span>
            <span class="text-slate-500">Created</span><span class="text-slate-200 font-mono">${esc(data.creationDate)}</span>
            <span class="text-slate-500">Expires</span><span class="text-slate-200 font-mono">${esc(data.expiryDate)}${expiry}</span>
          </div>
        </div>

        <div class="bg-slate-900/50 rounded-xl p-4 text-left">
          <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Try these alternatives</h4>
          <div class="flex flex-wrap gap-2">
            ${alts.map(a => `<span class="tld-badge suggestion-alt cursor-pointer" data-domain="${esc(a)}">${esc(a)}</span>`).join('')}
          </div>
        </div>

        ${!inWl ? `
        <div class="bg-slate-900/40 rounded-xl p-4 text-left flex items-start gap-4">
          <div class="shrink-0 h-9 w-9 flex items-center justify-center rounded-lg bg-blue-500/10 text-blue-400"><i class="fas fa-bell"></i></div>
          <div class="flex-1">
            <p class="text-sm font-semibold text-white">Watch this domain</p>
            <p class="text-xs text-slate-400 mt-0.5">Get alerted if it drops, expires, or goes up for sale. We track it — you just wait.</p>
          </div>
          <button id="watchlistDomainBtn"
            class="btn-secondary text-white font-semibold px-4 py-2 rounded-lg text-xs flex items-center gap-2 shrink-0 transition">
            <i class="fas fa-bookmark"></i> Watch it
          </button>
        </div>` : `
        <div class="bg-blue-900/25 rounded-xl p-3.5 text-sm text-blue-300 flex items-center gap-2">
          <i class="fas fa-check-circle text-green-400"></i>
          Already on your watchlist — we'll alert you when it becomes available.
        </div>`}

        ${data.expiryDate && data.expiryDate !== 'N/A' ? `
        <div class="bg-amber-900/20 rounded-xl p-3.5 text-left flex items-start gap-3 border border-amber-500/20">
          <i class="fas fa-clock-rotate-left text-amber-400 mt-0.5 shrink-0"></i>
          <div>
            <p class="text-xs font-semibold text-amber-200">Want to backorder this domain?</p>
            <p class="text-xs text-slate-400 mt-0.5">If the owner doesn't renew, we can attempt to register it the moment it drops.</p>
            <a href="${appUrl('register.php?plan=pro')}" class="inline-block mt-2 text-xs font-semibold text-amber-300 hover:text-amber-200 transition">
              Place a backorder <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
          </div>
        </div>` : ''}
      </div>`;
  }

  card.innerHTML = html;
  card.classList.remove('hidden');
  ph.classList.add('hidden');

  document.querySelectorAll('.suggestion-alt').forEach(el => {
    el.addEventListener('click', () => {
      document.getElementById('domainInput').value = el.dataset.domain;
      performCheck();
    });
  });

  if (!data.available && !inWl) {
    document.getElementById('watchlistDomainBtn')?.addEventListener('click', () => handleWatchlist(norm));
  }
}

async function handleWatchlist(domain) {
  if (watchlistDomains.has(domain)) { showToast(`"${domain}" is already on your watchlist.`); return; }
  const btn = document.getElementById('watchlistDomainBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Adding…'; }
  try {
    const data = await addToWatchlist(domain);
    if (!data.success) { showToast(data.message || 'Could not add to watchlist.', true); return; }
    watchlistDomains.set(data.domain || domain, { watchlistDate: new Date().toISOString() });
    updateWatchlistBadge();
    showToast(data.message || `"${domain}" added to your watchlist.`);
    performCheck();
  } catch(e) {
    showToast(e.message || 'Error adding to watchlist.', true);
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-bookmark"></i> Watch it'; }
  }
}

function renderError(msg) {
  const card = document.getElementById('availabilityCard');
  document.getElementById('placeholderMsg').classList.add('hidden');
  card.classList.remove('hidden');
  card.innerHTML = `<div class="text-center py-8 text-red-400"><i class="fas fa-exclamation-triangle text-4xl mb-3"></i><p class="font-medium">${esc(msg)}</p></div>`;
}

// ── Main check function ───────────────────────────────────
async function performCheck() {
  const input  = document.getElementById('domainInput');
  const domain = normalizeDomain(input.value);
  input.value  = domain;

  const v = validateDomain(domain);
  if (!v.valid) { showToast(v.message, true); renderError(v.message); return; }

  const card    = document.getElementById('availabilityCard');
  const captcha = document.getElementById('captchaAnswer')?.value.trim() || '';

  document.getElementById('placeholderMsg').classList.add('hidden');
  card.classList.remove('hidden');
  card.innerHTML = `
    <div class="flex flex-col items-center justify-center py-12 gap-3">
      <i class="fas fa-spinner fa-pulse text-4xl text-blue-400"></i>
      <span class="text-blue-300 font-medium text-sm">Checking <span class="font-mono">${esc(domain)}</span>…</span>
    </div>`;

  try {
    const res = await fetch(appUrl('api/checkingdomain.php'), {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ domain, captcha })
    });
    const data = await parseJson(res);

    if (data.requiresCaptcha) { showCaptcha(data); renderError(data.message || 'Complete the verification to continue.'); return; }
    if (data.error || data.success === false) { const m = data.error||data.message||'Unable to check this domain.'; showToast(m,true); renderError(m); return; }

    hideCaptcha();
    renderResult(domain, data);
  } catch(e) {
    card.innerHTML = `<div class="text-center py-8 text-red-400"><i class="fas fa-exclamation-triangle text-4xl mb-3"></i><p class="font-medium">${esc(e.message||'Unable to check domain status')}</p><p class="text-sm mt-2 text-gray-400">Please try again.</p></div>`;
  }
}

// ── Subscribe ─────────────────────────────────────────────
document.getElementById('subscribeBtn')?.addEventListener('click', async () => {
  const email = document.getElementById('subscriberEmail')?.value.trim();
  const name  = document.getElementById('subscriberName')?.value.trim();
  if (!email) { showToast('Please enter your email address.', true); return; }
  const btn = document.getElementById('subscribeBtn');
  const orig = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Subscribing…'; btn.disabled = true;
  try {
    const res = await fetch(appUrl('api/subscribe.php'), { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ email, name, source:'website' }) });
    const data = await parseJson(res);
    showToast(data.message || (data.success ? 'Subscribed!' : 'Subscription failed.'), !data.success);
    if (data.success) { document.getElementById('subscriberEmail').value = ''; document.getElementById('subscriberName').value = ''; }
  } catch(e) { showToast('Network error. Please try again.', true); }
  finally { btn.innerHTML = orig; btn.disabled = false; }
});

// ── Event listeners ───────────────────────────────────────
document.getElementById('checkBtn').addEventListener('click', performCheck);
document.getElementById('domainInput').addEventListener('keypress', e => { if (e.key==='Enter') performCheck(); });
document.getElementById('captchaAnswer')?.addEventListener('keypress', e => { if (e.key==='Enter') performCheck(); });
document.getElementById('domainInput').addEventListener('input', e => {
  clearTimeout(suggestionsTimeout);
  suggestionsTimeout = setTimeout(() => getSuggestions(e.target.value), 300);
});
document.addEventListener('click', e => {
  if (!e.target.closest('#domainInput') && !e.target.closest('#suggestionsContainer'))
    document.getElementById('suggestionsContainer').classList.add('hidden');
});
document.querySelectorAll('.tld-badge:not(.suggestion-alt)').forEach(b => {
  b.addEventListener('click', () => {
    const tld  = String(b.dataset.tld||'').replace(/^\.+/,'').toLowerCase();
    const curr = normalizeDomain(document.getElementById('domainInput').value);
    let name   = curr ? curr.split('.')[0] : 'mybrand';
    if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i.test(name)) name = 'mybrand';
    document.getElementById('domainInput').value = `${name}.${tld}`;
    performCheck();
  });
});
document.querySelectorAll('.popular-search').forEach(el => {
  el.addEventListener('click', () => { document.getElementById('domainInput').value = el.dataset.search; performCheck(); });
});

// ── Init ──────────────────────────────────────────────────
loadWatchlistFromDatabase();

const examples = ['mybrand','startup','techco','mystore','launchpad'];
let ei = 0;
setInterval(() => {
  const inp = document.getElementById('domainInput');
  if (!inp.value && document.activeElement !== inp) {
    inp.placeholder = `Try: ${examples[ei++ % examples.length]}.com`;
  }
}, 4000);
</script>
</body>
</html>