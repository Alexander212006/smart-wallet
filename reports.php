<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/financial_reports.php';
require_once __DIR__ . '/includes/financial_report_exports.php';

$pdo = getDatabaseConnection();
$today = new DateTimeImmutable('today');
$generatedAt = new DateTimeImmutable('now');

$currencyStatement = $pdo->prepare(
    'SELECT DISTINCT currency_code
     FROM wallets
     WHERE user_id = :user_id AND is_archived = 0
     ORDER BY currency_code'
);
$currencyStatement->execute(['user_id' => $walletUserId]);
$currencies = $currencyStatement->fetchAll(PDO::FETCH_COLUMN);
$filters = wallet_resolve_financial_report_filters($_GET, $currencies, $today);
$period = $filters['period'];
$currency = $filters['currency'];
$filterErrors = $filters['errors'];

$requestedExport = $_GET['export'] ?? null;
$export = null;
if ($requestedExport !== null) {
    if (is_string($requestedExport) && in_array($requestedExport, ['csv', 'pdf'], true)) {
        $export = $requestedExport;
    } else {
        $filterErrors[] = 'The requested export format is not supported.';
    }
}
if ($export !== null && $currency === null) {
    $filterErrors[] = 'Create or restore an active wallet before exporting a report.';
}

$report = null;
$reportError = null;
if ($currency !== null) {
    try {
        $report = wallet_load_financial_report(
            $pdo,
            $walletUserId,
            $currency,
            $period,
            $generatedAt
        );
    } catch (Throwable) {
        $reportError = 'The report could not be loaded right now. Please try again.';
    }
}

if ($export !== null && $filterErrors === []) {
    if ($report === null) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        echo 'The financial report could not be generated.';
        exit;
    }

    $filename = 'smart-wallet-' . $period['type'] . '-' . $period['selection'] . '-' . $currency . '.' . $export;
    try {
        $output = $export === 'csv'
            ? wallet_financial_report_csv($report)
            : wallet_financial_report_pdf($report);
    } catch (Throwable) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo 'The financial report export could not be generated.';
        exit;
    }
    header('Content-Type: ' . ($export === 'csv' ? 'text/csv; charset=UTF-8' : 'application/pdf'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($output));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $output;
    exit;
}

$queryFor = static function (string $type, ?string $selectedCurrency, DateTimeImmutable $date): string {
    $parameters = [
        'period' => $type,
        ['weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$type]
            => wallet_report_default_selection($type, $date),
    ];
    if ($selectedCurrency !== null) {
        $parameters['currency'] = $selectedCurrency;
    }

    return 'reports.php?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
};
$canonicalParameters = [
    'period' => $period['type'],
    ['weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$period['type']] => $period['selection'],
];
if ($currency !== null) {
    $canonicalParameters['currency'] = $currency;
}
$canonicalQuery = http_build_query($canonicalParameters, '', '&', PHP_QUERY_RFC3986);
$money = static fn (int $cents): string => ($currency ?? '') . ' ' . wallet_report_format_cents($cents);
$signedMoney = static fn (?int $cents): string => $cents === null
    ? 'N/A'
    : ($cents > 0 ? '+' : '') . ($currency ?? '') . ' ' . wallet_report_format_cents($cents);
$signedPercent = static fn (?float $value, string $suffix = '%'): string => $value === null
    ? 'N/A'
    : ($value > 0 ? '+' : '') . number_format($value, 1) . $suffix;
$categoryColors = [
    'bg-emerald-600', 'bg-sky-500', 'bg-amber-400', 'bg-violet-500',
    'bg-rose-400', 'bg-cyan-500', 'bg-lime-500', 'bg-slate-400',
];

$appTitle = 'Financial Reports';
$activePage = 'reports';
require __DIR__ . '/includes/app_shell_start.php';
?>
<section class="flex flex-col justify-between gap-5 xl:flex-row xl:items-end">
    <div>
        <p class="text-sm font-medium text-emerald-800">Review your financial position</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Financial Reports</h1>
        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">Generate accurate weekly, monthly, and yearly cash-flow reports, budget insights, and period comparisons.</p>
    </div>
    <?php if ($report !== null): ?>
        <div class="flex flex-wrap gap-2">
            <a download href="reports.php?<?= wallet_escape($canonicalQuery . '&export=pdf') ?>" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-emerald-300 hover:text-emerald-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">Export PDF</a>
            <a download href="reports.php?<?= wallet_escape($canonicalQuery . '&export=csv') ?>" class="inline-flex items-center rounded-xl bg-[#102820] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">Export CSV</a>
        </div>
    <?php endif; ?>
</section>

<?php if ($filterErrors !== []): ?>
    <section aria-label="Filter notices" class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
        <ul class="list-disc space-y-1 pl-5">
            <?php foreach ($filterErrors as $filterError): ?>
                <li><?= wallet_escape($filterError) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-5" aria-labelledby="report-filters-title">
    <div class="flex flex-col gap-5">
        <div>
            <h2 id="report-filters-title" class="text-sm font-semibold text-slate-900">Report period</h2>
            <nav class="mt-3 inline-flex max-w-full overflow-x-auto rounded-xl bg-slate-100 p-1" aria-label="Report period">
                <?php foreach (['weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $type => $label): ?>
                    <a href="<?= wallet_escape($queryFor($type, $currency, $today)) ?>" class="whitespace-nowrap rounded-lg px-4 py-2 text-sm font-medium transition <?= $period['type'] === $type ? 'bg-white text-emerald-900 shadow-sm' : 'text-slate-500 hover:text-slate-900' ?>" <?= $period['type'] === $type ? 'aria-current="page"' : '' ?>><?= wallet_escape($label) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php if ($currency !== null): ?>
            <form method="get" action="reports.php" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_minmax(180px,0.65fr)_auto] xl:items-end">
                <input type="hidden" name="period" value="<?= wallet_escape($period['type']) ?>">
                <div>
                    <?php if ($period['type'] === 'weekly'): ?>
                        <label for="report-week" class="mb-1.5 block text-sm font-medium text-slate-700">Week (Monday–Sunday)</label>
                        <input id="report-week" name="week" type="week" min="1001-W01" max="<?= wallet_escape($today->format('o-\WW')) ?>" value="<?= wallet_escape($period['selection']) ?>" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10">
                    <?php elseif ($period['type'] === 'monthly'): ?>
                        <label for="report-month" class="mb-1.5 block text-sm font-medium text-slate-700">Month</label>
                        <input id="report-month" name="month" type="month" min="1001-01" max="<?= wallet_escape($today->format('Y-m')) ?>" value="<?= wallet_escape($period['selection']) ?>" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10">
                    <?php else: ?>
                        <label for="report-year" class="mb-1.5 block text-sm font-medium text-slate-700">Year</label>
                        <input id="report-year" name="year" type="number" min="1001" max="<?= (int) $today->format('Y') ?>" value="<?= wallet_escape($period['selection']) ?>" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10">
                    <?php endif; ?>
                </div>
                <div>
                    <label for="report-currency" class="mb-1.5 block text-sm font-medium text-slate-700">Currency</label>
                    <select id="report-currency" name="currency" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10">
                        <?php foreach ($currencies as $optionCurrency): ?>
                            <option value="<?= wallet_escape((string) $optionCurrency) ?>" <?= $optionCurrency === $currency ? 'selected' : '' ?>><?= wallet_escape((string) $optionCurrency) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="rounded-xl bg-[#102820] px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">Generate Report</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($currency === null): ?>
    <section class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
        <h2 class="text-lg font-semibold">Create an active wallet to generate reports</h2>
        <p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-500">Reports keep currencies separate and use transactions from active wallets. Create or restore a wallet to begin.</p>
        <a href="wallet.php" class="mt-5 inline-flex rounded-xl bg-[#102820] px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">Manage wallets</a>
    </section>
<?php elseif ($reportError !== null || $report === null): ?>
    <section class="rounded-2xl border border-rose-200 bg-rose-50 px-6 py-8 text-center text-rose-900">
        <h2 class="font-semibold">Report unavailable</h2>
        <p class="mt-2 text-sm"><?= wallet_escape($reportError ?? 'The report could not be loaded.') ?></p>
    </section>
<?php else: ?>
    <?php
    $summary = $report['summary'];
    $maxCashFlow = max(1, $summary['income_cents'], $summary['expense_cents']);
    $incomeWidth = $summary['income_cents'] / $maxCashFlow * 100;
    $expenseWidth = $summary['expense_cents'] / $maxCashFlow * 100;
    ?>
    <section class="flex flex-col gap-2 rounded-2xl border border-emerald-100 bg-emerald-50/60 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800"><?= wallet_escape($period['type_label']) ?> report</p>
            <h2 class="mt-1 text-lg font-semibold text-emerald-950"><?= wallet_escape($period['effective_label']) ?></h2>
        </div>
        <p class="max-w-xl text-xs leading-5 text-emerald-800 sm:text-right"><?= wallet_escape($report['policy_note']) ?><?= $period['is_partial'] ? ' ' . wallet_escape($period['comparison_note']) : '' ?></p>
    </section>

    <section aria-label="Financial summary" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl bg-[#102820] p-5 text-white">
            <p class="text-sm text-emerald-100/80">Total income</p>
            <p class="mt-4 break-words text-2xl font-semibold tracking-tight"><?= wallet_escape($money($summary['income_cents'])) ?></p>
            <p class="mt-2 text-xs text-emerald-100/70">Positive transactions in this period</p>
        </article>
        <article class="rounded-2xl border border-slate-200/80 bg-white p-5">
            <p class="text-sm text-slate-500">Total expenses</p>
            <p class="mt-4 break-words text-2xl font-semibold tracking-tight text-rose-600"><?= wallet_escape($money($summary['expense_cents'])) ?></p>
            <p class="mt-2 text-xs text-slate-500">Absolute value of negative transactions</p>
        </article>
        <article class="rounded-2xl border border-slate-200/80 bg-white p-5">
            <p class="text-sm text-slate-500">Transaction-derived net savings</p>
            <p class="mt-4 break-words text-2xl font-semibold tracking-tight <?= $summary['net_cents'] < 0 ? 'text-rose-600' : 'text-emerald-700' ?>"><?= wallet_escape($money($summary['net_cents'])) ?></p>
            <p class="mt-2 text-xs text-slate-500">Income minus expenses; not goal contributions</p>
        </article>
        <article class="rounded-2xl border border-slate-200/80 bg-white p-5">
            <p class="text-sm text-slate-500">Savings rate</p>
            <p class="mt-4 text-2xl font-semibold tracking-tight <?= $summary['savings_rate'] !== null && $summary['savings_rate'] < 0 ? 'text-rose-600' : 'text-emerald-700' ?>"><?= $summary['savings_rate'] === null ? 'N/A' : number_format($summary['savings_rate'], 1) . '%' ?></p>
            <p class="mt-2 text-xs text-slate-500"><?= $summary['savings_rate'] === null ? 'No income baseline for this period' : 'Net savings divided by income' ?></p>
        </article>
    </section>

    <?php if ($summary['transaction_count'] === 0): ?>
        <section class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-8 text-center">
            <h2 class="font-semibold">No transactions in this reporting window</h2>
            <p class="mt-2 text-sm text-slate-500">The zero-value report and applicable budget information are still shown below.</p>
        </section>
    <?php endif; ?>

    <section class="grid gap-6 xl:grid-cols-2">
        <article class="min-w-0 rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
            <h2 class="font-semibold tracking-tight">Income vs. expenses</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">Cash flow recorded in the selected currency and period.</p>
            <div class="mt-7 space-y-6" role="img" aria-label="Income <?= wallet_escape($money($summary['income_cents'])) ?>; expenses <?= wallet_escape($money($summary['expense_cents'])) ?>.">
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3 text-sm"><span class="font-medium text-slate-700">Income</span><span class="tabular-nums"><?= wallet_escape($money($summary['income_cents'])) ?></span></div>
                    <div class="h-4 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: <?= number_format($incomeWidth, 4, '.', '') ?>%"></div></div>
                </div>
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3 text-sm"><span class="font-medium text-slate-700">Expenses</span><span class="tabular-nums"><?= wallet_escape($money($summary['expense_cents'])) ?></span></div>
                    <div class="h-4 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-rose-400" style="width: <?= number_format($expenseWidth, 4, '.', '') ?>%"></div></div>
                </div>
            </div>
        </article>

        <article class="min-w-0 rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
            <h2 class="font-semibold tracking-tight">Expense distribution</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">Each category’s exact share of total report expenses.</p>
            <?php if ($report['categories'] === []): ?>
                <p class="mt-6 rounded-xl bg-slate-50 px-4 py-6 text-sm text-slate-500">Category distribution will appear after an expense is recorded in this period.</p>
            <?php else: ?>
                <div class="mt-6 flex h-5 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="<?= wallet_escape('Expense distribution: ' . implode('; ', array_map(static fn (array $category): string => $category['name'] . ' ' . number_format($category['share_percent'], 1) . ' percent', $report['categories']))) ?>">
                    <?php foreach ($report['categories'] as $index => $category): ?>
                        <div title="<?= wallet_escape($category['name'] . ': ' . number_format($category['share_percent'], 1) . '%') ?>" class="h-full <?= $categoryColors[$index % count($categoryColors)] ?>" style="width: <?= number_format($category['share_percent'], 4, '.', '') ?>%"></div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    <?php foreach ($report['categories'] as $index => $category): ?>
                        <div class="flex min-w-0 items-center justify-between gap-3 text-sm">
                            <span class="flex min-w-0 items-center gap-2"><span class="h-2.5 w-2.5 shrink-0 rounded-full <?= $categoryColors[$index % count($categoryColors)] ?>"></span><span class="truncate text-slate-600"><?= wallet_escape($category['name']) ?></span></span>
                            <span class="shrink-0 font-medium tabular-nums"><?= number_format($category['share_percent'], 1) ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <section class="min-w-0 rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="font-semibold tracking-tight">Expense breakdown</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">Category totals reconcile exactly to <?= wallet_escape($money($summary['expense_cents'])) ?>.</p>
            </div>
            <p class="text-xs text-slate-500"><?= count($report['categories']) ?> <?= count($report['categories']) === 1 ? 'category' : 'categories' ?></p>
        </div>
        <?php if ($report['categories'] === []): ?>
            <p class="mt-6 rounded-xl bg-slate-50 px-4 py-6 text-sm text-slate-500">No expense categories to display.</p>
        <?php else: ?>
            <div class="mt-5 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Expense category breakdown for <?= wallet_escape($period['effective_label']) ?></caption>
                    <thead class="border-b border-slate-100 text-xs text-slate-500"><tr><th scope="col" class="pb-3 pr-4 font-medium">Category</th><th scope="col" class="px-4 pb-3 text-right font-medium">Transactions</th><th scope="col" class="px-4 pb-3 text-right font-medium">Share</th><th scope="col" class="pb-3 pl-4 text-right font-medium">Total spent</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($report['categories'] as $category): ?>
                            <tr><th scope="row" class="max-w-xs break-words py-4 pr-4 font-medium text-slate-800"><?= wallet_escape($category['name']) ?></th><td class="px-4 py-4 text-right tabular-nums text-slate-500"><?= (int) $category['count'] ?></td><td class="px-4 py-4 text-right tabular-nums text-slate-500"><?= number_format($category['share_percent'], 1) ?>%</td><td class="whitespace-nowrap py-4 pl-4 text-right font-semibold tabular-nums"><?= wallet_escape($money($category['total_cents'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="border-t border-slate-200"><tr><th scope="row" colspan="3" class="pt-4 pr-4 text-right font-semibold">Reconciled total</th><td class="whitespace-nowrap pt-4 pl-4 text-right font-semibold tabular-nums"><?= wallet_escape($money(array_sum(array_column($report['categories'], 'total_cents')))) ?></td></tr></tfoot>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="min-w-0 rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
        <h2 class="font-semibold tracking-tight">Budget performance</h2>
        <p class="mt-1 max-w-4xl text-sm leading-6 text-slate-500">Applicable allocations are summed across complete cycles and prorated by covered calendar days for partial cycles. Budgets are evaluated independently; overlapping budget rows are never summed into total spending.</p>
        <?php if ($report['budgets'] === []): ?>
            <div class="mt-6 rounded-xl bg-slate-50 px-4 py-6 text-sm text-slate-500">No existing budget applies to this currency and reporting window. <a href="expenses.php" class="font-semibold text-emerald-800 hover:underline">Manage budgets</a></div>
        <?php else: ?>
            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <?php foreach ($report['budgets'] as $budget): ?>
                    <?php
                    $budgetTone = $budget['status'] === 'Over Budget'
                        ? 'bg-rose-500'
                        : ($budget['status'] === 'Near Limit' ? 'bg-amber-400' : 'bg-emerald-500');
                    $statusTone = $budget['status'] === 'Over Budget'
                        ? 'bg-rose-50 text-rose-700'
                        : ($budget['status'] === 'Near Limit' ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800');
                    $progress = min(100.0, max(0.0, $budget['percent_used']));
                    ?>
                    <article class="rounded-2xl border border-slate-200 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0"><h3 class="break-words font-semibold text-slate-900"><?= wallet_escape($budget['name']) ?></h3><p class="mt-1 text-xs text-slate-500"><?= wallet_escape($budget['scope']) ?> · <?= wallet_escape(ucfirst($budget['period'])) ?></p></div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold <?= $statusTone ?>"><?= wallet_escape($budget['status']) ?></span>
                        </div>
                        <div class="mt-5 grid grid-cols-3 gap-3 text-sm">
                            <div><p class="text-xs text-slate-500">Allocation</p><p class="mt-1 break-words font-semibold"><?= wallet_escape($money($budget['allocation_cents'])) ?></p></div>
                            <div><p class="text-xs text-slate-500">Actual</p><p class="mt-1 break-words font-semibold"><?= wallet_escape($money($budget['spent_cents'])) ?></p></div>
                            <div><p class="text-xs text-slate-500"><?= $budget['remaining_cents'] < 0 ? 'Overspent' : 'Remaining' ?></p><p class="mt-1 break-words font-semibold <?= $budget['remaining_cents'] < 0 ? 'text-rose-600' : 'text-emerald-700' ?>"><?= wallet_escape($money(abs($budget['remaining_cents']))) ?></p></div>
                        </div>
                        <div class="mt-5">
                            <div class="mb-2 flex justify-between gap-3 text-xs"><span class="text-slate-500">Budget used</span><span class="font-semibold tabular-nums"><?= number_format($budget['percent_used'], 1) ?>%</span></div>
                            <div class="h-2.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="<?= wallet_escape($budget['name']) ?> budget used" aria-valuenow="<?= number_format($progress, 1, '.', '') ?>" aria-valuetext="<?= number_format($budget['percent_used'], 1, '.', '') ?> percent used" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full <?= $budgetTone ?>" style="width: <?= number_format($progress, 4, '.', '') ?>%"></div></div>
                        </div>
                        <p class="mt-4 text-xs leading-5 text-slate-500"><?= wallet_escape($budget['coverage_label']) ?> · <?= wallet_escape($budget['allocation_note']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="min-w-0 rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
        <h2 class="font-semibold tracking-tight">Previous-period comparison</h2>
        <p class="mt-1 text-sm leading-6 text-slate-500"><?= wallet_escape($period['comparison_note']) ?> Percentage change uses (current − previous) ÷ |previous| × 100; a zero baseline is N/A.</p>
        <div class="mt-6 grid gap-4 lg:grid-cols-3" aria-label="Current and previous period comparison chart">
            <?php foreach (['income', 'expenses', 'net'] as $comparisonKey): ?>
                <?php
                $comparisonChart = $report['comparison'][$comparisonKey];
                $comparisonMaximum = max(1, abs((int) $comparisonChart['current']), abs((int) $comparisonChart['previous']));
                $currentComparisonWidth = abs((int) $comparisonChart['current']) / $comparisonMaximum * 100;
                $previousComparisonWidth = abs((int) $comparisonChart['previous']) / $comparisonMaximum * 100;
                ?>
                <article class="rounded-xl bg-slate-50 p-4">
                    <h3 class="text-sm font-semibold text-slate-800"><?= wallet_escape($comparisonChart['label']) ?></h3>
                    <div class="mt-4 space-y-3">
                        <div>
                            <div class="mb-1.5 flex justify-between gap-2 text-xs"><span class="text-emerald-800">Current</span><span class="font-medium tabular-nums"><?= wallet_escape($money((int) $comparisonChart['current'])) ?></span></div>
                            <div class="h-2 overflow-hidden rounded-full bg-white"><div class="h-full rounded-full bg-emerald-500" style="width: <?= number_format($currentComparisonWidth, 4, '.', '') ?>%"></div></div>
                        </div>
                        <div>
                            <div class="mb-1.5 flex justify-between gap-2 text-xs"><span class="text-slate-500">Previous</span><span class="font-medium tabular-nums"><?= wallet_escape($money((int) $comparisonChart['previous'])) ?></span></div>
                            <div class="h-2 overflow-hidden rounded-full bg-white"><div class="h-full rounded-full bg-slate-300" style="width: <?= number_format($previousComparisonWidth, 4, '.', '') ?>%"></div></div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="mt-5 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Comparison with <?= wallet_escape($period['previous_label']) ?></caption>
                <thead class="border-b border-slate-100 text-xs text-slate-500"><tr><th scope="col" class="pb-3 pr-4 font-medium">Metric</th><th scope="col" class="px-4 pb-3 text-right font-medium">Current</th><th scope="col" class="px-4 pb-3 text-right font-medium">Previous</th><th scope="col" class="px-4 pb-3 text-right font-medium">Difference</th><th scope="col" class="pb-3 pl-4 text-right font-medium">% change</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($report['comparison'] as $comparison): ?>
                        <?php
                        $currentValue = $comparison['is_rate']
                            ? ($comparison['current'] === null ? 'N/A' : number_format($comparison['current'], 1) . '%')
                            : $money((int) $comparison['current']);
                        $previousValue = $comparison['is_rate']
                            ? ($comparison['previous'] === null ? 'N/A' : number_format($comparison['previous'], 1) . '%')
                            : $money((int) $comparison['previous']);
                        $differenceValue = $comparison['is_rate']
                            ? $signedPercent($comparison['difference'], ' pp')
                            : $signedMoney($comparison['difference']);
                        ?>
                        <tr><th scope="row" class="py-4 pr-4 font-medium"><?= wallet_escape($comparison['label']) ?></th><td class="whitespace-nowrap px-4 py-4 text-right tabular-nums"><?= wallet_escape($currentValue) ?></td><td class="whitespace-nowrap px-4 py-4 text-right tabular-nums text-slate-500"><?= wallet_escape($previousValue) ?></td><td class="whitespace-nowrap px-4 py-4 text-right tabular-nums"><?= wallet_escape($differenceValue) ?></td><td class="whitespace-nowrap py-4 pl-4 text-right tabular-nums"><?= wallet_escape($signedPercent($comparison['percent_change'])) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="mt-4 text-xs text-slate-500">Income, expense, and net changes use neutral indicators because direction alone does not determine whether a change is desirable.</p>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/app_shell_end.php'; ?>
