<?php
require_once __DIR__.'/config.php';
http_response_code(404);
?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>404 — Page Not Found | BulkReplace</title>
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.err-wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:40px 24px;position:relative;z-index:1;}
.err-code{font-family:'JetBrains Mono',monospace;font-size:96px;font-weight:700;background:linear-gradient(135deg,#f0a500,#00d4aa);-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1;margin-bottom:16px;}
.err-title{font-family:'Syne',sans-serif;font-size:24px;font-weight:700;color:#fff;margin-bottom:8px;}
.err-sub{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);margin-bottom:32px;max-width:360px;}
</style></head><body>
<div class="err-wrap">
  <div class="err-code">404</div>
  <div class="err-title">Page not found</div>
  <p class="err-sub">The page you're looking for doesn't exist or has been moved.</p>
  <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">
    <a href="/" class="btn btn-amber">← Back to Home</a>
    <a href="/dashboard/" class="btn btn-ghost">Dashboard</a>
  </div>
</div>
</body></html>
