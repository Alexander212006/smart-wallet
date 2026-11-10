<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/spending_analysis.php';
require_once __DIR__ . '/includes/expense_limits.php';

$userId = $walletUserId;
$name = is_string($_SESSION['user']['name'] ?? null) ? $_SESSION['user']['name'] : 'there';
$firstName = explode(' ', trim($name))[0];
$today = new DateTimeImmutable('today');
$monthStart = $today->modify('first day of this month')->format('Y-m-d');
$cashFlowRanges = [7 => 'Last 7 days', 30 => 'Last 30 days', 365 => 'Last 365 days'];
$requestedCashFlowDays = $_GET['cash_flow_days'] ?? null;
$cashFlowDays = is_string($requestedCashFlowDays)
    && ctype_digit($requestedCashFlowDays)
    && isset($cashFlowRanges[(int) $requestedCashFlowDays])
        ? (int) $requestedCashFlowDays
        : 7;
$cashFlowStartDate = $today->modify('-' . ($cashFlowDays - 1) . ' days');
$cashFlowStart = $cashFlowStartDate->format('Y-m-d');
$monthLabel = $today->format('F Y');
$pdo = getDatabaseConnection();
$expenseLimits = wallet_load_expense_limits($pdo, $userId, $today);
$overspendingAlerts = wallet_overspending_alerts($expenseLimits);
$overspendingNotifications = wallet_take_overspending_notifications($overspendingAlerts, $userId);

$currencyStatement = $pdo->prepare(
    'SELECT DISTINCT currency_code
     FROM wallets
     WHERE user_id = :user_id AND is_archived = 0
     ORDER BY currency_code'
);
$currencyStatement->execute(['user_id' => $userId]);
$currencies = $currencyStatement->fetchAll(PDO::FETCH_COLUMN);
$requestedCurrency = $_GET['currency'] ?? null;
$currency = is_string($requestedCurrency) && in_array($requestedCurrency, $currencies, true)
    ? $requestedCurrency
    : ($currencies[0] ?? null);
$hasAccounts = $currency !== null;

$accounts = [];
$balance = 0.0;
$monthlyIncome = 0.0;
$monthlyExpenses = 0.0;
$cashFlowActivity = [];
$expensesByCategory = [];
$budgets = [];
$goals = [];
$recentTransactions = [];

if ($hasAccounts) {
    $accountStatement = $pdo->prepare(
        'SELECT a.id, a.name, a.wallet_type AS account_type, a.currency_code,
                a.current_balance
         FROM wallets a
         WHERE a.user_id = :user_id
            AND a.currency_code = :currency
            AND a.is_archived = 0
         ORDER BY a.name'
    );
    $accountStatement->execute(['user_id' => $userId, 'currency' => $currency]);
    $accounts = $accountStatement->fetchAll();
    $monthlyTotals = wallet_call_procedure($pdo, 'sp_get_monthly_summary', [$userId, $currency, $monthStart])[0];
    $balance = (float) $monthlyTotals['balance'];
    $monthlyIncome = (float) $monthlyTotals['income'];
    $monthlyExpenses = (float) $monthlyTotals['expenses'];
    $activityRows = wallet_call_procedure($pdo, 'sp_get_cash_flow', [
        $userId, $currency, $cashFlowStart, $today->modify('+1 day')->format('Y-m-d'),
    ]);
    $activityByDate = [];
    foreach ($activityRows as $row) {
        $activityByDate[$row['activity_date']] = $row;
    }

    for ($day = $cashFlowDays - 1; $day >= 0; $day--) {
        $date = $today->modify("-$day days");
        $dateKey = $date->format('Y-m-d');
        $activity = $activityByDate[$dateKey] ?? ['income' => 0, 'expenses' => 0];
        if ($cashFlowDays === 365) {
            $monthKey = $date->format('Y-m');
            if (!isset($cashFlowActivity[$monthKey])) {
                $cashFlowActivity[$monthKey] = [
                    'label' => $date->format('M'),
                    'income' => 0.0,
                    'expenses' => 0.0,
                ];
            }
            $cashFlowActivity[$monthKey]['income'] += (float) $activity['income'];
            $cashFlowActivity[$monthKey]['expenses'] += (float) $activity['expenses'];
        } elseif ($cashFlowDays === 30) {
            $weekIndex = intdiv($cashFlowDays - 1 - $day, 7);
            $weekKey = (string) $weekIndex;
            if (!isset($cashFlowActivity[$weekKey])) {
                $weekStartDate = $cashFlowStartDate->modify('+' . ($weekIndex * 7) . ' days');
                $weekEndDate = $cashFlowStartDate->modify(
                    '+' . min(($weekIndex * 7) + 6, $cashFlowDays - 1) . ' days'
                );
                $cashFlowActivity[$weekKey] = [
                    'label' => $weekStartDate->format('M j') . '–' . $weekEndDate->format('M j'),
                    'income' => 0.0,
                    'expenses' => 0.0,
                ];
            }
            $cashFlowActivity[$weekKey]['income'] += (float) $activity['income'];
            $cashFlowActivity[$weekKey]['expenses'] += (float) $activity['expenses'];
        } else {
            $cashFlowActivity[] = [
                'label' => $date->format('D'),
                'income' => (float) $activity['income'],
                'expenses' => (float) $activity['expenses'],
            ];
        }
    }

    $expensesByCategory = array_slice(wallet_call_procedure($pdo, 'sp_get_category_spending', [$userId, $currency, $monthStart]), 0, 4);

    $budgets = wallet_active_expense_limits($expenseLimits, $currency);

    $goalStatement = $pdo->prepare(
        'SELECT id,name,target_amount,target_date,currency_code,saved FROM vw_savings_goal_progress
         WHERE user_id=:user_id AND currency_code=:currency AND status=\'active\'
         ORDER BY target_date IS NULL,target_date LIMIT 3'
    );
    $goalStatement->execute(['user_id' => $userId, 'currency' => $currency]);
    $goals = $goalStatement->fetchAll();

    $recentStatement = $pdo->prepare(
        'SELECT id,amount,description,transaction_date,account_name,category_name FROM vw_transaction_details
         WHERE user_id=:user_id AND currency_code=:currency AND is_archived=0
         ORDER BY transaction_date DESC,id DESC LIMIT 6'
    );
    $recentStatement->execute(['user_id' => $userId, 'currency' => $currency]);
    $recentTransactions = $recentStatement->fetchAll();
}

$activityValues = array_map(
    static fn (array $day): float => max($day['income'], $day['expenses']),
    $cashFlowActivity
);
$maxActivity = max([1, ...$activityValues]);
$formatMoney = static fn (float|int|string $amount): string =>
    ($currency ?? '') . ' ' . number_format((float) $amount, 2);
$spendingAnalysis = $hasAccounts
    ? wallet_load_spending_analysis($pdo, $userId, $currency, $today->modify('first day of this month'), $today)
    : null;
$activePage = 'overview';
$appTitle = 'Overview';
require __DIR__ . '/includes/app_shell_start.php';
?>
            <section class="ui-page-heading flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                <div>
                    <p class="ui-eyebrow"><?= $today->format('l, F j, Y') ?></p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Overview</h1>
                    <p class="mt-2 text-sm text-slate-500">Welcome back, <?= wallet_escape($firstName) ?>. Here is your financial picture.</p>
                </div>
                <?php if ($hasAccounts): ?>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="income.php?new=income" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-xs font-medium text-slate-600">Add income</a>
                        <a href="expenses.php?new=expense" class="inline-flex items-center gap-2 rounded-lg bg-[#123a2c] px-4 py-2.5 text-xs font-medium text-white"><span aria-hidden="true">+</span> Add expense</a>
                    </div>
                <?php endif; ?>
            </section>

            <?php require __DIR__ . '/includes/overspending_alerts.php'; ?>

            <?php if (!$hasAccounts): ?>
                <section class="rounded-2xl border border-slate-200 bg-white px-7 py-8 text-slate-900 sm:px-8">
                    <div class="max-w-2xl">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 9h18m-14 6h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        </span>
                        <h2 class="mt-5 text-2xl font-semibold tracking-tight">Your money overview starts with a wallet.</h2>
                        <p class="mt-3 text-sm leading-6 text-slate-500">
                            Your dashboard is ready. Once you create a wallet, you’ll see your balance, monthly activity, expense insights, and savings progress here.
                        </p>
                        <a href="wallet.php" class="mt-5 inline-flex items-center gap-3 rounded-lg bg-[#123a2c] px-4 py-2.5 text-sm font-medium text-white">Create your first wallet <span aria-hidden="true">&rarr;</span></a>
                    </div>
                </section>
                <section class="grid gap-4 sm:grid-cols-3">
                    <?php foreach (['Total balance', 'Income this month', 'Expenses this month'] as $label): ?>
                        <article class="ui-card rounded-2xl border border-slate-200/80 bg-white p-5">
                            <p class="text-sm font-medium text-slate-500"><?= wallet_escape($label) ?></p>
                            <p class="mt-4 text-2xl font-semibold tracking-tight text-slate-300">—</p>
                            <p class="mt-2 text-xs text-slate-400">Create a wallet to see this summary</p>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php else: ?>
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Financial summary">
                    <article class="ui-metric ui-metric-primary rounded-2xl bg-[#123a2c] p-5 text-white shadow-sm sm:col-span-2 xl:col-span-1">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-emerald-100">Total balance</p>
                            <span class="ui-metric-icon flex h-9 w-9 items-center justify-center rounded-xl bg-white/10 text-emerald-200">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 8.5h15a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2v-11a1 1 0 0 1 1-1h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M16 13.5h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            </span>
                        </div>
                        <p class="mt-5 text-3xl font-semibold tracking-tight"><?= wallet_escape($formatMoney($balance)) ?></p>
                        <p class="mt-2 text-xs text-emerald-100/80">Across <?= count($accounts) ?> active <?= count($accounts) === 1 ? 'wallet' : 'wallets' ?></p>
                    </article>
                    <article class="ui-metric ui-card rounded-2xl border border-slate-200/80 bg-white p-5">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-slate-500">Income this month</p>
                            <span class="ui-metric-icon flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 19V5m-6 6 6-6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </div>
                        <p class="mt-5 text-2xl font-semibold tracking-tight"><?= wallet_escape($formatMoney($monthlyIncome)) ?></p>
                        <p class="mt-2 text-xs text-slate-400">Since <?= wallet_escape($today->modify('first day of this month')->format('M j')) ?></p>
                    </article>
                    <article class="ui-metric ui-card rounded-2xl border border-slate-200/80 bg-white p-5">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-slate-500">Expenses this month</p>
                            <span class="ui-metric-icon flex h-9 w-9 items-center justify-center rounded-xl bg-rose-50 text-rose-600">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14m6-6-6 6-6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </div>
                        <p class="mt-5 text-2xl font-semibold tracking-tight"><?= wallet_escape($formatMoney($monthlyExpenses)) ?></p>
                        <p class="mt-2 text-xs text-slate-400">Across your active wallets</p>
                    </article>
                    <article class="ui-metric ui-card rounded-2xl border border-slate-200/80 bg-white p-5">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-slate-500">Net cash flow</p>
                            <span class="ui-metric-icon flex h-9 w-9 items-center justify-center rounded-xl bg-sky-50 text-sky-700">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 17.5 9.2 12l3.5 3.2L20 7.5M14.8 7.5H20v5.2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </div>
                        <p class="mt-5 text-2xl font-semibold tracking-tight <?= $monthlyIncome - $monthlyExpenses < 0 ? 'text-rose-600' : 'text-slate-900' ?>">
                            <?= wallet_escape($formatMoney($monthlyIncome - $monthlyExpenses)) ?>
                        </p>
                        <p class="mt-2 text-xs text-slate-400">Income minus expenses</p>
                    </article>
                </section>

                <section class="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(300px,0.85fr)]">
                    <article class="ui-card rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="font-semibold tracking-tight">Cash flow</h2>
                                <p class="mt-1 text-sm text-slate-500">
                                    <?= $cashFlowDays === 365 ? 'Monthly totals' : ($cashFlowDays === 30 ? 'Weekly totals' : 'Daily totals') ?> · <?= wallet_escape(strtolower($cashFlowRanges[$cashFlowDays])) ?>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-4">
                                <div class="flex gap-4 text-xs font-medium text-slate-500">
                                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>Income</span>
                                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-rose-400"></span>Expenses</span>
                                </div>
                                <form method="get" action="index.php">
                                    <input type="hidden" name="currency" value="<?= wallet_escape($currency) ?>">
                                    <label for="cash_flow_days" class="sr-only">Cash flow date range</label>
                                    <select id="cash_flow_days" name="cash_flow_days" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs font-medium text-slate-600 outline-none focus:border-emerald-600">
                                        <?php foreach ($cashFlowRanges as $days => $label): ?>
                                            <option value="<?= $days ?>" <?= $cashFlowDays === $days ? 'selected' : '' ?>><?= wallet_escape($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-slate-400">Bar percentages are relative to the largest amount in this range.</p>
                        <div class="mt-5 space-y-3">
                            <?php foreach ($cashFlowActivity as $day): ?>
                                <?php
                                $incomePercent = (int) round($day['income'] / $maxActivity * 100);
                                $expensePercent = (int) round($day['expenses'] / $maxActivity * 100);
                                $incomeWidth = $day['income'] > 0 ? max(2, $incomePercent) : 0;
                                $expenseWidth = $day['expenses'] > 0 ? max(2, $expensePercent) : 0;
                                ?>
                                <div class="grid grid-cols-[52px_minmax(0,1fr)_32px] items-center gap-3">
                                    <span class="truncate text-xs font-medium text-slate-400"><?= wallet_escape($day['label']) ?></span>
                                    <div class="space-y-1.5">
                                        <div class="h-2 overflow-hidden rounded-full bg-slate-100" title="Income: <?= wallet_escape($formatMoney($day['income'])) ?>">
                                            <div class="h-full rounded-full bg-emerald-500" style="width: <?= $incomeWidth ?>%"></div>
                                        </div>
                                        <div class="h-2 overflow-hidden rounded-full bg-slate-100" title="Expenses: <?= wallet_escape($formatMoney($day['expenses'])) ?>">
                                            <div class="h-full rounded-full bg-rose-400" style="width: <?= $expenseWidth ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="space-y-1 text-right text-[10px] font-medium leading-3 text-slate-400" aria-label="Income and expense percentages of range peak">
                                        <div><?= $incomePercent ?>%</div>
                                        <div><?= $expensePercent ?>%</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($monthlyExpenses > 0 && $expensesByCategory !== []): ?>
                            <div class="mt-7 rounded-xl bg-[#f6f8f6] px-4 py-3 text-sm text-slate-600">
                                Your top expense category this month is
                                <span class="font-semibold text-slate-900"><?= wallet_escape((string) $expensesByCategory[0]['name']) ?></span>.
                            </div>
                        <?php endif; ?>
                    </article>

                    <article id="wallet" class="ui-card rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="font-semibold tracking-tight">Wallets</h2>
                                <p class="mt-1 text-sm text-slate-500">Your balances at a glance</p>
                            </div>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600"><?= count($accounts) ?></span>
                        </div>
                        <div class="mt-5 divide-y divide-slate-100">
                            <?php foreach ($accounts as $account): ?>
                                <div class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-800">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 9h18m-14 6h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium"><?= wallet_escape((string) $account['name']) ?></p>
                                            <p class="mt-0.5 text-xs capitalize text-slate-500"><?= wallet_escape((string) $account['account_type']) ?></p>
                                        </div>
                                    </div>
                                    <p class="shrink-0 text-sm font-semibold"><?= wallet_escape($formatMoney((float) $account['current_balance'])) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <a href="wallet.php" class="mt-6 inline-flex items-center gap-2 text-xs font-medium text-emerald-700">Manage wallets <span aria-hidden="true">&rarr;</span></a>
                    </article>
                </section>

                <section class="grid gap-6 xl:grid-cols-2">
                    <article id="expenses" class="ui-card rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="font-semibold tracking-tight">Expenses</h2>
                                <p class="mt-1 text-sm text-slate-500">Your active weekly, monthly, and yearly limits</p>
                            </div>
                            <svg class="h-5 w-5 text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 19V5m0 14h16M8 15v-3m4 3V7m4 8v-5m4 5V4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                        <?php if ($budgets === []): ?>
                            <p class="mt-6 rounded-xl bg-slate-50 px-4 py-5 text-sm text-slate-500">No active expense limits yet.</p>
                        <?php else: ?>
                            <div class="mt-5 space-y-5">
                                <?php foreach ($budgets as $budget): ?>
                                    <div data-overview-expense-limit data-limit-id="<?= (int) $budget['id'] ?>">
                                        <div class="mb-2 flex flex-col items-start justify-between gap-1.5 sm:flex-row sm:items-center sm:gap-3">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-medium"><?= wallet_escape((string) $budget['name']) ?></p>
                                                <p class="mt-0.5 text-xs text-slate-500"><?= wallet_escape((string) $budget['category_name']) ?> · <?= wallet_escape(ucfirst((string) $budget['period'])) ?></p>
                                            </div>
                                            <p class="shrink-0 text-left text-xs sm:text-right <?= $budget['is_over'] ? 'font-semibold text-rose-600' : 'text-slate-500' ?>">
                                                <?= wallet_escape(wallet_expense_limit_money($budget, $budget['spent_cents'])) ?> spent / <?= wallet_escape(wallet_expense_limit_money($budget, $budget['limit_cents'])) ?> limit
                                            </p>
                                        </div>
                                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full <?= $budget['is_over'] ? 'bg-rose-500' : 'bg-emerald-500' ?>" style="width: <?= $budget['progress_percent'] ?>%"></div>
                                        </div>
                                        <p class="mt-1.5 text-xs <?= $budget['is_over'] ? 'font-medium text-rose-600' : 'text-slate-400' ?>"><?= wallet_escape(wallet_expense_limit_status($budget)) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>

                    <article id="savings" class="ui-card rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="font-semibold tracking-tight">Savings</h2>
                                <p class="mt-1 text-sm text-slate-500">Small steps add up</p>
                            </div>
                            <svg class="h-5 w-5 text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="4.5" stroke="currentColor" stroke-width="1.7"/><circle cx="12" cy="12" r="1" fill="currentColor"/></svg>
                        </div>
                        <?php if ($goals === []): ?>
                            <p class="mt-6 rounded-xl bg-slate-50 px-4 py-5 text-sm text-slate-500">Your active savings goals will show up here.</p>
                        <?php else: ?>
                            <div class="mt-5 space-y-5">
                                <?php foreach ($goals as $goal): ?>
                                    <?php
                                    $targetAmount = (float) $goal['target_amount'];
                                    $saved = (float) $goal['saved'];
                                    $progress = $targetAmount > 0 ? min(100, (int) round($saved / $targetAmount * 100)) : 0;
                                    ?>
                                    <div>
                                        <div class="mb-2 flex items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-medium"><?= wallet_escape((string) $goal['name']) ?></p>
                                                <p class="mt-0.5 text-xs text-slate-500">
                                                    <?= $goal['target_date'] !== null ? 'Target ' . wallet_escape(date('M j, Y', strtotime((string) $goal['target_date']))) : 'No target date' ?>
                                                </p>
                                            </div>
                                            <p class="shrink-0 text-right text-xs text-slate-500"><?= wallet_escape($formatMoney($saved)) ?> / <?= wallet_escape($formatMoney($targetAmount)) ?></p>
                                        </div>
                                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-sky-500" style="width: <?= $progress ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                </section>

                <?php require __DIR__ . '/includes/spending_summary.php'; ?>

                <section class="ui-card rounded-2xl border border-slate-200/80 bg-white">
                    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-5 sm:px-6">
                        <div>
                            <h2 class="font-semibold tracking-tight">Recent transactions</h2>
                            <p class="mt-1 text-sm text-slate-500">Your latest wallet activity</p>
                        </div>
                    </div>
                    <?php if ($recentTransactions === []): ?>
                        <p class="px-5 py-8 text-center text-sm text-slate-500 sm:px-6">Your transactions will appear here when you add them.</p>
                    <?php else: ?>
                        <div class="divide-y divide-slate-100">
                            <?php foreach ($recentTransactions as $transaction): ?>
                                <?php
                                $isIncome = (float) $transaction['amount'] > 0;
                                $amountPrefix = $isIncome ? '+' : '−';
                                $amountClass = $isIncome ? 'text-emerald-700' : 'text-slate-900';
                                $detail = $transaction['category_name'];
                                ?>
                                <div class="flex items-center justify-between gap-4 px-5 py-4 sm:px-6">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl <?= $isIncome ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <?php if ($isIncome): ?>
                                                    <path d="M12 19V5m-6 6 6-6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                                <?php else: ?>
                                                    <path d="M12 5v14m6-6-6 6-6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                                <?php endif; ?>
                                            </svg>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium"><?= wallet_escape((string) ($transaction['description'] ?: $detail)) ?></p>
                                            <p class="mt-0.5 truncate text-xs text-slate-500">
                                                <?= wallet_escape($detail) ?> · <?= wallet_escape((string) $transaction['account_name']) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="text-sm font-semibold <?= $amountClass ?>"><?= $amountPrefix ?><?= wallet_escape($formatMoney(abs((float) $transaction['amount']))) ?></p>
                                        <p class="mt-0.5 text-xs text-slate-500"><?= wallet_escape(date('M j, Y', strtotime((string) $transaction['transaction_date']))) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
<?php require __DIR__ . '/includes/app_shell_end.php'; ?>
