<?php
/**
 * libs/fpdf/PalmasPDFReport.php
 * Official Executive PDF Report Generator for Palma's Elite Gym ERP
 */

require_once __DIR__ . '/fpdf.php';

class PalmasPDFReport extends FPDF
{
    public string $reportTitle = 'Executive Management Report';
    public string $dateRange = 'All Time';
    public string $docRef = '';
    public string $auditorName = 'Authorized Administrator';
    public array $summaryStats = [];

    function __construct($orientation = 'P', $title = '', $dateRange = '', $docRef = '', $auditor = '')
    {
        parent::__construct($orientation, 'mm', 'A4');
        $this->reportTitle = $title ?: $this->reportTitle;
        $this->dateRange = $dateRange ?: $this->dateRange;
        $this->docRef = $docRef ?: ('PEG-RPT-' . date('Ymd-His'));
        $this->auditorName = $auditor ?: $this->auditorName;
        $this->SetAutoPageBreak(true, 18);
        $this->AliasNbPages();
    }

    function Header()
    {
        // Emerald brand bar
        $this->SetFillColor(27, 67, 50); // #1B4332
        $this->Rect(0, 0, $this->w, 6, 'F');

        // Brand Title
        $this->SetY(10);
        $this->SetFont('Helvetica', 'B', 15);
        $this->SetTextColor(27, 67, 50);
        $this->Cell(0, 6, "PALMA'S ELITE GYM", 0, 1, 'L');

        // Subtitle & Metadata
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(45, 106, 79); // #2D6A4F
        $this->Cell(120, 5, strtoupper($this->reportTitle), 0, 0, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, "Ref: " . $this->docRef, 0, 1, 'R');

        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(120, 4, "Date Range: " . $this->dateRange, 0, 0, 'L');
        $this->Cell(0, 4, "Generated: " . date('M d, Y h:i A'), 0, 1, 'R');

        // Divider
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Line($this->lMargin, $this->GetY() + 3, $this->w - $this->rMargin, $this->GetY() + 3);
        $this->Ln(6);
    }

    function Footer()
    {
        $this->SetY(-14);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Line($this->lMargin, $this->GetY(), $this->w - $this->rMargin, $this->GetY());
        $this->Ln(2);

        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(100, 4, "Palma's Elite Gym Management System • Auditor: " . $this->auditorName, 0, 0, 'L');
        $this->Cell(0, 4, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }

    function RenderSummaryCards(array $stats)
    {
        $this->SetFont('Helvetica', 'B', 9);
        $cardW = ($this->w - $this->lMargin - $this->rMargin) / max(1, count($stats));

        $startX = $this->GetX();
        $startY = $this->GetY();

        foreach ($stats as $idx => $stat) {
            $x = $startX + ($idx * $cardW);
            $this->SetXY($x, $startY);

            // Card border & fill
            $this->SetFillColor(248, 250, 249);
            $this->SetDrawColor(203, 213, 225);
            $this->Rect($x, $startY, $cardW - 3, 13, 'DF');

            // Label
            $this->SetXY($x + 2, $startY + 1.5);
            $this->SetFont('Helvetica', '', 7);
            $this->SetTextColor(100, 116, 139);
            $this->Cell($cardW - 7, 3, strtoupper($stat['label']), 0, 1, 'L');

            // Value
            $this->SetX($x + 2);
            $this->SetFont('Helvetica', 'B', 10);
            $this->SetTextColor(27, 67, 50);
            $this->Cell($cardW - 7, 5, $stat['value'], 0, 1, 'L');
        }

        $this->SetY($startY + 16);
    }

    function RenderDataTable(array $headers, array $rows)
    {
        $totalW = $this->w - $this->lMargin - $this->rMargin;
        $numCols = max(1, count($headers));
        
        // Auto-compute column widths based on header count
        $colWidths = [];
        $baseW = floor($totalW / $numCols);
        $rem = $totalW - ($baseW * $numCols);
        for ($i = 0; $i < $numCols; $i++) {
            $colWidths[$i] = $baseW + ($i === 0 ? $rem : 0);
        }

        // Table Header
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(45, 106, 79); // #2D6A4F
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(32, 87, 67);

        foreach ($headers as $i => $h) {
            // Clean encoding
            $cleanH = iconv('UTF-8', 'windows-1252//TRANSLIT', (string)$h);
            $this->Cell($colWidths[$i], 6, ' ' . $cleanH, 1, 0, 'L', true);
        }
        $this->Ln(6);

        // Rows
        $this->SetFont('Helvetica', '', 7.5);
        $fill = false;

        if (empty($rows)) {
            $this->SetFillColor(255, 255, 255);
            $this->SetTextColor(148, 163, 184);
            $this->Cell($totalW, 8, 'No records found for the selected criteria.', 1, 1, 'C', true);
            return;
        }

        foreach ($rows as $row) {
            // Check if row will cause page overflow
            if ($this->GetY() + 5.5 > $this->PageBreakTrigger) {
                $this->AddPage($this->CurOrientation);
                // Re-print header on new page
                $this->SetFont('Helvetica', 'B', 8);
                $this->SetFillColor(45, 106, 79);
                $this->SetTextColor(255, 255, 255);
                foreach ($headers as $i => $h) {
                    $cleanH = iconv('UTF-8', 'windows-1252//TRANSLIT', (string)$h);
                    $this->Cell($colWidths[$i], 6, ' ' . $cleanH, 1, 0, 'L', true);
                }
                $this->Ln(6);
                $this->SetFont('Helvetica', '', 7.5);
            }

            if ($fill) {
                $this->SetFillColor(248, 250, 249);
            } else {
                $this->SetFillColor(255, 255, 255);
            }
            $this->SetTextColor(30, 41, 59);
            $this->SetDrawColor(226, 232, 240);

            foreach ($headers as $i => $h) {
                $val = (string)($row[$i] ?? '');
                // Replace peso sign with PHP for PDF core font compatibility
                $val = str_replace(['₱', 'â‚±'], 'PHP ', $val);
                $cleanVal = iconv('UTF-8', 'windows-1252//TRANSLIT', $val);
                
                // Truncate overly long values
                if (strlen($cleanVal) > 35) {
                    $cleanVal = substr($cleanVal, 0, 32) . '...';
                }

                $align = (stripos($h, 'amount') !== false || stripos($h, 'price') !== false || stripos($h, 'revenue') !== false) ? 'R' : 'L';
                $pad = ($align === 'R') ? ' ' : ' ';
                $this->Cell($colWidths[$i], 5.2, $cleanVal . $pad, 1, 0, $align, true);
            }
            $this->Ln(5.2);
            $fill = !$fill;
        }
    }
}
