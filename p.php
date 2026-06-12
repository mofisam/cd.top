<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WHOIS Lookup — CheckDomain</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;700;800&family=DM+Mono:wght@400;500&family=Instrument+Serif:ital@1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0A0B0E;--bg2:#111318;--bg3:#181C24;--bg4:#1E2230;
  --border:rgba(255,255,255,0.06);--border2:rgba(255,255,255,0.11);
  --text:#E9E7DF;--text2:#8A8880;--text3:#454340;
  --green:#1D9E75;--green2:#14C48A;--green-bg:rgba(29,158,117,0.1);
  --amber:#EF9F27;--amber-bg:rgba(239,159,39,0.1);
  --coral:#E8593C;--coral-bg:rgba(232,89,60,0.1);
  --purple:#7F77DD;--purple-bg:rgba(127,119,221,0.1);
  --blue:#4A90D9;--blue-bg:rgba(74,144,217,0.1);
  --mono:'DM Mono',monospace;--display:'Syne',sans-serif;
  --serif:'Instrument Serif',serif;--sb-width:224px;
}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:var(--display);min-height:100vh;display:flex;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;
  background-image:linear-gradient(rgba(29,158,117,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(29,158,117,.02) 1px,transparent 1px);
  background-size:52px 52px;pointer-events:none;z-index:0}

/* ── Layout ─── */
.main{margin-left:var(--sb-width);flex:1;position:relative;z-index:1;min-height:100vh}

/* ── Topbar ─── */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:15px 28px;border-bottom:1px solid var(--border);backdrop-filter:blur(12px);background:rgba(10,11,14,0.85);position:sticky;top:0;z-index:40;gap:14px}
.topbar-left{display:flex;align-items:center;gap:12px}
.topbar-right{display:flex;align-items:center;gap:10px}
.mobile-menu-btn{display:none;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--text2);font-size:16px;cursor:pointer}
.breadcrumb{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text3)}
.breadcrumb a{color:var(--text2);text-decoration:none;transition:color .15s}
.breadcrumb a:hover{color:var(--text)}
.topbar-btn{display:flex;align-items:center;justify-content:center;width:33px;height:33px;border-radius:8px;background:var(--bg2);border:1px solid var(--border);color:var(--text2);font-size:14px;cursor:pointer;text-decoration:none;transition:border-color .15s,color .15s}
.topbar-btn:hover{border-color:var(--border2);color:var(--text)}
.credits-pill{display:flex;align-items:center;gap:6px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:6px 12px;font-family:var(--mono);font-size:12px;color:var(--text2);white-space:nowrap}
.credits-pill b{color:var(--amber);font-weight:700}

/* ── Content ─── */
.content{padding:28px 28px 60px}
.page-title{font-family:var(--serif);font-style:italic;font-size:26px;color:var(--text);margin-bottom:4px}
.page-sub{font-size:13px;color:var(--text2);margin-bottom:24px}
.page-sub em{color:var(--green);font-style:normal;font-family:var(--mono)}

/* ── Upgrade gate ─── */
.upgrade-gate{background:linear-gradient(135deg,rgba(29,158,117,.07),rgba(127,119,221,.05));border:1px solid rgba(29,158,117,.2);border-radius:14px;padding:28px 24px;text-align:center;margin-bottom:28px}
.gate-icon{font-size:28px;margin-bottom:12px}
.gate-title{font-size:16px;font-weight:800;color:var(--text);margin-bottom:6px}
.gate-sub{font-size:13px;color:var(--text2);max-width:400px;margin:0 auto 18px;line-height:1.6}
.gate-cta{display:inline-flex;align-items:center;gap:7px;background:var(--green);color:#fff;border:none;border-radius:9px;padding:10px 24px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s;text-transform:uppercase;letter-spacing:.06em}
.gate-cta:hover{background:var(--green2)}

/* ── Search bar ─── */
.search-hero{background:var(--bg2);border:1px solid var(--border2);border-radius:16px;padding:22px 24px;margin-bottom:24px}
.search-hero-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--text3);margin-bottom:10px;display:flex;align-items:center;gap:6px}
.search-row{display:flex;gap:10px;align-items:center}
.search-input-wrap{flex:1;position:relative;min-width:0}
.search-input{width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:12px 16px 12px 42px;font-family:var(--mono);font-size:14px;color:var(--text);outline:none;transition:border-color .2s}
.search-input::placeholder{color:var(--text3)}
.search-input:focus{border-color:var(--green)}
.search-input:disabled{opacity:.45;cursor:not-allowed}
.search-input-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:13px;pointer-events:none}
.search-btn{display:flex;align-items:center;gap:8px;background:var(--green);color:#fff;border:none;border-radius:10px;padding:12px 22px;font-family:var(--display);font-size:13px;font-weight:700;cursor:pointer;transition:background .2s;white-space:nowrap;flex-shrink:0}
.search-btn:hover{background:var(--green2)}
.search-btn:disabled{opacity:.5;cursor:not-allowed}
.search-hint{display:flex;align-items:center;gap:14px;margin-top:10px;font-size:11px;color:var(--text3);flex-wrap:wrap}
.search-hint i{font-size:10px}
.cost-pill{background:var(--amber-bg);color:var(--amber);font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px}
.cache-pill{background:var(--green-bg);color:var(--green2);font-family:var(--mono);font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px}

/* ── Loading state ─── */
.loading-state{display:none;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:48px 24px;background:var(--bg2);border:1px solid var(--border);border-radius:14px;margin-bottom:24px}
.loading-state.visible{display:flex}
.loading-spinner{width:40px;height:40px;border:3px solid var(--border);border-top-color:var(--green);border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.loading-domain{font-family:var(--mono);font-size:13px;color:var(--text2)}
.loading-steps{display:flex;flex-direction:column;gap:6px;text-align:center}
.loading-step{font-size:11px;color:var(--text3);transition:color .3s}
.loading-step.active{color:var(--green2)}
.loading-step.done{color:var(--text3)}
.loading-step.done::before{content:'✓ '}

/* ── Result panel ─── */
.result-panel{display:none;margin-bottom:24px}
.result-panel.visible{display:block}

/* Available banner */
.available-banner{background:linear-gradient(135deg,rgba(29,158,117,.1),rgba(20,196,138,.05));border:1px solid rgba(29,158,117,.25);border-radius:14px;padding:22px 24px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.avail-icon{width:52px;height:52px;border-radius:13px;background:var(--green-bg);border:1px solid rgba(29,158,117,.2);display:flex;align-items:center;justify-content:center;font-size:22px;color:var(--green2);flex-shrink:0}
.avail-info{flex:1}
.avail-domain{font-family:var(--mono);font-size:20px;font-weight:700;color:var(--text);margin-bottom:4px}
.avail-label{font-size:13px;color:var(--green2);font-weight:600;margin-bottom:6px}
.avail-sub{font-size:12px;color:var(--text2)}
.avail-actions{display:flex;gap:8px;flex-wrap:wrap}
.avail-cta{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;font-family:var(--display);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .15s;border:none;text-transform:uppercase;letter-spacing:.05em}
.cta-primary{background:var(--green);color:#fff}
.cta-primary:hover{background:var(--green2)}
.cta-secondary{background:var(--bg3);color:var(--text2);border:1px solid var(--border2)!important}
.cta-secondary:hover{background:var(--bg4);color:var(--text)}

/* WHOIS result card */
.whois-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.whois-card-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.whois-domain-title{font-family:var(--mono);font-size:16px;font-weight:700;color:var(--text)}
.whois-domain-title span{color:var(--text3);font-weight:400}
.whois-meta-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.whois-badge{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;padding:3px 8px;border-radius:4px}
.wb-taken{background:var(--amber-bg);color:var(--amber)}
.wb-available{background:var(--green-bg);color:var(--green2)}
.wb-cache{background:var(--bg4);color:var(--text3)}
.wb-api{background:var(--blue-bg);color:var(--blue)}
.wb-socket{background:var(--purple-bg);color:var(--purple)}
.whois-header-actions{display:flex;gap:7px}
.icon-btn{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;background:none;border:1px solid var(--border);color:var(--text3);font-size:12px;cursor:pointer;transition:all .13s;text-decoration:none}
.icon-btn:hover{background:var(--bg3);border-color:var(--border2);color:var(--text)}

/* Sections */
.whois-sections{display:grid;grid-template-columns:1fr 1fr;gap:0;border-top:1px solid var(--border)}
.whois-section{padding:20px 22px;border-right:1px solid var(--border);border-bottom:1px solid var(--border)}
.whois-section:nth-child(even){border-right:none}
.whois-section:nth-last-child(-n+2){border-bottom:none}
.whois-section-title{font-size:10px;text-transform:uppercase;letter-spacing:.14em;color:var(--text3);font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.whois-section-title i{font-size:11px}
.whois-fields{display:flex;flex-direction:column;gap:9px}
.wf-row{display:flex;flex-direction:column;gap:2px}
.wf-label{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--text3)}
.wf-value{font-size:12px;font-family:var(--mono);color:var(--text);word-break:break-all}
.wf-value.na{color:var(--text3);font-style:italic;font-family:var(--display)}
.wf-value.expiring{color:var(--coral)}
.wf-value.expiring-soon{color:var(--amber)}
.wf-value.available-val{color:var(--green2)}

/* Status codes */
.status-tags{display:flex;flex-wrap:wrap;gap:5px;margin-top:4px}
.status-tag{font-size:9px;font-family:var(--mono);padding:2px 7px;border-radius:3px;background:var(--bg4);color:var(--text3)}
.status-tag.active-status{background:var(--green-bg);color:var(--green2)}

/* Nameservers */
.ns-list{display:flex;flex-direction:column;gap:5px;margin-top:4px}
.ns-item{font-size:11px;font-family:var(--mono);color:var(--text2);display:flex;align-items:center;gap:6px}
.ns-item::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--green);flex-shrink:0}

/* Expiry bar */
.expiry-bar-section{padding:16px 22px;border-top:1px solid var(--border);background:var(--bg3)}
.expiry-bar-label{display:flex;justify-content:space-between;font-size:11px;color:var(--text3);font-family:var(--mono);margin-bottom:6px}
.expiry-bar-wrap{height:5px;background:var(--border);border-radius:3px;overflow:hidden}
.expiry-bar-fill{height:100%;border-radius:3px;transition:width .8s ease}

/* Raw WHOIS */
.raw-section{padding:16px 22px;border-top:1px solid var(--border)}
.raw-toggle{display:flex;align-items:center;gap:7px;font-size:12px;color:var(--text2);cursor:pointer;background:none;border:none;font-family:var(--display);padding:0;transition:color .15s}
.raw-toggle:hover{color:var(--text)}
.raw-toggle i{font-size:11px;transition:transform .2s}
.raw-toggle.open i{transform:rotate(180deg)}
.raw-content{display:none;margin-top:12px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:14px;font-family:var(--mono);font-size:11px;color:var(--text2);line-height:1.7;white-space:pre-wrap;word-break:break-word;max-height:320px;overflow-y:auto}
.raw-content.open{display:block}

/* ── Actions bar ─── */
.result-actions{display:flex;align-items:center;gap:8px;padding:14px 22px;border-top:1px solid var(--border);flex-wrap:wrap}
.result-action-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:8px;font-family:var(--display);font-size:11px;font-weight:700;cursor:pointer;text-decoration:none;transition:all .15s;border:none;text-transform:uppercase;letter-spacing:.05em}
.rab-green{background:var(--green-bg);color:var(--green2)}
.rab-green:hover{background:rgba(29,158,117,.2)}
.rab-amber{background:var(--amber-bg);color:var(--amber)}
.rab-amber:hover{background:rgba(239,159,39,.2)}
.rab-coral{background:var(--coral-bg);color:var(--coral)}
.rab-coral:hover{background:rgba(232,89,60,.2)}
.rab-default{background:var(--bg3);color:var(--text2);border:1px solid var(--border)}
.rab-default:hover{background:var(--bg4);color:var(--text)}

/* ── History table ─── */
.history-wrap{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-top:28px}
.history-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.history-title{font-size:12px;font-weight:700;color:var(--text)}
.ht-head{display:grid;grid-template-columns:1fr 160px 120px 90px 80px;padding:9px 20px;background:var(--bg3);border-bottom:1px solid var(--border)}
.ht-th{font-size:10px;text-transform:uppercase;letter-spacing:.11em;color:var(--text3);font-weight:600}
.ht-th.right{text-align:right}
.ht-row{display:grid;grid-template-columns:1fr 160px 120px 90px 80px;padding:12px 20px;border-bottom:1px solid var(--border);align-items:center;transition:background .12s;cursor:pointer}
.ht-row:last-child{border-bottom:none}
.ht-row:hover{background:var(--bg3)}
.ht-domain{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--text)}
.ht-domain-tld{color:var(--text3);font-weight:400}
.ht-registrar{font-size:11px;color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ht-date{font-family:var(--mono);font-size:11px;color:var(--text2)}
.ht-status-pill{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:2px 7px;border-radius:4px;white-space:nowrap}
.hsp-taken{background:var(--amber-bg);color:var(--amber)}
.hsp-available{background:var(--green-bg);color:var(--green2)}
.ht-credits{font-family:var(--mono);font-size:11px;color:var(--text3);text-align:right}
.hist-empty{text-align:center;padding:36px 20px;color:var(--text3);font-size:12px}

/* ── Toast ─── */
.toast{position:fixed;bottom:28px;right:28px;z-index:999;background:var(--bg3);border:1px solid var(--border2);border-radius:10px;padding:12px 18px;font-size:13px;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.4);transform:translateY(20px);opacity:0;transition:all .3s ease;max-width:340px;display:flex;align-items:center;gap:9px}
.toast.show{transform:translateY(0);opacity:1}
.toast.success{border-color:rgba(29,158,117,.3)}
.toast.error{border-color:rgba(232,89,60,.3)}

/* ── Responsive ─── */
@media(max-width:900px){
  .whois-sections{grid-template-columns:1fr}
  .whois-section{border-right:none}
  .whois-section:nth-child(n){border-bottom:1px solid var(--border)}
  .whois-section:last-child{border-bottom:none}
  .ht-head,.ht-row{grid-template-columns:1fr 100px 80px}
  .ht-th:nth-child(2),.ht-row>*:nth-child(2),.ht-th:nth-child(5),.ht-row>*:nth-child(5){display:none}
}
@media(max-width:768px){
  .main{margin-left:0}.mobile-menu-btn{display:flex}
  .content{padding:20px 16px 50px}
  .search-row{flex-direction:column;align-items:stretch}
  .credits-pill{display:none}
  .ht-head,.ht-row{grid-template-columns:1fr 80px}
  .ht-th:nth-child(3),.ht-row>*:nth-child(3){display:none}
}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:49}
.sidebar-overlay.show{display:block}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ═══════════════════════════════════════════════════
     SIDEBAR  —  includes/sidebar.php
═══════════════════════════════════════════════════ -->
<aside class="cd-sidebar" id="cdSidebar" aria-label="Main navigation">

  <!-- Logo -->
  <a href="/web/checkdomain/index.php" class="cd-sb-logo">
    
      <img src="/web/checkdomain/images/logo.png" alt="checkdomain.top logo" height="20px" >
    
    <span class="cd-sb-logo-text">CheckDomain</span>
  </a>

  <!-- Main nav -->
  <div class="cd-sb-section-label">Main</div>
  <nav class="cd-sb-nav" aria-label="Main">
        <a href="/web/checkdomain/dashboard.php"
       class="cd-sb-link "
       >
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-th-large"></i></span>
      Dashboard          </a>
        <a href="/web/checkdomain/index.php"
       class="cd-sb-link "
       >
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-search"></i></span>
      Search          </a>
        <a href="/web/checkdomain/watchlist.php"
       class="cd-sb-link "
       >
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-bookmark"></i></span>
      Watchlist              <span class="cd-sb-badge green">1</span>
          </a>
        <a href="/web/checkdomain/alerts.php"
       class="cd-sb-link "
       >
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-bell"></i></span>
      Alerts          </a>
        <a href="/web/checkdomain/backorders.php"
       class="cd-sb-link "
       >
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-clock"></i></span>
      Backorders          </a>
      </nav>

  <!-- Services nav -->
  <div class="cd-sb-section-label">Services</div>
  <nav class="cd-sb-nav" aria-label="Services">
        <a href="/web/checkdomain/whois.php"
       class="cd-sb-link active "
       aria-current="page"       >
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-file-alt"></i></span>
      WHOIS Lookup          </a>
        <a href="/web/checkdomain/broker.php"
       class="cd-sb-link  "
              >
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-handshake"></i></span>
      Broker Service          </a>
        <a href="/web/checkdomain/dead-sites.php"
       class="cd-sb-link  "
              >
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-skull"></i></span>
      Dead Sites          </a>
        <a href="/web/checkdomain/billing.php"
       class="cd-sb-link  "
              >
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-credit-card"></i></span>
      Billing          </a>
      </nav>

  <!-- Divider -->
  <div class="cd-sb-divider" role="separator"></div>

  <!-- System nav -->
  <nav class="cd-sb-nav" aria-label="Account">
    <a href="/web/checkdomain/account-settings.php"
       class="cd-sb-link ">
      <span class="cd-sb-icon" aria-hidden="true"><i class="fas fa-cog"></i></span>
      Settings
    </a>
    <a href="/web/checkdomain/logout.php" class="cd-sb-link cd-sb-link--danger">
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
        <span class="cd-sb-credits-value">678 <span class="cd-sb-credits-max">/ 100</span></span>
      </div>
      <div class="cd-sb-credits-bar-wrap" role="progressbar"
           aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"
           aria-label="Credits remaining">
        <div class="cd-sb-credits-bar-fill "
             style="width:100%"></div>
      </div>
    </div>

    <!-- Upgrade strip — only for free users -->
    
    <!-- User card -->
    <a href="/web/checkdomain/account-settings.php" class="cd-sb-user">
      <div class="cd-sb-avatar" aria-hidden="true">AS</div>
      <div class="cd-sb-user-info">
        <div class="cd-sb-user-name">Alao</div>
        <div class="cd-sb-user-plan">Pro plan</div>
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
<main class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
      <div class="breadcrumb">
        <a href="/web/checkdomain/dashboard.php">Dashboard</a>
        <span style="color:var(--text3);font-size:9px;"><i class="fas fa-chevron-right"></i></span>
        <span style="color:var(--text);">WHOIS Lookup</span>
      </div>
    </div>
    <div class="topbar-right">
      <div class="credits-pill" id="creditsPill">
        <i class="fas fa-bolt" style="color:var(--amber);font-size:11px;"></i>
        <b id="creditsDisplay">678</b> credits
      </div>
      <a href="/web/checkdomain/billing.php?topup=1" class="topbar-btn" title="Top up credits">
        <i class="fas fa-plus"></i>
      </a>
    </div>
  </div>

  <div class="content">

    <div class="page-title">WHOIS Lookup.</div>
    <div class="page-sub">
      Deep registrar data, expiry dates, nameservers, and ownership records.
              Each lookup costs <em>3 credits</em>. Results cached for 24 hours — repeat lookups are free.
          </div>

    <!-- Upgrade gate -->
    
    <!-- Search hero -->
    <div class="search-hero">
      <div class="search-hero-label">
        <i class="fas fa-search" style="color:var(--green2);"></i>
        Domain lookup
      </div>
      <div class="search-row">
        <div class="search-input-wrap">
          <i class="fas fa-globe search-input-icon"></i>
          <input class="search-input" type="text" id="whoisInput"
                 placeholder="Enter any domain — e.g. techlaunch.com, mybrand.ng"
                 value=""
                                  autocomplete="off" maxlength="253"
                 onkeydown="if(event.key==='Enter')runLookup()">
        </div>
        <button class="search-btn" id="searchBtn" onclick="runLookup()" >
          <i class="fas fa-search" style="font-size:11px;"></i> Lookup
        </button>
      </div>
      <div class="search-hint">
        <span><span class="cost-pill">3 credits</span> per lookup</span>
        <span><span class="cache-pill">FREE</span> if cached within 24 hours</span>
        <span><i class="fas fa-info-circle"></i> Works for .com .net .org .io .ng .co.uk .de .fr and 20+ more TLDs</span>
              </div>
    </div>

    <!-- Loading state -->
    <div class="loading-state" id="loadingState">
      <div class="loading-spinner"></div>
      <div class="loading-domain" id="loadingDomain"></div>
      <div class="loading-steps">
        <div class="loading-step" id="step1">Connecting to WHOIS server…</div>
        <div class="loading-step" id="step2">Parsing registration data…</div>
        <div class="loading-step" id="step3">Building result…</div>
      </div>
    </div>

    <!-- Result panel -->
    <div class="result-panel" id="resultPanel"></div>

    <!-- Lookup history -->
        <div class="history-wrap">
      <div class="history-header"><span class="history-title">Lookup history</span></div>
      <div class="hist-empty">
        <i class="fas fa-history" style="font-size:20px;margin-bottom:10px;display:block;opacity:.3;"></i>
        Your WHOIS lookups will appear here.
      </div>
    </div>
    
  </div>
</main>

<!-- Toast -->
<div class="toast" id="toast">
  <i class="fas fa-check-circle" id="toastIcon" style="font-size:14px;flex-shrink:0;color:var(--green2);"></i>
  <span id="toastText"></span>
</div>

<script>
const API_URL   = " window.location.pathname";
const APP_BASE  = "\/web\/checkdomain";
const CAN_WHOIS = true;

// ── Run lookup ─────────────────────────────────────────────
async function runLookup() {
  if (!CAN_WHOIS) return;
  const input = document.getElementById('whoisInput');
  const btn   = document.getElementById('searchBtn');
  let   val   = input.value.trim().toLowerCase().replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
  if (!val) { input.focus(); return; }
  if (!val.includes('.')) val += '.com';

  showLoading(val);

  btn.disabled  = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:11px;"></i> Looking up…';

  try {
    const res  = await fetch(API_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body:    JSON.stringify({ action: 'lookup', domain: val }),
    });
    const data = await res.json();

    hideLoading();

    if (data.success) {
      renderResult(data.data, val);
      if (!data.data.from_cache) {
        const credEl = document.getElementById('creditsDisplay');
        if (credEl) credEl.textContent = data.credits_remaining;
      }
      if (data.data.from_cache) showToast('Loaded from cache — no credits deducted.', 'success');
    } else if (data.requiresUpgrade) {
      showToast('WHOIS lookups require a Pro plan.', 'error');
    } else if (data.insufficientCredits) {
      showToast(data.message, 'error');
      setTimeout(() => window.location.href = APP_BASE + '/billing.php?topup=1', 2000);
    } else {
      showToast(data.message || 'Lookup failed. Try again.', 'error');
    }
  } catch (err) {
    console.error('WHOIS lookup failed:', err);
    hideLoading();
    showToast(err.message || 'Network error. Please try again.', 'error');
  } finally {
    btn.disabled  = false;
    btn.innerHTML = '<i class="fas fa-search" style="font-size:11px;"></i> Lookup';
  }
}

// ── Quick load from history ────────────────────────────────
function quickLoad(domain) {
  const input = document.getElementById('whoisInput');
  input.value = domain;
  input.focus();
  runLookup();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Loading animation ──────────────────────────────────────
let stepTimer;
function showLoading(domain) {
  document.getElementById('loadingDomain').textContent = 'Looking up ' + domain + '…';
  document.getElementById('loadingState').classList.add('visible');
  document.getElementById('resultPanel').classList.remove('visible');
  document.getElementById('resultPanel').innerHTML = '';

  const steps = ['step1','step2','step3'];
  let i = 0;
  steps.forEach(s => { const el = document.getElementById(s); el.classList.remove('active','done'); });

  function tick() {
    if (i > 0) document.getElementById(steps[i-1]).classList.replace('active','done');
    if (i < steps.length) {
      document.getElementById(steps[i]).classList.add('active');
      i++;
      stepTimer = setTimeout(tick, 800);
    }
  }
  tick();
}
function hideLoading() {
  clearTimeout(stepTimer);
  document.getElementById('loadingState').classList.remove('visible');
}

// ── Render WHOIS result ────────────────────────────────────
function renderResult(d, domain) {
  const panel = document.getElementById('resultPanel');
  panel.classList.add('visible');

  if (d.is_available) {
    panel.innerHTML = renderAvailable(d, domain);
    return;
  }
  panel.innerHTML = renderWhoisCard(d, domain);
}

function renderAvailable(d, domain) {
  return `
    <div class="available-banner">
      <div class="avail-icon"><i class="fas fa-check-circle"></i></div>
      <div class="avail-info">
        <div class="avail-domain">${escHtml(domain)}</div>
        <div class="avail-label">✓ Domain is available!</div>
        <div class="avail-sub">This domain is not currently registered. You can register it right now.</div>
      </div>
      <div class="avail-actions">
        <a href="${APP_BASE}/index.php?q=${encodeURIComponent(domain)}" class="avail-cta cta-primary">
          <i class="fas fa-shopping-cart" style="font-size:10px;"></i> Register now
        </a>
        <button class="avail-cta cta-secondary" onclick="watchDomain('${escHtml(domain)}')">
          <i class="fas fa-bookmark" style="font-size:10px;"></i> Watch it
        </button>
      </div>
    </div>`;
}

function renderWhoisCard(d, domain) {
  const parts  = domain.split('.');
  const sld    = parts[0];
  const tld    = '.' + parts.slice(1).join('.');
  const src    = d.source === 'api' ? 'API' : d.source === 'socket' ? 'Socket' : 'Cache';
  const srcCls = d.source === 'api' ? 'wb-api' : d.source === 'socket' ? 'wb-socket' : 'wb-cache';

  // Expiry calculations
  let expiryHtml = '<span class="wf-value na">Not available</span>';
  let expiryBarHtml = '';
  if (d.expiry_date) {
    const exTs    = new Date(d.expiry_date).getTime() / 1000;
    const now     = Date.now() / 1000;
    const crTs    = d.created_date ? new Date(d.created_date).getTime() / 1000 : now - 31536000;
    const daysLeft = Math.ceil((exTs - now) / 86400);
    const totalDays = (exTs - crTs) / 86400;
    const pct     = Math.max(0, Math.min(100, Math.round(((now - crTs) / (exTs - crTs)) * 100)));
    const barColor = daysLeft < 30 ? 'var(--coral)' : daysLeft < 90 ? 'var(--amber)' : 'var(--green)';
    const valCls   = daysLeft < 30 ? 'expiring' : daysLeft < 90 ? 'expiring-soon' : '';

    expiryHtml = `<span class="wf-value ${valCls}">${d.expiry_date}${daysLeft >= 0 ? ` <span style="font-size:10px;color:${barColor}">(${daysLeft}d left)</span>` : ''}</span>`;

    expiryBarHtml = `
      <div class="expiry-bar-section">
        <div class="expiry-bar-label">
          <span>${d.created_date || 'Created'}</span>
          <span>${daysLeft >= 0 ? daysLeft + ' days remaining' : 'Expired'}</span>
          <span>${d.expiry_date}</span>
        </div>
        <div class="expiry-bar-wrap">
          <div class="expiry-bar-fill" style="width:${pct}%;background:${barColor};"></div>
        </div>
      </div>`;
  }

  // Status tags
  const statusList = Array.isArray(d.status) ? d.status : (d.status ? d.status.split(' ') : []);
  const statusTagsHtml = statusList.length > 0
    ? `<div class="status-tags">${statusList.map(s => `<span class="status-tag ${s.startsWith('client') ? 'active-status' : ''}">${escHtml(s)}</span>`).join('')}</div>`
    : '<span class="wf-value na">Not available</span>';

  // Nameservers
  const ns = Array.isArray(d.nameservers) ? d.nameservers : [];
  const nsHtml = ns.length > 0
    ? `<div class="ns-list">${ns.map(n => `<div class="ns-item">${escHtml(n)}</div>`).join('')}</div>`
    : '<span class="wf-value na">Not available</span>';

  return `
    <div class="whois-card">
      <div class="whois-card-header">
        <div>
          <div class="whois-domain-title">${escHtml(sld)}<span>${escHtml(tld)}</span></div>
          <div class="whois-meta-row" style="margin-top:5px;">
            <span class="whois-badge wb-taken">Registered</span>
            <span class="whois-badge ${srcCls}">${src}</span>
            ${d.from_cache ? `<span class="whois-badge wb-cache">Cached · ${d.cached_age || ''}</span>` : ''}
          </div>
        </div>
        <div class="whois-header-actions">
          <button class="icon-btn" onclick="copyWhois()" title="Copy all data"><i class="fas fa-copy"></i></button>
          <a href="${APP_BASE}/index.php?q=${encodeURIComponent(domain)}" class="icon-btn" title="Check availability"><i class="fas fa-search"></i></a>
        </div>
      </div>

      <div class="whois-sections">
        <div class="whois-section">
          <div class="whois-section-title"><i class="fas fa-building" style="color:var(--blue);"></i> Registrar</div>
          <div class="whois-fields">
            <div class="wf-row">
              <div class="wf-label">Registrar name</div>
              ${d.registrar ? `<div class="wf-value">${escHtml(d.registrar)}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
            ${d.registrar_url ? `<div class="wf-row"><div class="wf-label">Registrar URL</div><div class="wf-value"><a href="${escHtml(d.registrar_url)}" target="_blank" rel="noopener" style="color:var(--green2);text-decoration:none;">${escHtml(d.registrar_url.replace(/^https?:\/\//,'').substring(0,40))}</a></div></div>` : ''}
          </div>
        </div>

        <div class="whois-section">
          <div class="whois-section-title"><i class="fas fa-calendar" style="color:var(--amber);"></i> Dates</div>
          <div class="whois-fields">
            <div class="wf-row">
              <div class="wf-label">Created</div>
              ${d.created_date ? `<div class="wf-value">${d.created_date}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
            <div class="wf-row">
              <div class="wf-label">Last updated</div>
              ${d.updated_date ? `<div class="wf-value">${d.updated_date}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
            <div class="wf-row">
              <div class="wf-label">Expiry date</div>
              ${expiryHtml}
            </div>
          </div>
        </div>

        <div class="whois-section">
          <div class="whois-section-title"><i class="fas fa-user" style="color:var(--purple);"></i> Registrant</div>
          <div class="whois-fields">
            <div class="wf-row">
              <div class="wf-label">Name</div>
              ${d.registrant_name ? `<div class="wf-value">${escHtml(d.registrant_name)}</div>` : '<div class="wf-value na">Redacted for privacy</div>'}
            </div>
            <div class="wf-row">
              <div class="wf-label">Organisation</div>
              ${d.registrant_org ? `<div class="wf-value">${escHtml(d.registrant_org)}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
            <div class="wf-row">
              <div class="wf-label">Country</div>
              ${d.registrant_country ? `<div class="wf-value">${escHtml(d.registrant_country)}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
          </div>
        </div>

        <div class="whois-section">
          <div class="whois-section-title"><i class="fas fa-shield-alt" style="color:var(--green2);"></i> Status &amp; Security</div>
          <div class="whois-fields">
            <div class="wf-row">
              <div class="wf-label">Domain status</div>
              ${statusTagsHtml}
            </div>
            <div class="wf-row" style="margin-top:6px;">
              <div class="wf-label">DNSSEC</div>
              ${d.dnssec ? `<div class="wf-value">${escHtml(d.dnssec)}</div>` : '<div class="wf-value na">Not available</div>'}
            </div>
          </div>
        </div>

        <div class="whois-section" style="grid-column:1/-1;">
          <div class="whois-section-title"><i class="fas fa-server" style="color:var(--coral);"></i> Nameservers</div>
          ${nsHtml}
        </div>
      </div>

      ${expiryBarHtml}

      <div class="result-actions">
        <button class="result-action-btn rab-amber" onclick="watchDomain('${escHtml(domain)}')">
          <i class="fas fa-bookmark" style="font-size:10px;"></i> Add to watchlist
        </button>
        <button class="result-action-btn rab-coral" onclick="backorderDomain('${escHtml(domain)}')">
          <i class="fas fa-clock" style="font-size:10px;"></i> Place backorder
        </button>
        <button class="result-action-btn rab-default" onclick="copyWhois()">
          <i class="fas fa-copy" style="font-size:10px;"></i> Copy data
        </button>
        <a href="${APP_BASE}/index.php?q=${encodeURIComponent(domain)}" class="result-action-btn rab-green">
          <i class="fas fa-search" style="font-size:10px;"></i> Full domain check
        </a>
      </div>

      <div class="raw-section">
        <button class="raw-toggle" id="rawToggle" onclick="toggleRaw()">
          <i class="fas fa-chevron-down"></i> Raw WHOIS response
        </button>
        <pre class="raw-content" id="rawContent">${escHtml(d.raw || 'No raw data available.')}</pre>
      </div>

    </div>`;
}

// ── Toggle raw WHOIS ───────────────────────────────────────
function toggleRaw() {
  const toggle  = document.getElementById('rawToggle');
  const content = document.getElementById('rawContent');
  if (!toggle || !content) return;
  const open = content.classList.toggle('open');
  toggle.classList.toggle('open', open);
}

// ── Watch domain ───────────────────────────────────────────
async function watchDomain(domain) {
  try {
    const res  = await fetch(APP_BASE + '/api/watchlist-domain.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ domain }),
    });
    const data = await res.json();
    showToast(data.success ? `${domain} added to watchlist.` : (data.message || 'Failed to add.'),
              data.success ? 'success' : 'error');
  } catch {
    showToast('Network error.', 'error');
  }
}

// ── Backorder domain ───────────────────────────────────────
function backorderDomain(domain) {
  window.location.href = APP_BASE + '/backorders.php?domain=' + encodeURIComponent(domain);
}

// ── Copy WHOIS data ────────────────────────────────────────
function copyWhois() {
  const raw = document.getElementById('rawContent');
  const text = raw ? raw.textContent : document.getElementById('resultPanel').innerText;
  navigator.clipboard.writeText(text)
    .then(() => showToast('WHOIS data copied to clipboard.'))
    .catch(() => showToast('Could not copy.', 'error'));
}

// ── Helpers ────────────────────────────────────────────────
function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showToast(msg, type = 'success') {
  const t    = document.getElementById('toast');
  const icon = document.getElementById('toastIcon');
  document.getElementById('toastText').textContent = msg;
  icon.className   = `fas ${type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'}`;
  icon.style.color = type === 'error' ? 'var(--coral)' : 'var(--green2)';
  t.className = `toast show ${type}`;
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 3800);
}

function openSidebar()  { document.getElementById('cdSidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('show'); }
function closeSidebar() { document.getElementById('cdSidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('show'); }

// ── Auto-run if prefill present ────────────────────────────
window.addEventListener('DOMContentLoaded', () => {
  const input = document.getElementById('whoisInput');
  if (input?.value.trim() && CAN_WHOIS) {
    setTimeout(runLookup, 300);
  }
});
</script>

</body>
</html>