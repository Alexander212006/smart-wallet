<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/spending_analysis.php';

$pdo = getDatabaseConnection();
$today = new DateTimeImmutable('today');
$requestedMonth = $_GET['month'] ?? null;
$month = wallet_spending_month(is_string($requestedMonth) ? $requestedMonth : null, $today);
$currencyStatement = $pdo->prepare(
    'SELECT DISTINCT currency_code FROM wallets
     WHERE user_id = :user_id AND is_archived = 0 ORDER BY currency_code'
);
$currencyStatement->execute(['user_id' => $walletUserId]);
$currencies = $currencyStatement->fetchAll(PDO::FETCH_COLUMN);
$requestedCurrency = $_GET['currency'] ?? null;
$currency = is_string($requestedCurrency) && in_array($requestedCurrency, $currencies, true)
    ? $requestedCurrency
    : ($currencies[0] ?? null);
$analysis = $currency !== null
    ? wallet_load_spending_analysis($pdo, $walletUserId, $currency, $month, $today)
    : null;
$money = static fn (int $cents): string => ($currency ?? '') . ' ' . number_format($cents / 100, 2);
$shortDate = static fn (string $date): string => (new DateTimeImmutable($date))->format('M j');
$natureLabels = ['essential' => 'Essential', 'non_essential' => 'Non-essential', 'unclassified' => 'Unclassified'];
$natureColors = ['essential' => 'bg-emerald-500', 'non_essential' => 'bg-amber-400', 'unclassified' => 'bg-slate-300'];
$changeText = 'No change';
$changeTone = 'text-slate-600';
if ($analysis !== null) {
    $change = $analysis['change_percent'];
    if ($change === null && $analysis['total_cents'] > 0) {
        $changeText = 'No prior spending';
    } elseif ($change !== null && $change != 0) {
        $changeText = number_format(abs($change), 1) . '% ' . ($change > 0 ? 'increase' : 'decrease');
        $changeTone = $change > 0 ? 'text-rose-600' : 'text-emerald-700';
    }
}
$appTitle = 'Spending analysis';
$activePage = 'spending';
require __DIR__ . '/includes/app_shell_start.php';
?>
<section class="ui-page-heading flex flex-col justify-between gap-5 xl:flex-row xl:items-end">
    <div>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Spending analysis</h1>
        <p class="mt-2 max-w-xl text-sm leading-6 text-slate-500">See where your money goes, when you spend most, and how your habits change.</p>
    </div>
    <?php if ($currency !== null): ?>
        <form method="get" action="spending.php" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-3">
            <div>
                <label for="spending-month" class="mb-1 block text-xs font-medium text-slate-500">Month</label>
                <select id="spending-month" name="month" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600">
                    <?php for ($offset = 0; $offset < 12; $offset++): ?>
                        <?php $optionMonth = $today->modify('first day of this month')->modify('-' . $offset . ' months'); ?>
                        <option value="<?= $optionMonth->format('Y-m') ?>" <?= $optionMonth->format('Y-m') === $month->format('Y-m') ? 'selected' : '' ?>><?= $optionMonth->format('F Y') ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div>
                <label for="spending-currency" class="mb-1 block text-xs font-medium text-slate-500">Currency</label>
                <select id="spending-currency" name="currency" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-600">
                    <?php foreach ($currencies as $optionCurrency): ?>
                        <option value="<?= wallet_escape((string) $optionCurrency) ?>" <?= $optionCurrency === $currency ? 'selected' : '' ?>><?= wallet_escape((string) $optionCurrency) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="rounded-lg bg-[#102820] px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">Update view</button>
        </form>
    <?php endif; ?>
</section>

<?php if ($analysis === null): ?>
    <section class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
        <h2 class="text-lg font-semibold">Your spending story starts with a wallet</h2>
        <p class="mt-2 text-sm text-slate-500">Create or restore a wallet, then record expenses to discover your patterns.</p>
        <a href="wallet.php" class="mt-5 inline-flex rounded-xl bg-[#102820] px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-900">Manage wallets</a>
    </section>
<?php else: ?>
    <p class="text-xs leading-5 text-slate-500">
        <?= $month->format('M j') ?>&ndash;<?= $analysis['period_end']->modify('-1 day')->format('M j, Y') ?>
        &middot; <?= wallet_escape($currency) ?> &middot; Active wallets only. Future-dated expenses are excluded.
    </p>
    <?php if ($analysis['transaction_count'] === 0): ?>
        <section class="flex flex-col justify-between gap-4 rounded-2xl border border-emerald-100 bg-emerald-50/60 p-5 sm:flex-row sm:items-center">
            <div>
                <h2 class="font-semibold text-emerald-950">No expenses recorded for <?= $month->format('F Y') ?></h2>
                <p class="mt-1 text-sm text-emerald-800">Choose another month or add an expense. Your previous months are shown below.</p>
            </div>
            <a href="expenses.php" class="shrink-0 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-emerald-900 ring-1 ring-emerald-200 hover:bg-emerald-50">Add an expense</a>
        </section>
    <?php endif; ?>

    <section aria-label="Spending highlights" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="ui-metric ui-metric-primary rounded-2xl bg-[#102820] p-5 text-white">
            <p class="text-sm text-emerald-100/80"><?= $analysis['is_current_month'] ? 'Spent this month so far' : 'Total monthly spending' ?></p>
            <p class="mt-4 break-words text-2xl font-semibold tracking-tight"><?= wallet_escape($money($analysis['total_cents'])) ?></p>
            <p class="mt-2 text-xs text-emerald-100/70"><?= $analysis['transaction_count'] ?> <?= $analysis['transaction_count'] === 1 ? 'expense' : 'expenses' ?> recorded</p>
        </article>
        <article class="ui-metric ui-card rounded-2xl border border-slate-200/80 bg-white p-5">
            <p class="text-sm text-slate-500">Compared with <?= wallet_escape($analysis['comparison_label']) ?></p>
            <p class="mt-4 text-xl font-semibold tracking-tight <?= $changeTone ?>"><?= wallet_escape($changeText) ?></p>
            <p class="mt-2 text-xs leading-5 text-slate-500">Previous: <?= wallet_escape($money($analysis['previous_total_cents'])) ?></p>
        </article>
        <article class="ui-metric ui-card rounded-2xl border border-slate-200/80 bg-white p-5">
            <p class="text-sm text-slate-500">Highest spending day</p>
            <p class="mt-4 text-xl font-semibold tracking-tight"><?= $analysis['peak_day'] !== null ? wallet_escape((new DateTimeImmutable($analysis['peak_day']['date']))->format('D, M j')) : 'No spending yet' ?></p>
            <p class="mt-2 text-xs text-slate-500"><?= $analysis['peak_day'] !== null ? wallet_escape($money($analysis['peak_day']['total_cents'])) : 'Add expenses to find your peak day.' ?></p>
        </article>
        <article class="ui-metric ui-card rounded-2xl border border-slate-200/80 bg-white p-5">
            <p class="text-sm text-slate-500">Highest spending week</p>
            <p class="mt-4 text-xl font-semibold tracking-tight"><?= $analysis['peak_week'] !== null ? wallet_escape($shortDate($analysis['peak_week']['start']) . '–' . $shortDate($analysis['peak_week']['end'])) : 'No spending yet' ?></p>
            <p class="mt-2 text-xs text-slate-500"><?= $analysis['peak_week'] !== null ? wallet_escape($money($analysis['peak_week']['total_cents'])) : 'Add expenses to find your peak week.' ?></p>
        </article>
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        <article class="min-w-0 rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
            <h2 class="font-semibold tracking-tight">Most used spending categories</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">Ranked by how often you spend, with each category's share of the monthly total.</p>
            <?php if ($analysis['categories'] === []): ?>
                <p class="mt-6 rounded-xl bg-slate-50 px-4 py-6 text-sm text-slate-500">Category patterns will appear when you record expenses.</p>
            <?php else: ?>
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Expense categories ranked by transaction frequency for <?= $month->format('F Y') ?></caption>
                        <thead class="border-b border-slate-100 text-xs text-slate-400">
                            <tr><th scope="col" class="pb-3 pr-3 font-medium">Category</th><th scope="col" class="px-2 pb-3 text-right font-medium">Expenses</th><th scope="col" class="pb-3 pl-3 text-right font-medium">Spent / share</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($analysis['categories'] as $index => $category): ?>
                                <tr>
                                    <th scope="row" class="max-w-[200px] py-4 pr-3 font-medium">
                                        <span class="mr-2 text-xs text-slate-400"><?= $index + 1 ?></span><?= wallet_escape($category['name']) ?>
                                    </th>
                                    <td class="px-2 py-4 text-right tabular-nums text-slate-500"><?= $category['count'] ?></td>
                                    <td class="py-4 pl-3 text-right tabular-nums">
                                        <p class="whitespace-nowrap font-medium"><?= wallet_escape($money($category['total_cents'])) ?></p>
                                        <p class="mt-1 text-xs text-slate-500"><?= number_format($category['share_percent'], 1) ?>%</p>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>

        <article class="min-w-0 rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
            <h2 class="font-semibold tracking-tight">Essential vs. non-essential</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">Based on the expense types you choose when adding or editing an expense.</p>
            <?php if ($analysis['total_cents'] > 0): ?>
                <div class="mt-6 flex h-4 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="Expense type shares: <?php foreach ($natureLabels as $key => $label): ?><?= wallet_escape($label) ?> <?= number_format($analysis['nature'][$key]['share_percent'], 1) ?> percent. <?php endforeach; ?>">
                    <?php foreach ($natureLabels as $key => $label): ?>
                        <div class="h-full <?= $natureColors[$key] ?>" style="width: <?= number_format($analysis['nature'][$key]['share_percent'], 4, '.', '') ?>%"></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="mt-5 divide-y divide-slate-100">
                <?php foreach ($natureLabels as $key => $label): ?>
                    <div class="flex items-center justify-between gap-3 py-4">
                        <div>
                            <p class="flex items-center gap-2 text-sm font-medium"><span class="h-2.5 w-2.5 rounded-full <?= $natureColors[$key] ?>"></span><?= wallet_escape($label) ?></p>
                            <p class="mt-1 pl-[18px] text-xs text-slate-500"><?= $analysis['nature'][$key]['count'] ?> <?= $analysis['nature'][$key]['count'] === 1 ? 'expense' : 'expenses' ?></p>
                        </div>
                        <div class="text-right text-sm">
                            <p class="font-semibold"><?= wallet_escape($money($analysis['nature'][$key]['total_cents'])) ?></p>
                            <p class="mt-1 text-xs text-slate-500"><?= number_format($analysis['nature'][$key]['share_percent'], 1) ?>%</p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($analysis['nature']['unclassified']['count'] > 0): ?>
                <p class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-600">Some expenses are unclassified. <a href="expenses.php" class="font-semibold text-emerald-800 underline underline-offset-2">Label them in Expenses</a> to complete this picture.</p>
            <?php else: ?>
                <a href="expenses.php" class="mt-4 inline-flex text-sm font-semibold text-emerald-800 hover:underline">Manage expense types &rarr;</a>
            <?php endif; ?>
        </article>
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        <article class="min-w-0 rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
            <h2 class="font-semibold tracking-tight">Spending across months</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">Six monthly totals. The current month includes spending recorded so far.</p>
            <?php $maxMonthlySpending = max(1, ...array_column($analysis['months'], 'total_cents')); ?>
            <div class="mt-6 space-y-5">
                <?php foreach ($analysis['months'] as $trendMonth): ?>
                    <div>
                        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-sm">
                            <span class="<?= $trendMonth['month'] === $month->format('Y-m') ? 'font-semibold text-emerald-900' : 'text-slate-600' ?>"><?= wallet_escape($trendMonth['label']) ?><?= $trendMonth['is_partial'] ? ' · so far' : '' ?></span>
                            <span class="font-medium tabular-nums"><?= wallet_escape($money($trendMonth['total_cents'])) ?></span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                            <div class="h-full rounded-full <?= $trendMonth['month'] === $month->format('Y-m') ? 'bg-emerald-500' : 'bg-emerald-200' ?>" style="width: <?= number_format($trendMonth['total_cents'] / $maxMonthlySpending * 100, 4, '.', '') ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="mt-5 text-xs leading-5 text-slate-400"><?= $analysis['is_current_month'] ? 'The comparison above uses the same days of the previous month, capped at its last day.' : 'The comparison above uses the full previous calendar month.' ?> Bar lengths compare monthly totals.</p>
        </article>

        <article class="min-w-0 rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
            <h2 class="font-semibold tracking-tight">Weekly spending pattern</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500">Monday&ndash;Sunday weeks, counting only days in the selected period.</p>
            <?php $maxWeeklySpending = max(1, ...array_column($analysis['weeks'], 'total_cents')); ?>
            <div class="mt-6 space-y-5">
                <?php foreach ($analysis['weeks'] as $week): ?>
                    <div>
                        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-sm">
                            <span class="text-slate-600"><?= wallet_escape($shortDate($week['start']) . '–' . $shortDate($week['end'])) ?></span>
                            <span class="font-medium tabular-nums"><?= wallet_escape($money($week['total_cents'])) ?></span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                            <div class="h-full rounded-full bg-emerald-500" style="width: <?= number_format($week['total_cents'] / $maxWeeklySpending * 100, 4, '.', '') ?>%"></div>
                        </div>
                        <p class="mt-1 text-xs text-slate-400"><?= $week['count'] ?> <?= $week['count'] === 1 ? 'expense' : 'expenses' ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/app_shell_end.php'; ?>
