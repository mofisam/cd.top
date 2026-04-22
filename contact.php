<?php
require_once 'config/database.php';

// Track page view
trackPageView($_SERVER['REQUEST_URI'] ?? 'contact');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
  <title>Contact Us - checkdomain.top</title>
  <meta name="description" content="Get in touch with the checkdomain.top team. We're here to help with your domain needs.">
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
    .custom-logo {
      max-width: 60px;
      max-height: 60px;
      object-fit: contain;
    }
    @media (max-width: 640px) {
      .custom-logo {
        max-width: 45px;
        max-height: 45px;
      }
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
    .contact-info-card {
      background: rgba(30, 41, 59, 0.5);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(59, 130, 246, 0.2);
      transition: all 0.3s ease;
    }
    .contact-info-card:hover {
      transform: translateY(-2px);
      border-color: rgba(16, 185, 129, 0.4);
    }
  </style>
</head>
<body class="relative text-white overflow-x-hidden antialiased">

  <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
    <div class="absolute top-[10%] left-[0%] w-96 h-96 bg-blue-600/15 rounded-full blur-3xl animate-float"></div>
    <div class="absolute bottom-[20%] right-[5%] w-80 h-80 bg-green-500/10 rounded-full blur-3xl animate-float" style="animation-delay: 1.8s;"></div>
    <div class="bg-noise absolute inset-0"></div>
  </div>

  <main class="relative z-10 min-h-screen flex flex-col px-5 py-8">
    <div class="max-w-6xl mx-auto w-full">
      
      <!-- Navigation -->
      <div class="flex justify-between items-center mb-8">
        <a href="index.php" class="flex items-center gap-3 group">
          <img src="images/logo.png" alt="checkdomain.top" class="custom-logo" onerror="this.src='https://via.placeholder.com/60x60?text=CD'">
          <div>
            <h1 class="text-xl font-bold bg-gradient-to-r from-white via-blue-300 to-green-300 bg-clip-text text-transparent">checkdomain<span class="text-green-400">.</span>top</h1>
            <p class="text-[10px] text-gray-400">Contact Support</p>
          </div>
        </a>
        <div class="flex gap-6">
          <a href="index.php" class="nav-link text-gray-300 hover:text-blue-400 transition flex items-center gap-2">
            <i class="fas fa-home text-xs"></i>
            Home
          </a>
          <a href="#" id="loginLink" class="nav-link text-gray-300 hover:text-blue-400 transition flex items-center gap-2">
            <i class="fas fa-sign-in-alt text-xs"></i>
            Login
          </a>
        </div>
      </div>
      
      <!-- Header -->
      <div class="text-center mb-12 animate-enter">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-blue-500 to-green-500 rounded-full mb-4">
          <i class="fas fa-envelope text-white text-2xl"></i>
        </div>
        <h1 class="text-4xl md:text-5xl font-bold mb-4 bg-gradient-to-r from-white via-blue-300 to-green-300 bg-clip-text text-transparent">Contact Us</h1>
        <p class="text-gray-300 text-lg max-w-2xl mx-auto">
          Have questions about domain availability? Need help with your account? We're here to help!
        </p>
      </div>
      
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Contact Information -->
        <div class="lg:col-span-1 space-y-6">
          <div class="contact-info-card rounded-2xl p-6">
            <div class="flex items-center gap-4 mb-4">
              <div class="w-12 h-12 bg-blue-500/20 rounded-full flex items-center justify-center">
                <i class="fas fa-map-marker-alt text-blue-400 text-xl"></i>
              </div>
              <div>
                <h3 class="font-semibold">Visit Us</h3>
                <p class="text-gray-400 text-sm">Online - 24/7 Support</p>
              </div>
            </div>
            <p class="text-gray-300 text-sm">We're fully online and ready to assist you anytime, anywhere in the world.</p>
          </div>
          
          <div class="contact-info-card rounded-2xl p-6">
            <div class="flex items-center gap-4 mb-4">
              <div class="w-12 h-12 bg-green-500/20 rounded-full flex items-center justify-center">
                <i class="fas fa-envelope text-green-400 text-xl"></i>
              </div>
              <div>
                <h3 class="font-semibold">Email Us</h3>
                <p class="text-gray-400 text-sm">Response within 24 hours</p>
              </div>
            </div>
            <p class="text-gray-300 text-sm">support@checkdomain.top</p>
            <p class="text-gray-300 text-sm mt-1">hello@checkdomain.top</p>
          </div>
          
          <div class="contact-info-card rounded-2xl p-6">
            <div class="flex items-center gap-4 mb-4">
              <div class="w-12 h-12 bg-purple-500/20 rounded-full flex items-center justify-center">
                <i class="fas fa-clock text-purple-400 text-xl"></i>
              </div>
              <div>
                <h3 class="font-semibold">Support Hours</h3>
                <p class="text-gray-400 text-sm">Mon - Fri, 9AM - 6PM EST</p>
              </div>
            </div>
            <p class="text-gray-300 text-sm">Emergency support available 24/7 for domain-related issues.</p>
          </div>
          
          <div class="contact-info-card rounded-2xl p-6">
            <div class="flex items-center gap-4 mb-4">
              <div class="w-12 h-12 bg-yellow-500/20 rounded-full flex items-center justify-center">
                <i class="fas fa-question-circle text-yellow-400 text-xl"></i>
              </div>
              <div>
                <h3 class="font-semibold">FAQ</h3>
                <p class="text-gray-400 text-sm">Quick answers</p>
              </div>
            </div>
            <p class="text-gray-300 text-sm">Check our FAQ section for common questions about domain registration and availability checking.</p>
          </div>
        </div>
        
        <!-- Contact Form -->
        <div class="lg:col-span-2">
          <div class="glass-card p-6 md:p-8">
            <h2 class="text-2xl font-semibold mb-2">Send us a message</h2>
            <p class="text-gray-400 text-sm mb-6">Fill out the form below and we'll get back to you as soon as possible.</p>
            
            <form id="contactForm" class="space-y-5">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                  <label class="block text-sm font-medium mb-2">Your Name *</label>
                  <input type="text" id="contactName" name="name" required
                    class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow">
                </div>
                <div>
                  <label class="block text-sm font-medium mb-2">Email Address *</label>
                  <input type="email" id="contactEmail" name="email" required
                    class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow">
                </div>
              </div>
              
              <div>
                <label class="block text-sm font-medium mb-2">Subject *</label>
                <input type="text" id="contactSubject" name="subject" required
                  class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow"
                  placeholder="What is this regarding?">
              </div>
              
              <div>
                <label class="block text-sm font-medium mb-2">Message *</label>
                <textarea id="contactMessage" name="message" rows="6" required
                  class="w-full bg-slate-800/70 border border-blue-500/40 rounded-xl py-3 px-4 text-white placeholder:text-gray-400 focus:outline-none input-glow resize-none"
                  placeholder="Please provide as much detail as possible..."></textarea>
              </div>
              
              <div class="flex items-start gap-3">
                <input type="checkbox" id="contactConsent" required class="mt-1 w-4 h-4 rounded border-blue-500/40">
                <label for="contactConsent" class="text-xs text-gray-400">
                  I consent to having this website store my submitted information so they can respond to my inquiry.
                </label>
              </div>
              
              <button type="submit" id="submitBtn" class="btn-primary text-white font-semibold px-8 py-3 rounded-xl transition w-full md:w-auto">
                <i class="fas fa-paper-plane mr-2"></i>
                Send Message
              </button>
            </form>
            
            <div id="formSuccess" class="hidden mt-6 p-4 bg-green-500/20 border border-green-500/50 rounded-xl">
              <div class="flex items-center gap-3">
                <i class="fas fa-check-circle text-green-400 text-xl"></i>
                <div>
                  <p class="font-semibold text-green-400">Message Sent Successfully!</p>
                  <p class="text-sm text-gray-300">Thank you for reaching out. We'll get back to you within 24 hours.</p>
                </div>
              </div>
            </div>
            
            <div id="formError" class="hidden mt-6 p-4 bg-red-500/20 border border-red-500/50 rounded-xl">
              <div class="flex items-center gap-3">
                <i class="fas fa-exclamation-triangle text-red-400 text-xl"></i>
                <div>
                  <p class="font-semibold text-red-400">Error Sending Message</p>
                  <p id="errorMessage" class="text-sm text-gray-300"></p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- FAQ Section -->
      <div class="mt-12 glass-card p-6 md:p-8">
        <h2 class="text-2xl font-semibold text-center mb-6">Frequently Asked Questions</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <h3 class="font-semibold text-blue-400 mb-2">How does domain availability checking work?</h3>
            <p class="text-gray-300 text-sm">Our system queries real-time domain registries to check if a domain is available for registration.</p>
          </div>
          <div>
            <h3 class="font-semibold text-blue-400 mb-2">How do I get notified about domain availability?</h3>
            <p class="text-gray-300 text-sm">Simply pin a domain that's taken, and we'll email you instantly when it becomes available.</p>
          </div>
          <div>
            <h3 class="font-semibold text-blue-400 mb-2">Is my information secure?</h3>
            <p class="text-gray-300 text-sm">Yes! We use industry-standard encryption and never share your personal information.</p>
          </div>
          <div>
            <h3 class="font-semibold text-blue-400 mb-2">Can I register domains through your platform?</h3>
            <p class="text-gray-300 text-sm">Domain registration will be available at launch (Q2 2026). Subscribe for early access!</p>
          </div>
        </div>
      </div>
      
      <!-- Footer -->
      <div class="mt-8 text-center text-gray-500 text-xs flex flex-wrap justify-center gap-6 border-t border-blue-500/20 pt-6">
        <span><i class="far fa-clock mr-1 text-blue-400"></i> Launching Q2 2026</span>
        <span><i class="fas fa-shield-alt mr-1 text-blue-400"></i> 100% Privacy Focused</span>
        <a href="index.php" class="hover:text-blue-400 transition">Home</a>
      </div>
    </div>
  </main>

  <div id="toastMsg" class="fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 bg-slate-900/95 backdrop-blur-lg border border-blue-500 rounded-full px-5 py-2.5 text-sm font-medium text-white transition-all duration-300 opacity-0 pointer-events-none flex items-center gap-2 shadow-xl">
    <i class="fas fa-circle-info text-green-400"></i> <span id="toastText">Message</span>
  </div>

  <script>
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
    
    document.getElementById('contactForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const name = document.getElementById('contactName').value.trim();
      const email = document.getElementById('contactEmail').value.trim();
      const subject = document.getElementById('contactSubject').value.trim();
      const message = document.getElementById('contactMessage').value.trim();
      const consent = document.getElementById('contactConsent').checked;
      
      if (!consent) {
        showToast("Please consent to our privacy policy", true);
        return;
      }
      
      const submitBtn = document.getElementById('submitBtn');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Sending...';
      submitBtn.disabled = true;
      
      try {
        const response = await fetch('/api/contact.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, email, subject, message })
        });
        
        const data = await response.json();
        
        if (data.success) {
          document.getElementById('formSuccess').classList.remove('hidden');
          document.getElementById('formError').classList.add('hidden');
          document.getElementById('contactForm').reset();
          showToast(data.message, false);
        } else {
          document.getElementById('formError').classList.remove('hidden');
          document.getElementById('formSuccess').classList.add('hidden');
          const errorMsg = data.errors ? data.errors.join(', ') : 'Failed to send message';
          document.getElementById('errorMessage').innerText = errorMsg;
          showToast(errorMsg, true);
        }
      } catch (error) {
        document.getElementById('formError').classList.remove('hidden');
        document.getElementById('formSuccess').classList.add('hidden');
        document.getElementById('errorMessage').innerText = 'Network error. Please try again.';
        showToast('Network error. Please try again.', true);
      } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      }
    });
    
    // Login link handler
    document.getElementById('loginLink')?.addEventListener('click', (e) => {
      e.preventDefault();
      showToast("🔐 Admin login page coming soon. Please check back later.", false);
    });
  </script>
</body>
</html>