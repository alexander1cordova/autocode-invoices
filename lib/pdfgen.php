<?php
// ============================================================
// AutoCode Invoices — PDF builder (FPDF, no dependencies).
// Input: snapshot data array from build_snapshot_data().
// Output: PDF binary string. All text in English.
//
// Every document is automatically scaled to fit on a SINGLE
// Letter page, no matter how many line items it has.
// ============================================================

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/fpdf/fpdf.php';

if (!function_exists('clean_doc_text')) {
    /**
     * Prepare a long-form text block (diagnosis result, warranty) for display.
     * - Normalizes \r\n line endings.
     * - Drops a duplicated heading first line (headings render automatically).
     * - Unwraps hard single line breaks (e.g. from pasted text) so paragraphs
     *   flow and justify as one block. Blank lines (paragraphs) and list
     *   items (1. / - / bullets) are preserved.
     * (Defined here too because pdfgen.php is standalone — qa_pdfs.php loads
     * it without includes/functions.php.)
     */
    function clean_doc_text(string $text, string $title): string
    {
        $t = str_replace("\r\n", "\n", $text);
        $t = str_replace("\r", "\n", $t);
        $t = ltrim($t);
        $nl = strpos($t, "\n");
        $first = $nl === false ? $t : substr($t, 0, $nl);
        $norm_first = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $first));
        $norm_title = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $title));
        if ($first !== '' && $norm_first === $norm_title) {
            $t = $nl === false ? '' : substr($t, $nl + 1);
            $t = ltrim($t);
        }
        $t = (string)preg_replace('/(?<!\n)\n(?!\n)(?!\s*(?:\d+[.)]\s|[-•*]\s))/u', ' ', $t);
        return trim($t);
    }
}

/** Convert UTF-8 to something FPDF core fonts can render (latin-1). */
function pdf_text(?string $s): string
{
    $s = (string)$s;
    $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
    return $out === false ? (string)preg_replace('/[^\x20-\x7E]/', '', $s) : $out;
}

function pdf_money($n): string
{
    return '$' . number_format((float)$n, 2, '.', ',');
}

/** Binding statement printed next to the customer signature. */
function pdf_acceptance_text(): string
{
    return 'By writing my name and placing my signature, I accept the terms and conditions '
        . 'presented in this document and the services and prices provided.';
}

class JobPDF extends FPDF
{
    public $navy = [0, 64, 112];
    public $blue = [64, 160, 240];
    public $gray = [105, 115, 125];

    /** Usable content width: page width minus left/right margins. */
    public function usableWidth(): float
    {
        return $this->w - $this->lMargin - $this->rMargin;
    }

    /** Y position that triggers an automatic page break. */
    public function breakTrigger(): float
    {
        return $this->PageBreakTrigger;
    }

    public function Header()
    {
        // Only on first page; FPDF calls Header on every page — we want the
        // business band only on page 1, simple page numbers otherwise.
        if ($this->PageNo() === 1) {
            return; // band is drawn manually before content
        }
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(...$this->gray);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . ' / {nb}', 0, 0, 'C');
    }

    /** Number of lines a MultiCell of width $w will take for $txt. */
    public function nbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++; $sep = -1; $j = $i; $l = 0; $nl++;
                continue;
            }
            if ($c === ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 600;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    /** Table row where cells can wrap (MultiCell). */
    public function wrappedRow($widths, $data, $aligns, $lineH, $fill, $bold, $fontPt)
    {
        $this->SetFont('Helvetica', $bold ? 'B' : '', $fontPt);
        $nb = 0;
        foreach ($data as $i => $txt) {
            $nb = max($nb, $this->nbLines($widths[$i], $txt));
        }
        $h = $lineH * $nb;
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage();
        }
        foreach ($data as $i => $txt) {
            $x = $this->GetX();
            $y = $this->GetY();
            if ($fill) {
                $this->SetFillColor(...$this->navy);
                $this->SetTextColor(255, 255, 255);
            }
            $this->Rect($x, $y, $widths[$i], $h, $fill ? 'DF' : 'D');
            $this->MultiCell($widths[$i], $lineH, $txt, 0, $aligns[$i], $fill);
            $this->SetXY($x + $widths[$i], $y);
            if ($fill) {
                $this->SetTextColor(0, 0, 0);
            }
        }
        $this->SetFont('Helvetica', '', $fontPt);
        $this->Ln($h);
    }
}

/**
 * Build the document at scale $k (1.0 = full size). Returns the FPDF object
 * so the caller can check the page count before outputting.
 */
function build_job_pdf(array $snap, float $k): JobPDF
{
    // Scale helpers: $fs() for font sizes (floored at 6pt for legibility),
    // $mm() for heights, spacing and dimensions.
    $fs = static fn(float $pt): float => max(6.0, round($pt * $k, 1));
    $mm = static fn(float $v): float => round($v * $k, 2);

    $pdf = new JobPDF('P', 'mm', 'Letter');
    $pdf->AliasNbPages();
    $pdf->SetMargins(12, 10, 12);
    $pdf->AddPage();

    // ---- Business header band ----
    $pdf->SetFillColor(...$pdf->navy);
    $pdf->Rect(0, 0, 216, $mm(32), 'F');
    $pdf->SetY($mm(6));
    $pdf->SetX(12);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', $fs(20));
    $pdf->Cell(0, $mm(10), pdf_text($snap['business']['name']), 0, 1);
    $pdf->SetFont('Helvetica', '', $fs(10));
    $pdf->SetX(12);
    $pdf->Cell(0, $mm(6), pdf_text($snap['business']['address']), 0, 1);
    $pdf->SetX(12);
    $pdf->Cell(0, $mm(6), pdf_text($snap['business']['phone']), 0, 1);
    $pdf->SetY($mm(36));

    // ---- Document title ----
    $pdf->SetTextColor(...$pdf->navy);
    $pdf->SetFont('Helvetica', 'B', $fs(17));
    $pdf->Cell(0, $mm(10), pdf_text(strtoupper($snap['title'])), 0, 1);
    $pdf->SetFont('Helvetica', '', $fs(10));
    $pdf->SetTextColor(...$pdf->gray);
    $pdf->Cell(
        0, $mm(6),
        pdf_text('Finalized: ' . date('M j, Y g:i A', strtotime($snap['finalized_at']))),
        0, 1
    );
    $pdf->Ln($mm(3));

    // ---- Customer / vehicle ----
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', 'B', $fs(11));
    $pdf->SetFillColor(235, 243, 250);
    $pdf->Cell(93, $mm(8), 'Customer', 0, 0, 'L', true);
    $pdf->Cell(6, $mm(8), '', 0, 0);
    $pdf->Cell(93, $mm(8), 'Vehicle', 0, 1, 'L', true);
    $pdf->SetFont('Helvetica', '', $fs(10));
    $c = $snap['client'];
    $v = $snap['vehicle'];
    $cLines = [
        pdf_text($c['name'] ?? ''),
        pdf_text($c['phone'] ?? ''),
        pdf_text($c['email'] ?? ''),
        pdf_text($c['address'] ?? ''),
    ];
    $vLines = $v ? [
        pdf_text(trim(($v['year'] ?? '') . ' ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? ''))),
        pdf_text('Plate: ' . ($v['plate'] ?? '—') . '   VIN: ' . ($v['vin'] ?? '—')),
        pdf_text('Mileage: ' . ($v['mileage'] ?? '—') . '   Color: ' . ($v['color'] ?? '—')),
        '',
    ] : [pdf_text('—'), '', '', ''];
    $y0 = $pdf->GetY();
    $x0 = $pdf->GetX();
    $pdf->MultiCell(93, $mm(6), implode("\n", array_filter($cLines, static fn($l) => $l !== '')), 0, 'L');
    $y1 = $pdf->GetY();
    $pdf->SetXY($x0 + 99, $y0);
    $pdf->MultiCell(93, $mm(6), implode("\n", array_filter($vLines, static fn($l) => $l !== '')), 0, 'L');
    $pdf->SetY(max($y1, $pdf->GetY()) + $mm(3));

    // ---- Problem ----
    $pdf->SetFont('Helvetica', 'B', $fs(11));
    $pdf->Cell(0, $mm(7), 'Problem / requested work:', 0, 1);
    $pdf->SetFont('Helvetica', '', $fs(10));
    $pdf->MultiCell(0, $mm(6), pdf_text($snap['job']['problem_title']), 0, 'L');
    $pdf->Ln($mm(3));

    // ---- Lines table ----
    $widths = [12, 104, 22, 26, 28]; // #, description, qty, unit, total
    $pdf->wrappedRow(
        $widths,
        ['#', 'DESCRIPTION', 'QTY', 'UNIT PRICE', 'TOTAL'],
        ['C', 'L', 'C', 'R', 'R'],
        $mm(7), true, true, $fs(10)
    );
    $n = 0;
    foreach ($snap['lines'] as $l) {
        $n++;
        $qty = rtrim(rtrim(number_format((float)$l['qty'], 2, '.', ''), '0'), '.');
        $pdf->wrappedRow(
            $widths,
            [
                (string)$n,
                pdf_text($l['description']),
                $qty,
                pdf_money($l['unit_price']),
                pdf_money($l['line_total']),
            ],
            ['C', 'L', 'C', 'R', 'R'],
            $mm(6), false, false, $fs(10)
        );
    }
    if ($n === 0) {
        $pdf->SetFont('Helvetica', 'I', $fs(10));
        $pdf->Cell(0, $mm(8), 'No lines.', 0, 1);
    }

    // ---- Totals ----
    $pdf->Ln($mm(2));
    $rightX = 216 - 12 - 70;
    $tot = $snap['totals'];
    $pdf->SetFont('Helvetica', '', $fs(10));
    $pdf->SetX($rightX);
    $pdf->Cell(40, $mm(7), 'Subtotal:', 0, 0, 'R');
    $pdf->Cell(30, $mm(7), pdf_money($tot['subtotal']), 0, 1, 'R');
    $pdf->SetX($rightX);
    $pdf->Cell(40, $mm(7), 'Tax (' . rtrim(rtrim((string)$snap['tax_rate'], '0'), '.') . '%):', 0, 0, 'R');
    $pdf->Cell(30, $mm(7), pdf_money($tot['tax']), 0, 1, 'R');
    $pdf->SetFont('Helvetica', 'B', $fs(13));
    $pdf->SetTextColor(...$pdf->navy);
    $pdf->SetX($rightX);
    $pdf->Cell(40, $mm(9), 'TOTAL:', 0, 0, 'R');
    $pdf->Cell(30, $mm(9), pdf_money($tot['total']), 0, 1, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln($mm(4));

    // ---- Diagnosis result (invoice only; shown only when the admin wrote something) ----
    // Centered title + justified body, tight single line spacing (Migue's preference).
    $diagnosis = clean_doc_text((string)($snap["diagnosis"] ?? ""), "Specialized Diagnostic Results");
    if ($snap['stage'] === 'invoice' && $diagnosis !== '') {
        $pdf->SetFont('Helvetica', 'B', $fs(11));
        $pdf->SetTextColor(...$pdf->navy);
        $pdf->Cell(0, $mm(7), 'Specialized Diagnostic Results', 0, 1, 'C');
        $pdf->Ln($mm(2));
        $pdf->SetFont('Helvetica', '', $fs(10));
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(0, $mm(4), pdf_text($diagnosis), 0, 'J');
        $pdf->Ln($mm(4));
    }

    // ---- Payment (invoice only; method/notes recorded by the admin) ----
    $pay = $snap['payment'] ?? null;
    $pay_method = trim((string)($pay['method'] ?? ''));
    $pay_notes = trim((string)($pay['notes'] ?? ''));
    if ($snap['stage'] === 'invoice' && ($pay_method !== '' || $pay_notes !== '')) {
        $pdf->SetFont('Helvetica', 'B', $fs(11));
        $pdf->SetTextColor(...$pdf->navy);
        $pdf->Cell(0, $mm(8), 'Payment', 0, 1);
        $pdf->SetFont('Helvetica', '', $fs(10));
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, $mm(6), pdf_text('Method: ' . $pay_method), 0, 1);
        if ($pay_notes !== '') {
            $pdf->Cell(0, $mm(6), pdf_text('Notes: ' . $pay_notes), 0, 1);
        }
        $pdf->Ln($mm(4));
    }

    // ---- Warranty & Policies (authorization + invoice; NOT on estimates) ----
    // Centered title + justified body, tight single line spacing (Migue's preference).
    $warranty_text = clean_doc_text((string)($snap["warranty"] ?? ""), "Warranty & Policies");
    if (in_array($snap['stage'], ['authorization', 'invoice'], true) && $warranty_text !== '') {
        $pdf->SetFont('Helvetica', 'B', $fs(11));
        $pdf->SetTextColor(...$pdf->navy);
        $pdf->Cell(0, $mm(7), 'Warranty & Policies', 0, 1, 'C');
        $pdf->Ln($mm(2));
        $pdf->SetFont('Helvetica', '', $fs(9));
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(0, $mm(3.5), pdf_text($warranty_text), 0, 'J');
        $pdf->Ln($mm(4));
    }

    // ---- Signature (kept together: never split across pages) ----
    if (!empty($snap['signature'])) {
        $sig = $snap['signature'];

        // Measure the signature image first (max 70mm wide, 30mm tall).
        $imgPath = __DIR__ . '/../' . $sig['image_path'];
        $w = 0.0;
        $h = 0.0;
        if (is_file($imgPath)) {
            $size = @getimagesize($imgPath);
            $w = $mm(70);
            $h = $mm(30);
            if ($size && $size[0] > 0) {
                $h = round($w * $size[1] / $size[0], 2);
                $maxH = $mm(30);
                if ($h > $maxH) {
                    $h = $maxH;
                    $w = round($h * $size[0] / $size[1], 2);
                }
            }
        }

        // Estimate the whole signature block height; if it does not fit in
        // the remaining space, force a page break here. That makes the page
        // count 2, which tells the fit-loop to shrink everything and retry.
        $pdf->SetFont('Helvetica', '', $fs(9));
        $accLines = $pdf->nbLines(
            $pdf->usableWidth(),
            pdf_text(pdf_acceptance_text())
        );
        $blockH = $mm(8) + $mm(6) + $mm(6)
            + ($h > 0 ? $mm(2) + $h + $mm(2) : 0)
            + $accLines * $mm(5) + $mm(4);
        if ($pdf->GetY() + $blockH > $pdf->breakTrigger()) {
            $pdf->AddPage();
        }

        $pdf->SetFont('Helvetica', 'B', $fs(11));
        $pdf->SetTextColor(...$pdf->navy);
        $label = $snap['stage'] === 'invoice' ? 'Customer acceptance' : 'Customer authorization';
        $pdf->Cell(0, $mm(8), $label, 0, 1);
        $pdf->SetFont('Helvetica', '', $fs(10));
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, $mm(6), pdf_text('Signed by: ' . $sig['signer_name']), 0, 1);
        $pdf->Cell(
            0, $mm(6),
            pdf_text('Date: ' . date('M j, Y g:i A', strtotime($sig['signed_at']))),
            0, 1
        );
        if ($h > 0) {
            $pdf->Ln($mm(2));
            $pdf->Image($imgPath, $pdf->GetX(), $pdf->GetY(), $w, $h, 'PNG');
            $pdf->Ln($h + $mm(2));
        }
        $pdf->SetFont('Helvetica', '', $fs(9));
        $pdf->MultiCell(0, $mm(5), pdf_text(pdf_acceptance_text()), 0, 'L');
    }

    return $pdf;
}

/**
 * Render the snapshot to PDF, shrinking everything proportionally until
 * the whole document fits on a single page.
 *
 * @param array $snap snapshot data from build_snapshot_data()
 */
function job_pdf(array $snap): string
{
    $k = 1.0;
    $pdf = build_job_pdf($snap, $k);
    while ($pdf->PageNo() > 1 && $k > 0.5) {
        $k = round($k - 0.05, 2);
        $pdf = build_job_pdf($snap, $k);
    }
    return $pdf->Output('S');
}
