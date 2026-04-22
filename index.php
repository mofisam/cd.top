<?php
require_once 'config/database.php';

// Track page view
trackPageView($_SERVER['REQUEST_URI'] ?? 'homepage');

// Get popular TLDs for display
$popularTLDs = getPopularTLDs(12);
$popularSearches = getPopularSearches(5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
  <title>checkdomain.top - Check Domain Availability Instantly</title>
  <meta name="description" content="Check if any domain is available for registration. Never miss your perfect domain again.">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: {
              blue: { DEFAULT: '#1E3A8A', light: '#3B82F6', vivid: '#2563EB', sky: '#38BDF8', dark: '#172554' },
              green: { DEFAULT: '#10B981', light: '#34D399', bright: '#6EE7B7', deep: '#059669' },
            }
          },
          animation: {
            'float': 'float 6s ease-in-out infinite',
            'slide-up-fade': 'slideUpFade 0.7s ease-out forwards',
            'ping-slow': 'ping 2.5s cubic-bezier(0, 0, 0.2, 1) infinite',
          },
          keyframes: {
            float: {
              '0%, 100%': { transform: 'translateY(0px)' },
              '50%': { transform: 'translateY(-12px)' },
            },
            slideUpFade: {
              '0%': { opacity: '0', transform: 'translateY(30px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' },
            }
          }
        }
      }
    }
  </script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    body {
      background: linear-gradient(135deg, #0F172A 0%, #1E293B 50%, #0B1120 100%);
      font-family: 'Inter', system-ui, sans-serif;
    }
    .glass-card {
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(16px);
      border: 1px solid rgba(59, 130, 246, 0.35);
      border-radius: 2rem;
      transition: all 0.3s ease;
    }
    .glass-card:hover {
      border-color: rgba(16, 185, 129, 0.5);
      transform: translateY(-2px);
    }
    .input-glow:focus {
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.5);
      border-color: #3B82F6;
    }
    .btn-primary {
      background: linear-gradient(135deg, #2563EB 0%, #3B82F6 100%);
      transition: all 0.2s ease;
    }
    .btn-primary:hover {
      background: linear-gradient(135deg, #3B82F6 0%, #60A5FA 100%);
      transform: translateY(-1px);
      box-shadow: 0 10px 20px -5px rgba(37, 99, 235, 0.4);
    }
    .btn-secondary {
      background: linear-gradient(135deg, #059669 0%, #10B981 100%);
    }
    .btn-secondary:hover {
      background: linear-gradient(135deg, #10B981 0%, #34D399 100%);
      transform: translateY(-1px);
    }
    .bg-noise {
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='1' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.025'/%3E%3C/svg%3E");
      pointer-events: none;
    }
    .animate-enter {
      animation: slideUpFade 0.6s ease-out forwards;
    }
    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-track { background: #0F172A; }
    ::-webkit-scrollbar-thumb { background: #3B82F6; border-radius: 10px; }
    .result-card {
      animation: slideUpFade 0.4s ease-out;
    }
    .custom-logo {
      max-width: 60px;
      max-height: 60px;
      object-fit: contain;
      filter: drop-shadow(0 4px 12px rgba(59, 130, 246, 0.3));
      transition: filter 0.3s ease, transform 0.3s ease;
    }
    .custom-logo:hover {
      filter: drop-shadow(0 6px 16px rgba(16, 185, 129, 0.4));
      transform: scale(1.05);
    }
    @media (max-width: 640px) {
      .custom-logo {
        max-width: 45px;
        max-height: 45px;
      }
    }
    /* Suggestions dropdown */
    .suggestions-dropdown {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: rgba(30, 41, 59, 0.95);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(59, 130, 246, 0.3);
      border-radius: 1rem;
      margin-top: 0.5rem;
      z-index: 50;
      max-height: 300px;
      overflow-y: auto;
    }
    .suggestion-item {
      padding: 0.75rem 1rem;
      cursor: pointer;
      transition: all 0.2s;
      border-bottom: 1px solid rgba(59, 130, 246, 0.1);
    }
    .suggestion-item:hover {
      background: rgba(59, 130, 246, 0.2);
    }
    .tld-badge {
      display: inline-block;
      padding: 0.25rem 0.75rem;
      background: rgba(59, 130, 246, 0.2);
      border-radius: 9999px;
      font-size: 0.75rem;
      cursor: pointer;
      transition: all 0.2s;
    }
    .tld-badge:hover {
      background: rgba(59, 130, 246, 0.4);
      transform: translateY(-1px);
    }
    /* Navigation styles */
    .nav-link {
      transition: all 0.2s ease;
      position: relative;
    }
    .nav-link:hover {
      color: #3B82F6;
    }
    .nav-link::after {
      content: '';
      position: absolute;
      bottom: -4px;
      left: 0;
      right: 0;
      height: 2px;
      background: linear-gradient(90deg, #3B82F6, #10B981);
      transform: scaleX(0);
      transition: transform 0.2s ease;
    }
    .nav-link:hover::after {
      transform: scaleX(1);
    }
  </style>
</head>
<body class="relative text-white overflow-x-hidden antialiased">

  <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
    <div class="absolute top-[10%] left-[0%] w-96 h-96 bg-blue-600/15 rounded-full blur-3xl animate-float"></div>
    <div class="absolute bottom-[20%] right-[5%] w-80 h-80 bg-green-500/10 rounded-full blur-3xl animate-float" style="animation-delay: 1.8s;"></div>
    <div class="absolute top-[55%] left-[70%] w-72 h-72 bg-blue-400/15 rounded-full blur-2xl animate-float" style="animation-delay: 3s;"></div>
    <div class="bg-noise absolute inset-0"></div>
  </div>

  <main class="relative z-10 min-h-screen flex flex-col items-center justify-center px-5 py-12">
    <div class="w-full max-w-4xl mx-auto text-center animate-enter">
      
      <!-- Top Navigation Bar -->
      <div class="flex justify-end items-center gap-6 mb-4 text-sm">
        <a href="#" id="contactLink" class="nav-link text-gray-300 hover:text-blue-400 transition flex items-center gap-2">
          <i class="fas fa-envelope text-xs"></i>
          Contact Us
        </a>
        <a href="#" id="loginLink" class="nav-link text-gray-300 hover:text-blue-400 transition flex items-center gap-2">
          <i class="fas fa-sign-in-alt text-xs"></i>
          Login
        </a>
      </div>
      
      <!-- Logo Section -->
      <div class="flex flex-col items-center justify-center mb-6">
        <div class="relative inline-flex items-center justify-center gap-4 bg-slate-900/40 backdrop-blur-sm rounded-2xl p-3 px-7 border border-blue-500/30 shadow-xl">
          <div class="relative">
            <img src="images/logo.png" 
                 alt="checkdomain.top logo" 
                 class="custom-logo"
                 onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'w-14 h-14 md:w-16 md:h-16 bg-gradient-to-br from-blue-500 to-green-500 rounded-full flex items-center justify-center shadow-lg\'><i class=\'fas fa-globe text-white text-2xl\'></i><i class=\'fas fa-check-circle absolute -bottom-1 -right-1 text-green-400 text-lg bg-slate-900 rounded-full\'></i></div>'">
            <span class="absolute -bottom-1 -right-1 w-4 h-4 bg-green-500 rounded-full border-2 border-slate-900 animate-pulse"></span>
          </div>
          <div class="text-left">
            <h1 class="text-3xl md:text-4xl font-extrabold bg-gradient-to-r from-white via-blue-300 to-green-300 bg-clip-text text-transparent">checkdomain<span class="text-green-400">.</span>top</h1>
            <p class="text-[11px] md:text-xs text-blue-300/80 font-mono tracking-wide">Never miss the perfect domain again</p>
          </div>
        </div>
      </div>
      
      <!-- Main Search Section -->
      <div class="glass-card mt-6 p-6 md:p-8">
        <div class="flex flex-col items-center">
          <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-600 to-blue-400 flex items-center justify-center mb-3">
            <i class="fas fa-magnifying-glass-chart text-white text-xl"></i>
          </div>
          <h2 class="text-2xl font-semibold bg-gradient-to-r from-white to-blue-300 bg-clip-text text-transparent">Find your perfect domain</h2>
          <p class="text-gray-300 text-sm mt-1">Search millions of domains for availability</p>
        </div>
        
        <!-- Search Input with Suggestions -->
        <div class="mt-5 w-full relative">
          <div class="flex flex-col sm:flex-row gap-3 items-center justify-center">
            <div class="relative w-full sm:w-2/3">
              <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                <i class="fas fa-link text-blue-400 text-sm"></i>
              </div>
              <input type="text" id="domainInput" 
                placeholder="Search for a domain... (e.g., mybrand, startup, blog)" 
                class="w-full bg-slate-800/70 border border-blue-500/40 rounded-2xl py-3.5 pl-10 pr-4 text-white placeholder:text-gray-400 focus:outline-none input-glow font-mono text-sm"
                autocomplete="off">
              <div id="suggestionsContainer" class="suggestions-dropdown hidden"></div>
            </div>
            <button id="checkBtn" class="btn-primary text-white font-semibold px-6 py-3.5 rounded-2xl transition-all flex items-center gap-2 w-full sm:w-auto justify-center shadow-lg">
              <i class="fas fa-search text-sm"></i> Check Domain
            </button>
          </div>
          
          <!-- Popular TLDs Quick Select -->
          <div class="mt-4 flex flex-wrap justify-center gap-2">
            <?php foreach ($popularTLDs as $tld): ?>
            <span class="tld-badge" data-tld="<?php echo $tld['tld']; ?>">
              <?php echo $tld['tld']; ?>
            </span>
            <?php endforeach; ?>
          </div>
          
          <!-- Popular Searches -->
          <?php if (!empty($popularSearches)): ?>
          <div class="mt-4 text-center">
            <p class="text-xs text-gray-400 mb-2">Popular searches:</p>
            <div class="flex flex-wrap justify-center gap-2">
              <?php foreach ($popularSearches as $search): ?>
              <span class="text-xs text-blue-300 cursor-pointer hover:text-blue-400 popular-search" data-search="<?php echo htmlspecialchars($search['search_term']); ?>">
                <?php echo htmlspecialchars($search['search_term']); ?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        
        <!-- Results Container -->
        <div id="resultContainer" class="mt-8 transition-all duration-500">
          <div id="availabilityCard" class="hidden"></div>
          <div id="placeholderMsg" class="text-center py-8 text-gray-400 text-sm flex flex-col items-center gap-3">
            <i class="fas fa-chart-line text-3xl opacity-60 text-blue-400"></i>
            <span>✨ Enter a domain name to check availability ✨</span>
            <span class="text-xs text-gray-500">Try: mybrand, startup, techcompany + any extension</span>
          </div>
        </div>
      </div>
      
      <!-- Features Grid -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-8">
        <div class="bg-slate-800/40 backdrop-blur-sm rounded-xl p-3 text-center border border-blue-500/20">
          <i class="fas fa-bolt text-yellow-400 text-xl"></i>
          <p class="text-xs mt-1">Instant Check</p>
        </div>
        <div class="bg-slate-800/40 backdrop-blur-sm rounded-xl p-3 text-center border border-blue-500/20">
          <i class="fas fa-thumbtack text-green-400 text-xl"></i>
          <p class="text-xs mt-1">Pin & Monitor</p>
        </div>
        <div class="bg-slate-800/40 backdrop-blur-sm rounded-xl p-3 text-center border border-blue-500/20">
          <i class="fas fa-lightbulb text-purple-400 text-xl"></i>
          <p class="text-xs mt-1">Smart Suggestions</p>
        </div>
        <div class="bg-slate-800/40 backdrop-blur-sm rounded-xl p-3 text-center border border-blue-500/20">
          <i class="fas fa-bell text-red-400 text-xl"></i>
          <p class="text-xs mt-1">Availability Alerts</p>
        </div>
      </div>
      
      <!-- Domain Tips Section -->
      <div class="mt-8 bg-slate-800/40 backdrop-blur-sm rounded-xl p-4 text-left">
        <h3 class="text-sm font-semibold mb-2 flex items-center gap-2">
          <i class="fas fa-lightbulb text-yellow-400"></i> Domain Name Tips
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs text-gray-300">
          <div>✓ Keep it short and memorable (6-14 characters)</div>
          <div>✓ Use keywords relevant to your business</div>
          <div>✓ Avoid numbers and hyphens when possible</div>
          <div>✓ Consider .com, .io, .co for professional appeal</div>
          <div>✓ Check trademark conflicts before purchasing</div>
          <div>✓ Act fast when you find an available domain</div>
        </div>
      </div>
      
      <!-- Early Access Form -->
      <div class="mt-8 bg-slate-800/40 backdrop-blur-sm rounded-2xl p-6 border border-blue-500/20">
        <h3 class="text-lg font-semibold text-white mb-2">🚀 Get Domain Alerts</h3>
        <p class="text-gray-300 text-sm mb-4">Be notified when your dream domain becomes available</p>
        <div class="flex flex-col sm:flex-row gap-3 max-w-md mx-auto">
          <input type="text" id="subscriberName" placeholder="Your name (optional)" 
            class="flex-1 bg-slate-800 border border-blue-500/40 rounded-xl py-2.5 px-4 text-white placeholder:text-gray-400 focus:outline-none focus:border-blue-400 text-sm">
          <input type="email" id="subscriberEmail" placeholder="Your email address" 
            class="flex-1 bg-slate-800 border border-blue-500/40 rounded-xl py-2.5 px-4 text-white placeholder:text-gray-400 focus:outline-none focus:border-blue-400 text-sm">
          <button id="subscribeBtn" class="btn-secondary text-white font-medium px-5 py-2.5 rounded-xl transition flex items-center gap-2 justify-center">
            <i class="fas fa-bell"></i> Subscribe
          </button>
        </div>
        <p class="text-xs text-gray-400 mt-3">Get alerts for domain availability, price drops, and expiring domains</p>
      </div>
      
      <!-- Footer with Navigation Links -->
      <div class="mt-8 text-center text-gray-500 text-xs flex flex-wrap justify-center gap-6 border-t border-blue-500/20 pt-6">
        <span><i class="far fa-clock mr-1 text-blue-400"></i> Launching Q2 2026</span>
        <span><i class="fas fa-shield-alt mr-1 text-blue-400"></i> 100% Privacy Focused</span>
        <a href="#" id="contactLinkFooter" class="hover:text-blue-400 transition flex items-center gap-1">
          <i class="fas fa-envelope mr-1 text-green-400"></i> Contact Us
        </a>
        <a href="#" id="loginLinkFooter" class="hover:text-blue-400 transition flex items-center gap-1">
          <i class="fas fa-sign-in-alt mr-1 text-green-400"></i> Admin Login
        </a>
      </div>
    </div>
  </main>

  <div id="toastMsg" class="fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 bg-slate-900/95 backdrop-blur-lg border border-blue-500 rounded-full px-5 py-2.5 text-sm font-medium text-white transition-all duration-300 opacity-0 pointer-events-none flex items-center gap-2 shadow-xl">
    <i class="fas fa-circle-info text-green-400"></i> <span id="toastText">Message</span>
  </div>

  <script>
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
          badge.onclick = () => showToast(`📌 You have ${pinnedDomains.size} pinned domain(s). You'll get alerts when they become available!`, false);
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
    
    // Navigation handlers (placeholder - pages to be created later)
    function handleContactClick() {
      showToast("📧 Contact page coming soon! For now, email us at hello@checkdomain.top", false);
    }
    
    function handleLoginClick() {
      showToast("🔐 Admin login page coming soon. Please check back later.", false);
    }
    
    // Get suggestions as user types
    async function getSuggestions(query) {
      if (query.length < 2) {
        document.getElementById('suggestionsContainer').classList.add('hidden');
        return;
      }
      
      try {
        const response = await fetch(`/api/suggestions.php?q=${encodeURIComponent(query)}&type=domains`);
        const data = await response.json();
        
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
      const response = await fetch('/api/subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email, name: name, source: 'website' })
      });
      return await response.json();
    }
    
    async function checkDomainAPI(domain) {
      const response = await fetch('/api/check-domain.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ domain: domain })
      });
      return await response.json();
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
            <p class="text-gray-300 text-md">🎉 Congratulations! This domain is ready for registration.</p>
            <div class="mt-4 flex justify-center gap-3">
              <button class="bg-gradient-to-r from-green-600 to-emerald-600 text-white px-6 py-2 rounded-xl text-sm font-medium transition">
                <i class="fas fa-shopping-cart"></i> Register Now
              </button>
              <button class="bg-slate-700 hover:bg-slate-600 text-white px-6 py-2 rounded-xl text-sm font-medium transition">
                <i class="fas fa-heart"></i> Save for Later
              </button>
            </div>
            <div class="mt-2 p-3 bg-blue-900/20 rounded-xl">
              <p class="text-xs text-blue-300">💡 Tip: Consider buying multiple extensions to protect your brand</p>
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
              <h4 class="text-sm font-semibold mb-2">💡 Alternative Suggestions</h4>
              <div class="flex flex-wrap justify-center gap-2">
                ${alternatives.map(alt => `<span class="tld-badge suggestion-alt" data-domain="${alt}">${alt}</span>`).join('')}
              </div>
            </div>
            
            <p class="text-gray-300 text-sm">🔔 Pin this domain and we'll notify you when it becomes available.</p>
            
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
        showToast(`⚠️ "${domain}" is already pinned.`, false);
        return;
      }
      pinnedDomains.set(domain, { pinDate: new Date().toISOString() });
      savePinnedToStorage();
      showToast(`📌 Pinned "${domain}"! You'll be notified when available.`, false);
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
    
    async function performCheck() {
      let domain = document.getElementById('domainInput').value.trim();
      if (!domain) {
        showToast("Please enter a domain name", true);
        return;
      }
      
      // Auto-add .com if no extension provided
      if (!domain.includes('.')) {
        domain = domain + '.com';
        document.getElementById('domainInput').value = domain;
      }
      
      const domainPattern = /^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/;
      if (!domainPattern.test(domain)) {
        showToast("Please enter a valid domain name (e.g., example.com)", true);
        return;
      }
      
      const resultContainer = document.getElementById('availabilityCard');
      const placeholderMsg = document.getElementById('placeholderMsg');
      
      placeholderMsg.classList.add('hidden');
      resultContainer.classList.remove('hidden');
      resultContainer.innerHTML = `
        <div class="flex flex-col justify-center items-center py-12">
          <i class="fas fa-spinner fa-pulse text-4xl text-blue-400"></i>
          <span class="mt-4 text-blue-300 font-medium">Checking ${escapeHtml(domain)}...</span>
        </div>
      `;
      
      try {
        const data = await checkDomainAPI(domain);
        renderResult(domain, data);
      } catch (error) {
        resultContainer.innerHTML = `
          <div class="text-center py-8 text-red-400">
            <i class="fas fa-exclamation-triangle text-4xl mb-3"></i>
            <p class="font-medium">Unable to check domain status</p>
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
    
    // Navigation event listeners
    document.getElementById('contactLink')?.addEventListener('click', (e) => {
      e.preventDefault();
      handleContactClick();
    });
    document.getElementById('loginLink')?.addEventListener('click', (e) => {
      e.preventDefault();
      handleLoginClick();
    });
    document.getElementById('contactLinkFooter')?.addEventListener('click', (e) => {
      e.preventDefault();
      handleContactClick();
    });
    document.getElementById('loginLinkFooter')?.addEventListener('click', (e) => {
      e.preventDefault();
      handleLoginClick();
    });
    
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
        const currentValue = document.getElementById('domainInput').value;
        const namePart = currentValue.split('.')[0];
        if (namePart && !currentValue.includes(badge.dataset.tld)) {
          document.getElementById('domainInput').value = `${namePart}.${badge.dataset.tld}`;
          performCheck();
        } else if (!currentValue) {
          document.getElementById('domainInput').value = `mybrand.${badge.dataset.tld}`;
          performCheck();
        }
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