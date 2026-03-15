<?php
require_once dirname(__DIR__).'/config.php';
$lang = getLang();
$canonicalUrl = APP_URL . '/landing/privacy.php';
?><!DOCTYPE html><html lang="<?= $lang ?>"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Privacy Policy — BulkReplace | Data Protection & Client-Side Processing</title>
<meta name="description" content="BulkReplace privacy policy: Client-side processing means your files never leave your browser. Learn how we protect your data, what we collect, and your privacy rights.">
<link rel="canonical" href="<?= $canonicalUrl ?>">
<meta name="robots" content="index, follow">
<meta property="og:title" content="BulkReplace Privacy Policy">
<meta property="og:description" content="Client-side processing means your files never leave your browser. Learn how we protect your data.">
<meta property="og:url" content="<?= $canonicalUrl ?>">
<meta property="og:type" content="website">
<meta property="og:image" content="https://bulkreplacetool.com/img/og-cover.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="BulkReplace Privacy Policy">
<meta name="twitter:description" content="Client-side processing. Your files never leave your browser.">
<meta name="twitter:image" content="https://bulkreplacetool.com/img/og-cover.png">
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[
  {"@type":"ListItem","position":1,"name":"Home","item":"https://bulkreplacetool.com/"},
  {"@type":"ListItem","position":2,"name":"Privacy Policy","item":"https://bulkreplacetool.com/landing/privacy.php"}
]}
</script>
<style>
.legal-wrap{max-width:760px;margin:0 auto;padding:60px 24px;}
.legal-wrap h1{font-family:'Syne',sans-serif;font-size:32px;font-weight:800;color:#fff;margin-bottom:8px;}
.legal-meta{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:40px;}
.legal-wrap h2{font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:var(--a1);margin:32px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border);}
.legal-wrap p,.legal-wrap li{font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.9;color:var(--text);margin-bottom:10px;}
.legal-wrap ul{padding-left:20px;margin-bottom:10px;}
</style></head><body>
<div id="toast-wrap"></div>

<!-- Navigation -->
<nav><div class="nav-inner">
  <a href="/" class="nav-logo">
    <img src="/img/logo.png" alt="BulkReplace" class="nav-logo-img">
    <span class="nav-logo-text">BulkReplace</span>
  </a>
  <div class="nav-links">
    <a href="/"><?= t('nav_home') ?></a>
    <a href="/landing/tutorial.php"><?= t('nav_tutorial') ?></a>
    <a href="/landing/pricing.php"><?= t('nav_pricing') ?></a>
    <a href="/landing/terms.php"><?= t('nav_terms') ?></a>
    <a href="/landing/privacy.php" style="color:var(--a1);">Privacy</a>
  </div>
  <div class="nav-cta">
    <div style="display:inline-flex;gap:6px;margin-right:12px;background:var(--dim);border:1px solid var(--border);border-radius:8px;padding:4px;">
      <a href="/lang/switch.php?lang=en&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" style="padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;text-decoration:none;transition:all .2s;<?= $lang==='en'?'background:var(--a1);color:#000;font-weight:700;':'color:var(--muted);' ?>">EN</a>
      <a href="/lang/switch.php?lang=id&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" style="padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;text-decoration:none;transition:all .2s;<?= $lang==='id'?'background:var(--a1);color:#000;font-weight:700;':'color:var(--muted);' ?>">ID</a>
    </div>
    <a href="/auth/login.php" class="btn btn-ghost btn-sm"><?= t('nav_signin') ?></a>
    <a href="/auth/register.php" class="btn btn-amber btn-sm"><?= t('nav_register') ?></a>
  </div>
</div></nav>

<div class="legal-wrap z1">
  <h1>Privacy Policy</h1>
  <p class="legal-meta">Last updated: <?= date('F d, Y') ?> &nbsp;·&nbsp; BulkReplace (bulkreplacetool.com)</p>

  <h2>1. Overview</h2>
  <p>BulkReplace ("we", "our", "us") is committed to protecting your privacy. This policy explains what data we collect, how we use it, and your rights regarding that data.</p>

  <h2>2. Data We Collect</h2>
  <ul>
    <li><strong>Account data:</strong> Email address and hashed password when you register.</li>
    <li><strong>Usage data:</strong> Number of CSV rows processed per month, tool type, and timestamp — used solely for quota enforcement.</li>
    <li><strong>Payment data:</strong> Billing is handled entirely by LemonSqueezy. We do not store credit card numbers or payment details.</li>
    <li><strong>File data:</strong> Files processed by the Copy &amp; Rename and BulkReplace tools are handled entirely in your browser via the File System Access API. They are never uploaded to our servers.</li>
    <li><strong>CSV Generator data:</strong> Domain names you submit are sent to OpenAI (for parsing) and Google Places API (for location data). See their respective privacy policies.</li>
  </ul>

  <h2>3. How We Use Your Data</h2>
  <ul>
    <li>To authenticate your account and maintain your session.</li>
    <li>To enforce monthly CSV row quotas per your plan.</li>
    <li>To process payments via LemonSqueezy.</li>
    <li>To provide support via Telegram when you contact us.</li>
  </ul>

  <h2>4. Third-Party Services</h2>
  <ul>
    <li><strong>LemonSqueezy</strong> — payment processing. <a href="https://www.lemonsqueezy.com/privacy" target="_blank" style="color:var(--a2);">Privacy Policy</a></li>
    <li><strong>Google Places API</strong> — location data for CSV Generator. <a href="https://policies.google.com/privacy" target="_blank" style="color:var(--a2);">Privacy Policy</a></li>
    <li><strong>OpenAI</strong> — domain name parsing for CSV Generator. <a href="https://openai.com/policies/privacy-policy" target="_blank" style="color:var(--a2);">Privacy Policy</a></li>
  </ul>

  <h2>5. Data Retention</h2>
  <p>Account data is retained as long as your account is active. Usage logs older than 12 months are purged automatically. You may request account deletion at any time by contacting us via Telegram.</p>

  <h2>6. Cookies</h2>
  <p>We use a single session cookie (<?= SESSION_NAME ?>) to maintain your login state. No tracking or advertising cookies are used.</p>

  <h2>7. Your Rights</h2>
  <p>You have the right to access, correct, or delete your personal data at any time. Contact us via <a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank" style="color:var(--a2);">Telegram</a> to make a request.</p>

  <h2>8. Security</h2>
  <p>Passwords are hashed using bcrypt. All traffic is encrypted via HTTPS. We do not sell or share your personal data with third parties except as described in this policy.</p>

  <h2>9. Contact</h2>
  <p>For privacy-related questions, contact us at <a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank" style="color:var(--a2);"><?= SUPPORT_TELEGRAM ?></a> on Telegram.</p>
</div>

<footer><div class="footer-grid">
  <div class="footer-brand">
    <a href="/" class="nav-logo"><img src="/img/logo.png" alt="BulkReplace" class="nav-logo-img"><span class="nav-logo-text" style="margin-left:10px;">BulkReplace</span></a>
    <p>Bulk content replacement tool for agencies.</p>
  </div>
  <div class="footer-col"><h4>Product</h4><a href="/">Home</a><a href="/landing/tutorial.php">Tutorial</a><a href="/landing/pricing.php">Pricing</a></div>
  <div class="footer-col"><h4>Support</h4><a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank">Telegram</a><a href="/landing/terms.php">Terms</a><a href="/landing/privacy.php">Privacy</a></div>
  <div class="footer-col"><h4>Account</h4><a href="/auth/login.php">Sign In</a><a href="/auth/register.php">Register</a></div>
</div><div class="footer-bottom"><span>© <?= date('Y') ?> BulkReplace</span></div></footer>
</body></html>
