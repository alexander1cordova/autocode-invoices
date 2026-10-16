<?php
// ============================================================
// AutoCode Copilot — Meta Model API client (skeleton, Oct 2026)
//
// Single place that talks to the Meta Model API
// (https://api.meta.ai/v1, OpenAI-compatible Chat Completions).
// The API key lives ONLY in config.php (MODEL_API_KEY), which is
// git-ignored and .htaccess-blocked. Never hard-code a key here.
//
// Used by copilot.php (technician review flow) and by
// tools/copilot-smoke.php (CLI connectivity check).
// ============================================================

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

class MetaApiException extends Exception {}

function meta_api_base(): string
{
    return rtrim(defined('META_MODEL_API_BASE') ? (string)META_MODEL_API_BASE : 'https://api.meta.ai/v1', '/');
}

function meta_model_id(): string
{
    return defined('META_MODEL_ID') ? (string)META_MODEL_ID : 'muse-spark-1.3';
}

function meta_api_key(): string
{
    return defined('MODEL_API_KEY') ? trim((string)MODEL_API_KEY) : '';
}

/** True only when a real-looking key is configured (not the sample placeholder). */
function meta_api_configured(): bool
{
    $k = meta_api_key();
    return $k !== '' && stripos($k, 'REPLACE_WITH') === false;
}

/**
 * Low-level request. Returns the decoded JSON body as array.
 * Retries ONCE on HTTP 429 / 5xx. Throws MetaApiException with a
 * key-free message on any failure.
 */
function meta_api_request(string $path, ?array $payload = null): array
{
    if (!meta_api_configured()) {
        throw new MetaApiException('Meta Model API is not configured: set MODEL_API_KEY in config.php.');
    }
    if (!function_exists('curl_init')) {
        throw new MetaApiException('The PHP cURL extension is not enabled on this server (required by Copilot).');
    }
    $url = meta_api_base() . '/' . ltrim($path, '/');
    $isPost = $payload !== null;
    $body = $isPost ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    $attempt = 0;
    while (true) {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $isPost ? 'POST' : 'GET',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . meta_api_key(),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 90,
        ]);
        if ($isPost) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new MetaApiException('Network error talking to Meta Model API: ' . $cerr);
        }
        if (($status === 429 || $status >= 500) && $attempt < 2) {
            usleep(800000); // brief backoff, one retry only
            continue;
        }
        $data = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300) {
            $msg = is_array($data) ? (string)($data['error']['message'] ?? 'HTTP ' . $status) : 'HTTP ' . $status;
            throw new MetaApiException('Meta Model API error: ' . $msg);
        }
        if (!is_array($data)) {
            throw new MetaApiException('Meta Model API returned a non-JSON response.');
        }
        return $data;
    }
}

/** GET /v1/models — used by the smoke test to confirm the key works. */
function meta_api_models(): array
{
    $data = meta_api_request('models');
    return is_array($data['data'] ?? null) ? $data['data'] : [];
}

/**
 * One chat completion. $messages is the OpenAI-style array; a message
 * content may be a string or an array of parts (text / image_url) for
 * multimodal input. Returns the assistant's text content.
 */
function meta_chat(array $messages, array $opts = []): string
{
    $payload = [
        'model'    => (string)($opts['model'] ?? meta_model_id()),
        'messages' => $messages,
    ];
    if (isset($opts['reasoning_effort'])) {
        $payload['reasoning_effort'] = (string)$opts['reasoning_effort']; // minimal|low|medium|high|xhigh
    }
    if (isset($opts['max_completion_tokens'])) {
        $payload['max_completion_tokens'] = (int)$opts['max_completion_tokens'];
    }
    $data = meta_api_request('chat/completions', $payload);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new MetaApiException('Meta Model API returned an empty completion.');
    }
    return $content;
}

/** Extract the first JSON object from a model reply (tolerates ```json fences / prose). */
function meta_extract_json(string $content): ?array
{
    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Normalize + validate a Copilot extraction result.
 * Hard rules: unknown stays null, DTCs must match the OBD format,
 * VIN must match the VIN charset/length. Prices are NEVER taken
 * from the model — estimate lines carry descriptions only.
 * Returns ['data' => array, 'warnings' => string[]].
 */
function copilot_normalize(array $in): array
{
    $warnings = [];
    $str = static function ($v): ?string {
        $v = is_string($v) ? trim($v) : null;
        return ($v === null || $v === '') ? null : $v;
    };

    $veh = is_array($in['vehicle'] ?? null) ? $in['vehicle'] : [];
    $vin = $str($veh['vin'] ?? null);
    if ($vin !== null) {
        $vin = strtoupper($vin);
        if (!preg_match('/^[A-HJ-NPR-Z0-9]{11,17}$/', $vin)) {
            $warnings[] = 'VIN from the model failed format validation and was dropped: ' . $vin;
            $vin = null;
        }
    }

    $dtcs = [];
    foreach ((array)($in['dtcs'] ?? []) as $d) {
        $code = is_array($d) ? strtoupper((string)($d['code'] ?? '')) : strtoupper((string)$d);
        if (preg_match('/^[PBCU][0-9]{4}$/', $code)) {
            $dtcs[] = ['code' => $code, 'description' => $str(is_array($d) ? ($d['description'] ?? null) : null)];
        } elseif ($code !== '') {
            $warnings[] = 'Dropped a value that is not a valid DTC: ' . $code;
        }
    }

    $lines = [];
    foreach ((array)($in['estimate_lines'] ?? []) as $l) {
        $desc = $str(is_array($l) ? ($l['description'] ?? null) : $l);
        if ($desc !== null) {
            $lines[] = ['description' => $desc];
        }
    }

    return [
        'data' => [
            'vehicle' => [
                'year'  => isset($veh['year']) && is_numeric($veh['year']) ? (int)$veh['year'] : null,
                'make'  => $str($veh['make'] ?? null),
                'model' => $str($veh['model'] ?? null),
                'vin'   => $vin,
            ],
            'dtcs'                  => $dtcs,
            'diagnosis_summary_es'  => $str($in['diagnosis_summary_es'] ?? null),
            'customer_explanation_en' => $str($in['customer_explanation_en'] ?? null),
            'estimate_lines'        => $lines,
        ],
        'warnings' => $warnings,
    ];
}

/** System prompt for the scan-report extraction. One source of truth. */
function copilot_system_prompt(): string
{
    return 'You are the extraction engine for AutoCode Copilot, used by a mobile '
        . 'automotive electronics technician. From the technician\'s Spanish voice-note '
        . 'text and (optionally) a photo of a scan-tool report, return ONLY a JSON object '
        . 'with this shape: {"vehicle":{"year":null,"make":null,"model":null,"vin":null},'
        . '"dtcs":[{"code":"P0000","description":""}],'
        . '"diagnosis_summary_es":"", "customer_explanation_en":"", '
        . '"estimate_lines":[{"description":""}]}. '
        . 'HARD RULES: never invent data — any field you cannot read in the note or the '
        . 'photo must be null or omitted. DTC codes must be copied exactly as shown. '
        . 'estimate_lines describe the work in English and NEVER include prices. '
        . 'customer_explanation_en is plain, honest English a car owner can understand. '
        . 'Return JSON only, no prose, no markdown fences.';
}
