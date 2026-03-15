<?php
require_once dirname(__DIR__).'/config.php';
startSession();
requireLogin();
try {
    $user=currentUser();
    if(!$user){
        session_destroy();
        header('Location: /auth/login.php?error=session_expired');
        exit;
    }
    $plan=getPlan($user['plan']);
    $quota=getUserQuota($user['id']);

    // Get active promo code from database
    $activePromo = null;
    try {
        $promoStmt = db()->prepare("SELECT code, discount_percent, valid_until FROM promo_codes WHERE is_active=1 AND UNIX_TIMESTAMP(valid_from) <= UNIX_TIMESTAMP() AND UNIX_TIMESTAMP(valid_until) >= UNIX_TIMESTAMP() ORDER BY created_at DESC LIMIT 1");
        $promoStmt->execute();
        $activePromo = $promoStmt->fetch();
    } catch(Exception $pe) {
        error_log('Promo fetch error: '.$pe->getMessage());
    }
} catch(Exception $e) {
    error_log('Billing Error: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!DOCTYPE html><html><head><title>Error</title><style>body{font-family:monospace;padding:40px;background:#0a0a0a;color:#999;}h1{color:#ff4560;}.error-box{background:#1a1a1a;padding:20px;border-radius:8px;border-left:4px solid #ff4560;margin:20px 0;}</style></head><body>';
    echo '<h1>Billing Page Error</h1>';
    echo '<div class="error-box"><p>An error occurred while loading your billing information.</p></div>';
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="/dashboard/" style="color:#00d4aa;">Back to Dashboard</a> or <a href="'.SUPPORT_TELEGRAM_URL.'" style="color:#00d4aa;">Contact Support</a></p>';
    echo '</body></html>';
    exit;
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Billing — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css"></head><body>
<div class="dash-layout">
<?php include __DIR__.'/_sidebar.php'; ?>
<div class="dash-main">
  <div class="dash-topbar"><div class="dash-page-title">💳 Billing & Plan</div></div>
  <div class="dash-content">

    <!-- HOW IT WORKS INFO (for free users) -->
    <?php if($user['plan'] === 'free'): ?>
    <div class="card" style="margin-bottom:24px;background:linear-gradient(135deg,rgba(0,212,170,.05),rgba(0,212,170,.02));border:2px solid rgba(0,212,170,.2);">
      <div style="flex:1;">
        <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:var(--a2);margin-bottom:12px;">How to Activate Your Plan</div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:16px;">
          <div style="background:var(--dim);border-radius:8px;padding:16px;border-left:3px solid var(--a2);">
            <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:8px;">Step 1 — Buy on Gumroad</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.8;">
              Purchase any plan (Pro, Platinum, Lifetime) or add-on.<br>
              After payment, Gumroad sends your <strong style="color:var(--text);">license key</strong> by email.
            </div>
          </div>

          <div style="background:var(--dim);border-radius:8px;padding:16px;border-left:3px solid var(--a1);">
            <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:8px;">Step 2 — Enter License Key Below</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.8;">
              Copy the license key from your Gumroad email (format: <code style="color:var(--a1);">XXXX-XXXX-XXXX-XXXX</code>).<br>
              Paste it in the "Activate License Key" box below and click Activate.
            </div>
          </div>
        </div>

        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;">
          License key applies to: Pro, Platinum, Lifetime, and all add-ons
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- PROMO BANNER (Dynamic from database) -->
    <?php if($activePromo && $user['plan'] !== 'lifetime'): ?>
    <div class="info-box" style="background:linear-gradient(135deg,rgba(251,191,36,.08),rgba(251,146,60,.06));border-color:rgba(251,191,36,.2);margin-bottom:24px;position:relative;">
      <div style="position:absolute;top:-8px;right:16px;background:var(--a1);color:#000;padding:4px 12px;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;letter-spacing:1px;border-radius:6px;">PROMO</div>
      <div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:700;color:var(--a1);margin-bottom:8px;">Get <?= (int)$activePromo['discount_percent'] ?>% OFF with code <span style="background:var(--a1);color:#000;padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:14px;margin:0 4px;"><?= htmlspecialchars($activePromo['code']) ?></span></div>
      <p style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin:0;">Valid until <?= $activePromo['valid_until'] ? date('F j, Y', strtotime($activePromo['valid_until'])) : 'further notice' ?> - Apply at checkout when upgrading your plan!</p>
    </div>
    <?php endif; ?>

    <!-- CURRENT PLAN -->
    <div class="card" style="margin-bottom:24px;">
      <div class="card-title">Current Plan</div>
      <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="flex:1;">
          <div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:#fff;"><?= ucfirst($user['plan']) ?> <span class="plan-pill pp-<?= $user['plan'] ?>"><?= $user['billing_cycle']??'none' ?></span></div>
          <?php if($user['plan']!=='free'&&$user['plan_expires_at']): ?>
          <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-top:8px;">
            <?= ($user['billing_cycle']==='lifetime') ? 'Lifetime access — never expires' : 'Renews / Expires: '.date('F j, Y',strtotime($user['plan_expires_at'])) ?>
          </div>
          <?php endif; ?>
          <?php if($user['plan']==='free'): ?>
          <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-top:8px;">Free plan — <?= $quota['remaining'] ?> of 20 rows remaining (one-time allocation)</div>
          <?php endif; ?>
        </div>
        <?php if($user['plan']!=='lifetime'): ?>
        <a href="/landing/pricing.php" class="btn btn-amber">⚡ Upgrade Plan</a>
        <?php else: ?>
        <div class="badge badge-ok">♾️ Lifetime Member</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- QUOTA DETAILS -->
    <div class="card" style="margin-bottom:24px;">
      <div class="card-title">📊 Quota Details</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
        <div style="background:var(--dim);border-radius:12px;padding:16px;text-align:center;">
          <div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--a1);"><?= $quota['unlimited']?'∞':number_format($quota['limit']) ?></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-top:4px;">Monthly Limit</div>
        </div>
        <div style="background:var(--dim);border-radius:12px;padding:16px;text-align:center;">
          <div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--ok);"><?= number_format($quota['rollover']) ?></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-top:4px;">Rollover Balance</div>
        </div>
        <div style="background:var(--dim);border-radius:12px;padding:16px;text-align:center;">
          <div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--a2);"><?= $quota['unlimited']?'∞':number_format($quota['remaining']) ?></div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-top:4px;">Total Available</div>
        </div>
      </div>
      <?php if(isset($plan['rollover']) && $plan['rollover']): ?>
      <div class="info-box" style="margin-top:16px;margin-bottom:0;">🔄 Your unused quota rolls over every month. Rollover accumulates up to 3x your monthly limit.</div>
      <?php endif; ?>
    </div>

    <!-- ACTIVE ADD-ONS (for premium users) -->
    <?php
    $activeAddons = [];
    if($user['plan'] !== 'free') {
      try {
        $addonStmt = db()->prepare("
          SELECT DISTINCT addon_slug, purchased_at, gumroad_sale_id
          FROM user_addons
          WHERE user_id=? AND addon_slug IS NOT NULL
          ORDER BY purchased_at DESC
        ");
        $addonStmt->execute([$user['id']]);
        $activeAddons = $addonStmt->fetchAll();
      } catch(Exception $e) {
        error_log('Addon fetch error: '.$e->getMessage());
      }
    }

    if(!empty($activeAddons)):
    ?>
    <div class="card" style="margin-bottom:24px;">
      <div class="card-title">🎁 Active Add-ons</div>
      <div style="display:grid;gap:12px;">
        <?php
        $addonNames = [
          'csv-generator-pro' => 'CSV Generator Pro',
          'zip-manager' => 'ZIP Manager',
          'copy-rename' => 'Copy & Rename',
          'autopilot' => 'AI Autopilot',
          'premium-bundle' => 'Premium Bundle'
        ];
        foreach($activeAddons as $addon):
          $displayName = $addonNames[$addon['addon_slug']] ?? ucwords(str_replace('-', ' ', $addon['addon_slug']));
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:var(--dim);border-radius:8px;border-left:3px solid var(--a2);">
          <div>
            <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:var(--text);">✨ <?= htmlspecialchars($displayName) ?></div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:4px;">
              Activated: <?= date('M j, Y', strtotime($addon['purchased_at'])) ?>
            </div>
          </div>
          <div class="badge badge-ok">Active</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- SUBSCRIPTION PLANS INFO (for free users) -->
    <?php if($user['plan'] === 'free'): ?>
    <div class="card" style="margin-bottom:24px;">
      <div class="card-title">Choose a Plan</div>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:16px;">
        <div style="background:var(--dim);border-radius:12px;padding:20px;border:2px solid var(--border);">
          <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:var(--a2);margin-bottom:8px;">Pro Plan</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--muted);margin-bottom:12px;">Perfect for growing teams</div>
          <div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:#fff;margin-bottom:16px;">$<?= PLAN_DATA['pro']['pm'] ?><span style="font-size:14px;color:var(--muted);">/mo</span></div>
          <a href="https://bulkreplacetool.gumroad.com/l/<?= PLAN_DATA['pro']['gumroad_monthly'] ?>" target="_blank" class="btn btn-amber" style="width:100%;text-align:center;display:block;">Buy on Gumroad</a>
        </div>

        <div style="background:var(--dim);border-radius:12px;padding:20px;border:2px solid var(--a1);">
          <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:var(--a1);margin-bottom:8px;">Platinum Plan</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--muted);margin-bottom:12px;">Unlimited power</div>
          <div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:#fff;margin-bottom:16px;">$<?= PLAN_DATA['platinum']['pm'] ?><span style="font-size:14px;color:var(--muted);">/mo</span></div>
          <a href="https://bulkreplacetool.gumroad.com/l/<?= PLAN_DATA['platinum']['gumroad_monthly'] ?>" target="_blank" class="btn btn-amber" style="width:100%;text-align:center;display:block;">Buy on Gumroad</a>
        </div>
      </div>

      <div style="background:rgba(0,212,170,.05);border:1px dashed rgba(0,212,170,.3);border-radius:8px;padding:16px;margin-top:4px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.9;">
          <strong style="color:var(--text);">After purchasing on Gumroad:</strong><br>
          1. Check your email for the license key from Gumroad<br>
          2. Copy the license key (format: <code style="color:var(--a2);">XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX</code>)<br>
          3. Paste it in the "Activate License Key" section below and click Activate
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ACTIVATE LICENSE -->
    <div class="card">
      <div class="card-title">Activate License Key</div>

      <p style="font-size:14px;color:var(--muted);margin-bottom:20px;line-height:1.6;">
        Enter your Gumroad license key to activate your plan or add-on. The key is in your Gumroad purchase confirmation email.
      </p>

      <div id="activationMessage"></div>

      <form id="activateLicenseForm" style="display:flex;gap:12px;flex-wrap:wrap;align-items:stretch;">
        <input type="text" id="licenseKey" name="license_key"
          placeholder="Paste Gumroad license key: XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX"
          required autocomplete="off" spellcheck="false"
          style="flex:1;min-width:280px;background:var(--dim);border:1px solid var(--border);border-radius:8px;padding:14px 16px;color:var(--text);font-family:'JetBrains Mono',monospace;font-size:12px;letter-spacing:1px;">
        <button type="submit" class="btn btn-amber" id="activateBtn" style="min-width:140px;">
          <span id="activateBtnText">Activate</span>
        </button>
      </form>

      <div style="margin-top:16px;padding:14px 18px;background:var(--dim);border-radius:8px;border-left:3px solid var(--a2);">
        <div style="font-size:12px;color:var(--muted);line-height:1.9;">
          <strong style="color:var(--text);display:block;margin-bottom:6px;">Where to find your license key?</strong>
          After purchasing on Gumroad, check your email for the Gumroad receipt.<br>
          The license key is in the email, format: <code style="color:var(--a2);background:var(--bg);padding:2px 8px;border-radius:4px;font-size:10px;">XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX</code><br><br>
          <strong style="color:var(--text);">Also accepted:</strong> System key sent by us via email (format: <code style="color:var(--a1);background:var(--bg);padding:2px 8px;border-radius:4px;font-size:10px;">PRO-M-XXXXXXXX-XXXX</code>)
        </div>
      </div>

      <!-- Show user's licenses -->
      <div id="userLicenses" style="margin-top:24px;"></div>

      <p style="font-size:12px;color:var(--muted);margin-top:16px;">
        Can't find your key? <a href="<?= htmlspecialchars(SUPPORT_TELEGRAM_URL) ?>" target="_blank" style="color:var(--a2);text-decoration:none;font-weight:600;">Contact Support</a>
      </p>
    </div>

    <script>
    async function loadUserLicenses() {
      try {
        const res = await fetch('/api/verify_license.php?t=' + Date.now(), {
          method: 'GET', credentials: 'include'
        });
        const result = await res.json();
        if (!result.success || !result.licenses || result.licenses.length === 0) return;

        const licensesDiv = document.getElementById('userLicenses');
        let html = '<div style="border-top:1px solid var(--border);padding-top:24px;margin-top:24px;">';
        html += '<div style="font-family:\'Syne\',sans-serif;font-size:16px;font-weight:700;color:var(--text);margin-bottom:16px;">Your Licenses</div>';
        html += '<div style="display:grid;gap:12px;">';

        result.licenses.forEach(license => {
          const isActive = license.status === 'active';
          const statusColor = isActive ? 'var(--ok)' : license.status === 'revoked' ? 'var(--err)' : 'var(--muted)';
          const statusIcon  = isActive ? '✅' : license.status === 'revoked' ? '🚫' : '⏳';
          const keyDisplay  = license.license_key || '—';
          const createdDate = new Date(license.created_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
          const expiryText  = license.expires_at
            ? 'Expires: ' + new Date(license.expires_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'})
            : 'Lifetime Access';

          html += `
            <div style="background:var(--dim);border-radius:10px;padding:16px 20px;border-left:3px solid ${statusColor};">
              <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:12px;">
                <div style="flex:1;min-width:220px;">
                  <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:var(--text);margin-bottom:8px;">
                    ${statusIcon} ${license.product_name || 'License'}
                  </div>
                  <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:${statusColor};background:var(--bg);padding:7px 12px;border-radius:6px;display:inline-block;margin-bottom:8px;word-break:break-all;">
                    ${keyDisplay}
                  </div>
                  <div style="font-size:11px;color:var(--muted);">
                    ${isActive ? 'Activated' : 'Purchased'}: ${createdDate} &nbsp;•&nbsp; ${expiryText}
                  </div>
                </div>
                <div style="padding:5px 10px;background:${isActive?'rgba(0,212,170,0.12)':'var(--bg)'};border:1px solid ${statusColor};border-radius:6px;font-size:10px;font-weight:700;color:${statusColor};text-transform:uppercase;white-space:nowrap;">
                  ${license.status}
                </div>
              </div>
            </div>`;
        });

        html += '</div></div>';
        licensesDiv.innerHTML = html;
      } catch (err) {
        console.error('Failed to load licenses:', err);
      }
    }

    document.getElementById('activateLicenseForm').addEventListener('submit', async (e) => {
      e.preventDefault();

      const btn        = document.getElementById('activateBtn');
      const btnText    = document.getElementById('activateBtnText');
      const msgDiv     = document.getElementById('activationMessage');
      const licenseKey = document.getElementById('licenseKey').value.trim();

      if (!licenseKey) {
        msgDiv.innerHTML = '<div class="err-box">Please enter a license key.</div>';
        return;
      }

      btn.disabled   = true;
      btnText.textContent = 'Activating...';
      msgDiv.innerHTML    = '';

      try {
        const res = await fetch('/api/activate_license.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({ license_key: licenseKey })
        });

        const result = await res.json();

        if (result.success) {
          let sysKeyBox = '';
          if (result.system_license_key) {
            sysKeyBox = `
              <div style="margin-top:12px;padding:12px 16px;background:rgba(251,191,36,.06);border:1px solid rgba(251,191,36,.25);border-radius:8px;">
                <div style="font-size:10px;color:var(--a1);font-weight:700;margin-bottom:6px;text-transform:uppercase;letter-spacing:1px;">Your System License Key — Save This!</div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#fff;background:var(--dim);padding:10px 12px;border-radius:6px;word-break:break-all;border:1px solid var(--border);">${result.system_license_key}</div>
                <div style="font-size:10px;color:var(--muted);margin-top:6px;">Both your Gumroad key and this system key will work for future activations.</div>
              </div>`;
          }
          msgDiv.innerHTML = `
            <div class="info-box" style="background:rgba(0,212,170,.07);border-color:rgba(0,212,170,.3);">
              License activated! <strong>${result.product_name || ''}</strong> plan is now active.
              ${sysKeyBox}
            </div>`;
          setTimeout(() => location.reload(), 3000);
        } else {
          msgDiv.innerHTML = `<div class="err-box">${result.error || 'Activation failed. Please check your key and try again.'}</div>`;
          btn.disabled = false;
          btnText.textContent = 'Activate';
        }
      } catch (err) {
        msgDiv.innerHTML = '<div class="err-box">Network error. Check your connection and try again.</div>';
        btn.disabled = false;
        btnText.textContent = 'Activate';
      }
    });

    loadUserLicenses();
    </script>
  </div>
</div>
</div>
<script src="https://gumroad.com/js/gumroad.js"></script>
</body></html>
