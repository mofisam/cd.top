<?php
require_once 'config/database.php';
require_once 'includes/error_handler.php';

trackPageView($_SERVER['REQUEST_URI'] ?? 'contact');

$pageTitle = 'Contact Us - checkdomain.top';
$pageDescription = 'Get in touch with the checkdomain.top team. We are here to help with domain search, monitoring, and availability questions.';
$showHeaderHero = false;

require_once 'includes/header.php';
?>

      <section class="py-10 md:py-14">
        <div class="max-w-3xl text-left">
          <div class="hero-chip inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium text-sky-100">
            <i class="fas fa-envelope text-green-300"></i>
            Support for domain search, alerts and account questions
          </div>
          <h1 class="mt-5 text-4xl font-extrabold leading-tight tracking-normal text-white md:text-6xl">
            Contact checkdomain team.
          </h1>
          <p class="mt-5 max-w-2xl text-base leading-7 text-slate-300 md:text-lg">
            Have a question, found an issue, or need help choosing a domain workflow? Send us a message and we will get back to you as soon as possible.
          </p>
        </div>
      </section>

      <section class="grid grid-cols-1 gap-8 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-1">
          <div class="feature-card rounded-lg p-5 text-left">
            <div class="flex items-center gap-4">
              <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-500/15 text-blue-300">
                <i class="fas fa-location-dot text-lg"></i>
              </div>
              <div>
                <h2 class="font-semibold text-white">Online Support</h2>
                <p class="text-sm text-slate-400">Available from anywhere</p>
              </div>
            </div>
            <p class="mt-4 text-sm leading-6 text-slate-300">
              We are fully online, so you can reach us whenever you need help with your domain search.
            </p>
          </div>

          <div class="feature-card rounded-lg p-5 text-left">
            <div class="flex items-center gap-4">
              <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-green-500/15 text-green-300">
                <i class="fas fa-envelope text-lg"></i>
              </div>
              <div>
                <h2 class="font-semibold text-white">Email Us</h2>
                <p class="text-sm text-slate-400">Response within 24 hours</p>
              </div>
            </div>
            <div class="mt-4 space-y-1 text-sm text-slate-300">
              <p>support@checkdomain.top</p>
            </div>
          </div>

          <div class="feature-card rounded-lg p-5 text-left">
            <div class="flex items-center gap-4">
              <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-purple-500/15 text-purple-300">
                <i class="fas fa-clock text-lg"></i>
              </div>
              <div>
                <h2 class="font-semibold text-white">Support Hours</h2>
                <p class="text-sm text-slate-400">Mon - Fri, 9AM - 6PM EST</p>
              </div>
            </div>
            <p class="mt-4 text-sm leading-6 text-slate-300">
              Send a message anytime. We prioritize domain search issues, account questions, and launch-related support.
            </p>
          </div>
        </div>

        <div class="lg:col-span-2">
          <div class="glass-card p-6 md:p-8">
            <h2 class="text-2xl font-semibold text-white">Send us a message</h2>
            <p class="mt-2 text-sm text-slate-400">Tell us what you need, and include the domain name if your message is about a specific search.</p>

            <form id="contactForm" class="mt-6 space-y-5">
              <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                  <label for="contactName" class="mb-2 block text-sm font-medium text-slate-200">Your Name *</label>
                  <input type="text" id="contactName" name="name" required
                    class="input-glow w-full rounded-lg border border-slate-700 bg-slate-900/80 px-4 py-3 text-white placeholder:text-slate-500 focus:outline-none">
                </div>
                <div>
                  <label for="contactEmail" class="mb-2 block text-sm font-medium text-slate-200">Email Address *</label>
                  <input type="email" id="contactEmail" name="email" required
                    class="input-glow w-full rounded-lg border border-slate-700 bg-slate-900/80 px-4 py-3 text-white placeholder:text-slate-500 focus:outline-none">
                </div>
              </div>

              <div>
                <label for="contactSubject" class="mb-2 block text-sm font-medium text-slate-200">Subject *</label>
                <input type="text" id="contactSubject" name="subject" required
                  class="input-glow w-full rounded-lg border border-slate-700 bg-slate-900/80 px-4 py-3 text-white placeholder:text-slate-500 focus:outline-none"
                  placeholder="What is this regarding?">
              </div>

              <div>
                <label for="contactMessage" class="mb-2 block text-sm font-medium text-slate-200">Message *</label>
                <textarea id="contactMessage" name="message" rows="6" required
                  class="input-glow w-full resize-none rounded-lg border border-slate-700 bg-slate-900/80 px-4 py-3 text-white placeholder:text-slate-500 focus:outline-none"
                  placeholder="Please provide as much detail as possible..."></textarea>
              </div>

              <div class="flex items-start gap-3">
                <input type="checkbox" id="contactConsent" required class="mt-1 h-4 w-4 rounded border-slate-600 bg-slate-900">
                <label for="contactConsent" class="text-xs leading-5 text-slate-400">
                  I consent to having this website store my submitted information so the team can respond to my inquiry.
                </label>
              </div>

              <button type="submit" id="submitBtn" class="btn-primary inline-flex w-full items-center justify-center gap-2 rounded-lg px-8 py-3 font-semibold text-white md:w-auto">
                <i class="fas fa-paper-plane"></i>
                Send Message
              </button>
            </form>

            <div id="formSuccess" class="mt-6 hidden rounded-lg border border-green-500/50 bg-green-500/15 p-4">
              <div class="flex items-center gap-3">
                <i class="fas fa-check-circle text-xl text-green-400"></i>
                <div>
                  <p class="font-semibold text-green-300">Message Sent Successfully</p>
                  <p class="text-sm text-slate-300">Thank you for reaching out. We will get back to you within 24 hours.</p>
                </div>
              </div>
            </div>

            <div id="formError" class="mt-6 hidden rounded-lg border border-red-500/50 bg-red-500/15 p-4">
              <div class="flex items-center gap-3">
                <i class="fas fa-exclamation-triangle text-xl text-red-400"></i>
                <div>
                  <p class="font-semibold text-red-300">Error Sending Message</p>
                  <p id="errorMessage" class="text-sm text-slate-300"></p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="mx-auto mt-8 max-w-5xl rounded-lg border border-slate-700/60 bg-slate-950/45 p-5 text-left backdrop-blur md:p-7">
        <h2 class="text-2xl font-semibold text-white">Frequently Asked Questions</h2>
        <div class="mt-6 grid grid-cols-1 gap-5 md:grid-cols-2">
          <div>
            <h3 class="font-semibold text-blue-300">How does domain availability checking work?</h3>
            <p class="mt-2 text-sm leading-6 text-slate-300">Our system checks domain availability and shows whether a name appears ready to register or already taken.</p>
          </div>
          <div>
            <h3 class="font-semibold text-blue-300">How do I get notified about domain availability?</h3>
            <p class="mt-2 text-sm leading-6 text-slate-300">Pin a taken domain and subscribe for alerts so you can respond when availability changes.</p>
          </div>
          <div>
            <h3 class="font-semibold text-blue-300">Is my information secure?</h3>
            <p class="mt-2 text-sm leading-6 text-slate-300">We keep the experience privacy-focused and only use submitted contact details to respond to your inquiry.</p>
          </div>
          <div>
            <h3 class="font-semibold text-blue-300">Can I register domains through your platform?</h3>
            <p class="mt-2 text-sm leading-6 text-slate-300">Domain registration is planned for launch. For now, check availability, save ideas, and subscribe for updates.</p>
          </div>
        </div>
      </section>

      <?php require_once 'includes/footer.php'; ?>

  <script>
    const APP_BASE_PATH = <?php echo json_encode($appBasePath ?? ''); ?>;
    const appUrl = (path) => `${APP_BASE_PATH}/${String(path).replace(/^\/+/, '')}`;

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
        throw new Error('Unexpected server response. Please try again.');
      }
    }

    document.getElementById('contactForm').addEventListener('submit', async (event) => {
      event.preventDefault();

      const name = document.getElementById('contactName').value.trim();
      const email = document.getElementById('contactEmail').value.trim();
      const subject = document.getElementById('contactSubject').value.trim();
      const message = document.getElementById('contactMessage').value.trim();
      const consent = document.getElementById('contactConsent').checked;

      if (!consent) {
        showToast('Please consent so we can respond to your inquiry.', true);
        return;
      }

      const submitBtn = document.getElementById('submitBtn');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Sending...';
      submitBtn.disabled = true;

      try {
        const response = await fetch(appUrl('api/contact.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name, email, subject, message })
        });

        const data = await parseJsonResponse(response);

        if (data.success) {
          document.getElementById('formSuccess').classList.remove('hidden');
          document.getElementById('formError').classList.add('hidden');
          document.getElementById('contactForm').reset();
          showToast(data.message, false);
        } else {
          const errorMsg = data.errors ? data.errors.join(', ') : 'Failed to send message';
          document.getElementById('formError').classList.remove('hidden');
          document.getElementById('formSuccess').classList.add('hidden');
          document.getElementById('errorMessage').innerText = errorMsg;
          showToast(errorMsg, true);
        }
      } catch (error) {
        document.getElementById('formError').classList.remove('hidden');
        document.getElementById('formSuccess').classList.add('hidden');
        document.getElementById('errorMessage').innerText = error.message || 'Network error. Please try again.';
        showToast(error.message || 'Network error. Please try again.', true);
      } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
      }
    });
  </script>
</body>
</html>
