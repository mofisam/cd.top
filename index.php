<?php
require_once 'config/database.php';
require_once 'includes/error_handler.php';

// Track page view
trackPageView($_SERVER['REQUEST_URI'] ?? 'homepage');

// Get popular TLDs for display
$popularTLDs = getPopularTLDs(12);
$popularSearches = getPopularSearches(5);
require_once 'includes/header.php';
?>
      <!-- Main Search Section -->
      <div class="glass-card mx-auto mt-2 max-w-5xl p-5 md:p-7">
        <div class="flex flex-col gap-4 text-left md:flex-row md:items-center md:justify-between">
          <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-sky-500/15 text-sky-200">
              <i class="fas fa-magnifying-glass-chart text-xl"></i>
            </div>
            <div>
              <h2 class="text-2xl font-semibold text-white">Search domain availability</h2>
              <p class="text-sm text-gray-300">Enter a full domain or type a name and we will try .com first.</p>
            </div>
          </div>
          <div class="inline-flex w-fit items-center gap-2 rounded-full border border-green-400/25 bg-green-500/10 px-3 py-1.5 text-xs font-medium text-green-200">
            <i class="fas fa-circle text-[7px]"></i>
            Live lookup
          </div>
        </div>
        
        <!-- Search Input with Suggestions -->
        <div class="mt-6 w-full relative">
          <div class="flex flex-col gap-3 rounded-lg border border-slate-700/70 bg-slate-950/50 p-3 sm:flex-row sm:items-center">
            <div class="relative w-full">
              <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                <i class="fas fa-link text-blue-400 text-sm"></i>
              </div>
              <input type="text" id="domainInput" 
                placeholder="mybrand.com, startup.io, or just mybrand" 
                class="w-full bg-slate-900/80 border border-slate-700 rounded-lg py-4 pl-10 pr-4 text-white placeholder:text-gray-500 focus:outline-none input-glow font-mono text-sm"
                autocomplete="off">
              <div id="suggestionsContainer" class="suggestions-dropdown hidden"></div>
            </div>
            <button id="checkBtn" class="btn-primary text-white font-semibold px-6 py-4 rounded-lg transition-all flex items-center gap-2 w-full sm:w-auto justify-center shadow-lg">
              <i class="fas fa-search text-sm"></i> Check Domain
            </button>
          </div>
          
          <!-- Popular TLDs Quick Select -->
          <div class="mt-4 flex flex-wrap gap-2">
            <?php foreach ($popularTLDs as $tld): ?>
            <?php $tldValue = ltrim(strtolower($tld['tld']), '.'); ?>
            <span class="tld-badge" data-tld="<?php echo htmlspecialchars($tldValue, ENT_QUOTES, 'UTF-8'); ?>">
              .<?php echo htmlspecialchars($tldValue, ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <?php endforeach; ?>
          </div>
          
          <!-- Popular Searches -->
          <?php if (!empty($popularSearches)): ?>
          <div class="mt-5 text-left">
            <p class="text-xs text-gray-400 mb-2">Popular searches:</p>
            <div class="flex flex-wrap gap-2">
              <?php foreach ($popularSearches as $search): ?>
              <span class="rounded-full bg-slate-900/70 px-3 py-1.5 text-xs text-blue-300 cursor-pointer hover:text-blue-200 popular-search" data-search="<?php echo htmlspecialchars($search['search_term']); ?>">
                <?php echo htmlspecialchars($search['search_term']); ?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

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
        
        <!-- Results Container -->
        <div id="resultContainer" class="mt-8 transition-all duration-500">
          <div id="availabilityCard" class="hidden"></div>
          <div id="placeholderMsg" class="rounded-lg border border-dashed border-slate-700 bg-slate-950/35 px-5 py-8 text-center text-gray-400 text-sm flex flex-col items-center gap-3">
            <i class="fas fa-chart-line text-3xl opacity-80 text-blue-400"></i>
            <span class="font-medium text-slate-300">Enter a domain name to check availability</span>
            <span class="text-xs text-gray-500">Try: mybrand, startup, techcompany, or any extension</span>
          </div>
        </div>
      </div>
      
      <!-- Features Grid -->
      <div class="mx-auto grid max-w-5xl grid-cols-1 gap-4 mt-8 md:grid-cols-4">
        <div class="feature-card rounded-lg p-4 text-left">
          <i class="fas fa-bolt text-yellow-400 text-xl"></i>
          <p class="mt-3 text-sm font-semibold">Instant Check</p>
          <p class="mt-1 text-xs text-slate-400">Quick status feedback for common domains.</p>
        </div>
        <div class="feature-card rounded-lg p-4 text-left">
          <i class="fas fa-thumbtack text-green-400 text-xl"></i>
          <p class="mt-3 text-sm font-semibold">Pin & Monitor</p>
          <p class="mt-1 text-xs text-slate-400">Save taken names for future alerts.</p>
        </div>
        <div class="feature-card rounded-lg p-4 text-left">
          <i class="fas fa-lightbulb text-purple-400 text-xl"></i>
          <p class="mt-3 text-sm font-semibold">Smart Suggestions</p>
          <p class="mt-1 text-xs text-slate-400">Try practical alternatives when a name is taken.</p>
        </div>
        <div class="feature-card rounded-lg p-4 text-left">
          <i class="fas fa-bell text-red-400 text-xl"></i>
          <p class="mt-3 text-sm font-semibold">Availability Alerts</p>
          <p class="mt-1 text-xs text-slate-400">Subscribe for launch and availability updates.</p>
        </div>
      </div>
      
      <!-- Domain Tips Section -->
      <div class="mx-auto mt-8 max-w-5xl rounded-lg border border-slate-700/60 bg-slate-950/45 p-5 text-left backdrop-blur">
        <h3 class="text-sm font-semibold mb-2 flex items-center gap-2">
          <i class="fas fa-lightbulb text-yellow-400"></i> Domain Name Tips
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs text-gray-300">
          <div><i class="fas fa-check text-green-400 mr-2"></i>Keep it short and memorable, ideally 6-14 characters</div>
          <div><i class="fas fa-check text-green-400 mr-2"></i>Use keywords that match your business or product</div>
          <div><i class="fas fa-check text-green-400 mr-2"></i>Avoid numbers and hyphens when possible</div>
          <div><i class="fas fa-check text-green-400 mr-2"></i>Consider .com, .io, .co, and .app for professional projects</div>
          <div><i class="fas fa-check text-green-400 mr-2"></i>Check trademarks before purchasing</div>
          <div><i class="fas fa-check text-green-400 mr-2"></i>Move quickly when you find the right available domain</div>
        </div>
      </div>
      
      <!-- Early Access Form -->
      <div class="mx-auto mt-8 max-w-5xl rounded-lg border border-sky-400/20 bg-sky-950/20 p-5 text-left backdrop-blur md:flex md:items-center md:justify-between md:gap-6">
        <div>
          <h3 class="text-lg font-semibold text-white mb-2">Get Domain Alerts</h3>
          <p class="text-gray-300 text-sm">Be notified when your dream domain becomes available.</p>
        </div>
        <div class="mt-4 flex flex-col gap-3 md:mt-0 md:flex-1 sm:flex-row lg:min-w-[520px]">
          <input type="text" id="subscriberName" placeholder="Your name (optional)" 
            class="flex-1 bg-slate-900/80 border border-slate-700 rounded-lg py-2.5 px-4 text-white placeholder:text-gray-400 focus:outline-none focus:border-blue-400 text-sm">
          <input type="email" id="subscriberEmail" placeholder="Your email address" 
            class="flex-1 bg-slate-900/80 border border-slate-700 rounded-lg py-2.5 px-4 text-white placeholder:text-gray-400 focus:outline-none focus:border-blue-400 text-sm">
          <button id="subscribeBtn" class="btn-secondary text-white font-medium px-5 py-2.5 rounded-lg transition flex items-center gap-2 justify-center">
            <i class="fas fa-bell"></i> Subscribe
          </button>
        </div>
      </div>
      
      <?php require_once 'includes/footer.php'; ?>

  <script>
    const APP_BASE_PATH = <?php echo json_encode($appBasePath ?? ''); ?>;
    const appUrl = (path) => `${APP_BASE_PATH}/${String(path).replace(/^\/+/, '')}`;
    let pinnedDomains = new Map();
    let suggestionsTimeout;
    
    function loadPinnedFromStorage() {
      const stored = localStorage.getItem("checkdomain_pins");
      if(stored) {
        try {
          const parsed = JSON.parse(stored);
          pinnedDomains = new Map(Object.entries(parsed));
          updatePinnedBadge();
        } catch(e) { console.warn(e); }
      }
    }
    
    function savePinnedToStorage() {
      const obj = Object.fromEntries(pinnedDomains);
      localStorage.setItem("checkdomain_pins", JSON.stringify(obj));
      updatePinnedBadge();
    }
    
    function updatePinnedBadge() {
      let badge = document.getElementById('pinnedCounterBadge');
      if(pinnedDomains.size > 0) {
        if(!badge) {
          badge = document.createElement('div');
          badge.id = 'pinnedCounterBadge';
          badge.className = 'fixed bottom-20 left-4 bg-slate-900/80 backdrop-blur rounded-full px-3 py-1.5 text-xs border border-blue-500/60 z-30 flex items-center gap-2 cursor-pointer hover:scale-105 transition';
          badge.onclick = () => showToast(`You have ${pinnedDomains.size} pinned domain(s). You'll get alerts when they become available.`, false);
          document.body.appendChild(badge);
        }
        badge.innerHTML = `<i class="fas fa-thumbtack text-green-400"></i> ${pinnedDomains.size} pinned domain${pinnedDomains.size > 1 ? 's' : ''}`;
      } else if(badge) {
        badge.remove();
      }
    }
    
    function showToast(message, isError = false) {
      const toast = document.getElementById('toastMsg');
      const toastSpan = document.getElementById('toastText');
      toastSpan.innerText = message;
      toast.classList.remove('opacity-0');
      toast.classList.add('opacity-100', 'pointer-events-auto');
      setTimeout(() => {
        toast.classList.remove('opacity-100', 'pointer-events-auto');
        toast.classList.add('opacity-0');
      }, 3200);
    }
    
    async function parseJsonResponse(response) {
      const text = await response.text();

      try {
        return JSON.parse(text);
      } catch (error) {
        throw new Error(`Unexpected server response from ${response.url}. Check that the API path exists.`);
      }
    }
    
    // Get suggestions as user types
    async function getSuggestions(query) {
      if (query.length < 2) {
        document.getElementById('suggestionsContainer').classList.add('hidden');
        return;
      }
      
      try {
        const response = await fetch(appUrl(`api/suggestions.php?q=${encodeURIComponent(query)}&type=domains`));
        const data = await parseJsonResponse(response);
        
        if (data.success && data.suggestions.length > 0) {
          showSuggestions(data.suggestions);
        } else {
          document.getElementById('suggestionsContainer').classList.add('hidden');
        }
      } catch (error) {
        console.error('Suggestions error:', error);
      }
    }
    
    function showSuggestions(suggestions) {
      const container = document.getElementById('suggestionsContainer');
      container.innerHTML = '';
      
      suggestions.forEach(sugg => {
        const div = document.createElement('div');
        div.className = 'suggestion-item flex justify-between items-center';
        div.innerHTML = `
          <div>
            <span class="font-mono">${escapeHtml(sugg.text)}</span>
            ${sugg.count ? `<span class="text-xs text-gray-400 ml-2">(${sugg.count} searches)</span>` : ''}
          </div>
          <i class="fas fa-search text-blue-400 text-xs"></i>
        `;
        div.onclick = () => {
          document.getElementById('domainInput').value = sugg.text;
          container.classList.add('hidden');
          performCheck();
        };
        container.appendChild(div);
      });
      
      container.classList.remove('hidden');
    }
    
    async function subscribe(email, name) {
      const response = await fetch(appUrl('api/subscribe.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email, name: name, source: 'website' })
      });
      return await parseJsonResponse(response);
    }
    
    async function checkDomainAPI(domain, captchaAnswer = '') {
      const response = await fetch(appUrl('api/check-domain.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ domain: domain, captcha: captchaAnswer })
      });
      return await parseJsonResponse(response);
    }
    
    function renderResult(domain, data) {
      const resultContainer = document.getElementById('availabilityCard');
      const placeholderMsg = document.getElementById('placeholderMsg');
      const normalizedDomain = domain.toLowerCase();
      const isPinned = pinnedDomains.has(normalizedDomain);
      
      let html = '';
      
      if (data.available) {
        html = `
          <div class="result-card space-y-4 text-center">
            <div class="flex items-center justify-center gap-3 text-green-300 bg-green-950/30 rounded-2xl py-4 px-6 inline-flex mx-auto border border-green-500/40">
              <i class="fas fa-check-circle text-3xl animate-pulse"></i>
              <span class="text-xl font-bold">${escapeHtml(domain)} is AVAILABLE!</span>
            </div>
            <p class="text-gray-300 text-md">Congratulations. This domain is ready for registration.</p>
            <div class="mt-4 flex justify-center gap-3">
              <button class="bg-gradient-to-r from-green-600 to-emerald-600 text-white px-6 py-2 rounded-xl text-sm font-medium transition">
                <i class="fas fa-shopping-cart"></i> Register Now
              </button>
              <button class="bg-slate-700 hover:bg-slate-600 text-white px-6 py-2 rounded-xl text-sm font-medium transition">
                <i class="fas fa-heart"></i> Save for Later
              </button>
            </div>
            <div class="mt-2 p-3 bg-blue-900/20 rounded-xl">
              <p class="text-xs text-blue-300">Tip: Consider buying multiple extensions to protect your brand.</p>
            </div>
          </div>
        `;
      } else {
        // Generate alternative suggestions
        const namePart = domain.split('.')[0];
        const alternatives = [
          `${namePart}.io`, `${namePart}.co`, `${namePart}.net`, 
          `get${namePart}.com`, `go${namePart}.com`
        ];
        
        html = `
          <div class="result-card space-y-4 text-center">
            <div class="flex items-center justify-center gap-3 text-rose-300 bg-rose-950/30 rounded-2xl py-4 px-6 inline-flex mx-auto border border-rose-500/30">
              <i class="fas fa-lock text-2xl"></i>
              <span class="text-xl font-bold">${escapeHtml(domain)} is TAKEN</span>
            </div>
            
            <div class="bg-slate-800/50 rounded-xl p-5 text-left space-y-3">
              <h3 class="font-semibold text-blue-300 text-sm flex items-center gap-2"><i class="fas fa-info-circle"></i> Registration Details</h3>
              <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="text-gray-400">Registrar:</div>
                <div class="text-gray-200 font-mono text-xs break-all">${escapeHtml(data.registrar)}</div>
                <div class="text-gray-400">Created:</div>
                <div class="text-gray-200 font-mono text-xs">${escapeHtml(data.creationDate)}</div>
                <div class="text-gray-400">Expires:</div>
                <div class="text-gray-200 font-mono text-xs">${escapeHtml(data.expiryDate)}</div>
              </div>
            </div>
            
            <div class="bg-purple-900/20 rounded-xl p-4">
              <h4 class="text-sm font-semibold mb-2">Alternative Suggestions</h4>
              <div class="flex flex-wrap justify-center gap-2">
                ${alternatives.map(alt => `<span class="tld-badge suggestion-alt" data-domain="${alt}">${alt}</span>`).join('')}
              </div>
            </div>
            
            <p class="text-gray-300 text-sm">Pin this domain and we'll notify you when it becomes available.</p>
            
            ${!isPinned ? 
              `<div class="mt-2">
                <button id="pinDomainBtn" class="btn-secondary text-white font-medium px-6 py-2.5 rounded-xl shadow-lg flex items-center gap-2 mx-auto transition">
                  <i class="fas fa-thumbtack"></i> Pin & Get Alerts
                </button>
              </div>` : 
              `<div class="mt-2 bg-blue-900/40 rounded-xl p-3 text-sm text-blue-300">
                <i class="fas fa-check-circle"></i> Already pinned! You'll receive alerts.
              </div>`
            }
          </div>
        `;
      }
      
      resultContainer.innerHTML = html;
      resultContainer.classList.remove('hidden');
      placeholderMsg.classList.add('hidden');
      
      // Attach alternative suggestion handlers
      document.querySelectorAll('.suggestion-alt').forEach(el => {
        el.addEventListener('click', () => {
          document.getElementById('domainInput').value = el.dataset.domain;
          performCheck();
        });
      });
      
      if (!data.available && !isPinned) {
        const pinBtn = document.getElementById('pinDomainBtn');
        if (pinBtn) pinBtn.addEventListener('click', () => handlePinDomain(normalizedDomain));
      }
    }
    
    function handlePinDomain(domain) {
      if(pinnedDomains.has(domain)) {
        showToast(`"${domain}" is already pinned.`, false);
        return;
      }
      pinnedDomains.set(domain, { pinDate: new Date().toISOString() });
      savePinnedToStorage();
      showToast(`Pinned "${domain}". You'll be notified when available.`, false);
    }
    
    function escapeHtml(str) {
      if (!str) return 'N/A';
      return String(str).replace(/[&<>]/g, function(m) {
        if(m === '&') return '&amp;';
        if(m === '<') return '&lt;';
        if(m === '>') return '&gt;';
        return m;
      });
    }

    function normalizeDomainInput(value) {
      let domain = String(value || '').trim().toLowerCase();
      domain = domain.replace(/^https?:\/\//, '').split('/')[0];

      if (domain && !domain.includes('.')) {
        domain = `${domain}.com`;
      }

      return domain;
    }

    function validateDomainFormat(domain) {
      if (!domain) {
        return { valid: false, message: 'Please enter a domain name' };
      }

      if (domain.length > 253) {
        return { valid: false, message: 'Domain name is too long' };
      }

      if (/\s|[^a-z0-9.-]/i.test(domain)) {
        return { valid: false, message: 'Domain can only contain letters, numbers, dots, and hyphens' };
      }

      if (domain.startsWith('.') || domain.endsWith('.') || domain.includes('..')) {
        return { valid: false, message: 'Domain cannot start, end, or contain repeated dots' };
      }

      const labels = domain.split('.');
      if (labels.length < 2) {
        return { valid: false, message: 'Domain must include a TLD, like example.com' };
      }

      for (const label of labels) {
        if (label.length < 1 || label.length > 63) {
          return { valid: false, message: 'Each domain label must be 1-63 characters' };
        }

        if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i.test(label)) {
          return { valid: false, message: 'Domain labels cannot start or end with a hyphen' };
        }
      }

      const tld = labels[labels.length - 1];
      if (!/^[a-z]{2,63}$/i.test(tld)) {
        return { valid: false, message: 'Invalid TLD. Use letters only, 2-63 characters' };
      }

      return { valid: true };
    }

    function showCaptchaChallenge(data) {
      const panel = document.getElementById('captchaPanel');
      const question = document.getElementById('captchaQuestion');
      const message = document.getElementById('captchaMessage');
      const input = document.getElementById('captchaAnswer');

      question.innerText = data.captcha?.question || 'Please answer the challenge';
      message.innerText = data.message || 'Please answer this once to continue checking domains.';
      panel.classList.remove('hidden');
      input.focus();
    }

    function hideCaptchaChallenge() {
      const panel = document.getElementById('captchaPanel');
      const input = document.getElementById('captchaAnswer');
      panel.classList.add('hidden');
      input.value = '';
    }

    function renderInlineError(message) {
      const resultContainer = document.getElementById('availabilityCard');
      const placeholderMsg = document.getElementById('placeholderMsg');

      placeholderMsg.classList.add('hidden');
      resultContainer.classList.remove('hidden');
      resultContainer.innerHTML = `
        <div class="text-center py-8 text-red-400">
          <i class="fas fa-exclamation-triangle text-4xl mb-3"></i>
          <p class="font-medium">${escapeHtml(message)}</p>
        </div>
      `;
    }
    
    async function performCheck() {
      const input = document.getElementById('domainInput');
      const domain = normalizeDomainInput(input.value);
      input.value = domain;

      const validation = validateDomainFormat(domain);
      if (!validation.valid) {
        showToast(validation.message, true);
        renderInlineError(validation.message);
        return;
      }
      
      const resultContainer = document.getElementById('availabilityCard');
      const placeholderMsg = document.getElementById('placeholderMsg');
      const captchaAnswer = document.getElementById('captchaAnswer')?.value.trim() || '';
      
      placeholderMsg.classList.add('hidden');
      resultContainer.classList.remove('hidden');
      resultContainer.innerHTML = `
        <div class="flex flex-col justify-center items-center py-12">
          <i class="fas fa-spinner fa-pulse text-4xl text-blue-400"></i>
          <span class="mt-4 text-blue-300 font-medium">Checking ${escapeHtml(domain)}...</span>
        </div>
      `;
      
      try {
        const data = await checkDomainAPI(domain, captchaAnswer);

        if (data.requiresCaptcha) {
          showCaptchaChallenge(data);
          renderInlineError(data.message || 'Please complete the verification challenge to continue.');
          return;
        }

        if (data.error || data.success === false) {
          const message = data.error || data.message || 'Unable to check this domain.';
          showToast(message, true);
          renderInlineError(message);
          return;
        }

        hideCaptchaChallenge();
        renderResult(domain, data);
      } catch (error) {
        const message = error.message || 'Unable to check domain status';
        resultContainer.innerHTML = `
          <div class="text-center py-8 text-red-400">
            <i class="fas fa-exclamation-triangle text-4xl mb-3"></i>
            <p class="font-medium">${escapeHtml(message)}</p>
            <p class="text-sm mt-2 text-gray-400">Please try again.</p>
          </div>
        `;
      }
    }
    
    // Event Listeners
    document.getElementById('checkBtn').addEventListener('click', performCheck);
    document.getElementById('domainInput').addEventListener('keypress', (e) => {
      if (e.key === 'Enter') performCheck();
    });
    document.getElementById('captchaAnswer')?.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') performCheck();
    });
    
    // Navigation links use their href attributes.
    
    // Suggestions on input
    document.getElementById('domainInput').addEventListener('input', (e) => {
      clearTimeout(suggestionsTimeout);
      suggestionsTimeout = setTimeout(() => getSuggestions(e.target.value), 300);
    });
    
    // Hide suggestions on click outside
    document.addEventListener('click', (e) => {
      if (!e.target.closest('#domainInput') && !e.target.closest('#suggestionsContainer')) {
        document.getElementById('suggestionsContainer').classList.add('hidden');
      }
    });
    
    // TLD badges click
    document.querySelectorAll('.tld-badge').forEach(badge => {
      badge.addEventListener('click', () => {
        const input = document.getElementById('domainInput');
        const tld = String(badge.dataset.tld || '').replace(/^\.+/, '').toLowerCase();
        const currentValue = normalizeDomainInput(input.value);
        let namePart = currentValue ? currentValue.split('.')[0] : 'mybrand';

        if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i.test(namePart)) {
          namePart = 'mybrand';
        }

        input.value = `${namePart}.${tld}`;
        performCheck();
      });
    });
    
    // Popular searches click
    document.querySelectorAll('.popular-search').forEach(el => {
      el.addEventListener('click', () => {
        document.getElementById('domainInput').value = el.dataset.search;
        performCheck();
      });
    });
    
    // Subscription handler
    document.getElementById('subscribeBtn')?.addEventListener('click', async () => {
      const email = document.getElementById('subscriberEmail')?.value.trim();
      const name = document.getElementById('subscriberName')?.value.trim();
      
      if (!email) {
        showToast("Please enter your email address", true);
        return;
      }
      
      const subscribeBtn = document.getElementById('subscribeBtn');
      const originalText = subscribeBtn.innerHTML;
      subscribeBtn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Subscribing...';
      subscribeBtn.disabled = true;
      
      try {
        const result = await subscribe(email, name);
        if (result.success) {
          showToast(result.message, false);
          document.getElementById('subscriberEmail').value = '';
          document.getElementById('subscriberName').value = '';
        } else {
          showToast(result.message || 'Subscription failed.', true);
        }
      } catch (error) {
        showToast('Network error. Please try again.', true);
      } finally {
        subscribeBtn.innerHTML = originalText;
        subscribeBtn.disabled = false;
      }
    });
    
    // Initialize
    loadPinnedFromStorage();
    
    // Rotating placeholder examples
    const examples = ['mybrand', 'startup', 'techcompany', 'onlinestore', 'blogger'];
    let exampleIndex = 0;
    setInterval(() => {
      const input = document.getElementById('domainInput');
      if (!input.value && document.activeElement !== input) {
        input.placeholder = `Search: ${examples[exampleIndex % examples.length]}.com`;
        exampleIndex++;
      }
    }, 4000);
  </script>
</body>
</html>
