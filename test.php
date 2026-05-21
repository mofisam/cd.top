<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>CheckDomain — Autopilot for Domain Acquisition</title>
  <!-- Tailwind CSS v3 + Google Fonts (Inter & Space Grotesk) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Custom config override for brutalist / musk vibe -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            'mono': ['"Space Grotesk"', 'ui-monospace', 'SF Mono', 'monospace'],
            'sans': ['Inter', 'system-ui', 'sans-serif'],
          },
          colors: {
            'tesla-dark': '#0a0a0a',
            'tesla-gray': '#1a1a1a',
            'cyber-red': '#ff2c2c',
            'cyber-green': '#00f0ff',
          },
          animation: {
            'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            'glow-pulse': 'glow 2s ease-in-out infinite alternate',
            'spin-slow': 'spin 8s linear infinite',
          },
          keyframes: {
            glow: {
              '0%': { textShadow: '0 0 0px rgba(0,240,255,0)' },
              '100%': { textShadow: '0 0 12px rgba(0,240,255,0.6)' },
            }
          }
        }
      }
    }
  </script>
  <style>
    /* Additional brutalist / engineering style */
    body {
      background: radial-gradient(circle at 10% 20%, #0c0c0c, #000000);
      background-attachment: fixed;
    }
    .grid-bg {
      background-image: 
        linear-gradient(#1e1e1e 1px, transparent 1px),
        linear-gradient(90deg, #1e1e1e 1px, transparent 1px);
      background-size: 40px 40px;
    }
    .glow-text {
      transition: all 0.2s ease;
    }
    .card-hover {
      transition: transform 0.2s ease, border-color 0.2s;
    }
    .card-hover:hover {
      transform: translateY(-3px);
      border-color: #00f0ff;
      box-shadow: 0 0 18px rgba(0, 240, 255, 0.15);
    }
    .neon-border {
      position: relative;
    }
    .neon-border::after {
      content: '';
      position: absolute;
      bottom: -2px;
      left: 0;
      width: 0%;
      height: 2px;
      background: #00f0ff;
      transition: width 0.3s ease;
    }
    .neon-border:hover::after {
      width: 100%;
    }
    /* custom range slider (engineer style) */
    input[type="range"] {
      -webkit-appearance: none;
      background: #2a2a2a;
      height: 3px;
      border-radius: 2px;
    }
    input[type="range"]:focus {
      outline: none;
    }
    input[type="range"]::-webkit-slider-thumb {
      -webkit-appearance: none;
      width: 14px;
      height: 14px;
      border-radius: 0px;
      background: #00f0ff;
      cursor: pointer;
      border: none;
      box-shadow: 0 0 4px #00f0ff;
    }
  </style>
</head>
<body class="font-sans text-gray-200 antialiased">

  <!-- NAVIGATION — minimalist & engineering style -->
  <nav class="border-b border-white/10 backdrop-blur-sm sticky top-0 z-50 bg-black/70">
    <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
      <div class="flex items-center gap-2">
        <div class="w-2 h-8 bg-cyber-green rotate-12 shadow-[0_0_6px_#00f0ff]"></div>
        <span class="font-mono text-2xl font-bold tracking-tighter text-white">CHECK<span class="text-cyber-green">DOMAIN</span></span>
        <span class="text-[10px] uppercase border border-cyber-green/50 text-cyber-green px-2 py-0.5 ml-2 rounded-sm">Alpha v0.1</span>
      </div>
      <div class="hidden md:flex gap-6 text-sm font-mono">
        <a href="#" class="hover:text-cyber-green transition-colors">AUTOPILOT</a>
        <a href="#" class="hover:text-cyber-green transition-colors">PREDICT</a>
        <a href="#" class="hover:text-cyber-green transition-colors">DASH</a>
      </div>
      <div class="flex gap-3">
        <button class="border border-white/30 px-4 py-1.5 text-sm font-mono hover:border-cyber-green hover:text-cyber-green transition">SIGN IN</button>
        <button class="bg-cyber-green/10 border border-cyber-green text-cyber-green px-4 py-1.5 text-sm font-mono hover:bg-cyber-green hover:text-black transition shadow-[0_0_6px_rgba(0,240,255,0.3)]">LAUNCH</button>
      </div>
    </div>
  </nav>

  <!-- HERO SECTION — musk-style brutalist statement -->
  <main class="max-w-7xl mx-auto px-6 py-16 md:py-24">
    <div class="grid lg:grid-cols-2 gap-12 items-center">
      <div>
        <div class="inline-flex items-center gap-2 bg-white/5 border-l-4 border-cyber-green pl-3 py-1 pr-4 rounded-sm mb-6">
          <span class="h-2 w-2 bg-cyber-green animate-pulse rounded-full"></span>
          <span class="text-xs uppercase tracking-wider font-mono">First-principles domain acquisition</span>
        </div>
        <h1 class="text-5xl md:text-7xl font-bold tracking-tighter leading-[1.1]">
          <span class="text-white">Own it.</span><br>
          <span class="text-cyber-green glow-text" style="animation: glow 2s ease-in-out infinite alternate;">Before the drop.</span>
        </h1>
        <p class="text-gray-400 text-lg mt-6 max-w-md font-light leading-relaxed">
          Not a "notifier". A predictive autopilot that acquires the domain the millisecond it's available. 
          <span class="text-white font-mono text-sm block mt-3">❄️ Zero latency. Radical simplicity.</span>
        </p>
        
        <!-- SEARCH / INPUT CORE (simulated interactive UI) -->
        <div class="mt-10 bg-tesla-dark/70 border border-gray-800 p-5 rounded-xl backdrop-blur-sm">
          <label class="text-xs font-mono text-cyber-green uppercase tracking-wider">target domain</label>
          <div class="flex flex-col sm:flex-row gap-3 mt-2">
            <div class="flex-1 relative">
              <input type="text" id="domainInput" placeholder="e.g., starship.xyz" class="w-full bg-black border border-gray-700 rounded-md py-3 px-4 text-white font-mono focus:outline-none focus:border-cyber-green focus:ring-0 transition">
              <span class="absolute right-3 top-3 text-gray-500 text-sm">.com .io .xyz</span>
            </div>
            <button id="trackBtn" class="bg-white text-black font-mono font-bold px-6 py-3 rounded-md hover:bg-cyber-green hover:text-black transition flex items-center justify-center gap-2 shadow-lg">
              <span>⚡ TRACK</span>
            </button>
          </div>
          <div class="flex gap-4 mt-4 text-xs text-gray-500 font-mono">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" id="autopilotToggle" class="accent-cyber-green w-4 h-4"> 
              <span>⚙️ ENGAGE AUTOPILOT (auto-buy)</span>
            </label>
            <span class="text-cyber-green/70">| predictive ETA: ~ 6-14 days</span>
          </div>
          <!-- live status mock (just UI preview) -->
          <div id="statusMock" class="mt-4 text-sm font-mono hidden border-t border-gray-800 pt-3">
            <div class="flex justify-between">
              <span>📡 REAL-TIME STATUS:</span>
              <span id="mockStatusText" class="text-cyber-green">MONITORING POLL (500ms)</span>
            </div>
            <div class="w-full bg-gray-800 h-1 mt-2 rounded-full overflow-hidden">
              <div class="bg-cyber-green h-full w-3/4 animate-pulse"></div>
            </div>
          </div>
        </div>
        
        <!-- notification demo (engineer like uptime) -->
        <div class="mt-6 flex gap-5 text-xs text-gray-500 font-mono">
          <div class="flex items-center gap-1"><div class="w-2 h-2 bg-green-500 rounded-full"></div> avg latency: 380ms</div>
          <div class="flex items-center gap-1"><div class="w-2 h-2 bg-cyber-green rounded-full animate-pulse"></div> 99.7% predictive accuracy</div>
          <div class="flex items-center gap-1">🚀 first-principles engine</div>
        </div>
      </div>
      
      <!-- RIGHT SIDE: RADICAL VISUAL + STATS (moonshot dashboard) -->
      <div class="border border-white/10 bg-black/40 rounded-2xl p-6 backdrop-blur-sm grid-bg">
        <div class="flex justify-between items-center border-b border-white/10 pb-3 mb-4">
          <span class="font-mono text-sm text-cyber-green">⛅ AUTOPILOT DASH</span>
          <span class="text-[10px] bg-black/60 px-2 py-0.5 rounded">live telemetry</span>
        </div>
        <div class="space-y-4">
          <div>
            <div class="flex justify-between text-xs"><span class="text-gray-400">tracked domains</span><span class="font-mono">247</span></div>
            <div class="w-full bg-gray-800 h-1.5 mt-1"><div class="bg-cyber-green h-full w-2/3"></div></div>
          </div>
          <div>
            <div class="flex justify-between text-xs"><span class="text-gray-400">autopilot acquisitions</span><span class="font-mono text-cyber-green">+18 this week</span></div>
            <div class="w-full bg-gray-800 h-1.5 mt-1"><div class="bg-cyber-green h-full w-4/5 animate-pulse-slow"></div></div>
          </div>
          <div class="grid grid-cols-2 gap-3 mt-4">
            <div class="bg-black/60 p-3 rounded border border-gray-800">
              <div class="text-cyber-green text-xl font-mono font-bold">0.4<span class="text-sm">s</span></div>
              <div class="text-[10px] uppercase">notification delta</div>
            </div>
            <div class="bg-black/60 p-3 rounded border border-gray-800">
              <div class="text-cyber-green text-xl font-mono font-bold">100%</div>
              <div class="text-[10px] uppercase">auto-buy success</div>
            </div>
          </div>
          <!-- upcoming prediction card -->
          <div class="mt-4 border-t border-white/10 pt-4">
            <div class="text-xs text-gray-400 flex justify-between"><span>🌙 PREDICTED DROP (example: tesla.xyz)</span><span class="text-cyber-green">Jun 22, 2026 - 14:32:01 UTC</span></div>
            <div class="flex gap-2 mt-2 items-center">
              <div class="h-1 flex-1 bg-gray-800 rounded-full overflow-hidden"><div class="bg-cyber-green w-[68%] h-full"></div></div>
              <span class="text-[11px] font-mono">68% confidence</span>
            </div>
          </div>
        </div>
        <div class="mt-6 text-center text-[11px] text-gray-600 font-mono">🔍 whois deep-learning + registry epp simulation</div>
      </div>
    </div>
    
    <!-- FEATURE SECTION: three core musk engineering pillars -->
    <div class="mt-32">
      <div class="text-center mb-12">
        <h2 class="text-3xl font-mono font-bold tracking-tight">Engineered like a <span class="text-cyber-green border-b border-cyber-green">Falcon 9</span></h2>
        <p class="text-gray-500 mt-2">Microsecond monitoring. Predictive release radar. Autopilot acquisition.</p>
      </div>
      <div class="grid md:grid-cols-3 gap-8">
        <div class="bg-black/40 border border-gray-800 rounded-xl p-6 card-hover transition">
          <div class="text-4xl mb-3">⚡</div>
          <h3 class="text-xl font-mono font-bold">Sub‑second alert</h3>
          <p class="text-gray-400 text-sm mt-2">Event-driven registry hooks + redundant polling. Notify latency ≤ 400ms.</p>
          <div class="mt-4 text-cyber-green text-xs font-mono flex items-center gap-1">REAL-TIME → <span class="animate-pulse">●</span></div>
        </div>
        <div class="bg-black/40 border border-gray-800 rounded-xl p-6 card-hover transition">
          <div class="text-4xl mb-3">🤖</div>
          <h3 class="text-xl font-mono font-bold">Autopilot Mode</h3>
          <p class="text-gray-400 text-sm mt-2">Pre-authorize payment → system buys domain instantly upon availability. No human delay.</p>
          <div class="mt-4 text-cyber-green text-xs font-mono">"set & forget"</div>
        </div>
        <div class="bg-black/40 border border-gray-800 rounded-xl p-6 card-hover transition">
          <div class="text-4xl mb-3">📡</div>
          <h3 class="text-xl font-mono font-bold">Predictive Radar</h3>
          <p class="text-gray-400 text-sm mt-2">ML-based expiration patterns + drop catch forecasting. Know availability date before registry.</p>
          <div class="mt-4 text-cyber-green text-xs font-mono">± 1.2 days accuracy</div>
        </div>
      </div>
    </div>
    
    <!-- TESTIMONIAL / RADICAL SIMPLICITY (style similar to internal memo) -->
    <div class="mt-32 border-l-4 border-cyber-green bg-white/5 p-8 rounded-r-2xl">
      <p class="text-2xl font-mono italic text-gray-300">“Most ‘availability checkers’ are just fancy email timers. That’s a horse stable when you should build a hyperloop. CheckDomain is the hyperloop.”</p>
      <div class="flex items-center gap-3 mt-4">
        <div class="w-8 h-8 bg-gray-700 rounded-full flex items-center justify-center text-xs">EM</div>
        <div><span class="font-bold text-sm">— engineering philosophy</span><span class="text-gray-500 text-xs ml-2">first principles review</span></div>
      </div>
    </div>
    
    <!-- CTA: join waitlist -->
    <div class="mt-24 text-center bg-gradient-to-r from-black via-tesla-dark to-black border-y border-white/10 py-12">
      <h3 class="text-3xl font-mono font-bold">Deploy your domain autopilot.</h3>
      <p class="text-gray-400 max-w-xl mx-auto mt-3">Stop refreshing whois. Let the machine acquire it for you. Zero latency. Radical autonomy.</p>
      <div class="flex justify-center gap-4 mt-8 flex-wrap">
        <button class="border border-cyber-green text-cyber-green px-8 py-3 font-mono hover:bg-cyber-green hover:text-black transition shadow-md">VIEW TECHNICAL DEEP DIVE</button>
        <button class="bg-cyber-green text-black px-8 py-3 font-mono font-bold hover:bg-white transition">⚡ START TRACKING (FREE)</button>
      </div>
    </div>
    
    <!-- FOOTER: minimal -->
    <footer class="mt-16 pt-8 border-t border-white/10 text-gray-500 text-xs flex flex-col md:flex-row justify-between font-mono">
      <span>© 2026 CheckDomain — radical acquisition engine</span>
      <span class="flex gap-4"><a href="#" class="hover:text-cyber-green">autopilot docs</a><a href="#" class="hover:text-cyber-green">API latency</a><a href="#" class="hover:text-cyber-green">registry status</a></span>
    </footer>
  </main>

  <!-- SIMPLE JAVASCRIPT for interactive demo: simulate tracking + autopilot + UI feedback -->
  <script>
    (function(){
      // DOM elements
      const domainInput = document.getElementById('domainInput');
      const trackBtn = document.getElementById('trackBtn');
      const autopilotToggle = document.getElementById('autopilotToggle');
      const statusMockDiv = document.getElementById('statusMock');
      const mockStatusText = document.getElementById('mockStatusText');

      // helper to show status panel mock
      function showTrackingStatus(domain, autopilotEngaged) {
        statusMockDiv.classList.remove('hidden');
        if (autopilotEngaged) {
          mockStatusText.innerHTML = `🤖 AUTOPILOT ARMED — acquiring "${domain}" upon drop (auto-buy)`;
          mockStatusText.classList.add('text-cyber-green');
          // adding additional visual effect
        } else {
          mockStatusText.innerHTML = `🔍 MONITORING: "${domain}" — notification only (instant alert)`;
          mockStatusText.classList.add('text-cyber-green');
        }
        // give a fake "real-time checking simulation" progress blink
        const progressBar = statusMockDiv.querySelector('.bg-cyber-green');
        if (progressBar) {
          progressBar.style.width = '100%';
          progressBar.classList.add('animate-pulse');
        }
        // additional toast style feedback (popup console)
        showFloatingToast(domain, autopilotEngaged);
      }
      
      function showFloatingToast(domain, autoMode){
        // create temporary mini notification to look like "predictive radar"
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-6 right-6 bg-black/90 border border-cyber-green text-cyber-green font-mono text-xs px-4 py-2 rounded-md shadow-xl backdrop-blur-sm z-50 transition-all';
        toast.innerHTML = autoMode ? `🚀 AUTOPILOT ENGAGED: ${domain} | predictive drop in ~9 days` : `📡 ${domain} added to tracking queue. You'll get alert <400ms after available.`;
        document.body.appendChild(toast);
        setTimeout(() => {
          toast.style.opacity = '0';
          setTimeout(() => toast.remove(), 500);
        }, 2800);
      }
      
      // additional function to update dashboard visual (simulate "just tracked")
      function incrementTrackedCount(){
        const trackedSpan = document.querySelector('.space-y-4 .flex.justify-between.text-xs span.font-mono:first-child');
        if(trackedSpan && trackedSpan.innerText.includes('tracked domains')){
          let currentValue = parseInt(trackedSpan.innerText);
          if(!isNaN(currentValue)){
            trackedSpan.innerText = currentValue + 1;
            const bar = trackedSpan.parentElement?.nextElementSibling?.querySelector('.bg-cyber-green');
            if(bar) bar.style.width = 'calc(100% * 0.68)';
          } else {
            // fallback if text changed
            let nextVal = 248;
            trackedSpan.innerText = nextVal;
          }
        }
      }
      
      // handling demo tracking
      trackBtn.addEventListener('click', (e) => {
        e.preventDefault();
        let domain = domainInput.value.trim();
        if(domain === "") {
          // placeholder fake demo
          domain = "starship.io";
          domainInput.value = domain;
        }
        // basic pattern filter
        if(!domain.includes('.') || domain.length < 4){
          // just demo - default
          domain = domain + ".com";
          domainInput.value = domain;
        }
        const isAutopilot = autopilotToggle.checked;
        showTrackingStatus(domain, isAutopilot);
        incrementTrackedCount();
        // if autopilot mode, also show a special second toast "auto-buy pre-authorized"
        if(isAutopilot){
          setTimeout(() => {
            const msg = document.createElement('div');
            msg.className = 'fixed bottom-24 right-6 bg-cyber-green/10 border-l-4 border-cyber-green text-xs p-3 rounded shadow-lg font-mono text-gray-200';
            msg.innerHTML = `✅ CREDIT CARD PRE-AUTHORIZED · System will purchase ${domain} autonomously. Zero human latency.`;
            document.body.appendChild(msg);
            setTimeout(() => { msg.style.opacity = '0'; setTimeout(() => msg.remove(), 500); }, 4000);
          }, 800);
        } else {
          // simulate a notification preview
          setTimeout(() => {
            const notifPreview = document.createElement('div');
            notifPreview.className = 'fixed bottom-24 right-6 bg-black border border-gray-700 p-3 rounded-md text-xs font-mono shadow-xl';
            notifPreview.innerHTML = `🔔 [DEMO NOTIFICATION] Domain <strong>${domain}</strong> is still unavailable. Next check in 0.2s. (simulated low latency)`;
            document.body.appendChild(notifPreview);
            setTimeout(() => notifPreview.remove(), 3500);
          }, 1500);
        }
        // update the status text with live mock refresh
        if(mockStatusText){
          if(isAutopilot){
            mockStatusText.innerHTML = `🤖 AUTOPILOT MODE: scanning registry for ${domain} · will execute purchase at t0 + 87ms`;
          } else {
            mockStatusText.innerHTML = `📡 REAL-TIME WATCH: ${domain} · will push browser notification & email within 380ms`;
          }
        }
      });
      
      // extra demo: when domain input change, show placeholder interaction
      domainInput.addEventListener('focus', () => {
        // style
        domainInput.classList.add('border-cyber-green');
      });
      domainInput.addEventListener('blur', () => {
        domainInput.classList.remove('border-cyber-green');
      });
      
      // Simulate some initial preset toast to convey speed
      window.addEventListener('load', () => {
        setTimeout(() => {
          const initToast = document.createElement('div');
          initToast.className = 'fixed top-24 right-6 bg-black/80 border border-cyber-green/40 text-cyber-green text-[11px] px-3 py-1.5 rounded-md font-mono z-40';
          initToast.innerHTML = '⚙️ predictive engine online · latency 0.38 sec target';
          document.body.appendChild(initToast);
          setTimeout(() => initToast.remove(), 3000);
        }, 800);
      });
    })();
  </script>
</body>
</html>