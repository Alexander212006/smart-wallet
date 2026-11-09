<?php

declare(strict_types=1);

/**
 * Convert integer cents to a locale-independent decimal string suitable for exports.
 */
function wallet_report_export_decimal(int $cents): string
{
    if (function_exists('wallet_report_format_cents')) {
        return wallet_report_format_cents($cents, false);
    }

    $digits = (string) $cents;
    $negative = str_starts_with($digits, '-');
    if ($negative) {
        $digits = substr($digits, 1);
    }
    $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);

    return ($negative ? '-' : '') . substr($digits, 0, -2) . '.' . substr($digits, -2);
}

function wallet_report_export_money(int $cents, string $currency): string
{
    return $currency . ' ' . wallet_report_export_decimal($cents);
}

function wallet_report_export_percent(?float $percent, int $precision = 2): string
{
    return $percent === null ? 'N/A' : number_format($percent, $precision, '.', '') . '%';
}

/**
 * Neutralize spreadsheet formula prefixes in text that may originate with a user.
 */
function wallet_report_export_safe_csv_text(string $value): string
{
    $value = str_replace("\0", '', $value);
    if (preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/u', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}

/**
 * @param resource $stream
 * @param list<string|int|float|null> $cells
 */
function wallet_report_export_csv_row($stream, array $cells): void
{
    if (fputcsv($stream, $cells, ',', '"', '', "\r\n") === false) {
        throw new RuntimeException('The CSV report could not be generated.');
    }
}

/**
 * Build a UTF-8 CSV containing every dashboard section from the same report data.
 */
function wallet_financial_report_csv(array $report): string
{
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('The CSV report could not be generated.');
    }

    try {
        fwrite($stream, "\xEF\xBB\xBF");
        $period = $report['period'];
        $summary = $report['summary'];
        $currency = (string) $report['currency'];
        $generatedAt = $report['generated_at'];

        wallet_report_export_csv_row($stream, ['Smart Wallet Financial Report']);
        wallet_report_export_csv_row($stream, ['Metadata']);
        wallet_report_export_csv_row($stream, ['Report type', ucfirst((string) $period['type'])]);
        wallet_report_export_csv_row($stream, ['Selected period', (string) $period['label']]);
        wallet_report_export_csv_row($stream, ['Included window', (string) $period['effective_label']]);
        wallet_report_export_csv_row($stream, ['Currency', $currency]);
        wallet_report_export_csv_row($stream, ['Generated at', $generatedAt instanceof DateTimeInterface
            ? $generatedAt->format('Y-m-d H:i:s T') : '']);
        wallet_report_export_csv_row($stream, ['Partial period', !empty($period['is_partial']) ? 'Yes' : 'No']);
        wallet_report_export_csv_row($stream, ['Comparison method', (string) $period['comparison_note']]);
        wallet_report_export_csv_row($stream, ['Wallet policy', 'Active wallets only; future-dated transactions excluded']);

        wallet_report_export_csv_row($stream, []);
        wallet_report_export_csv_row($stream, ['Financial summary']);
        wallet_report_export_csv_row($stream, ['Metric', 'Value', 'Unit']);
        wallet_report_export_csv_row($stream, ['Total income', wallet_report_export_decimal((int) $summary['income_cents']), $currency]);
        wallet_report_export_csv_row($stream, ['Total expenses', wallet_report_export_decimal((int) $summary['expense_cents']), $currency]);
        wallet_report_export_csv_row($stream, ['Transaction-derived net savings', wallet_report_export_decimal((int) $summary['net_cents']), $currency]);
        wallet_report_export_csv_row($stream, ['Savings rate', $summary['savings_rate'] === null
            ? 'N/A' : number_format((float) $summary['savings_rate'], 2, '.', ''), 'Percent']);
        wallet_report_export_csv_row($stream, ['Transactions', (int) $summary['transaction_count'], 'Count']);

        wallet_report_export_csv_row($stream, []);
        wallet_report_export_csv_row($stream, ['Expense categories']);
        wallet_report_export_csv_row($stream, ['Category', 'Amount', 'Currency', 'Share percent', 'Transaction count']);
        foreach ($report['categories'] as $category) {
            wallet_report_export_csv_row($stream, [
                wallet_report_export_safe_csv_text((string) $category['name']),
                wallet_report_export_decimal((int) $category['total_cents']),
                $currency,
                number_format((float) $category['share_percent'], 4, '.', ''),
                (int) $category['count'],
            ]);
        }
        if ($report['categories'] === []) {
            wallet_report_export_csv_row($stream, ['No expense transactions in this period']);
        }

        wallet_report_export_csv_row($stream, []);
        wallet_report_export_csv_row($stream, ['Budget performance']);
        wallet_report_export_csv_row($stream, [
            'Budget', 'Scope', 'Budget period', 'Allocation', 'Actual spending', 'Remaining',
            'Currency', 'Percent used', 'Status', 'Covered dates', 'Allocation basis',
        ]);
        foreach ($report['budgets'] as $budget) {
            wallet_report_export_csv_row($stream, [
                wallet_report_export_safe_csv_text((string) $budget['name']),
                wallet_report_export_safe_csv_text((string) $budget['scope']),
                ucfirst((string) $budget['period']),
                wallet_report_export_decimal((int) $budget['allocation_cents']),
                wallet_report_export_decimal((int) $budget['spent_cents']),
                wallet_report_export_decimal((int) $budget['remaining_cents']),
                $currency,
                $budget['percent_used'] === null ? 'N/A' : number_format((float) $budget['percent_used'], 4, '.', ''),
                (string) $budget['status'],
                (string) $budget['coverage_label'],
                (string) $budget['allocation_note'],
            ]);
        }
        if ($report['budgets'] === []) {
            wallet_report_export_csv_row($stream, ['No applicable budgets in this period']);
        }

        wallet_report_export_csv_row($stream, []);
        wallet_report_export_csv_row($stream, ['Previous-period comparison']);
        wallet_report_export_csv_row($stream, [
            'Metric', 'Current', 'Previous', 'Difference', 'Value unit', 'Percentage change',
        ]);
        foreach ($report['comparison'] as $comparison) {
            $isRate = !empty($comparison['is_rate']);
            $formatValue = static function ($value) use ($isRate): string {
                if ($value === null) {
                    return 'N/A';
                }

                return $isRate
                    ? number_format((float) $value, 2, '.', '')
                    : wallet_report_export_decimal((int) $value);
            };
            wallet_report_export_csv_row($stream, [
                (string) $comparison['label'],
                $formatValue($comparison['current']),
                $formatValue($comparison['previous']),
                $formatValue($comparison['difference']),
                $isRate ? 'Percent; difference in percentage points' : $currency,
                $comparison['percent_change'] === null
                    ? 'N/A' : number_format((float) $comparison['percent_change'], 4, '.', ''),
            ]);
        }
        wallet_report_export_csv_row($stream, ['Comparison period', (string) $period['previous_label']]);
        wallet_report_export_csv_row($stream, ['Formula', 'Percentage change = (current - previous) / absolute previous x 100; zero baselines are N/A']);

        rewind($stream);
        $csv = stream_get_contents($stream);
        if ($csv === false) {
            throw new RuntimeException('The CSV report could not be generated.');
        }

        return $csv;
    } finally {
        fclose($stream);
    }
}

/**
 * Small, dependency-free PDF writer for a text-and-table financial report.
 * Uses PDF's built-in Helvetica fonts, so no font files or Composer package are required.
 */
final class WalletReportExportPdfWriter
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;
    private const LEFT = 44.0;
    private const RIGHT = 44.0;
    private const TOP = 790.0;
    private const BOTTOM = 52.0;

    /** @var list<string> */
    private array $pages = [];
    private int $pageIndex = -1;
    private float $y = self::TOP;
    private string $documentTitle;
    private DateTimeInterface $generatedAt;

    public function __construct(string $documentTitle, DateTimeInterface $generatedAt)
    {
        $this->documentTitle = $documentTitle;
        $this->generatedAt = $generatedAt;
        $this->newPage(false);
    }

    public function heading(string $text, int $level = 2): void
    {
        $size = $level === 1 ? 22.0 : 13.0;
        $before = $level === 1 ? 0.0 : 8.0;
        $after = $level === 1 ? 13.0 : 7.0;
        $this->space($before);
        $this->ensureSpace($size + $after + 4.0);
        $color = $level === 1 ? [0.063, 0.157, 0.125] : [0.055, 0.212, 0.153];
        $this->drawText(self::LEFT, $this->y, $text, $size, true, $color);
        $this->y -= $size + $after;
    }

    public function paragraph(string $text, float $size = 9.0, array $color = [0.29, 0.34, 0.42]): void
    {
        $lines = $this->wrap($text, self::PAGE_WIDTH - self::LEFT - self::RIGHT, $size, false);
        foreach ($lines as $line) {
            $this->ensureSpace(12.0);
            $this->drawText(self::LEFT, $this->y, $line, $size, false, $color);
            $this->y -= 12.0;
        }
        $this->y -= 3.0;
    }

    public function rule(): void
    {
        $this->ensureSpace(12.0);
        $this->append(sprintf(
            "q 0.82 0.86 0.84 RG 0.6 w %.2F %.2F m %.2F %.2F l S Q\n",
            self::LEFT,
            $this->y,
            self::PAGE_WIDTH - self::RIGHT,
            $this->y
        ));
        $this->y -= 12.0;
    }

    public function space(float $points): void
    {
        if ($points <= 0) {
            return;
        }
        $this->ensureSpace($points);
        $this->y -= $points;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<float> $widths
     * @param list<'left'|'right'|'center'> $alignments
     */
    public function table(array $headers, array $rows, array $widths, array $alignments = []): void
    {
        if ($headers === [] || count($headers) !== count($widths)) {
            return;
        }

        $drawHeader = function () use ($headers, $widths, $alignments): void {
            $height = 24.0;
            $this->ensureSpace($height + 4.0);
            $x = self::LEFT;
            foreach ($headers as $index => $header) {
                $width = $widths[$index];
                $this->drawRect($x, $this->y - $height + 5.0, $width, $height, [0.063, 0.157, 0.125]);
                $this->drawCellLine(
                    $x,
                    $this->y - 10.0,
                    (string) $header,
                    $width,
                    8.0,
                    true,
                    $alignments[$index] ?? 'left',
                    [1.0, 1.0, 1.0]
                );
                $x += $width;
            }
            $this->y -= $height;
        };

        $drawHeader();
        foreach ($rows as $rowIndex => $row) {
            $wrapped = [];
            $lineCount = 1;
            foreach ($headers as $index => $_header) {
                $cellLines = $this->wrap((string) ($row[$index] ?? ''), $widths[$index] - 10.0, 7.7, false);
                $wrapped[$index] = $cellLines;
                $lineCount = max($lineCount, count($cellLines));
            }
            $height = max(24.0, $lineCount * 9.5 + 10.0);
            if ($this->y - $height < self::BOTTOM) {
                $this->newPage(true);
                $drawHeader();
            }

            $x = self::LEFT;
            $fill = $rowIndex % 2 === 0 ? [0.965, 0.975, 0.97] : [1.0, 1.0, 1.0];
            foreach ($headers as $index => $_header) {
                $width = $widths[$index];
                $this->drawRect($x, $this->y - $height + 5.0, $width, $height, $fill, [0.86, 0.89, 0.88]);
                foreach ($wrapped[$index] as $lineIndex => $line) {
                    $this->drawCellLine(
                        $x,
                        $this->y - 9.0 - ($lineIndex * 9.5),
                        $line,
                        $width,
                        7.7,
                        false,
                        $alignments[$index] ?? 'left',
                        [0.12, 0.16, 0.20]
                    );
                }
                $x += $width;
            }
            $this->y -= $height;
        }
        $this->y -= 5.0;
    }

    public function output(): string
    {
        $pageCount = count($this->pages);
        foreach ($this->pages as $index => &$content) {
            $footer = 'Smart Wallet  |  ' . ($index + 1) . ' of ' . $pageCount;
            $content .= $this->textCommand(
                self::LEFT,
                28.0,
                $footer,
                7.5,
                false,
                [0.45, 0.49, 0.54]
            );
        }
        unset($content);

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        foreach ($this->pages as $index => $content) {
            $pageObject = 5 + ($index * 2);
            $contentObject = $pageObject + 1;
            $kids[] = $pageObject . ' 0 R';
            $objects[$pageObject] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentObject
            );
            $objects[$contentObject] = "<< /Length " . strlen($content) . ">>\nstream\n" . $content . "endstream";
        }
        $objects[2] = '<< /Type /Pages /Count ' . $pageCount . ' /Kids [' . implode(' ', $kids) . '] >>';
        $infoObject = 5 + ($pageCount * 2);
        $objects[$infoObject] = '<< /Title (' . self::escapePdfText($this->documentTitle)
            . ') /Author (Smart Wallet) /Creator (Smart Wallet Financial Reports) /Producer (Smart Wallet built-in PDF exporter)'
            . ' /CreationDate (D:' . $this->generatedAt->format('YmdHis') . ') >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $objectCount = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($objectCount + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($number = 1; $number <= $objectCount; $number++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$number]) . "\n";
        }
        $pdf .= 'trailer << /Size ' . ($objectCount + 1) . ' /Root 1 0 R /Info ' . $infoObject . " 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    private function newPage(bool $runningHeader): void
    {
        $this->pages[] = '';
        $this->pageIndex = count($this->pages) - 1;
        $this->y = self::TOP;
        if ($runningHeader) {
            $this->drawText(self::LEFT, $this->y, 'SMART WALLET  /  FINANCIAL REPORT', 8.0, true, [0.055, 0.212, 0.153]);
            $this->y -= 12.0;
            $this->rule();
        }
    }

    private function ensureSpace(float $height, bool $allowNewPage = true): void
    {
        if ($this->y - $height >= self::BOTTOM) {
            return;
        }
        if ($allowNewPage) {
            $this->newPage(true);
        }
    }

    private function append(string $command): void
    {
        $this->pages[$this->pageIndex] .= $command;
    }

    private function drawText(float $x, float $y, string $text, float $size, bool $bold, array $color): void
    {
        $this->append($this->textCommand($x, $y, $text, $size, $bold, $color));
    }

    private function textCommand(float $x, float $y, string $text, float $size, bool $bold, array $color): string
    {
        return sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $bold ? 'F2' : 'F1',
            $size,
            $color[0],
            $color[1],
            $color[2],
            $x,
            $y,
            self::escapePdfText($text)
        );
    }

    private function drawRect(float $x, float $y, float $width, float $height, array $fill, ?array $stroke = null): void
    {
        $command = sprintf(
            'q %.3F %.3F %.3F rg ',
            $fill[0],
            $fill[1],
            $fill[2]
        );
        if ($stroke !== null) {
            $command .= sprintf('%.3F %.3F %.3F RG 0.35 w ', $stroke[0], $stroke[1], $stroke[2]);
        }
        $command .= sprintf('%.2F %.2F %.2F %.2F re %s Q', $x, $y, $width, $height, $stroke === null ? 'f' : 'B');
        $this->append($command . "\n");
    }

    private function drawCellLine(
        float $x,
        float $y,
        string $text,
        float $width,
        float $size,
        bool $bold,
        string $alignment,
        array $color
    ): void {
        $textWidth = $this->measure($text, $size, $bold);
        $textX = $x + 5.0;
        if ($alignment === 'right') {
            $textX = max($x + 5.0, $x + $width - 5.0 - $textWidth);
        } elseif ($alignment === 'center') {
            $textX = max($x + 5.0, $x + (($width - $textWidth) / 2));
        }
        $this->drawText($textX, $y, $text, $size, $bold, $color);
    }

    /** @return list<string> */
    private function wrap(string $text, float $maxWidth, float $size, bool $bold): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [''];
        }

        $words = preg_split('/\s+/u', $text) ?: [$text];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $pieces = $this->splitWord($word, $maxWidth, $size, $bold);
            foreach ($pieces as $pieceIndex => $piece) {
                $candidate = $line === '' ? $piece : $line . ' ' . $piece;
                if ($line !== '' && $this->measure($candidate, $size, $bold) > $maxWidth) {
                    $lines[] = $line;
                    $line = $piece;
                } else {
                    $line = $candidate;
                }
                if ($pieceIndex < count($pieces) - 1) {
                    $lines[] = $line;
                    $line = '';
                }
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [''] : $lines;
    }

    /** @return list<string> */
    private function splitWord(string $word, float $maxWidth, float $size, bool $bold): array
    {
        if ($this->measure($word, $size, $bold) <= $maxWidth) {
            return [$word];
        }
        $characters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($word);
        $pieces = [];
        $piece = '';
        foreach ($characters as $character) {
            $candidate = $piece . $character;
            if ($piece !== '' && $this->measure($candidate, $size, $bold) > $maxWidth) {
                $pieces[] = $piece;
                $piece = $character;
            } else {
                $piece = $candidate;
            }
        }
        if ($piece !== '') {
            $pieces[] = $piece;
        }

        return $pieces;
    }

    private function measure(string $text, float $size, bool $bold): float
    {
        $units = function_exists('mb_strwidth') ? mb_strwidth($text, 'UTF-8') : strlen($text);

        return $units * $size * ($bold ? 0.55 : 0.51);
    }

    private static function escapePdfText(string $text): string
    {
        $text = str_replace(["\r", "\n", "\0"], [' ', ' ', ''], $text);
        $text = str_replace(['–', '—', '‑', '−'], '-', $text);
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}

function wallet_financial_report_pdf(array $report): string
{
    $period = $report['period'];
    $summary = $report['summary'];
    $currency = (string) $report['currency'];
    $generatedAt = $report['generated_at'] instanceof DateTimeInterface
        ? $report['generated_at'] : new DateTimeImmutable('now');
    $writer = new WalletReportExportPdfWriter('Smart Wallet Financial Report', $generatedAt);

    $writer->paragraph('SMART WALLET', 8.5, [0.055, 0.45, 0.32]);
    $writer->heading('Financial Report', 1);
    $writer->paragraph(
        ucfirst((string) $period['type']) . ' report  |  ' . (string) $period['label'] . '  |  ' . $currency,
        10.0,
        [0.12, 0.16, 0.20]
    );
    $writer->paragraph(
        'Included window: ' . (string) $period['effective_label'] . '. Generated '
        . $generatedAt->format('M j, Y g:i A T') . '.',
        8.5
    );
    if (!empty($period['is_partial'])) {
        $writer->paragraph('Partial-period report. ' . (string) $period['comparison_note'], 8.5, [0.65, 0.36, 0.04]);
    }
    $writer->paragraph('Active wallets only. Future-dated transactions are excluded. Amounts are shown in ' . $currency . '.');
    $writer->rule();

    $writer->heading('Financial summary');
    $writer->table(
        ['Metric', 'Value'],
        [
            ['Total income', wallet_report_export_money((int) $summary['income_cents'], $currency)],
            ['Total expenses', wallet_report_export_money((int) $summary['expense_cents'], $currency)],
            ['Transaction-derived net savings', wallet_report_export_money((int) $summary['net_cents'], $currency)],
            ['Savings rate', wallet_report_export_percent($summary['savings_rate'] === null ? null : (float) $summary['savings_rate'])],
            ['Transactions included', (string) (int) $summary['transaction_count']],
        ],
        [330.0, 177.0],
        ['left', 'right']
    );

    $writer->heading('Expense breakdown');
    if ($report['categories'] === []) {
        $writer->paragraph('No expense transactions were recorded in the included window.');
    } else {
        $categoryRows = [];
        foreach ($report['categories'] as $category) {
            $categoryRows[] = [
                (string) $category['name'],
                wallet_report_export_money((int) $category['total_cents'], $currency),
                number_format((float) $category['share_percent'], 2) . '%',
                (string) (int) $category['count'],
            ];
        }
        $writer->table(
            ['Category', 'Spent', 'Share', 'Transactions'],
            $categoryRows,
            [205.0, 120.0, 80.0, 102.0],
            ['left', 'right', 'right', 'right']
        );
    }

    $writer->heading('Budget performance');
    $writer->paragraph(
        'Allocations reflect only the budget-cycle days covered by this report. Each budget is evaluated independently; overlapping budget rows are not added together.'
    );
    if ($report['budgets'] === []) {
        $writer->paragraph('No applicable budget records overlap the included window.');
    } else {
        $budgetRows = [];
        foreach ($report['budgets'] as $budget) {
            $budgetDetails = (string) $budget['name'] . ' | ' . (string) $budget['scope']
                . ' | ' . ucfirst((string) $budget['period']) . ' | ' . (string) $budget['coverage_label']
                . ' | ' . (string) $budget['allocation_note'];
            $used = $budget['percent_used'] === null
                ? 'N/A' : number_format((float) $budget['percent_used'], 1) . '%';
            $budgetRows[] = [
                $budgetDetails,
                wallet_report_export_money((int) $budget['allocation_cents'], $currency),
                wallet_report_export_money((int) $budget['spent_cents'], $currency),
                wallet_report_export_money((int) $budget['remaining_cents'], $currency),
                $used . ' | ' . (string) $budget['status'],
            ];
        }
        $writer->table(
            ['Budget / scope / coverage', 'Allocation', 'Spent', 'Remaining', 'Used / status'],
            $budgetRows,
            [167.0, 82.0, 78.0, 82.0, 98.0],
            ['left', 'right', 'right', 'right', 'right']
        );
    }

    $writer->heading('Previous-period comparison');
    $writer->paragraph((string) $period['comparison_note']);
    $comparisonRows = [];
    foreach ($report['comparison'] as $comparison) {
        $isRate = !empty($comparison['is_rate']);
        $format = static function ($value) use ($isRate, $currency): string {
            if ($value === null) {
                return 'N/A';
            }
            return $isRate
                ? number_format((float) $value, 2) . '%'
                : wallet_report_export_money((int) $value, $currency);
        };
        $difference = $comparison['difference'] === null
            ? 'N/A'
            : ($isRate
                ? number_format((float) $comparison['difference'], 2) . ' pp'
                : wallet_report_export_money((int) $comparison['difference'], $currency));
        $comparisonRows[] = [
            (string) $comparison['label'],
            $format($comparison['current']),
            $format($comparison['previous']),
            $difference,
            wallet_report_export_percent(
                $comparison['percent_change'] === null ? null : (float) $comparison['percent_change']
            ),
        ];
    }
    $writer->table(
        ['Metric', 'Current', 'Previous', 'Difference', '% change'],
        $comparisonRows,
        [118.0, 98.0, 98.0, 98.0, 95.0],
        ['left', 'right', 'right', 'right', 'right']
    );
    $writer->paragraph(
        'Comparison period: ' . (string) $period['previous_label']
        . '. Percentage change = (current - previous) / absolute previous x 100. A zero previous baseline is reported as N/A.'
    );

    return $writer->output();
}
