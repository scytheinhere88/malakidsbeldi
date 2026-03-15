<?php require_once dirname(__DIR__).'/config.php';
$lang = getLang();
$plans = PLAN_DATA;

// Get active promo code from database
$activePromo = null;
try {
    $promoStmt = db()->prepare("SELECT code, discount_percent, valid_until FROM promo_codes WHERE is_active=1 AND UNIX_TIMESTAMP(valid_from) <= UNIX_TIMESTAMP() AND UNIX_TIMESTAMP(valid_until) >= UNIX_TIMESTAMP() ORDER BY created_at DESC LIMIT 1");
    $promoStmt->execute();
    $activePromo = $promoStmt->fetch();
} catch(Exception $e) {
    error_log('Promo fetch error: '.$e->getMessage());
}
?>
<!DOCTYPE html><html lang="<?= $lang ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Pricing - BulkReplace Plans | Free, Pro, Platinum, Lifetime</title>
<meta name="description" content="BulkReplace pricing: Free (20 rows), Pro $19.9/mo (500 rows), Platinum $69.9/mo (1500 rows + all add-ons), Lifetime $469.9 (unlimited forever + all add-ons).">
<link rel="canonical" href="<?= APP_URL ?>/landing/pricing.php">
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<meta property="og:title" content="BulkReplace Pricing Plans">
<meta property="og:description" content="Choose from Free, Pro, Platinum, or Lifetime. Quota rollover included.">
<meta property="og:url" content="<?= APP_URL ?>/landing/pricing.php">
<meta property="og:type" content="website">
<meta property="og:image" content="https://bulkreplacetool.com/img/og-cover.png">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="BulkReplace Pricing Plans">
<meta name="twitter:description" content="Free, Pro $19.9/mo, Platinum $69.9/mo (all add-ons included), Lifetime $469.9 one-time.">
<meta name="twitter:image" content="https://bulkreplacetool.com/img/og-cover.png">
<meta name="robots" content="index, follow">

<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Product","name":"BulkReplace","description":"Bulk content replacement tool for agencies","brand":{"@type":"Brand","name":"BulkReplace"},"offers":[
  {"@type":"Offer","name":"Free Plan","price":"0","priceCurrency":"USD","availability":"https://schema.org/InStock","description":"20 CSV rows one-time allocation"},
  {"@type":"Offer","name":"Pro Monthly","price":"19.9","priceCurrency":"USD","availability":"https://schema.org/InStock","priceSpecification":{"@type":"UnitPriceSpecification","price":"19.9","priceCurrency":"USD","billingDuration":"P1M"},"description":"500 CSV rows per month with rollover"},
  {"@type":"Offer","name":"Platinum Monthly","price":"69.9","priceCurrency":"USD","availability":"https://schema.org/InStock","priceSpecification":{"@type":"UnitPriceSpecification","price":"69.9","priceCurrency":"USD","billingDuration":"P1M"},"description":"1500 CSV rows per month + all add-ons included"},
  {"@type":"Offer","name":"Lifetime","price":"469.9","priceCurrency":"USD","availability":"https://schema.org/InStock","description":"Unlimited CSV rows forever + all add-ons, one-time payment"}
]}
</script>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[
  {"@type":"ListItem","position":1,"name":"Home","item":"https://bulkreplacetool.com/"},
  {"@type":"ListItem","position":2,"name":"Pricing","item":"https://bulkreplacetool.com/landing/pricing.php"}
]}
</script>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[
  {"@type":"Question","name":"Does BulkReplace offer a free plan?","acceptedAnswer":{"@type":"Answer","text":"Yes. The free plan includes 20 CSV rows one-time. No credit card required."}},
  {"@type":"Question","name":"What add-ons are included with Platinum and Lifetime?","acceptedAnswer":{"@type":"Answer","text":"Platinum and Lifetime plans include CSV Generator, ZIP Manager, and Copy & Rename add-ons — all three at no extra cost."}},
  {"@type":"Question","name":"What happens to unused quota?","acceptedAnswer":{"@type":"Answer","text":"Unused rows roll over every month on Pro, Platinum, and Lifetime plans."}},
  {"@type":"Question","name":"Is there a lifetime deal?","acceptedAnswer":{"@type":"Answer","text":"Yes. The Lifetime plan is a one-time payment of $469.9 for unlimited CSV rows forever, including all add-ons and future updates."}}
]}
</script>

<style>
/* ── Pricing page extras ───────────────────────────────────── */
.pricing-hero{text-align:center;padding:24px 0 8px;}
.pricing-hero h1{font-family:'Syne',sans-serif;font-size:clamp(32px,5vw,52px);font-weight:900;color:#fff;line-height:1.1;margin-bottom:12px;}
.pricing-hero h1 span{background:linear-gradient(135deg,#f0a500,#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.pricing-hero p{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);max-width:480px;margin:0 auto 28px;}

/* Promo banner */
.promo-banner{background:linear-gradient(135deg,rgba(251,191,36,.1),rgba(251,146,60,.07));border:1px solid rgba(251,191,36,.25);border-radius:14px;padding:14px 24px;max-width:580px;margin:0 auto 36px;position:relative;overflow:hidden;display:flex;align-items:center;gap:14px;flex-wrap:wrap;justify-content:center;}
.promo-banner::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,var(--a1),transparent);}
.promo-tag{background:var(--a1);color:#000;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;letter-spacing:1.5px;padding:3px 10px;border-radius:5px;}
.promo-code{background:rgba(0,0,0,.4);border:1px dashed rgba(240,165,0,.4);border-radius:7px;padding:5px 14px;font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--a1);font-weight:700;letter-spacing:2px;}

/* Billing toggle */
.billing-wrap{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:40px;}
.billing-label{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);cursor:pointer;transition:color .2s;}
.billing-label.act{color:#fff;font-weight:700;}
.billing-sw{width:48px;height:26px;background:var(--dim);border:1px solid var(--border);border-radius:100px;cursor:pointer;position:relative;transition:background .2s;}
.billing-sw.on{background:var(--a1);}
.billing-knob{width:18px;height:18px;background:#fff;border-radius:50%;position:absolute;top:3px;left:4px;transition:left .2s;}
.billing-sw.on .billing-knob{left:26px;background:#000;}
.save-chip{background:rgba(0,230,118,.1);border:1px solid rgba(0,230,118,.25);color:var(--ok);font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;padding:3px 9px;border-radius:100px;letter-spacing:.5px;}

/* Plan grid */
.plan-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;max-width:1160px;margin:0 auto 64px;}
@media(max-width:1024px){.plan-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:560px){.plan-grid{grid-template-columns:1fr;}}

/* ── Plan card ────────────────────────────────────────── */
.pcard{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:20px;
  padding:0;
  position:relative;
  display:flex;
  flex-direction:column;
  transition:transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s;
  overflow:hidden;
}
.pcard:hover{transform:translateY(-6px);}

/* Accent top strip */
.pcard .pc-strip{height:3px;width:100%;display:block;border-radius:20px 20px 0 0;}
.pcard .pc-body{padding:26px 24px 24px;display:flex;flex-direction:column;flex:1;}

.pcard.amber{border-color:rgba(240,165,0,.35);box-shadow:0 2px 32px rgba(240,165,0,.07);}
.pcard.amber:hover{box-shadow:0 16px 48px rgba(240,165,0,.15);}
.pcard.amber .pc-strip{background:linear-gradient(90deg,#f0a500,#fbbf24,#f0a500);}

.pcard.teal{border-color:rgba(0,212,170,.35);box-shadow:0 2px 32px rgba(0,212,170,.07);}
.pcard.teal:hover{box-shadow:0 16px 48px rgba(0,212,170,.15);}
.pcard.teal .pc-strip{background:linear-gradient(90deg,#00d4aa,#22d3ee,#00d4aa);}

.pcard.purple{border-color:rgba(192,132,252,.35);box-shadow:0 2px 32px rgba(192,132,252,.1);}
.pcard.purple:hover{box-shadow:0 16px 48px rgba(192,132,252,.2);}
.pcard.purple .pc-strip{background:linear-gradient(90deg,#c084fc,#7c3aed,#c084fc);}

/* Badge */
.plan-badge{
  display:inline-flex;align-items:center;gap:5px;
  font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;
  letter-spacing:1.5px;padding:4px 12px;border-radius:100px;
  margin-bottom:16px;text-transform:uppercase;width:fit-content;
}
.badge-amber{background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.3);color:var(--a1);}
.badge-teal{background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.3);color:var(--a2);}
.badge-purple{background:rgba(192,132,252,.1);border:1px solid rgba(192,132,252,.3);color:#c084fc;}
.badge-grey{background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--muted);}

/* Plan name */
.plan-name{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:18px;letter-spacing:-.3px;}

/* ── Price block — clean & professional ── */
.price-block{
  display:flex;align-items:stretch;gap:0;
  margin-bottom:6px;
  border-radius:12px;
  overflow:hidden;
  border:1px solid rgba(255,255,255,.06);
  background:rgba(0,0,0,.18);
}
.price-left{
  padding:14px 16px 14px 18px;
  display:flex;flex-direction:column;justify-content:center;flex:1;
}
.price-sup{font-family:'JetBrains Mono',monospace;font-size:13px;font-weight:700;color:var(--muted);line-height:1;margin-bottom:2px;}
.price-main{font-family:'Syne',sans-serif;font-size:40px;font-weight:900;line-height:1;letter-spacing:-1.5px;color:#fff;}
.price-right{
  padding:10px 14px;
  display:flex;flex-direction:column;justify-content:center;align-items:flex-start;
  border-left:1px solid rgba(255,255,255,.06);
  min-width:68px;
  gap:4px;
}
.price-period{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.3;white-space:nowrap;}
.price-was{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);text-decoration:line-through;white-space:nowrap;}
.price-save{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--ok);font-weight:700;letter-spacing:.3px;white-space:nowrap;}

/* Lifetime special — one-time badge in price block */
.price-onetime{
  background:rgba(192,132,252,.12);
  border:1px solid rgba(192,132,252,.25);
  border-radius:6px;
  padding:3px 8px;
  font-family:'JetBrains Mono',monospace;
  font-size:9px;font-weight:700;
  color:#c084fc;letter-spacing:.5px;
  text-transform:uppercase;white-space:nowrap;
}

/* Divider */
.pc-divider{height:1px;background:rgba(255,255,255,.05);margin:16px 0;}

/* Desc */
.plan-desc{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.7;margin-bottom:14px;}

/* Rollover pill */
.rollover-pill{
  display:inline-flex;align-items:center;gap:5px;
  font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;
  letter-spacing:.5px;padding:4px 11px;border-radius:100px;margin-bottom:16px;
  background:rgba(0,230,118,.06);border:1px solid rgba(0,230,118,.2);color:var(--ok);
  width:fit-content;
}

/* Addon included box */
.addon-box{border-radius:10px;padding:12px 14px;margin:12px 0 18px;}
.addon-box-teal{background:linear-gradient(135deg,rgba(0,212,170,.07),rgba(34,211,238,.04));border:1px solid rgba(0,212,170,.2);}
.addon-box-purple{background:linear-gradient(135deg,rgba(192,132,252,.09),rgba(168,85,247,.05));border:1px solid rgba(192,132,252,.2);}
.addon-box-label{font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;letter-spacing:1.5px;margin-bottom:8px;text-transform:uppercase;}
.addon-pills{display:flex;flex-wrap:wrap;gap:5px;}
.addon-pill{font-family:'JetBrains Mono',monospace;font-size:10px;padding:3px 9px;border-radius:6px;font-weight:600;}
.ap-amber{background:rgba(240,165,0,.1);color:var(--a1);border:1px solid rgba(240,165,0,.2);}
.ap-teal{background:rgba(0,212,170,.1);color:var(--a2);border:1px solid rgba(0,212,170,.2);}
.ap-purple{background:rgba(192,132,252,.1);color:#c084fc;border:1px solid rgba(192,132,252,.2);}

/* Features list */
.plan-features{list-style:none;padding:0;margin:0 0 20px;flex:1;}
.plan-features li{display:flex;align-items:flex-start;gap:9px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);padding:6px 0;border-bottom:1px solid rgba(255,255,255,.04);}
.plan-features li:last-child{border-bottom:none;}
.plan-features .ck{color:var(--ok);font-size:13px;flex-shrink:0;margin-top:0;}
.plan-features .no{color:rgba(255,255,255,.15);font-size:13px;flex-shrink:0;margin-top:0;}
.plan-features .dim{color:var(--muted);}
.plan-features strong{color:#fff;}

/* CTA */
.plan-btn{
  width:100%;justify-content:center;margin-top:auto;
  padding:13px 20px;font-size:13px;font-weight:700;
  border-radius:10px;letter-spacing:.3px;
  transition:transform .15s,box-shadow .15s;
}
.plan-btn:hover{transform:translateY(-1px);}

/* ── Add-ons section ── */
.addons-section{max-width:1000px;margin:0 auto 64px;}
.addons-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:32px;}
@media(max-width:768px){.addons-grid{grid-template-columns:1fr;}}
.addon-card{background:var(--card);border-radius:16px;padding:26px;position:relative;overflow:hidden;display:flex;flex-direction:column;transition:transform .2s;}
.addon-card:hover{transform:translateY(-3px);}
.addon-card::before{content:'ADDON';position:absolute;top:0;right:0;padding:4px 12px;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;letter-spacing:1px;border-bottom-left-radius:8px;}
.ac-amber{border:1px solid rgba(240,165,0,.25);}
.ac-amber::before{background:var(--a1);color:#000;}
.ac-teal{border:1px solid rgba(0,212,170,.25);}
.ac-teal::before{background:var(--a2);color:#000;}
.ac-purple{border:1px solid rgba(192,132,252,.3);}
.ac-purple::before{background:#c084fc;color:#fff;}
.ac-icon{font-size:38px;margin-bottom:12px;}
.ac-name{font-family:'Syne',sans-serif;font-size:19px;font-weight:700;color:#fff;margin-bottom:6px;}
.ac-desc{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.7;margin-bottom:16px;flex:1;}
.ac-features{background:var(--dim);border-radius:8px;padding:10px 12px;margin-bottom:18px;}
.ac-features div{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);line-height:1.9;}
.ac-price{font-family:'Syne',sans-serif;font-size:30px;font-weight:800;margin-bottom:16px;}
.ac-price span{font-size:13px;color:var(--muted);font-weight:400;font-family:'JetBrains Mono',monospace;}

/* Bundle */
.bundle-card{background:var(--card);border:2px solid rgba(240,165,0,.3);border-radius:20px;padding:36px;text-align:center;position:relative;overflow:hidden;}
.bundle-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--a1),#c084fc,var(--a2));}
.bundle-hot{position:absolute;top:20px;right:-26px;background:linear-gradient(135deg,#ff4560,#c00);color:#fff;font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;padding:5px 36px;transform:rotate(35deg);letter-spacing:.5px;text-transform:uppercase;}
.bundle-title{font-family:'Syne',sans-serif;font-size:26px;font-weight:900;color:#fff;margin-bottom:8px;}
.bundle-items{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin:24px 0;}
.bundle-item{border-radius:10px;padding:12px 16px;text-align:center;min-width:110px;}
.bi-amber{background:rgba(240,165,0,.07);border:1px solid rgba(240,165,0,.2);}
.bi-teal{background:rgba(0,212,170,.07);border:1px solid rgba(0,212,170,.2);}
.bi-purple{background:rgba(192,132,252,.07);border:1px solid rgba(192,132,252,.2);}
.bundle-plus{display:flex;align-items:center;color:var(--muted);font-size:20px;font-weight:700;}
.bundle-pricing{display:flex;align-items:center;justify-content:center;gap:20px;flex-wrap:wrap;margin-bottom:24px;}
.bundle-was{font-family:'JetBrains Mono',monospace;font-size:15px;color:var(--muted);text-decoration:line-through;}
.bundle-price{font-family:'Syne',sans-serif;font-size:52px;font-weight:900;background:linear-gradient(135deg,var(--a1),#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1;}
.bundle-save{background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.2);color:var(--ok);font-family:'JetBrains Mono',monospace;font-size:11px;padding:6px 14px;border-radius:8px;font-weight:700;}

/* Rollover explainer */
.rollover-section{max-width:680px;margin:0 auto 56px;background:var(--card);border:1px solid rgba(0,230,118,.15);border-radius:16px;padding:32px;}

/* ── AUTOPILOT PRICING CARD (self-contained) ── */
.ap-pricing-card{background:linear-gradient(135deg,rgba(240,165,0,.06),rgba(192,132,252,.04));border:1px solid rgba(240,165,0,.28);border-radius:24px;padding:44px;margin:40px 0;position:relative;overflow:hidden;}
.ap-pricing-card::before{content:'';position:absolute;top:-60px;right:-60px;width:300px;height:300px;background:radial-gradient(circle,rgba(240,165,0,.08),transparent 70%);pointer-events:none;}
.ap-pc-badge{display:inline-flex;align-items:center;gap:8px;background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.3);border-radius:100px;padding:5px 16px;font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--a1);letter-spacing:2px;margin-bottom:20px;}
.ap-pc-title{font-family:'Syne',sans-serif;font-size:clamp(24px,3vw,36px);font-weight:900;color:#fff;margin-bottom:10px;letter-spacing:-1px;}
.ap-pc-sub{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);line-height:2;max-width:520px;margin-bottom:28px;}
.ap-pc-grid{display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-bottom:32px;}
@media(max-width:700px){.ap-pc-grid{grid-template-columns:1fr;}}
.ap-pc-features{list-style:none;padding:0;}
.ap-pc-features li{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text);padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04);display:flex;gap:8px;align-items:flex-start;}
.ap-pc-features li:last-child{border-bottom:none;}
.ap-pc-features li .ck{color:var(--ok);flex-shrink:0;}
.ap-pc-how-step{display:flex;gap:10px;align-items:flex-start;margin-bottom:12px;}
.ap-pc-snum{width:28px;height:28px;min-width:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:12px;font-weight:900;flex-shrink:0;}
.ap-pc-snum.amber{background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.3);color:var(--a1);}
.ap-pc-snum.ai{background:rgba(192,132,252,.1);border:1px solid rgba(192,132,252,.3);color:#c084fc;font-size:9px;}
.ap-pc-snum.teal{background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.3);color:var(--a2);}
.ap-pc-snum.ok{background:rgba(0,230,118,.1);border:1px solid rgba(0,230,118,.3);color:var(--ok);font-size:14px;}
.ap-pc-sbody{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.7;padding-top:4px;}
.ap-pc-sbody strong{color:#fff;}
.ap-cost-box{background:rgba(0,0,0,.3);border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-top:14px;}
</style>
</head><body>
<div id="toast-wrap"></div>
<nav><div class="nav-inner">
  <a href="/" class="nav-logo"><img src="/img/logo.png" alt="BulkReplace" class="nav-logo-img"><span class="nav-logo-text">BulkReplace</span></a>
  <div class="nav-links">
    <a href="/"><?= t('nav_home') ?></a>
    <a href="/landing/tutorial.php"><?= t('nav_tutorial') ?></a>
    <a href="/landing/pricing.php" style="color:var(--a1);">Pricing</a>
    <a href="/landing/terms.php"><?= t('nav_terms') ?></a>
    <a href="/landing/privacy.php">Privacy</a>
  </div>
  <div class="nav-cta">
    <div class="lang-switcher" style="display:inline-flex;gap:6px;margin-right:12px;background:var(--dim);border:1px solid var(--border);border-radius:8px;padding:4px;">
      <a href="/lang/switch.php?lang=en&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="lang-btn <?= $lang==='en'?'active':'' ?>" style="padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;text-decoration:none;color:var(--muted);transition:all .2s;<?= $lang==='en'?'background:var(--a1);color:#000;font-weight:700;':'' ?>">EN</a>
      <a href="/lang/switch.php?lang=id&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="lang-btn <?= $lang==='id'?'active':'' ?>" style="padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:10px;text-decoration:none;color:var(--muted);transition:all .2s;<?= $lang==='id'?'background:var(--a1);color:#000;font-weight:700;':'' ?>">ID</a>
    </div>
    <a href="/auth/login.php" class="btn btn-ghost btn-sm"><?= t('nav_signin') ?></a>
    <a href="/auth/register.php" class="btn btn-amber btn-sm"><?= t('nav_register') ?></a>
  </div>
</div></nav>

<div class="wrap">
<section class="section">

  <!-- Hero -->
  <div class="pricing-hero">
    <h1>Simple, <span>Transparent</span> Pricing</h1>
    <p><?= t('pricing_subtitle') ?></p>
  </div>

  <!-- Promo Banner (Dynamic from database) -->
  <?php if($activePromo): ?>
  <div class="promo-banner">
    <span class="promo-tag">LIMITED PROMO</span>
    <span style="font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:#fff;">Get <?= (int)$activePromo['discount_percent'] ?>% OFF with code</span>
    <span class="promo-code"><?= htmlspecialchars($activePromo['code']) ?></span>
    <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">Valid until <?= $activePromo['valid_until'] ? date('F j, Y', strtotime($activePromo['valid_until'])) : 'further notice' ?></span>
  </div>
  <?php endif; ?>

  <!-- Billing Toggle -->
  <div class="billing-wrap">
    <span class="billing-label act" id="lbl-monthly" onclick="setBilling('monthly')">Monthly</span>
    <div class="billing-sw" id="billing-toggle" onclick="toggleBilling()"><div class="billing-knob"></div></div>
    <span class="billing-label" id="lbl-annual" onclick="setBilling('annual')">Annual</span>
    <span class="save-chip">💰 SAVE 20%</span>
  </div>

  <!-- Plan Cards -->
  <div class="plan-grid">

    <!-- FREE -->
    <div class="pcard">
      <span class="pc-strip"></span>
      <div class="pc-body">
      <span class="plan-badge badge-grey">🆓 Free Forever</span>
      <div class="plan-name" style="color:var(--muted);">Free</div>
      <div class="price-block" style="border-color:rgba(255,255,255,.04);">
        <div class="price-left">
          <div class="price-sup">$</div>
          <div class="price-main" style="color:rgba(255,255,255,.35);">0</div>
        </div>
        <div class="price-right">
          <div class="price-period">forever</div>
          <div class="price-period">free</div>
        </div>
      </div>
      <div class="pc-divider"></div>
      <div class="plan-desc"><?= t('pricing_free_desc') ?></div>
      <ul class="plan-features">
        <li><span class="ck">✓</span>20 rows <span style="color:var(--muted);margin-left:4px;">(one-time)</span></li>
        <li><span class="ck">✓</span><?= t('pricing_feature_clientside') ?></li>
        <li><span class="ck">✓</span><?= t('pricing_feature_extensions') ?></li>
        <li><span class="ck">✓</span><?= t('pricing_feature_output') ?></li>
        <li><span class="no">—</span><span class="dim">No rollover</span></li>
        <li><span class="no">—</span><span class="dim">Community support</span></li>
        <li><span class="no">—</span><span class="dim">No add-ons</span></li>
      </ul>
      <a href="/auth/register.php" class="btn btn-ghost plan-btn"><?= t('pricing_btn_free') ?></a>
      </div><!-- /pc-body -->
    </div>

    <!-- PRO -->
    <div class="pcard amber">
      <span class="pc-strip"></span>
      <div class="pc-body">
      <span class="plan-badge badge-amber">⚡ Most Popular</span>
      <div class="plan-name" style="color:var(--a1);">Pro</div>
      <div class="price-block" style="border-color:rgba(240,165,0,.12);background:rgba(240,165,0,.04);">
        <div class="price-left">
          <div class="price-sup" style="color:var(--a1);">$</div>
          <div class="price-main" id="pro-price" style="color:var(--a1);">19.9</div>
        </div>
        <div class="price-right">
          <div class="price-period" id="pro-period">/mo</div>
          <div class="price-was" id="pro-orig" style="display:none;"></div>
          <div class="price-save" id="pro-save" style="display:none;">SAVE 20%</div>
        </div>
      </div>
      <div class="pc-divider"></div>
      <div class="plan-desc"><?= t('pricing_pro_desc') ?></div>
      <div class="rollover-pill">🔄 Quota Rollover</div>
      <ul class="plan-features">
        <li><span class="ck">✓</span><strong>500 rows</strong>/month</li>
        <li><span class="ck">✓</span><?= t('pricing_feature_rollover') ?></li>
        <li><span class="ck">✓</span><?= t('pricing_feature_extensions') ?></li>
        <li><span class="ck">✓</span><?= t('pricing_feature_output') ?></li>
        <li><span class="ck">✓</span><?= t('pricing_feature_support') ?></li>
        <li><span class="ck">✓</span><?= t('pricing_feature_analytics') ?></li>
        <li><span class="no">—</span><span class="dim">Add-ons sold separately</span></li>
      </ul>
      <a href="https://bulkreplacetool.gumroad.com/l/<?= $plans['pro']['gumroad_monthly'] ?>" id="pro-btn" class="btn btn-amber plan-btn gumroad-button" data-gumroad-single-product="true"><?= t('pricing_btn_pro') ?></a>
      </div><!-- /pc-body -->
    </div>

    <!-- PLATINUM -->
    <div class="pcard teal">
      <span class="pc-strip"></span>
      <div class="pc-body">
      <span class="plan-badge badge-teal">💎 Best Value</span>
      <div class="plan-name" style="color:var(--a2);">Platinum</div>
      <div class="price-block" style="border-color:rgba(0,212,170,.12);background:rgba(0,212,170,.04);">
        <div class="price-left">
          <div class="price-sup" style="color:var(--a2);">$</div>
          <div class="price-main" id="plat-price" style="color:var(--a2);">69.9</div>
        </div>
        <div class="price-right">
          <div class="price-period" id="plat-period">/mo</div>
          <div class="price-was" id="plat-orig" style="display:none;"></div>
          <div class="price-save" id="plat-save" style="display:none;">SAVE 20%</div>
        </div>
      </div>
      <div class="pc-divider"></div>
      <div class="plan-desc"><?= t('pricing_platinum_desc') ?></div>
      <div class="rollover-pill" style="background:rgba(0,212,170,.06);border-color:rgba(0,212,170,.2);color:var(--a2);">🔄 Quota Rollover</div>

      <!-- Addon box -->
      <div class="addon-box addon-box-teal">
        <div class="addon-box-label" style="color:var(--a2);">✦ All Add-ons Included</div>
        <div class="addon-pills">
          <span class="addon-pill ap-amber">📊 CSV Generator</span>
          <span class="addon-pill ap-teal">🗜️ ZIP Manager</span>
          <span class="addon-pill ap-purple">📋 Copy &amp; Rename</span>
        </div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:8px;">$59.7 value — included FREE</div>
      </div>

      <ul class="plan-features">
        <li><span class="ck">✓</span><strong>1,500 rows</strong>/month</li>
        <li><span class="ck">✓</span><?= t('pricing_feature_rollover') ?></li>
        <li><span class="ck">✓</span>CSV Generator add-on</li>
        <li><span class="ck">✓</span>ZIP Manager add-on</li>
        <li><span class="ck">✓</span>Copy &amp; Rename add-on</li>
        <li><span class="ck">✓</span><?= t('pricing_feature_support_telegram') ?></li>
        <li><span class="ck">✓</span><?= t('pricing_feature_analytics_advanced') ?></li>
      </ul>
      <a href="https://bulkreplacetool.gumroad.com/l/<?= $plans['platinum']['gumroad_monthly'] ?>" id="plat-btn" class="btn btn-teal plan-btn gumroad-button" data-gumroad-single-product="true"><?= t('pricing_btn_platinum') ?></a>
      </div><!-- /pc-body -->
    </div>

    <!-- LIFETIME -->
    <div class="pcard purple">
      <span class="pc-strip"></span>
      <div class="pc-body">
      <span class="plan-badge badge-purple">🚀 Pay Once, Own Forever</span>
      <div class="plan-name" style="color:#c084fc;">Lifetime</div>
      <div class="price-block" style="border-color:rgba(192,132,252,.15);background:rgba(192,132,252,.05);">
        <div class="price-left">
          <div class="price-sup" style="color:#c084fc;">$</div>
          <div class="price-main" style="background:linear-gradient(135deg,#c084fc,#a78bfa);-webkit-background-clip:text;-webkit-text-fill-color:transparent;">469.9</div>
        </div>
        <div class="price-right">
          <span class="price-onetime">ONE-TIME</span>
          <div class="price-save" style="margin-top:2px;">NO RENEWAL</div>
        </div>
      </div>
      <div class="pc-divider"></div>
      <div class="plan-desc"><?= t('pricing_lifetime_desc') ?></div>
      <div class="rollover-pill" style="background:rgba(192,132,252,.06);border-color:rgba(192,132,252,.2);color:#c084fc;">♾️ Unlimited Forever</div>

      <!-- Addon box -->
      <div class="addon-box addon-box-purple">
        <div class="addon-box-label" style="color:#c084fc;">✦ All Add-ons Included</div>
        <div class="addon-pills">
          <span class="addon-pill ap-amber">📊 CSV Generator</span>
          <span class="addon-pill ap-teal">🗜️ ZIP Manager</span>
          <span class="addon-pill ap-purple">📋 Copy &amp; Rename</span>
        </div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:8px;">$59.7 value — included FREE</div>
      </div>

      <ul class="plan-features">
        <li><span class="ck">✓</span><strong>Unlimited rows</strong> forever</li>
        <li><span class="ck">✓</span><?= t('pricing_feature_future') ?></li>
        <li><span class="ck">✓</span>CSV Generator add-on</li>
        <li><span class="ck">✓</span>ZIP Manager add-on</li>
        <li><span class="ck">✓</span>Copy &amp; Rename add-on</li>
        <li><span class="ck">✓</span><?= t('pricing_feature_support_vip') ?></li>
        <li><span class="ck">✓</span><?= t('pricing_feature_early') ?></li>
      </ul>
      <a href="https://bulkreplacetool.gumroad.com/l/<?= $plans['lifetime']['gumroad_lifetime'] ?>" class="btn plan-btn gumroad-button" data-gumroad-single-product="true" style="background:linear-gradient(135deg,#c084fc,#7c3aed);color:#fff;border:none;box-shadow:0 6px 24px rgba(192,132,252,.3);"><?= t('pricing_btn_lifetime') ?> — $469.9</a>
      </div><!-- /pc-body -->
    </div>

  </div><!-- /plan-grid -->

  <!-- ── ADD-ONS ── -->
  <div class="addons-section">
    <div style="text-align:center;margin-bottom:36px;">
      <div class="section-tag">PREMIUM ADD-ONS</div>
      <div style="font-family:'Syne',sans-serif;font-size:32px;font-weight:800;color:#fff;margin-bottom:10px;">Power Up Your Workflow</div>
      <p style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);">Available à la carte for Free &amp; Pro — included free on Platinum &amp; Lifetime</p>
    </div>

    <div class="addons-grid">
      <!-- CSV Generator -->
      <div class="addon-card ac-amber">
        <div class="ac-icon">📊</div>
        <div class="ac-name">CSV Generator</div>
        <div class="ac-desc">Generate professional CSV data from domain lists — daerah, alamat, embed maps, email, telepon, dan lebih banyak field.</div>
        <div class="ac-features">
          <div><span style="color:var(--ok)">✓</span> AI domain parser (GPT-4o-mini)</div>
          <div><span style="color:var(--ok)">✓</span> Google Places real address</div>
          <div><span style="color:var(--ok)">✓</span> 16 field types available</div>
          <div><span style="color:var(--ok)">✓</span> Live SSE streaming log</div>
        </div>
        <div class="ac-price" style="color:var(--a1);">$19.9<span> one-time</span></div>
        <a href="https://bulkreplacetool.gumroad.com/l/<?= ADDON_DATA['csv-generator-pro']['gumroad_permalink'] ?>" class="btn btn-amber gumroad-button" data-gumroad-single-product="true" style="width:100%;justify-content:center;">Get CSV Generator</a>
      </div>

      <!-- ZIP Manager -->
      <div class="addon-card ac-teal">
        <div class="ac-icon">🗜️</div>
        <div class="ac-name">ZIP Manager</div>
        <div class="ac-desc">Unzip, bulk find &amp; replace inside ZIPs, then repackage. Perfect for WordPress themes, plugins, and template bundles.</div>
        <div class="ac-features">
          <div><span style="color:var(--ok)">✓</span> Extract ZIP files</div>
          <div><span style="color:var(--ok)">✓</span> Bulk find &amp; replace inside</div>
          <div><span style="color:var(--ok)">✓</span> Repackage automatically</div>
          <div><span style="color:var(--ok)">✓</span> Multi-file batch support</div>
        </div>
        <div class="ac-price" style="color:var(--a2);">$19.9<span> one-time</span></div>
        <a href="https://bulkreplacetool.gumroad.com/l/<?= ADDON_DATA['zip-manager']['gumroad_permalink'] ?>" class="btn btn-teal gumroad-button" data-gumroad-single-product="true" style="width:100%;justify-content:center;">Get ZIP Manager</a>
      </div>

      <!-- Copy & Rename -->
      <div class="addon-card ac-purple">
        <div class="ac-icon">📋</div>
        <div class="ac-name">Copy &amp; Rename</div>
        <div class="ac-desc">Paste 50 domains → get 50 renamed folders instantly. Parallel processing, domain mode, 100% local — no upload needed.</div>
        <div class="ac-features">
          <div><span style="color:var(--ok)">✓</span> Parallel copying (4× faster)</div>
          <div><span style="color:var(--ok)">✓</span> Domain mode (auto strip TLD)</div>
          <div><span style="color:var(--ok)">✓</span> Live execution log</div>
          <div><span style="color:var(--ok)">✓</span> 100% local, no upload</div>
        </div>
        <div class="ac-price" style="color:#c084fc;">$19.9<span> one-time</span></div>
        <a href="https://bulkreplacetool.gumroad.com/l/<?= ADDON_DATA['copy-rename']['gumroad_permalink'] ?>" class="btn gumroad-button" data-gumroad-single-product="true" style="width:100%;justify-content:center;background:linear-gradient(135deg,#c084fc,#7c3aed);color:#fff;border:none;box-shadow:0 4px 18px rgba(192,132,252,.25);">Get Copy &amp; Rename</a>
      </div>
    </div>

    <!-- Bundle -->
    <div class="bundle-card">
      <div class="bundle-hot">HOT DEAL</div>
      <div style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,69,96,.08);border:1px solid rgba(255,69,96,.25);border-radius:100px;padding:5px 16px;font-family:'JetBrains Mono',monospace;font-size:10px;color:#ff6b6b;margin-bottom:16px;font-weight:700;letter-spacing:.1em;">🔥 BEST VALUE — SAVE 17%</div>
      <div class="bundle-title">All-in-One Add-ons Bundle</div>
      <p style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--muted);max-width:480px;margin:0 auto 8px;line-height:1.8;">All 3 premium add-ons in one payment. Never buy separately again.</p>

      <div class="bundle-items">
        <div class="bundle-item bi-amber">
          <div style="font-size:26px;margin-bottom:4px;">📊</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#fff;font-weight:700;">CSV Generator</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--a1);margin-top:3px;">$19.9 value</div>
        </div>
        <div class="bundle-plus">+</div>
        <div class="bundle-item bi-teal">
          <div style="font-size:26px;margin-bottom:4px;">🗜️</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#fff;font-weight:700;">ZIP Manager</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--a2);margin-top:3px;">$19.9 value</div>
        </div>
        <div class="bundle-plus">+</div>
        <div class="bundle-item bi-purple">
          <div style="font-size:26px;margin-bottom:4px;">📋</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:#fff;font-weight:700;">Copy &amp; Rename</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:#c084fc;margin-top:3px;">$19.9 value</div>
        </div>
      </div>

      <div class="bundle-pricing">
        <div class="bundle-was">$59.7 if bought separately</div>
        <div class="bundle-price">$49.9</div>
        <div class="bundle-save">Save $9.8 🎉</div>
      </div>

      <a href="https://bulkreplacetool.gumroad.com/l/<?= ADDON_DATA['premium-bundle']['gumroad_permalink'] ?>" class="btn gumroad-button" data-gumroad-single-product="true" style="display:inline-flex;align-items:center;gap:10px;background:linear-gradient(135deg,#f0a500,#c47d00);color:#000;padding:14px 44px;font-size:15px;font-weight:800;border-radius:12px;box-shadow:0 6px 28px rgba(240,165,0,.3);">
        🔥 Get Full Bundle — $49.9
      </a>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:12px;">One-time payment · Lifetime access · All 3 add-ons unlocked instantly</div>
    </div>
  </div>


  <!-- AUTOPILOT ADD-ON — Lifetime Exclusive -->
  <div class="ap-pricing-card" id="autopilot">

    <div class="ap-pc-badge">
      <span style="width:7px;height:7px;background:var(--a1);border-radius:50%;animation:blink 1.2s infinite;display:inline-block;"></span>
      LIFETIME EXCLUSIVE &nbsp;·&nbsp; AI-POWERED
    </div>

    <div class="ap-pc-title">🤖 Autopilot <span style="background:linear-gradient(135deg,#f0a500,#c084fc);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;">Add-on</span></div>
    <p class="ap-pc-sub">Pick 1 template, drop 100 domains — Claude AI reads your template, detects every location value, fetches real data per domain via Google Places, replaces all content, and writes 100 ready websites directly to your PC. Zero manual work.</p>

    <div class="ap-pc-grid">

      <!-- Left: Features -->
      <div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--muted);margin-bottom:14px;">WHAT YOU GET</div>
        <ul class="ap-pc-features">
          <li><span class="ck">✓</span> Claude AI reads any template — no manual field mapping</li>
          <li><span class="ck">✓</span> Detects domain, address, phone, email, maps embed — all variants</li>
          <li><span class="ck">✓</span> Google Places API — real coordinates + maps per domain</li>
          <li><span class="ck">✓</span> Auto case-variants: Balikpapan / balikpapan / BALIKPAPAN</li>
          <li><span class="ck">✓</span> HTML &amp;amp; encoded URL handling — maps always replaced</li>
          <li><span class="ck">✓</span> Files written directly to your PC — no ZIP download</li>
          <li><span class="ck">✓</span> 2-pass verification — zero missed replacements</li>
          <li><span class="ck">✓</span> Manual field assignment UI for edge cases</li>
          <li><span class="ck">✓</span> Unlimited domains per session</li>
        </ul>
      </div>

      <!-- Right: How it works + price -->
      <div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:2px;color:var(--muted);margin-bottom:14px;">HOW IT WORKS</div>
        <div class="ap-pc-how-step"><div class="ap-pc-snum amber">1</div><div class="ap-pc-sbody"><strong>Pick template folder</strong> from your PC</div></div>
        <div class="ap-pc-how-step"><div class="ap-pc-snum ai">AI</div><div class="ap-pc-sbody"><strong>Claude reads template</strong> — detects all location values automatically</div></div>
        <div class="ap-pc-how-step"><div class="ap-pc-snum teal">3</div><div class="ap-pc-sbody"><strong>Drop domain list</strong> — one per line, unlimited count</div></div>
        <div class="ap-pc-how-step"><div class="ap-pc-snum amber">4</div><div class="ap-pc-sbody"><strong>Pick output folder</strong> on your PC</div></div>
        <div class="ap-pc-how-step"><div class="ap-pc-snum ok">✓</div><div class="ap-pc-sbody"><strong>100 sites written</strong> to your PC automatically</div></div>

        <div class="ap-cost-box">
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:10px;">API RUNNING COST</div>
          <div style="display:flex;gap:18px;flex-wrap:wrap;">
            <div><div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--a1);">$0.033</div><div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">Claude / session</div></div>
            <div><div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--ok);">FREE</div><div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">Google Maps ($200/mo)</div></div>
            <div><div style="font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:var(--a2);">$0</div><div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">Replace + Write</div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- CTA row -->
    <div style="border-top:1px solid rgba(255,255,255,.06);padding-top:28px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
      <div>
        <div style="font-family:'Syne',sans-serif;font-size:15px;font-weight:800;color:#fff;margin-bottom:4px;">Ready to automate 100 websites at once?</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">Requires Lifetime plan. One-time payment, permanent access. You provide Claude &amp; Google API keys.</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
        <a href="https://bulkreplacetool.gumroad.com/l/<?= ADDON_DATA['autopilot']['gumroad_permalink'] ?>"
           class="btn gumroad-button"
           data-gumroad-single-product="true"
           style="background:linear-gradient(135deg,#f0a500,#c47d00);color:#000;padding:13px 32px;font-size:15px;font-weight:800;box-shadow:0 6px 28px rgba(240,165,0,.3);">
          Get Autopilot — $99.9
        </a>
        <div style="text-align:right;font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);">
          <span style="background:rgba(192,132,252,.08);border:1px solid rgba(192,132,252,.2);border-radius:6px;padding:2px 8px;color:#c084fc;font-size:9px;">REQUIRES LIFETIME PLAN</span>
          &nbsp;·&nbsp; One-time &nbsp;·&nbsp; Lifetime access
        </div>
        <a href="/landing/tutorial.php#autopilot" class="btn btn-ghost btn-sm">See Tutorial →</a>
      </div>
    </div>

  </div>

  <!-- Activation Guide -->
  <div style="max-width:1000px;margin:80px auto 80px;padding:40px;background:linear-gradient(135deg,rgba(0,212,170,.05),rgba(0,212,170,.02));border:2px solid rgba(0,212,170,.2);border-radius:20px;">
    <div class="section-tag" style="background:rgba(0,212,170,.1);color:var(--a2);border-color:rgba(0,212,170,.3);">HOW ACTIVATION WORKS</div>
    <div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:#fff;margin-bottom:24px;text-align:center;">Two Ways to Activate Your Purchase</div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:24px;margin-bottom:24px;">

      <!-- Method 1: Subscriptions -->
      <div style="background:var(--card);border:2px solid rgba(0,212,170,.3);border-radius:16px;padding:28px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
          <div style="font-size:32px;">🔄</div>
          <div>
            <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--a2);">Monthly/Yearly Plans</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">Auto-activation via email</div>
          </div>
        </div>

        <div style="background:var(--dim);border-radius:12px;padding:16px;margin-bottom:16px;">
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:12px;">HOW IT WORKS:</div>
          <ol style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);line-height:2;margin:0;padding-left:20px;">
            <li>Click "Subscribe" on any monthly/yearly plan</li>
            <li>Complete payment on Gumroad</li>
            <li>Use the <strong style="color:var(--a2);">same email</strong> for both accounts</li>
            <li>Login to BulkReplace</li>
            <li>Your plan is <strong style="color:var(--ok);">active instantly!</strong></li>
          </ol>
        </div>

        <div style="background:rgba(0,212,170,.08);border:1px dashed rgba(0,212,170,.3);border-radius:8px;padding:12px;">
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--a2);line-height:1.8;">
            <strong>✨ NO LICENSE KEY NEEDED</strong><br>
            Activates automatically within seconds via webhook
          </div>
        </div>

        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:8px;">APPLIES TO:</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <span style="background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.25);color:var(--a2);padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;">Pro Monthly</span>
            <span style="background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.25);color:var(--a2);padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;">Pro Yearly</span>
            <span style="background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.25);color:var(--a2);padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;">Platinum Monthly</span>
            <span style="background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.25);color:var(--a2);padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;">Platinum Yearly</span>
          </div>
        </div>
      </div>

      <!-- Method 2: Add-ons -->
      <div style="background:var(--card);border:2px solid rgba(240,165,0,.3);border-radius:16px;padding:28px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
          <div style="font-size:32px;">🔑</div>
          <div>
            <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--a1);">One-Time Add-ons</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);">Manual license activation</div>
          </div>
        </div>

        <div style="background:var(--dim);border-radius:12px;padding:16px;margin-bottom:16px;">
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:12px;">HOW IT WORKS:</div>
          <ol style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);line-height:2;margin:0;padding-left:20px;">
            <li>Purchase any one-time add-on</li>
            <li>Check email for <strong style="color:var(--a1);">license key</strong></li>
            <li>Login to Dashboard → Billing</li>
            <li>Paste license key & select product</li>
            <li>Click "Activate" → <strong style="color:var(--ok);">Done!</strong></li>
          </ol>
        </div>

        <div style="background:rgba(240,165,0,.08);border:1px dashed rgba(240,165,0,.3);border-radius:8px;padding:12px;">
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--a1);line-height:1.8;">
            <strong>🔐 LICENSE KEY REQUIRED</strong><br>
            Format: XXXX-XXXX-XXXX-XXXX (sent via email)
          </div>
        </div>

        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
          <div style="font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:8px;">APPLIES TO:</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <span style="background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.25);color:var(--a1);padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;">CSV Generator</span>
            <span style="background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.25);color:var(--a1);padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;">ZIP Manager</span>
            <span style="background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.25);color:var(--a1);padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;">Copy & Rename</span>
            <span style="background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.25);color:var(--a1);padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;">Autopilot</span>
            <span style="background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.25);color:var(--a1);padding:4px 10px;border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:9px;">Lifetime Plan</span>
          </div>
        </div>
      </div>

    </div>

    <div style="background:var(--dim);border-radius:12px;padding:20px;text-align:center;">
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.9;">
        <strong style="color:#fff;">💡 Pro Tip:</strong> Both methods are instant and secure.<br>
        Monthly/yearly subscriptions use Gumroad's built-in system (no license keys).<br>
        One-time purchases come with permanent license keys for your records.<br><br>
        <span style="color:var(--a2);">Need help?</span> Contact <a href="<?= SUPPORT_TELEGRAM_URL ?>" style="color:var(--a1);" target="_blank"><?= SUPPORT_TELEGRAM ?></a> on Telegram
      </div>
    </div>
  </div>

  <!-- Rollover Explainer -->
  <div class="rollover-section">
    <div class="section-tag"><?= t('pricing_rollover_title') ?></div>
    <div style="font-family:'Syne',sans-serif;font-size:20px;font-weight:700;color:#fff;margin-bottom:12px;"><?= t('pricing_rollover_subtitle') ?></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px;">
      <div style="background:var(--dim);border-radius:10px;padding:16px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:10px;"><?= $lang==='id'?'Januari (Pro)':'January (Pro Plan)' ?></div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text);line-height:2;"><?= $lang==='id'?'Limit: 500 baris':'Limit: 500 rows' ?><br><?= $lang==='id'?'Terpakai: 200 baris':'Used: 200 rows' ?><br><span style="color:var(--ok);"><?= $lang==='id'?'Rollover: +300 baris':'Rollover: +300 rows' ?></span></div>
      </div>
      <div style="background:var(--dim);border-radius:10px;padding:16px;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);margin-bottom:10px;"><?= $lang==='id'?'Februari (Pro)':'February (Pro Plan)' ?></div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text);line-height:2;"><?= $lang==='id'?'Dasar: 500 baris':'Base: 500 rows' ?><br><?= $lang==='id'?'Rollover: 300 baris':'Rollover: 300 rows' ?><br><span style="color:var(--a1);"><?= $lang==='id'?'Total tersedia: 800 baris':'Total available: 800 rows' ?></span></div>
      </div>
    </div>
    <p style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-top:16px;line-height:1.8;"><?= t('pricing_rollover_note') ?></p>
  </div>

</section>
</div>

<footer><div class="footer-grid">
  <div class="footer-brand"><a href="/" class="nav-logo"><img src="/img/logo.png" alt="BulkReplace" class="nav-logo-img"><span class="nav-logo-text" style="margin-left:10px;">BulkReplace</span></a><p><?= t('footer_tagline') ?></p></div>
  <div class="footer-col"><h4><?= t('footer_product') ?></h4><a href="/"><?= t('nav_home') ?></a><a href="/landing/tutorial.php"><?= t('nav_tutorial') ?></a></div>
  <div class="footer-col"><h4><?= t('footer_support') ?></h4><a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank">Telegram</a><a href="/landing/terms.php"><?= t('nav_terms') ?></a><a href="/landing/privacy.php">Privacy</a></div>
  <div class="footer-col"><h4><?= t('footer_account') ?></h4><a href="/auth/login.php"><?= t('nav_signin') ?></a><a href="/auth/register.php"><?= t('nav_register') ?></a></div>
</div><div class="footer-bottom"><span>© <?= date('Y') ?> BulkReplace</span></div></footer>

<script src="https://gumroad.com/js/gumroad.js"></script>
<script>
let billing='monthly';
const plans=<?= json_encode($plans) ?>;

function toggleBilling(){setBilling(billing==='monthly'?'annual':'monthly');}
function setBilling(b){
  billing=b;
  const tog=document.getElementById('billing-toggle');
  const lm=document.getElementById('lbl-monthly');
  const la=document.getElementById('lbl-annual');
  if(b==='annual'){
    tog.classList.add('on');lm.classList.remove('act');la.classList.add('act');
  } else {
    tog.classList.remove('on');lm.classList.add('act');la.classList.remove('act');
  }

  // Pro
  const proM = (plans.pro.pa/12).toFixed(1);
  document.getElementById('pro-price').textContent  = b==='annual' ? proM : '19.9';
  document.getElementById('pro-period').textContent = b==='annual' ? '/mo · annual' : '/mo';
  const proWas = document.getElementById('pro-orig');
  const proSave = document.getElementById('pro-save');
  if(b==='annual'){
    proWas.textContent='was $19.9'; proWas.style.display='block';
    proSave.style.display='block';
  } else {
    proWas.style.display='none'; proSave.style.display='none';
  }
  document.getElementById('pro-btn').href='https://bulkreplacetool.gumroad.com/l/'+(b==='annual'?plans.pro.gumroad_annual:plans.pro.gumroad_monthly);

  // Platinum
  const platM = (plans.platinum.pa/12).toFixed(1);
  document.getElementById('plat-price').textContent  = b==='annual' ? platM : '69.9';
  document.getElementById('plat-period').textContent = b==='annual' ? '/mo · annual' : '/mo';
  const platWas = document.getElementById('plat-orig');
  const platSave = document.getElementById('plat-save');
  if(b==='annual'){
    platWas.textContent='was $69.9'; platWas.style.display='block';
    platSave.style.display='block';
  } else {
    platWas.style.display='none'; platSave.style.display='none';
  }
  document.getElementById('plat-btn').href='https://bulkreplacetool.gumroad.com/l/'+(b==='annual'?plans.platinum.gumroad_annual:plans.platinum.gumroad_monthly);
}
</script>
</body></html>
