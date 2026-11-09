<?php

declare(strict_types=1);

if ($spendingAnalysis === null) {
    return;
}

$topSpendingCategory = $spendingAnalysis['categories'][0] ?? null;
$spendingChange = $spendingAnalysis['change_percent'];
$spendingComparison = 'No change';
$spendingComparisonTone = 'text-slate-900';
if ($spendingChange === null && $spendingAnalysis['total_cents'] > 0) {
    $spendingComparison = 'No prior spending';
} elseif ($spendingChange !== null && $spendingChange != 0) {
    $spendingComparison = number_format(abs($spendingChange), 1) . '% ' . ($spendingChange > 0 ? 'increase' : 'decrease');
    $spendingComparisonTone = $spendingChange > 0 ? 'text-rose-600' : 'text-emerald-700';
}
?>
<section aria-labelledby="spending-summary-title" class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="spending-summary-title" class="font-semibold tracking-tight">Spending behavior</h2>
            <p class="mt-1 text-sm text-slate-500">Your patterns in <?= $today->format('F') ?> so far</p>
        </div>
        <a href="spending.php?currency=<?= rawurlencode($currency) ?>" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-900 transition hover:bg-emerald-100">View analysis &rarr;</a>
    </div>
    <?php if ($spendingAnalysis['transaction_count'] === 0): ?>
        <p class="mt-5 rounded-xl bg-slate-50 px-4 py-5 text-sm text-slate-500">Record expenses to discover your most used categories, peak spending days, and monthly changes.</p>
    <?php else: ?>
        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl bg-slate-50 p-4">
                <p class="text-xs font-medium text-slate-500">Most used category</p>
                <p class="mt-2 break-words text-base font-semibold"><?= wallet_escape($topSpendingCategory['name']) ?></p>
                <p class="mt-1 text-xs text-slate-500"><?= $topSpendingCategory['count'] ?> <?= $topSpendingCategory['count'] === 1 ? 'expense' : 'expenses' ?> this month</p>
            </div>
            <div class="rounded-xl bg-slate-50 p-4">
                <p class="text-xs font-medium text-slate-500">Highest spending day</p>
                <p class="mt-2 text-base font-semibold"><?= wallet_escape((new DateTimeImmutable($spendingAnalysis['peak_day']['date']))->format('D, M j')) ?></p>
                <p class="mt-1 text-xs text-slate-500"><?= wallet_escape($formatMoney($spendingAnalysis['peak_day']['total_cents'] / 100)) ?></p>
            </div>
            <div class="rounded-xl bg-slate-50 p-4">
                <p class="text-xs font-medium text-slate-500">Monthly comparison</p>
                <p class="mt-2 text-base font-semibold <?= $spendingComparisonTone ?>"><?= wallet_escape($spendingComparison) ?></p>
                <p class="mt-1 text-xs text-slate-500">vs. <?= wallet_escape($spendingAnalysis['comparison_label']) ?></p>
            </div>
            <div class="rounded-xl bg-slate-50 p-4">
                <p class="text-xs font-medium text-slate-500">Essential / non-essential</p>
                <p class="mt-2 text-base font-semibold"><?= number_format($spendingAnalysis['nature']['essential']['share_percent'], 0) ?>% / <?= number_format($spendingAnalysis['nature']['non_essential']['share_percent'], 0) ?>%</p>
                <p class="mt-1 text-xs text-slate-500"><?= number_format($spendingAnalysis['nature']['unclassified']['share_percent'], 0) ?>% unclassified &middot; by amount</p>
            </div>
        </div>
    <?php endif; ?>
</section>
