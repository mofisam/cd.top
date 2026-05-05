<?php
$pageTitle = $pageTitle ?? 'checkdomain.top - Check Domain Availability Instantly';
$pageDescription = $pageDescription ?? 'Check if any domain is available for registration. Never miss your perfect domain again.';
$popularTLDCount = isset($popularTLDs) && is_array($popularTLDs) ? count($popularTLDs) : 0;
$appBasePath = $appBasePath ?? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($appBasePath === '/' || $appBasePath === '.' || $appBasePath === '\\') {
    $appBasePath = '';
}
$assetUrl = $assetUrl ?? function ($path) use ($appBasePath) {
    return ($appBasePath ?: '') . '/' . ltrim($path, '/');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
  <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($assetUrl('favicon/favicon-96x96.png'), ENT_QUOTES, 'UTF-8'); ?>" sizes="96x96" />
  <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($assetUrl('favicon/favicon.svg'), ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="shortcut icon" href="<?php echo htmlspecialchars($assetUrl('favicon/favicon.ico'), ENT_QUOTES, 'UTF-8'); ?>" />
  <link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars($assetUrl('favicon/apple-touch-icon.png'), ENT_QUOTES, 'UTF-8'); ?>" />
  <meta name="apple-mobile-web-app-title" content="checkdomain" />
  <link rel="manifest" href="<?php echo htmlspecialchars($assetUrl('favicon/site.webmanifest'), ENT_QUOTES, 'UTF-8'); ?>" />
  <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
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
      background:
        linear-gradient(135deg, #07111f 0%, #111827 48%, #052019 100%);
      font-family: 'Inter', system-ui, sans-serif;
    }
    .glass-card {
      background: rgba(8, 17, 34, 0.82);
      backdrop-filter: blur(18px);
      border: 1px solid rgba(125, 211, 252, 0.24);
      border-radius: 1rem;
      transition: all 0.3s ease;
      box-shadow: 0 24px 70px rgba(0, 0, 0, 0.28);
    }
    .glass-card:hover {
      border-color: rgba(52, 211, 153, 0.45);
    }
    .input-glow:focus {
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.5);
      border-color: #3B82F6;
    }
    .btn-primary {
      background: linear-gradient(135deg, #2563EB 0%, #0891B2 100%);
      transition: all 0.2s ease;
    }
    .btn-primary:hover {
      background: linear-gradient(135deg, #3B82F6 0%, #10B981 100%);
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
    .page-texture {
      background-image:
        linear-gradient(rgba(148, 163, 184, 0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(148, 163, 184, 0.04) 1px, transparent 1px),
        linear-gradient(135deg, rgba(56, 189, 248, 0.1), transparent 38%, rgba(16, 185, 129, 0.08));
      background-size: 42px 42px, 42px 42px, 100% 100%;
    }
    .hero-chip {
      border: 1px solid rgba(148, 163, 184, 0.24);
      background: rgba(15, 23, 42, 0.62);
    }
    .feature-card {
      background: rgba(15, 23, 42, 0.54);
      border: 1px solid rgba(148, 163, 184, 0.18);
      transition: border-color 0.2s ease, transform 0.2s ease, background 0.2s ease;
    }
    .feature-card:hover {
      background: rgba(15, 23, 42, 0.72);
      border-color: rgba(125, 211, 252, 0.34);
      transform: translateY(-2px);
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
      padding: 0.35rem 0.8rem;
      background: rgba(15, 23, 42, 0.72);
      border: 1px solid rgba(125, 211, 252, 0.22);
      border-radius: 9999px;
      font-size: 0.75rem;
      cursor: pointer;
      transition: all 0.2s;
    }
    .tld-badge:hover {
      background: rgba(20, 184, 166, 0.18);
      border-color: rgba(52, 211, 153, 0.42);
      transform: translateY(-1px);
    }
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

  <div class="fixed inset-0 overflow-hidden pointer-events-none z-0 page-texture"></div>
  <div class="fixed inset-0 overflow-hidden pointer-events-none z-0 bg-noise"></div>

  <main class="relative z-10 min-h-screen flex flex-col items-center px-5 py-8 md:py-10">
    <div class="w-full max-w-6xl mx-auto animate-enter">

      <nav class="flex flex-col gap-4 rounded-lg border border-slate-700/60 bg-slate-950/50 px-4 py-3 backdrop-blur md:flex-row md:items-center md:justify-between">
        <a href="<?php echo htmlspecialchars($assetUrl('index.php'), ENT_QUOTES, 'UTF-8'); ?>" class="flex items-center gap-3">
          <img src="<?php echo htmlspecialchars($assetUrl('images/logo.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="checkdomain.top logo" class="h-10 w-10 object-contain">
          <div>
            <div class="text-lg font-extrabold tracking-tight">checkdomain<span class="text-green-400">.</span>top</div>
            <div class="text-[11px] font-mono text-sky-200/75">Domain availability intelligence</div>
          </div>
        </a>
        <div class="flex items-center gap-5 text-sm">
          <a href="<?php echo htmlspecialchars($assetUrl('contact.php'), ENT_QUOTES, 'UTF-8'); ?>" id="contactLink" class="nav-link text-gray-300 hover:text-blue-400 transition flex items-center gap-2">
            <i class="fas fa-envelope text-xs"></i>
            Contact
          </a>
          <a href="<?php echo htmlspecialchars($assetUrl('admin/login.php'), ENT_QUOTES, 'UTF-8'); ?>" id="loginLink" class="nav-link text-gray-300 hover:text-blue-400 transition flex items-center gap-2">
            <i class="fas fa-sign-in-alt text-xs"></i>
            Login
          </a>
        </div>
      </nav>

      <section class="grid gap-6 py-10 md:py-12 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-end">
        <div class="max-w-3xl text-left">
          <div class="hero-chip inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium text-sky-100">
            <i class="fas fa-signal text-green-300"></i>
            Instant WHOIS checks, alerts, and smart alternatives
          </div>
          <h1 class="mt-5 max-w-3xl text-4xl font-extrabold leading-tight tracking-normal text-white md:text-6xl">
            Find a domain name before someone else does.
          </h1>
          <p class="mt-4 max-w-2xl text-base leading-7 text-slate-300 md:text-lg">
            Check availability, compare useful alternatives, and pin taken names so you can move quickly when the right domain opens up.
          </p>
          <div class="mt-5 flex flex-wrap gap-2 text-xs text-slate-300">
            <span class="hero-chip rounded-full px-3 py-1.5"><i class="fas fa-shield-halved mr-1 text-sky-300"></i> Private searches</span>
            <span class="hero-chip rounded-full px-3 py-1.5"><i class="fas fa-bolt mr-1 text-amber-300"></i> Fast lookup</span>
            <span class="hero-chip rounded-full px-3 py-1.5"><i class="fas fa-thumbtack mr-1 text-green-300"></i> Pin domains</span>
          </div>
        </div>

        <div class="rounded-lg border border-slate-700/70 bg-slate-950/55 p-5 text-left shadow-2xl backdrop-blur">
          <div class="flex items-center justify-between border-b border-slate-700/60 pb-3">
            <div>
              <p class="text-xs uppercase tracking-[0.18em] text-slate-500">Checker Status</p>
              <p class="mt-1 text-lg font-semibold text-white">Ready to search</p>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-green-500/15 text-green-300">
              <i class="fas fa-check"></i>
            </div>
          </div>
          <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div class="rounded-lg bg-slate-900/70 p-3">
              <p class="text-slate-500">TLDs</p>
              <p class="mt-1 font-semibold text-sky-200"><?php echo $popularTLDCount; ?> popular</p>
            </div>
            <div class="rounded-lg bg-slate-900/70 p-3">
              <p class="text-slate-500">Security</p>
              <p class="mt-1 font-semibold text-green-200">Rate limited</p>
            </div>
          </div>
        </div>
      </section>
