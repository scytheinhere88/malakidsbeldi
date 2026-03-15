<?php
/**
 * AIDomainParser - Smart Dual AI System
 * PRIMARY: OpenAI (fast, proven, reliable)
 * FALLBACK: Claude (intelligent backup when OpenAI fails)
 * LAST RESORT: Regex parser
 */
class AIDomainParser {
    private string $openaiKey = '';
    private string $claudeKey = '';
    private string $primaryModel = 'gpt-4o-mini';
    private string $fallbackModel = 'claude-3-5-sonnet-20240620';
    private int $batchSize = 30;
    private static array $cache = [];
    private static int $openaiFailures = 0;
    private static int $claudeFailures = 0;

    public function __construct() {
        // Setup OpenAI (PRIMARY)
        if (defined('OPENAI_API_KEY') && !in_array(OPENAI_API_KEY, ['', 'YOUR_OPENAI_API_KEY'])) {
            $this->openaiKey = OPENAI_API_KEY;
        }

        // Setup Claude (SMART FALLBACK)
        if (defined('ANTHROPIC_API_KEY') && !in_array(ANTHROPIC_API_KEY, ['', 'YOUR_ANTHROPIC_API_KEY'])) {
            $this->claudeKey = ANTHROPIC_API_KEY;
        }
    }

    public function isAvailable(): bool {
        return !empty($this->openaiKey) || !empty($this->claudeKey);
    }

    public function parseBatch(array $domains, string $keywordHint = '', $progressCallback = null): array {
        if (!$this->isAvailable()) {
            return $this->regexFallback($domains, $keywordHint);
        }

        $results = [];
        $toAsk = [];

        // Check cache first
        foreach ($domains as $d) {
            $key = strtolower(trim($d));
            if (isset(self::$cache[$key])) {
                $results[$key] = self::$cache[$key];
            } else {
                $toAsk[] = $key;
            }
        }

        if (empty($toAsk)) {
            return $results;
        }

        // Process in batches
        $chunks = array_chunk($toAsk, $this->batchSize);
        $totalDomains = count($domains);
        $processedCount = count($results);

        foreach ($chunks as $i => $chunk) {
            if ($progressCallback) {
                $progressCallback($processedCount, $totalDomains, 'chunk_start');
            }

            $batchResults = $this->callAI($chunk, $keywordHint);

            // Cache results and report progress per domain
            foreach ($batchResults as $k => $v) {
                self::$cache[$k] = $v;
                $processedCount++;

                if ($progressCallback) {
                    $progressCallback($processedCount, $totalDomains, 'ai_domain', $v);
                }
            }

            $results = array_merge($results, $batchResults);
        }

        return $results;
    }

    public function parseOne(string $domain, string $keywordHint = ''): array {
        $r = $this->parseBatch([$domain], $keywordHint);
        return $r[strtolower(trim($domain))] ?? DataScraper::parseDomain($domain, $keywordHint);
    }

    /**
     * SMART AI CALLER - Tries OpenAI first, falls back to Claude if needed
     */
    private function callAI(array $domains, string $keywordHint): array {
        // Try OpenAI first (PRIMARY - faster, cheaper)
        if (!empty($this->openaiKey) && self::$openaiFailures < 3) {
            $result = $this->callOpenAI($domains, $keywordHint);
            if ($result !== false) {
                self::$openaiFailures = 0; // Reset on success
                return $result;
            }
            self::$openaiFailures++;
        }

        // Fallback to Claude (SMART BACKUP - more powerful)
        if (!empty($this->claudeKey) && self::$claudeFailures < 3) {
            $result = $this->callClaude($domains, $keywordHint);
            if ($result !== false) {
                self::$claudeFailures = 0; // Reset on success
                return $result;
            }
            self::$claudeFailures++;
        }

        // Last resort: Regex parser
        return $this->regexFallback($domains, $keywordHint);
    }

    /**
     * OpenAI API Call (PRIMARY)
     */
    private function callOpenAI(array $domains, string $keywordHint) {
        $domainList = implode("\n", array_map(
            fn($i, $d) => ($i + 1) . '. ' . $d,
            array_keys($domains), $domains
        ));

        $keywordInstr = $this->buildKeywordHint($keywordHint);
        $prompt = $this->buildPrompt($keywordInstr);

        $payload = json_encode([
            'model' => $this->primaryModel,
            'temperature' => 0,
            'max_tokens' => 3000,
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => "Parse:\n\n" . $domainList],
            ],
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->openaiKey,
            ],
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$resp) {
            return false; // Signal failure to trigger fallback
        }

        $data = json_decode($resp, true);
        if (!$data || !isset($data['choices'][0]['message']['content'])) {
            return false;
        }

        $content = $data['choices'][0]['message']['content'];
        return $this->parseJSON($content, $domains, $keywordHint);
    }

    /**
     * Claude API Call (SMART FALLBACK)
     */
    private function callClaude(array $domains, string $keywordHint) {
        $domainList = implode("\n", array_map(
            fn($i, $d) => ($i + 1) . '. ' . $d,
            array_keys($domains), $domains
        ));

        $keywordInstr = $this->buildKeywordHint($keywordHint);
        $prompt = $this->buildPrompt($keywordInstr);

        $payload = json_encode([
            'model' => $this->fallbackModel,
            'max_tokens' => 4096,
            'temperature' => 0,
            'messages' => [
                ['role' => 'user', 'content' => $prompt . "\n\nParse:\n\n" . $domainList],
            ],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->claudeKey,
                'anthropic-version: 2023-06-01',
            ],
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$resp) {
            return false;
        }

        $data = json_decode($resp, true);
        if (!$data || !isset($data['content'][0]['text'])) {
            return false;
        }

        $content = $data['content'][0]['text'];
        return $this->parseJSON($content, $domains, $keywordHint);
    }

    /**
     * Build keyword hint instruction
     */
    private function buildKeywordHint(string $keywordHint): string {
        if (empty($keywordHint)) {
            return '';
        }

        $upper = strtoupper($keywordHint);
        return "\n━━━ KEYWORD HINT — CRITICAL ━━━\n"
            . "ALL domains contain: \"{$keywordHint}\"\n"
            . "MUST:\n"
            . "1. Remove keyword from domain before extracting location\n"
            . "2. Use EXACTLY \"{$upper}\" (uppercase) as institution for ALL\n"
            . "3. Parse remaining text as location\n"
            . "4. NEVER modify keyword\n\n";
    }

    /**
     * Build system prompt
     */
    private function buildPrompt(string $keywordHint): string {
        return <<<PROMPT
Indonesian domain parser. Parse government, organization, school, hospital, business domains.
{$keywordHint}
CRITICAL RULES:
1. PRESERVE EXACT SPELLING - Use exact location name from domain
2. NO HALLUCINATION - "karangpilang" → "Karangpilang" (NOT "Karang Pilang")
3. NO AUTO-CORRECTION - "kotaagung" → "Kotaagung" (NOT "Kota Agung")
4. Compound words are single: Karangpilang, Tulangbawang, Gadingserpong

LOCATION LEVELS:
- nasional: no specific location
- provinsi: province name/abbreviation
- kabupaten: has "kab"/"kabupaten" → prefix "Kab."
- kota: has "kota" → prefix "Kota"
- kecamatan: subdistrict name
- kelurahan: village/neighborhood

OUTPUT (JSON only, no markdown):
{"results":[{"domain":"exact","institution":"SHORT","institution_full":"FULL NAME","location_display":"Proper Format","location_level":"level","province":"Province Name","location_slug":"lowercase","keyword":"slug-with-hyphens","search_query":"Google optimized","email_slug":"clean"}]}

EXAMPLES:
ksbsibungo.org → institution:"KSBSI", location_display:"Bungo"
ksbsikotabandung.org → institution:"KSBSI", location_display:"Kota Bandung"
ksbsikarangpilang.org → institution:"KSBSI", location_display:"Karangpilang" (NOT "Karang Pilang")

RESPOND with valid JSON ONLY - no explanation, no markdown.
PROMPT;
    }

    /**
     * Parse JSON from AI response
     */
    private function parseJSON(string $content, array $domains, string $keywordHint) {
        // Extract JSON from markdown or text
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $m)) {
            $content = $m[1];
        } elseif (preg_match('/(\{.*"results".*\})/s', $content, $m)) {
            $content = $m[1];
        }

        $parsed = json_decode($content, true);
        if (!$parsed || !isset($parsed['results'])) {
            return false; // Signal parse failure
        }

        return $this->normalizeResults($parsed['results']);
    }

    /**
     * Normalize AI results to standard format
     */
    private function normalizeResults(array $results): array {
        $out = [];
        foreach ($results as $r) {
            $d = strtolower(trim($r['domain'] ?? ''));
            if (!$d) continue;

            $emailSlug = $r['email_slug'] ?? preg_replace('/[^a-z0-9]/', '', explode('.', $d)[0]);

            $keyword = $r['keyword'] ?? 'data';
            $locationSlug = $r['location_slug'] ?? '';
            $cacheKey = md5($keyword . '|' . $locationSlug);

            $out[$d] = [
                'full_domain' => $d,
                'raw_main' => explode('.', $d)[0],
                'institution' => $r['institution'] ?? '',
                'institution_full' => $r['institution_full'] ?? $r['institution'] ?? '',
                'location_display' => $r['location_display'] ?? '',
                'location_level' => $r['location_level'] ?? 'kota',
                'province' => $r['province'] ?? '',
                'location_slug' => $locationSlug,
                'keyword' => $keyword,
                'search_query' => $r['search_query'] ?? '',
                'email_domain' => $d,
                'email_slug' => $emailSlug,
                'cache_key' => $cacheKey,
                'parse_source' => 'ai',
            ];
        }
        return $out;
    }

    /**
     * Regex fallback when both AIs fail
     */
    private function regexFallback(array $domains, string $keywordHint): array {
        $out = [];
        foreach ($domains as $d) {
            $fb = DataScraper::parseDomain($d, $keywordHint);
            $fb['parse_source'] = 'regex';
            $out[$d] = $fb;
        }
        return $out;
    }

    /**
     * Get system stats (for monitoring)
     */
    public static function getStats(): array {
        return [
            'cache_size' => count(self::$cache),
            'openai_failures' => self::$openaiFailures,
            'claude_failures' => self::$claudeFailures,
            'primary_active' => self::$openaiFailures < 3,
            'fallback_active' => self::$claudeFailures < 3,
        ];
    }
}
