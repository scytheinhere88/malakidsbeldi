<?php
/**
 * Autopilot Template Detection Engine
 * Strategy: send template CONTENT to Claude — AI reads context and finds actual values
 * Fallback: local regex engine
 */
require_once dirname(__DIR__).'/config.php';
requireLogin();

// Force create autopilot tables if not exist
try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS autopilot_jobs (
        id VARCHAR(36) PRIMARY KEY,
        user_id INT NOT NULL,
        total_domains INT NOT NULL DEFAULT 0,
        processed_domains INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        keyword_hint TEXT,
        user_hints TEXT,
        result_data JSON,
        error_log JSON,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_created_at (created_at DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS autopilot_queue (
        id VARCHAR(36) PRIMARY KEY,
        job_id VARCHAR(36) NOT NULL,
        domain VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        result_data JSON,
        error_message TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME NULL,
        INDEX idx_job_id (job_id),
        INDEX idx_status (status),
        INDEX idx_job_status (job_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {
    error_log("Autopilot tables creation: " . $e->getMessage());
}

set_time_limit(120);
ini_set('max_execution_time', 120);
header('Content-Type: application/json');

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$content     = $body['template_content'] ?? '';   // representative template text
$domains     = $body['domains']          ?? [];
$refDomain   = $body['ref_domain']       ?? ($domains[0] ?? '');
$keywordHint = trim($body['keyword_hint'] ?? '');
$userHints   = trim($body['user_hints']   ?? '');  // NEW: User-provided hints

// Store for analytics tracking
$GLOBALS['_autopilot_content'] = $content;
$GLOBALS['_autopilot_domains'] = $domains;

if (empty($content) || empty($refDomain)) {
    echo json_encode(['ok'=>false,'msg'=>'Missing template_content or domain']);
    exit;
}

// ── Result builder ────────────────────────────────────────────────────────────
function buildResult(array $mapping, array $undetected, string $method): string {
    $clean = [];
    foreach ($mapping as $field => $arr) {
        $c = array_values(array_unique(array_filter((array)$arr, fn($s)=>strlen(trim($s))>=2)));
        if ($c) $clean[$field] = $c;
    }

    // Extract detected domain (first from namalink or namalinkurl)
    $detectedDomain = '';
    if (!empty($clean['namalink'][0])) {
        $detectedDomain = $clean['namalink'][0];
    } elseif (!empty($clean['namalinkurl'][0])) {
        $detectedDomain = parse_url($clean['namalinkurl'][0], PHP_URL_HOST) ?: '';
    }

    // Build detected data object (first value of each field)
    $detectedData = [
        'domain'  => $detectedDomain,
        'city'    => $clean['daerah'][0] ?? '',
        'address' => $clean['alamat'][0] ?? '',
        'phone'   => $clean['notelp'][0] ?? '',
        'email'   => $clean['email'][0] ?? '',
        'postal'  => $clean['kodepos'][0] ?? '',
        'province'=> $clean['provinsi'][0] ?? '',
        'institution' => $clean['namainstansi'][0] ?? '',
    ];

    // Track analytics
    try {
        require_once dirname(__DIR__).'/includes/Analytics.php';
        $analytics = new Analytics(db());
        $uid = currentUser()['id'] ?? null;
        $analytics->trackEvent('autopilot_detected', 'feature_usage', $uid, [
            'fields_found' => count($clean),
            'total_strings' => array_sum(array_map('count', $clean)),
            'method' => $method,
            'template_length' => strlen($GLOBALS['_autopilot_content'] ?? ''),
            'domain_count' => count($GLOBALS['_autopilot_domains'] ?? [])
        ]);
    } catch(Exception $e) {}

    return json_encode([
        'ok'              => true,
        'detected_domain' => $detectedDomain,
        'detected_data'   => $detectedData,
        'mapping'         => $clean,
        'undetected'      => array_values(array_unique(array_filter($undetected??[], fn($s)=>strlen($s)>=2))),
        'fields_found'    => count($clean),
        'total_strings'   => array_sum(array_map('count', $clean)),
        'method'          => $method,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// CLAUDE API — PRIMARY DETECTION
// Strategy: send template content + ref domain, Claude finds ALL location-specific values
// ══════════════════════════════════════════════════════════════════════════════
$claudeKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
$placeholders = ['', 'YOUR_ANTHROPIC_API_KEY', 'your-anthropic-api-key', 'sk-ant-placeholder'];
$useCloud  = !empty($claudeKey) && !in_array($claudeKey, $placeholders);

if (!$useCloud) {
    error_log('WARNING: Anthropic API key not configured or using placeholder. Autopilot AI detection disabled.');
}

if ($useCloud) {
    $domainSample = implode(', ', array_slice($domains, 0, 8));
    $contentTrunc = mb_substr($content, 0, 8000); // ~8K chars is enough context

    $systemPrompt = <<<'PROMPT'
You are an expert Indonesian government/institutional website template analyzer with ENHANCED ADDRESS DETECTION.

WORKFLOW CONTEXT:
A user maintains bulk website templates for Indonesian institutions (dinas, BNN, lapas, sekolah, BPBD, KPU, etc.).
They pick a template folder, drop a list of target domains, and you extract ALL location-specific values that need replacing.
The system then deploys the template for each domain by substituting the detected values with new domain-specific data.

YOUR JOB: Read the provided template content carefully. Identify every string that is SPECIFIC to the original domain/location and must be changed per deployment.

FIELDS:
namalinkurl  = every URL containing the domain (ALL paths, ALL variants: with/without slash, with path)
namalink     = domain as plain text slug (e.g. "bnn-balikpapan.com", "bnn-balikpapan")
daerah       = city/district name — include EVERY case form present: "Balikpapan", "balikpapan", "BALIKPAPAN", "Kota Balikpapan"
alamat       = full street address (copy exactly including RT/RW, kelurahan, kecamatan, kota, province, postal code, "Indonesia")
               CRITICAL: alamat is THE MOST IMPORTANT FIELD - scan aggressively for ANY address pattern
notelp       = phone number in every format found: "+62542872638", "62542872638", "0542872638", "(0542) 872-638"
email        = email address
embedmap     = Google Maps iframe/embed URL - the EXACT src attribute value as it appears in HTML
               (may contain &amp; or & — copy exactly as-is from the src="..." attribute)
linkmaps     = Google Maps link URL
kodepos      = 5-digit postal code
provinsi     = province name (e.g. "Kalimantan Timur", "Jawa Barat", "Sumatera Utara")
namainstansi = institution full name in ALL forms (e.g. "BNN Balikpapan", "Badan Narkotika Nasional Balikpapan", "BNN BALIKPAPAN")

WHERE TO LOOK (scan ALL of these):
- <title> tag
- <meta name="description"> content
- <link rel="canonical"> href
- <meta property="og:url">, og:title, og:description
- JSON-LD <script type="application/ld+json">: name, url, logo, description, streetAddress, addressLocality, addressRegion, postalCode, telephone, email
- Body text: headings, paragraphs, footer address section, contact page
- Copyright line at bottom
- <address> tags
- Footer sections with class/id containing: footer, alamat, kontak, contact, lokasi, location
- href attributes containing the domain
- src attributes containing the domain
- TXT files (listed as "TXT VALUE: filename = value" — these are single exact values)
- ANY paragraph or div containing "Jl.", "Jalan", "Jln", "RT", "RW", "Kel.", "Kec."

ENHANCED ADDRESS DETECTION RULES:
1. SCAN AGGRESSIVELY: Look for ANY text containing Indonesian address patterns:
   - Starting with: Jl., Jalan, Jln., Jln, Komplek, Komp., Perumahan, Perum., Gedung, Gd.
   - Containing: RT, RW, Kel., Kelurahan, Kec., Kecamatan, Kabupaten, Kab., Kota
   - Including postal codes (5 digits near end of address)
2. CAPTURE COMPLETE ADDRESSES: Include the FULL address from start to end, including:
   - Street name and number
   - RT/RW if present
   - Kelurahan/Desa
   - Kecamatan
   - Kota/Kabupaten
   - Province (if present in same text block)
   - Postal code (if present)
   - "Indonesia" (if present)
3. MULTIPLE OCCURRENCES: If address appears in multiple places (footer, contact page, JSON-LD), include ALL variants
4. PARTIAL ADDRESSES: Even if incomplete, capture it if it starts with Jl./Jalan and has >15 characters

CRITICAL RULES:
1. TXT VALUES at the top of the input are exact single-value files — include them directly
2. For daerah: scan title, addressLocality, body text — include all distinct case variants found
3. For namalinkurl: include every URL, even asset paths like /assets/Logo.png
4. For alamat: BE EXTRA AGGRESSIVE - this field is often missed, scan EVERY section
5. Copy strings EXACTLY as they appear — no modification
6. If phone appears as both "+62542872638" and "62542872638" — include both
7. Return ONLY JSON — zero markdown, zero explanation

EXAMPLE (bnn-balikpapan.com):
{"mapping":{"namalinkurl":["https://bnn-balikpapan.com/","https://bnn-balikpapan.com","https://bnn-balikpapan.com/assets/Logo.png"],"namalink":["bnn-balikpapan.com","bnn-balikpapan"],"daerah":["Balikpapan","balikpapan","Kota Balikpapan"],"alamat":["Jl. Abdi Praja RT24, Kel. Sepinggan Baru, Sepinggan, Kecamatan Balikpapan Selatan, Kota Balikpapan, Kalimantan Timur 76115, Indonesia"],"notelp":["+62542872638","62542872638","(0542) 872-638"],"email":["info@bnn-balikpapan.com"],"kodepos":["76115"],"provinsi":["Kalimantan Timur"],"namainstansi":["BNN Balikpapan","Badan Narkotika Nasional Balikpapan"],"embedmap":["https://maps.google.com/maps?q=BNNK+Balikpapan&output=embed&z=15"],"linkmaps":["https://maps.google.com/?q=BNNK+Balikpapan"]},"undetected":[]}
PROMPT;

    $hintLine = $keywordHint ? "HINT: Institution type is \"{$keywordHint}\" - use this context to better identify institution names and location data.\n\n" : "";

    // NEW: User-provided hints for better detection
    $userHintSection = "";
    if ($userHints) {
        $userHintSection = "USER PROVIDED DETECTION HINTS:\n";
        $userHintSection .= "The user has provided specific guidance about where to find certain fields in this template.\n";
        $userHintSection .= "PRIORITIZE these hints - they come from someone who knows this exact template structure.\n\n";
        $userHintSection .= $userHints . "\n\n";
        $userHintSection .= "Apply these hints when scanning the template content below.\n\n";
    }

    $userMsg = "Reference domain: {$refDomain}\nDeploy targets: {$domainSample}\n{$hintLine}{$userHintSection}--- TEMPLATE CONTENT ---\n{$contentTrunc}\n--- END ---\n\nExtract all location-specific values that need replacing.";

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 3000,
        'system'     => $systemPrompt,
        'messages'   => [['role'=>'user','content'=>$userMsg]],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 50,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: '.$claudeKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $resp    = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode= curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$curlErr && $resp && $httpCode === 200) {
        $json = json_decode($resp, true);
        $raw  = $json['content'][0]['text'] ?? '';
        $raw  = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw));
        $result = json_decode($raw, true);
        if (isset($result['mapping'])) {
            echo buildResult($result['mapping'], $result['undetected'] ?? [], 'claude');
            exit;
        }
    } elseif ($curlErr || $httpCode !== 200) {
        error_log("Claude API error: HTTP {$httpCode}, cURL: {$curlErr}, Response: " . substr($resp, 0, 500));
    }
}

// ── Fallback: OpenAI ──────────────────────────────────────────────────────────
$openaiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
$useOAI    = ($openaiKey && $openaiKey !== 'YOUR_OPENAI_API_KEY');

if ($useOAI) {
    $contentTrunc2 = mb_substr($content, 0, 6000);
    $domainSample2 = implode(', ', array_slice($domains, 0, 5));

    $sysP = "You are a website template analyzer. Given template content and its reference domain, extract ALL location-specific strings that need replacement for bulk deployment. Return ONLY JSON: {\"mapping\":{\"namalinkurl\":[],\"namalink\":[],\"daerah\":[],\"alamat\":[],\"notelp\":[],\"email\":[],\"embedmap\":[],\"linkmaps\":[],\"kodepos\":[],\"provinsi\":[],\"namainstansi\":[]},\"undetected\":[]}. Include ALL case variants (Title, lower, UPPER) of city names. Include exact strings as found.";

    $payload2 = json_encode([
        'model'       => 'gpt-4o-mini',
        'temperature' => 0,
        'max_tokens'  => 2000,
        'messages'    => [
            ['role'=>'system','content'=>$sysP],
            ['role'=>'user','content'=>"Domain: {$refDomain}\nTargets: {$domainSample2}\n\n{$contentTrunc2}"],
        ],
    ]);

    $ch2 = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload2,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Bearer '.$openaiKey],
    ]);
    $resp2    = curl_exec($ch2);
    $curlErr2 = curl_error($ch2);
    $code2    = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if (!$curlErr2 && $resp2 && $code2 === 200) {
        $json2  = json_decode($resp2, true);
        $raw2   = $json2['choices'][0]['message']['content'] ?? '';
        $raw2   = preg_replace('/^```json\s*|\s*```$/m', '', trim($raw2));
        $result2= json_decode($raw2, true);
        if (isset($result2['mapping'])) {
            echo buildResult($result2['mapping'], $result2['undetected'] ?? [], 'openai');
            exit;
        }
    } elseif ($curlErr2 || $code2 !== 200) {
        error_log("OpenAI API error: HTTP {$code2}, cURL: {$curlErr2}, Response: " . substr($resp2, 0, 500));
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// LOCAL REGEX FALLBACK — parse template content directly
// ══════════════════════════════════════════════════════════════════════════════
function localDetectFromContent(string $content, string $refDomain): array {
    $slug   = preg_replace('/\.[a-z.]{2,10}$/', '', $refDomain);
    $slugLC = strtolower($slug);

    $mapping    = [];
    $assigned   = [];
    $assign = function(string $field, string $s) use (&$mapping, &$assigned) {
        $s = trim($s);
        if (strlen($s) < 2 || isset($assigned[$s])) return;
        $mapping[$field][] = $s;
        $assigned[$s] = true;
    };

    // namalinkurl: all URLs containing the domain
    preg_match_all('#https?://[^\s"\'<>]+' . preg_quote($slugLC, '#') . '[^\s"\'<>]*#i', $content, $m);
    foreach ($m[0] as $v) $assign('namalinkurl', $v);

    // namalink: domain as text (with or without protocol)
    preg_match_all('#(?:href|canonical|url|src)=["\']([^"\']*' . preg_quote($refDomain, '#') . '[^"\']*)["\']#i', $content, $m2);
    foreach ($m2[1] as $v) $assign('namalinkurl', $v);
    $assign('namalink', $refDomain);
    $assign('namalink', $slug);

    // email
    preg_match_all('/[\w.\-+]+@[\w.\-]+\.[a-z]{2,}/i', $content, $m3);
    foreach ($m3[0] as $v) if (stripos($v, $slugLC) !== false || !str_contains($v,'gmail')) $assign('email', $v);

    // alamat: Indonesian address lines - ENHANCED DETECTION
    // Pattern 1: Complete addresses with Jl./Jalan
    preg_match_all('/(?:Jl|Jalan|Jln|Komplek|Komp|Perumahan|Perum|Gedung|Gd)\.?\s+[A-Z][^\n"\'<>{};]{15,350}/u', $content, $m4);
    foreach ($m4[0] as $v) {
        $clean = trim(preg_replace('/\s+/', ' ', $v));
        // Extend if it ends mid-sentence (capture until period, Indonesia, or postal code)
        if (preg_match('/'.preg_quote($clean, '/').'[^\.]{0,100}(?:Indonesia|\d{5})[^\.]{0,50}/u', $content, $ext)) {
            $clean = trim($ext[0]);
        }
        $assign('alamat', $clean);
    }

    // Pattern 2: Address in <address> tags
    preg_match_all('/<address[^>]*>([^<]{20,400})<\/address>/uis', $content, $m4b);
    foreach ($m4b[1] as $v) $assign('alamat', trim(preg_replace('/\s+/', ' ', strip_tags($v))));

    // Pattern 3: JSON-LD streetAddress
    preg_match_all('/"streetAddress"\s*:\s*"([^"]{15,350})"/ui', $content, $m4c);
    foreach ($m4c[1] as $v) $assign('alamat', trim($v));

    // Pattern 4: Footer/contact sections with address-like content
    preg_match_all('/<(?:footer|div)[^>]*(?:class|id)=["\'][^"\']*(?:alamat|address|kontak|contact|lokasi|location)[^"\']*["\'][^>]*>[\s\S]{0,800}<\/(?:footer|div)>/ui', $content, $m4d);
    foreach ($m4d[0] as $block) {
        // Extract address-like text from this block
        if (preg_match('/(?:Jl|Jalan|Jln)\.?\s+[A-Z][^\n"\'<>{};]{15,250}/u', $block, $addr)) {
            $assign('alamat', trim(preg_replace('/\s+/', ' ', $addr[0])));
        }
    }

    // notelp
    preg_match_all('/(?:\+62|62|08)\d{8,14}/', $content, $m5);
    foreach ($m5[0] as $v) $assign('notelp', $v);
    preg_match_all('/\(0\d{2,3}\)\s?\d{5,10}/', $content, $m5b);
    foreach ($m5b[0] as $v) $assign('notelp', $v);

    // embedmap
    preg_match_all('/https?:[^\s"\'<>]*maps[^\s"\'<>]{20,}pb=[^\s"\'<>]+/i', $content, $m6);
    foreach ($m6[0] as $v) $assign('embedmap', $v);

    // linkmaps
    preg_match_all('/https?://(?:www\.)?(?:maps\.google\.com|goo\.gl\/maps)[^\s"\'<>]+/i', $content, $m7);
    foreach ($m7[0] as $v) $assign('linkmaps', $v);

    // kodepos
    preg_match_all('/\b\d{5}\b/', $content, $m8);
    foreach ($m8[0] as $v) $assign('kodepos', $v);

    // daerah: extract location from slug
    $daerahBase = preg_replace('/^(bnn|bpbd|disdik|dinkes|dishub|kpud|kpu|rsud|lapas|polres|dispora|mpp|bkpsdm|disdikpora|dispendik|diknas|dikpora|satpol|damkar|pemadam|satpolpp|bkd|bappeda|dprd|pengadilan|kejaksaan|pemkot|pemkab|diskominfo|disdukcapil|dinsosnaker|disnaker|disnakertrans|kemenag|kanwil|uptd|uptpd|balai)/i', '', $slug);
    $daerahBase = preg_replace('/(kab|kota|prov)$/i', '', $daerahBase);
    $daerahBase = trim($daerahBase, '-_');
    if (strlen($daerahBase) >= 3) {
        // Find all case variants of this in the content
        preg_match_all('/\b' . preg_quote($daerahBase, '/') . '\b/i', $content, $m9);
        $variants = array_values(array_unique($m9[0]));
        foreach ($variants as $v) $assign('daerah', $v);
    }

    // namainstansi: lines containing institution patterns
    preg_match_all('/"name"\s*:\s*"([^"]{5,100})"/i', $content, $m10);
    foreach ($m10[1] as $v) {
        if (stripos($v, $daerahBase) !== false || stripos($v, $slug) !== false)
            $assign('namainstansi', $v);
    }
    preg_match_all('/<title>([^<]{5,120})<\/title>/i', $content, $m11);
    foreach ($m11[1] as $v) {
        if (stripos($v, $daerahBase) !== false) $assign('namainstansi', $v);
    }

    return ['mapping' => $mapping, 'undetected' => []];
}

$local = localDetectFromContent($content, $refDomain);
echo buildResult($local['mapping'], $local['undetected'], $useCloud || $useOAI ? 'local_fallback' : 'local');
