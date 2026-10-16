<?php
// AutoCode Copilot — scan-to-job AI assist (SKELETON, Oct 2026).
// Flow: photo of the scan report + the technician's note in Spanish
//   -> Meta Model API extraction (strict JSON)
//   -> HUMAN REVIEW on this page. Nothing is written to the database
//      in this step; creating the job from an approved draft is the
//      next step and will reuse the existing job/estimate flow.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/lib/meta_api.php';
require_login();

$configured = meta_api_configured();
$draft = $_SESSION['copilot_draft'] ?? null;
$draft_warnings = $_SESSION['copilot_draft_warnings'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'analyze') {
    csrf_check();
    if (!$configured) {
        set_flash_err('Copilot is not configured yet: set MODEL_API_KEY in config.php.');
        redirect('copilot.php');
    }
    $note = post('note');
    $content = [];
    if ($note !== '') {
        $content[] = ['type' => 'text', 'text' => "Technician's note (Spanish):\n" . $note];
    }
    // Optional report photo -> multimodal content part (base64 data URL).
    if (!empty($_FILES['report_photo']['tmp_name']) && is_uploaded_file($_FILES['report_photo']['tmp_name'])) {
        $f = $_FILES['report_photo'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if ($f['size'] > 8 * 1024 * 1024) {
            set_flash_err('That photo is larger than 8 MB — please retake or compress it.');
            redirect('copilot.php');
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            set_flash_err('The report photo must be a JPEG, PNG or WebP image.');
            redirect('copilot.php');
        }
        $content[] = [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($f['tmp_name']))],
        ];
    }
    if ($content === []) {
        set_flash_err('Add a note or a report photo first.');
        redirect('copilot.php');
    }
    try {
        $reply = meta_chat(
            [
                ['role' => 'system', 'content' => copilot_system_prompt()],
                ['role' => 'user', 'content' => $content],
            ],
            ['reasoning_effort' => 'low', 'max_completion_tokens' => 2000]
        );
        $json = meta_extract_json($reply);
        if ($json === null) {
            set_flash_err('The model did not return valid JSON. Nothing was saved — try again.');
        } else {
            $norm = copilot_normalize($json);
            $_SESSION['copilot_draft'] = $norm['data'];
            $_SESSION['copilot_draft_warnings'] = $norm['warnings'];
            set_flash('Draft ready — review everything below before using it.');
        }
    } catch (MetaApiException $ex) {
        set_flash_err($ex->getMessage());
    }
    redirect('copilot.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'discard') {
    csrf_check();
    unset($_SESSION['copilot_draft'], $_SESSION['copilot_draft_warnings']);
    set_flash('Draft discarded.');
    redirect('copilot.php');
}

page_header('Copilot', '');
?>
<div class="card">
  <h2>AutoCode Copilot <span class="hint">— scan-to-job assist (beta skeleton)</span></h2>
  <?php if (!$configured): ?>
    <p><strong>Copilot is OFF.</strong> No Meta Model API key is configured yet.</p>
    <p class="hint">To enable it: create a key in the Meta developer dashboard
      (dev.meta.ai → API keys), then set <code>MODEL_API_KEY</code> in this app's
      <code>config.php</code> on the server (never in the code, never in Git).
      You can verify the key any time with <code>php tools/copilot-smoke.php</code>.</p>
  <?php else: ?>
    <p class="hint">Connected model: <code><?= e(meta_model_id()) ?></code> via
      <code><?= e(meta_api_base()) ?></code>. Everything the model returns is a
      <strong>draft for your review</strong> — nothing is saved to a job from this page yet.</p>
    <form method="post" action="copilot.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="analyze">
      <label for="report_photo">Photo of the scan report (optional)</label>
      <input type="file" id="report_photo" name="report_photo" accept="image/jpeg,image/png,image/webp">
      <label for="note">Your note / dictated findings (Spanish is fine)</label>
      <textarea id="note" name="note" style="min-height:120px" placeholder="Ej.: GLK 350 2015, no arranca, código P0087, presión baja de combustible en riel…"></textarea>
      <div class="actions"><button class="btn navy" type="submit">Analyze with AI</button></div>
    </form>
  <?php endif; ?>
</div>

<?php if ($draft !== null): ?>
<div class="card">
  <h2>Review draft <span class="hint">— check every field against the car before using it</span></h2>
  <?php foreach ($draft_warnings as $w): ?>
    <p class="hint" style="color:#b00">⚠ <?= e($w) ?></p>
  <?php endforeach; ?>
  <table>
    <tr><th>Vehicle</th>
      <td><?= e(trim(($draft['vehicle']['year'] ?? '') . ' ' . ($draft['vehicle']['make'] ?? '') . ' ' . ($draft['vehicle']['model'] ?? ''))) ?: '—' ?></td></tr>
    <tr><th>VIN</th><td><?= e($draft['vehicle']['vin'] ?? '') ?: '—' ?></td></tr>
    <tr><th>Fault codes</th>
      <td><?php if (($draft['dtcs'] ?? []) === []): ?>—<?php else: ?>
        <?php foreach ($draft['dtcs'] as $d): ?>
          <div><strong><?= e($d['code']) ?></strong><?= $d['description'] ? ' — ' . e($d['description']) : '' ?></div>
        <?php endforeach; ?><?php endif; ?></td></tr>
    <tr><th>Diagnosis (ES)</th><td><?= nl2br(e($draft['diagnosis_summary_es'] ?? '')) ?: '—' ?></td></tr>
    <tr><th>For the customer (EN)</th><td><?= nl2br(e($draft['customer_explanation_en'] ?? '')) ?: '—' ?></td></tr>
    <tr><th>Suggested estimate lines</th>
      <td><?php if (($draft['estimate_lines'] ?? []) === []): ?>—<?php else: ?>
        <ul><?php foreach ($draft['estimate_lines'] as $l): ?><li><?= e($l['description']) ?></li><?php endforeach; ?></ul>
        <span class="hint">Descriptions only — you set the prices, as always.</span><?php endif; ?></td></tr>
  </table>
  <form method="post" action="copilot.php" style="margin-top:12px">
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="discard">
    <div class="actions">
      <button class="btn" type="submit">Discard draft</button>
      <button class="btn navy" type="button" disabled title="Next step">Create job from draft — coming next</button>
    </div>
  </form>
</div>
<?php endif; ?>
<?php page_footer(); ?>
