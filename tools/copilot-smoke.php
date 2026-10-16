<?php
// AutoCode Copilot — CLI smoke test for the Meta Model API wiring.
// Usage:  php tools/copilot-smoke.php
// 1) Always runs an OFFLINE self-test of the JSON extraction +
//    validation (no network, no key needed).
// 2) If config.php has a real MODEL_API_KEY, also calls
//    GET /v1/models and one tiny chat completion.
// The key is NEVER printed (only its length).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
if (file_exists($root . '/config.php')) {
    require_once $root . '/config.php';
}
require_once $root . '/lib/meta_api.php';

$fail = 0;

// --- Offline self-test -------------------------------------------------
$sample = 'Here is the result: {"vehicle":{"year":2015,"make":"Mercedes-Benz",'
    . '"model":"GLK 350","vin":"WDCGG5HB0FG338330"},'
    . '"dtcs":[{"code":"P0087","description":"Fuel Rail Pressure Too Low"},'
    . '{"code":"X1234","description":"bogus"}],'
    . '"diagnosis_summary_es":"Presión baja de combustible.",'
    . '"customer_explanation_en":"The fuel pressure is too low.",'
    . '"estimate_lines":[{"description":"Fuel system diagnosis"}]} done.';
$json = meta_extract_json($sample);
if ($json === null) {
    echo "SELF-TEST FAIL: could not extract JSON\n";
    $fail = 1;
} else {
    $norm = copilot_normalize($json);
    $okVin = ($norm['data']['vehicle']['vin'] ?? null) === 'WDCGG5HB0FG338330';
    $okDtc = count($norm['data']['dtcs']) === 1 && $norm['data']['dtcs'][0]['code'] === 'P0087';
    $okWarn = count($norm['warnings']) >= 1; // bogus DTC must be dropped with a warning
    if ($okVin && $okDtc && $okWarn) {
        echo "SELF-TEST PASS: JSON extraction, VIN/DTC validation and warnings all work.\n";
    } else {
        echo "SELF-TEST FAIL: vin=" . var_export($okVin, true) . " dtc=" . var_export($okDtc, true)
            . " warnings=" . var_export($okWarn, true) . "\n";
        $fail = 1;
    }
}

// --- Live check (only with a configured key) ---------------------------
if (!meta_api_configured()) {
    echo "API: not configured — set MODEL_API_KEY in config.php to run the live check.\n";
    exit($fail);
}
echo "API: key present (" . strlen(meta_api_key()) . " chars), base " . meta_api_base()
    . ", model " . meta_model_id() . "\n";
try {
    $models = meta_api_models();
    $ids = array_map(static fn($m) => (string)($m['id'] ?? ''), $models);
    echo "API: /v1/models OK — " . count($ids) . " models; configured model "
        . (in_array(meta_model_id(), $ids, true) ? 'IS' : 'is NOT') . " in the list.\n";
    $reply = meta_chat(
        [['role' => 'user', 'content' => 'Reply with exactly: OK']],
        ['reasoning_effort' => 'minimal', 'max_completion_tokens' => 20]
    );
    echo "API: chat completion OK — reply: " . substr(trim($reply), 0, 80) . "\n";
} catch (MetaApiException $ex) {
    echo "API FAIL: " . $ex->getMessage() . "\n";
    $fail = 1;
}
exit($fail);
