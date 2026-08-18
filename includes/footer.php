<?php
if (!isset($assetUrl)) {
    $appBasePath = $appBasePath ?? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($appBasePath === '/' || $appBasePath === '.' || $appBasePath === '\\') {
        $appBasePath = '';
    }
    $assetUrl = function ($path) use ($appBasePath) {
        return ($appBasePath ?: '') . '/' . ltrim($path, '/');
    };
}
?>
      <div class="mt-8 border-t border-blue-500/20 pt-6">
        <div class="flex flex-wrap justify-center gap-6 text-center text-xs text-gray-500">
          <span><i class="far fa-clock mr-1 text-blue-400"></i> Launching Q2 2026</span>
          <span><i class="fas fa-shield-alt mr-1 text-blue-400"></i> 100% Privacy Focused</span>
          <a href="<?php echo htmlspecialchars($assetUrl('about.php'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-blue-400 transition flex items-center gap-1">
            <i class="fas fa-circle-info mr-1 text-sky-400"></i> About
          </a>
          <a href="<?php echo htmlspecialchars($assetUrl('index.php#plans'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-blue-400 transition flex items-center gap-1">
            <i class="fas fa-tag mr-1 text-amber-400"></i> Pricing
          </a>
          <a href="<?php echo htmlspecialchars($assetUrl('contact.php'), ENT_QUOTES, 'UTF-8'); ?>" id="contactLinkFooter" class="hover:text-blue-400 transition flex items-center gap-1">
            <i class="fas fa-envelope mr-1 text-green-400"></i> Contact Us
          </a>
          <a href="<?php echo htmlspecialchars($assetUrl('privacy.php'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-blue-400 transition flex items-center gap-1">
            <i class="fas fa-lock mr-1 text-purple-400"></i> Privacy
          </a>
          <a href="<?php echo htmlspecialchars($assetUrl('terms.php'), ENT_QUOTES, 'UTF-8'); ?>" class="hover:text-blue-400 transition flex items-center gap-1">
            <i class="fas fa-file-contract mr-1 text-purple-400"></i> Terms
          </a>
          <!--
          <a href="<?php echo htmlspecialchars($assetUrl('login.php'), ENT_QUOTES, 'UTF-8'); ?>" id="loginLinkFooter" class="hover:text-blue-400 transition flex items-center gap-1">
            <i class="fas fa-sign-in-alt mr-1 text-green-400"></i> Login
          </a>
          -->
        </div>
        <div class="mt-4 text-center text-[11px] text-gray-600">
          &copy; <?php echo date('Y'); ?> CheckDomain. All rights reserved.
        </div>
      </div>
    </div>
  </main>

  <div id="toastMsg" class="fixed bottom-6 left-1/2 transform -translate-x-1/2 z-50 bg-slate-900/95 backdrop-blur-lg border border-blue-500 rounded-full px-5 py-2.5 text-sm font-medium text-white transition-all duration-300 opacity-0 pointer-events-none flex items-center gap-2 shadow-xl">
    <i class="fas fa-circle-info text-green-400"></i> <span id="toastText">Message</span>
  </div>