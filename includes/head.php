<?php
// Shared head builder
function renderHead($title = '', $extraCss = '') {
    $t = $title ? "$title — " . APP_NAME : APP_NAME . ' — Bulk Content Replacer';
    echo <<<HTML
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>$t</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;700&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#080810;--bg2:#0d0d1a;--card:#0f0f20;--card2:#141428;
  --border:#1e1e3a;--border2:#2a2a48;
  --a1:#f0a500;--a2:#00d4aa;--purple:#a855f7;
  --err:#ff4560;--ok:#00e676;--warn:#ffd740;
  --text:#c8c8e8;--muted:#454568;--dim:#181830;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{background:var(--bg);color:var(--text);font-family:'Syne',sans-serif;min-height:100vh;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;background-image:radial-gradient(ellipse 60% 40% at 20% 10%,rgba(240,165,0,.04),transparent),radial-gradient(ellipse 50% 30% at 80% 90%,rgba(0,212,170,.03),transparent),linear-gradient(rgba(240,165,0,.004) 1px,transparent 1px),linear-gradient(90deg,rgba(240,165,0,.004) 1px,transparent 1px);background-size:auto,auto,48px 48px,48px 48px;pointer-events:none;z-index:0;}
a{color:inherit;text-decoration:none;}
input,select,textarea{font-family:'Syne',sans-serif;}
.z1{position:relative;z-index:1;}

/* NAV */
.nav{position:sticky;top:0;z-index:100;background:rgba(8,8,16,.9);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:0 24px;}
.nav-inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;height:64px;gap:20px;}
.nav-logo{display:flex;align-items:center;gap:10px;font-size:20px;font-weight:800;color:#fff;}
.nav-logo-icon{width:36px;height:36px;background:linear-gradient(135deg,#f0a500,#c47d00);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px;}
.nav-links{display:flex;align-items:center;gap:6px;}
.nav-link{font-family:'JetBrains Mono',monospace;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);padding:7px 14px;border-radius:7px;transition:all .15s;}
.nav-link:hover,.nav-link.active{color:var(--text);background:rgba(255,255,255,.06);}
.nav-cta{background:linear-gradient(135deg,#f0a500,#c47d00);color:#000;font-family:'Syne',sans-serif;font-size:13px;font-weight:700;padding:8px 18px;border-radius:9px;transition:all .2s;}
.nav-cta:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(240,165,0,.3);}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:7px;padding:11px 22px;border-radius:10px;font-family:'Syne',sans-serif;font-size:14px;font-weight:700;cursor:pointer;border:none;transition:all .2s;white-space:nowrap;letter-spacing:.3px;}
.btn-amber{background:linear-gradient(135deg,#f0a500,#c47d00);color:#000;box-shadow:0 4px 20px rgba(240,165,0,.3);}
.btn-amber:hover{transform:translateY(-1px);box-shadow:0 6px 28px rgba(240,165,0,.4);}
.btn-teal{background:linear-gradient(135deg,#00d4aa,#007a63);color:#000;box-shadow:0 4px 18px rgba(0,212,170,.25);}
.btn-teal:hover{transform:translateY(-1px);}
.btn-ghost{background:rgba(255,255,255,.05);color:var(--text);border:1px solid var(--border2);}
.btn-ghost:hover{background:rgba(255,255,255,.09);}
.btn-purple{background:linear-gradient(135deg,#a855f7,#7c3aed);color:#fff;box-shadow:0 4px 18px rgba(168,85,247,.25);}
.btn-purple:hover{transform:translateY(-1px);}
.btn-sm{padding:7px 14px;font-size:12px;}
.btn-lg{padding:14px 32px;font-size:16px;}
.btn:disabled{opacity:.4;cursor:not-allowed;transform:none!important;}

/* CARDS */
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:24px;}

/* BADGES */
.badge{font-family:'JetBrains Mono',monospace;font-size:10px;padding:4px 11px;border-radius:100px;letter-spacing:1px;text-transform:uppercase;}
.badge-amber{background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.25);color:var(--a1);}
.badge-teal{background:rgba(0,212,170,.08);border:1px solid rgba(0,212,170,.2);color:var(--a2);}
.badge-green{background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.2);color:var(--ok);}
.badge-purple{background:rgba(168,85,247,.1);border:1px solid rgba(168,85,247,.25);color:var(--purple);}
.badge-red{background:rgba(255,69,96,.1);border:1px solid rgba(255,69,96,.2);color:var(--err);}

/* FORMS */
.field{display:flex;flex-direction:column;gap:5px;margin-bottom:16px;}
.field-row{display:grid;gap:12px;margin-bottom:16px;}
.fr2{grid-template-columns:1fr 1fr;}
@media(max-width:600px){.fr2{grid-template-columns:1fr;}}
label{font-family:'JetBrains Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--muted);}
input[type=text],input[type=email],input[type=password],select,textarea{background:rgba(255,255,255,.04);border:1px solid var(--border2);border-radius:10px;color:#e0e0ff;font-family:'JetBrains Mono',monospace;font-size:12px;padding:11px 14px;outline:none;width:100%;transition:all .2s;}
input:focus,select:focus,textarea:focus{border-color:rgba(240,165,0,.4);box-shadow:0 0 0 3px rgba(240,165,0,.06);}
input::placeholder{color:var(--muted);}
select option{background:#0d0d1a;}

/* ALERTS */
.alert{padding:12px 16px;border-radius:10px;font-family:'JetBrains Mono',monospace;font-size:11px;line-height:1.8;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start;}
.alert-err{background:rgba(255,69,96,.08);border:1px solid rgba(255,69,96,.2);color:var(--err);}
.alert-ok{background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.2);color:var(--ok);}
.alert-warn{background:rgba(255,215,64,.08);border:1px solid rgba(255,215,64,.2);color:var(--warn);}
.alert-info{background:rgba(0,212,170,.06);border:1px solid rgba(0,212,170,.15);color:var(--a2);}

/* TOAST */
#toast-wrap{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none;}
.toast{background:var(--card2);border:1px solid var(--border2);border-radius:10px;padding:10px 16px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);display:flex;align-items:center;gap:10px;max-width:300px;animation:toastIn .2s ease;box-shadow:0 4px 20px rgba(0,0,0,.5);pointer-events:all;}
@keyframes toastIn{from{transform:translateX(30px);opacity:0;}to{transform:none;opacity:1;}}
.toast.ok{border-left:3px solid var(--ok);}
.toast.err{border-left:3px solid var(--err);}
.toast.warn{border-left:3px solid var(--warn);}

/* UTILS */
.hidden{display:none!important;}
.text-muted{color:var(--muted);}
.text-ok{color:var(--ok);}
.text-err{color:var(--err);}
.text-amber{color:var(--a1);}
.text-teal{color:var(--a2);}
.mono{font-family:'JetBrains Mono',monospace;}
.spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.15);border-top-color:var(--a1);border-radius:50%;animation:spin .6s linear infinite;display:inline-block;}
@keyframes spin{to{transform:rotate(360deg);}}
$extraCss
</style>
HTML;
}

function renderToast() {
    echo '<script src="'.APP_URL.'/assets/toast.js"></script>';
}

function renderNav($active = '', $user = null) {
    $links = [
        'features' => [APP_URL . '/#features', 'Features'],
        'pricing'  => [APP_URL . '/landing/pricing.php', 'Pricing'],
        'tutorial' => [APP_URL . '/landing/tutorial.php', 'Tutorial'],
    ];
    echo '<nav class="nav"><div class="nav-inner">';
    echo '<a href="'.APP_URL.'/" class="nav-logo"><div class="nav-logo-icon">⚡</div>'.APP_NAME.'</a>';
    echo '<div class="nav-links">';
    foreach ($links as $key => [$href, $label]) {
        $cls = ($active === $key) ? 'nav-link active' : 'nav-link';
        echo "<a href='$href' class='$cls'>$label</a>";
    }
    if ($user) {
        echo '<a href="'.APP_URL.'/dashboard/" class="nav-link active">Dashboard</a>';
        echo '<a href="'.APP_URL.'/auth/logout.php" class="nav-link">Logout</a>';
    } else {
        echo '<a href="'.APP_URL.'/auth/login.php" class="nav-link">Login</a>';
        echo '<a href="'.APP_URL.'/auth/register.php" class="nav-cta">Get Started Free</a>';
    }
    echo '</div></div></nav>';
}
