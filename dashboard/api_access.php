<?php
require_once dirname(__DIR__).'/config.php';
requireLogin();

$user = currentUser();
$isLifetime = $user['plan'] === 'lifetime';
$msg = '';
$err = '';

if (!$isLifetime) {
    header('Location: /dashboard/billing.php?upgrade=lifetime');
    exit;
}

$pdo = db();

try {
    $stmt = $pdo->prepare("SELECT api_key FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $apiKeyRow = $stmt->fetch();
    $hasApiKey = !empty($apiKeyRow['api_key']);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Unknown column') !== false) {
        $pdo->exec("ALTER TABLE users ADD COLUMN api_key VARCHAR(128) UNIQUE DEFAULT NULL");
        $apiKeyRow = ['api_key' => null];
        $hasApiKey = false;
    } else {
        throw $e;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>API Access — BulkReplace</title>
    <link rel="icon" type="image/png" href="/img/logo.png">
    <link rel="stylesheet" href="/assets/main.css">
    <link rel="stylesheet" href="/assets/loading.css">
    <style>
        .api-hero {
            background: linear-gradient(135deg, rgba(240,165,0,0.08), rgba(168,85,247,0.08));
            border: 1px solid rgba(240,165,0,0.2);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
        }
        .api-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(240,165,0,0.15), transparent);
            border-radius: 50%;
        }
        .api-hero-content {
            position: relative;
            z-index: 1;
        }
        .api-badge {
            display: inline-block;
            padding: 6px 14px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #000;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }
        .api-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 24px;
        }
        .api-key-display {
            background: rgba(0,0,0,0.4);
            border: 1px solid var(--border2);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .api-key-label {
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        .api-key-input-wrap {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .api-key-input {
            flex: 1;
            background: rgba(0,0,0,0.35);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            color: #fff;
        }
        .api-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .api-stat-card {
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        .api-stat-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--a1);
            margin-bottom: 8px;
        }
        .api-stat-label {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .api-code-block {
            background: #000;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            overflow-x: auto;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            line-height: 1.6;
        }
        .api-endpoint {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(0,230,118,0.08);
            border: 1px solid rgba(0,230,118,0.2);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 12px;
        }
        .api-method {
            background: rgba(0,230,118,0.2);
            color: var(--ok);
            padding: 4px 10px;
            border-radius: 6px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            font-weight: 700;
        }
        .api-url {
            flex: 1;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            color: var(--text);
        }
        .warning-box {
            background: rgba(255,193,7,0.08);
            border: 1px solid rgba(255,193,7,0.3);
            border-left: 4px solid #ffc107;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            font-size: 13px;
            color: var(--text);
        }
    </style>
</head>
<body>

<div id="toast-wrap"></div>
<div class="dash-layout">
<?php include '_sidebar.php'; ?>

<div class="dash-main">
    <div class="dash-topbar">
        <div class="dash-page-title">🔑 API Access</div>
        <div style="display:flex;align-items:center;gap:12px;">
            <button onclick="location.href='<?= SUPPORT_TELEGRAM_URL ?>'" class="btn btn-ghost btn-sm">💬 Support</button>
        </div>
    </div>

    <div class="dash-content">

    <?php if($msg): ?>
        <div class="info-box" style="margin-bottom:20px;">✅ <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if($err): ?>
        <div class="err-box" style="margin-bottom:20px;">⚠ <?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <div class="api-hero">
        <div class="api-hero-content">
            <div class="api-badge">⚡ LIFETIME EXCLUSIVE</div>
            <h2 style="font-size:28px;font-weight:800;margin-bottom:12px;color:#fff;">RESTful API Integration</h2>
            <p style="color:var(--muted);font-size:14px;max-width:600px;">
                Automate CSV generation, integrate with your systems, and build powerful workflows with our REST API.
            </p>
        </div>
    </div>

    <div class="api-stats">
        <div class="api-stat-card">
            <div class="api-stat-value">100</div>
            <div class="api-stat-label">Requests / Hour</div>
        </div>
        <div class="api-stat-card">
            <div class="api-stat-value">∞</div>
            <div class="api-stat-label">Daily Requests</div>
        </div>
        <div class="api-stat-card">
            <div class="api-stat-value">24/7</div>
            <div class="api-stat-label">Uptime</div>
        </div>
    </div>

    <div class="api-card">
        <h2 style="margin-top:0;font-size:20px;margin-bottom:20px;">🔐 Authentication</h2>

        <?php if ($hasApiKey): ?>
            <div class="api-key-display">
                <div class="api-key-label">Your API Key</div>
                <div class="api-key-input-wrap">
                    <input type="password" id="api-key-display" value="<?= htmlspecialchars($apiKeyRow['api_key']) ?>" readonly class="api-key-input">
                    <button onclick="toggleApiKey()" class="btn btn-ghost btn-sm">👁️ Show</button>
                    <button onclick="copyApiKey()" class="btn btn-ghost btn-sm">📋 Copy</button>
                </div>
            </div>

            <div class="warning-box">
                ⚠️ <strong>Security Warning:</strong> Never share your API key or commit it to version control. Anyone with this key can access your account.
            </div>

            <button onclick="regenerateApiKey()" class="btn btn-ghost" style="border-color:rgba(239,68,68,0.4);color:#ef4444;">
                🔄 Regenerate API Key
            </button>
        <?php else: ?>
            <p style="color:var(--muted);margin-bottom:16px;">Generate an API key to start using the BulkReplace API.</p>
            <button onclick="generateApiKey()" class="btn btn-amber">⚡ Generate API Key</button>
        <?php endif; ?>
    </div>

    <div class="api-card">
        <h2 style="margin-top:0;font-size:20px;margin-bottom:20px;">📡 Endpoint</h2>

        <div class="api-endpoint">
            <span class="api-method">POST</span>
            <span class="api-url"><?= APP_URL ?>/api/csv_api.php</span>
        </div>

        <div style="margin-top:20px;">
            <h3 style="font-size:14px;margin-bottom:12px;color:var(--a1);">Request Headers</h3>
            <div class="api-code-block">
<span style="color:#6b7280;">Content-Type:</span> <span style="color:#00e676;">application/json</span>
<span style="color:#6b7280;">X-API-Key:</span> <span style="color:#fbbf24;">your_api_key_here</span>
            </div>
        </div>

        <div style="margin-top:20px;">
            <h3 style="font-size:14px;margin-bottom:12px;color:var(--a1);">Request Body Example</h3>
            <div class="api-code-block">
<span style="color:#6b7280;">{</span>
  <span style="color:#a855f7;">"domains"</span><span style="color:#6b7280;">:</span> <span style="color:#6b7280;">[</span><span style="color:#00e676;">"example.com"</span>, <span style="color:#00e676;">"test.org"</span><span style="color:#6b7280;">]</span>,
  <span style="color:#a855f7;">"fields"</span><span style="color:#6b7280;">:</span> <span style="color:#6b7280;">[</span><span style="color:#00e676;">"namalink"</span>, <span style="color:#00e676;">"email"</span>, <span style="color:#00e676;">"daerah"</span><span style="color:#6b7280;">]</span>,
  <span style="color:#a855f7;">"keyword_hint"</span><span style="color:#6b7280;">:</span> <span style="color:#00e676;">"universitas"</span>,
  <span style="color:#a855f7;">"format"</span><span style="color:#6b7280;">:</span> <span style="color:#00e676;">"csv"</span>
<span style="color:#6b7280;">}</span>
            </div>
        </div>

        <div style="margin-top:20px;">
            <h3 style="font-size:14px;margin-bottom:12px;color:var(--a1);">Response Example</h3>
            <div class="api-code-block">
<span style="color:#6b7280;">{</span>
  <span style="color:#a855f7;">"ok"</span><span style="color:#6b7280;">:</span> <span style="color:#fbbf24;">true</span>,
  <span style="color:#a855f7;">"job_id"</span><span style="color:#6b7280;">:</span> <span style="color:#00e676;">"job_123456789"</span>,
  <span style="color:#a855f7;">"status"</span><span style="color:#6b7280;">:</span> <span style="color:#00e676;">"processing"</span>,
  <span style="color:#a855f7;">"message"</span><span style="color:#6b7280;">:</span> <span style="color:#00e676;">"Job queued successfully"</span>
<span style="color:#6b7280;">}</span>
            </div>
        </div>
    </div>

    <div class="api-card">
        <h2 style="margin-top:0;font-size:20px;margin-bottom:16px;">💡 Quick Start</h2>

        <details style="margin-bottom:16px;">
            <summary style="cursor:pointer;color:var(--a1);font-weight:700;margin-bottom:12px;">Node.js Example</summary>
            <div class="api-code-block">
<span style="color:#a855f7;">const</span> axios = <span style="color:#a855f7;">require</span>(<span style="color:#00e676;">'axios'</span>);

<span style="color:#a855f7;">const</span> response = <span style="color:#a855f7;">await</span> axios.post(
  <span style="color:#00e676;">'<?= APP_URL ?>/api/csv_api.php'</span>,
  {
    domains: [<span style="color:#00e676;">'example.com'</span>],
    fields: [<span style="color:#00e676;">'namalink'</span>, <span style="color:#00e676;">'email'</span>],
    keyword_hint: <span style="color:#00e676;">'universitas'</span>,
    format: <span style="color:#00e676;">'csv'</span>
  },
  {
    headers: {
      <span style="color:#00e676;">'X-API-Key'</span>: <span style="color:#00e676;">'your_api_key_here'</span>,
      <span style="color:#00e676;">'Content-Type'</span>: <span style="color:#00e676;">'application/json'</span>
    }
  }
);

console.log(response.data);
            </div>
        </details>

        <details style="margin-bottom:16px;">
            <summary style="cursor:pointer;color:var(--a1);font-weight:700;margin-bottom:12px;">Python Example</summary>
            <div class="api-code-block">
<span style="color:#a855f7;">import</span> requests

response = requests.post(
    <span style="color:#00e676;">'<?= APP_URL ?>/api/csv_api.php'</span>,
    json={
        <span style="color:#00e676;">'domains'</span>: [<span style="color:#00e676;">'example.com'</span>],
        <span style="color:#00e676;">'fields'</span>: [<span style="color:#00e676;">'namalink'</span>, <span style="color:#00e676;">'email'</span>],
        <span style="color:#00e676;">'keyword_hint'</span>: <span style="color:#00e676;">'universitas'</span>,
        <span style="color:#00e676;">'format'</span>: <span style="color:#00e676;">'csv'</span>
    },
    headers={
        <span style="color:#00e676;">'X-API-Key'</span>: <span style="color:#00e676;">'your_api_key_here'</span>,
        <span style="color:#00e676;">'Content-Type'</span>: <span style="color:#00e676;">'application/json'</span>
    }
)

print(response.json())
            </div>
        </details>

        <details>
            <summary style="cursor:pointer;color:var(--a1);font-weight:700;margin-bottom:12px;">cURL Example</summary>
            <div class="api-code-block">
curl -X POST <?= APP_URL ?>/api/csv_api.php \
  -H <span style="color:#00e676;">"X-API-Key: your_api_key_here"</span> \
  -H <span style="color:#00e676;">"Content-Type: application/json"</span> \
  -d <span style="color:#00e676;">'{
    "domains": ["example.com"],
    "fields": ["namalink", "email"],
    "keyword_hint": "universitas",
    "format": "csv"
  }'</span>
            </div>
        </details>
    </div>

    <div class="api-card">
        <h2 style="margin-top:0;font-size:20px;margin-bottom:12px;">📚 Resources</h2>
        <div style="display:grid;gap:12px;">
            <a href="<?= SUPPORT_TELEGRAM_URL ?>" target="_blank" class="btn btn-ghost" style="justify-content:flex-start;">
                💬 Get Help on Telegram
            </a>
            <a href="/landing/tutorial.php" class="btn btn-ghost" style="justify-content:flex-start;">
                📖 View Tutorial
            </a>
        </div>
    </div>
</div>

<script>
function toggleApiKey() {
    const input = document.getElementById('api-key-display');
    if (input) {
        input.type = input.type === 'password' ? 'text' : 'password';
    }
}

function copyApiKey() {
    const input = document.getElementById('api-key-display');
    if (input) {
        navigator.clipboard.writeText(input.value).then(() => {
            showToast('✅ API key copied to clipboard!');
        });
    }
}

function generateApiKey() {
    if (!confirm('Generate a new API key? This will allow you to access the BulkReplace API.')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Generating...';

    fetch('/api/generate_api_key.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'}
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            if (typeof showToast === 'function') {
                showToast('✅ ' + d.message);
            } else {
                alert('✅ ' + d.message);
            }
            setTimeout(() => location.reload(), 1500);
        } else {
            if (typeof showToast === 'function') {
                showToast('❌ ' + (d.error || 'Failed to generate API key'));
            } else {
                alert('❌ ' + (d.error || 'Failed to generate API key'));
            }
            btn.disabled = false;
            btn.textContent = '⚡ Generate API Key';
        }
    })
    .catch(err => {
        console.error('API Key Generation Error:', err);
        if (typeof showToast === 'function') {
            showToast('❌ Network error');
        } else {
            alert('❌ Network error');
        }
        btn.disabled = false;
        btn.textContent = '⚡ Generate API Key';
    });
}

function regenerateApiKey() {
    if (!confirm('⚠️ Regenerate API key? This will invalidate your current key and break any existing integrations.')) return;

    const btn = event.target;
    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = 'Regenerating...';

    fetch('/api/generate_api_key.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'}
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            if (typeof showToast === 'function') {
                showToast('✅ ' + d.message);
            } else {
                alert('✅ ' + d.message);
            }
            setTimeout(() => location.reload(), 1500);
        } else {
            if (typeof showToast === 'function') {
                showToast('❌ ' + (d.error || 'Failed to regenerate API key'));
            } else {
                alert('❌ ' + (d.error || 'Failed to regenerate API key'));
            }
            btn.disabled = false;
            btn.textContent = originalText;
        }
    })
    .catch(err => {
        console.error('API Key Regeneration Error:', err);
        if (typeof showToast === 'function') {
            showToast('❌ Network error');
        } else {
            alert('❌ Network error');
        }
        btn.disabled = false;
        btn.textContent = originalText;
    });
}
</script>

</div>
</div>
</div>

<script src="/assets/toast.js"></script>
<script src="/assets/darkmode.js"></script>
<script src="/assets/shortcuts.js"></script>
</body>
</html>
