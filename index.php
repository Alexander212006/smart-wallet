<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

wallet_start_session();

if (!isset($_SESSION['user']['id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user']['id'];
$name = is_string($_SESSION['user']['name'] ?? null) ? $_SESSION['user']['name'] : 'there';
$firstName = explode(' ', trim($name))[0];
$today = new DateTimeImmutable('today');
$monthStart = $today->modify('first day of this month')->format('Y-m-d');
$nextMonth = $today->modify('first day of next month')->format('Y-m-d');
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

$currencyStatement = $pdo->prepare(
    'SELECT DISTINCT currency_code
     FROM accounts
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
        'SELECT a.id, a.name, a.account_type, a.currency_code,
                a.opening_balance
                + COALESCE(SUM(
                    CASE
                        WHEN t.transaction_type = \'income\' AND t.account_id = a.id THEN t.amount
                        WHEN t.transaction_type = \'expense\' AND t.account_id = a.id THEN -t.amount
                        WHEN t.transaction_type = \'transfer\' AND t.account_id = a.id THEN -t.amount
                        WHEN t.transaction_type = \'transfer\' AND t.to_account_id = a.id THEN t.amount
                        ELSE 0
                    END
                ), 0) AS current_balance
         FROM accounts a
         LEFT JOIN transactions t
            ON t.user_id = a.user_id
            AND (t.account_id = a.id OR t.to_account_id = a.id)
         WHERE a.user_id = :user_id
            AND a.currency_code = :currency
            AND a.is_archived = 0
         GROUP BY a.id, a.name, a.account_type, a.currency_code, a.opening_balance
         ORDER BY a.name'
    );
    $accountStatement->execute(['user_id' => $userId, 'currency' => $currency]);
    $accounts = $accountStatement->fetchAll();
    $balance = array_sum(array_map(
        static fn (array $account): float => (float) $account['current_balance'],
        $accounts
    ));

    $monthlyStatement = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN t.transaction_type = \'income\' THEN t.amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN t.transaction_type = \'expense\' THEN t.amount ELSE 0 END), 0) AS expenses
         FROM transactions t
         INNER JOIN accounts a ON a.id = t.account_id
         WHERE t.user_id = :user_id
            AND a.user_id = :account_user_id
            AND a.currency_code = :currency
            AND a.is_archived = 0
            AND t.transaction_type IN (\'income\', \'expense\')
            AND t.transaction_date >= :month_start
            AND t.transaction_date < :next_month'
    );
    $monthlyStatement->execute([
        'user_id' => $userId,
        'account_user_id' => $userId,
        'currency' => $currency,
        'month_start' => $monthStart,
        'next_month' => $nextMonth,
    ]);
    $monthlyTotals = $monthlyStatement->fetch();
    $monthlyIncome = (float) $monthlyTotals['income'];
    $monthlyExpenses = (float) $monthlyTotals['expenses'];

    $activityStatement = $pdo->prepare(
        'SELECT DATE(t.transaction_date) AS activity_date,
                SUM(CASE WHEN t.transaction_type = \'income\' THEN t.amount ELSE 0 END) AS income,
                SUM(CASE WHEN t.transaction_type = \'expense\' THEN t.amount ELSE 0 END) AS expenses
         FROM transactions t
         INNER JOIN accounts a ON a.id = t.account_id
         WHERE t.user_id = :user_id
            AND a.user_id = :account_user_id
            AND a.currency_code = :currency
            AND a.is_archived = 0
            AND t.transaction_type IN (\'income\', \'expense\')
            AND t.transaction_date >= :activity_start
            AND t.transaction_date < :tomorrow
         GROUP BY DATE(t.transaction_date)'
    );
    $activityStatement->execute([
        'user_id' => $userId,
        'account_user_id' => $userId,
        'currency' => $currency,
        'activity_start' => $cashFlowStart,
        'tomorrow' => $today->modify('+1 day')->format('Y-m-d'),
    ]);
    $activityByDate = [];
    foreach ($activityStatement->fetchAll() as $row) {
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

    $categoryStatement = $pdo->prepare(
        'SELECT COALESCE(c.name, \'Uncategorized\') AS name, SUM(t.amount) AS total
         FROM transactions t
         INNER JOIN accounts a ON a.id = t.account_id
         LEFT JOIN categories c
            ON c.id = t.category_id
            AND (c.user_id = :category_user_id OR c.user_id IS NULL)
         WHERE t.user_id = :user_id
            AND a.user_id = :account_user_id
            AND a.currency_code = :currency
            AND a.is_archived = 0
            AND t.transaction_type = \'expense\'
            AND t.transaction_date >= :month_start
            AND t.transaction_date < :next_month
         GROUP BY c.id, c.name
         ORDER BY total DESC
         LIMIT 4'
    );
    $categoryStatement->execute([
        'category_user_id' => $userId,
        'user_id' => $userId,
        'account_user_id' => $userId,
        'currency' => $currency,
        'month_start' => $monthStart,
        'next_month' => $nextMonth,
    ]);
    $expensesByCategory = $categoryStatement->fetchAll();

    $budgetStatement = $pdo->prepare(
        'SELECT b.id, b.name, b.amount,
                COALESCE(c.name, \'All categories\') AS category_name,
                COALESCE((
                    SELECT SUM(t.amount)
                    FROM transactions t
                    INNER JOIN accounts a ON a.id = t.account_id
                    WHERE t.user_id = b.user_id
                        AND a.user_id = b.user_id
                        AND a.currency_code = b.currency_code
                        AND a.is_archived = 0
                        AND t.transaction_type = \'expense\'
                        AND t.transaction_date >= :month_start
                        AND t.transaction_date < :next_month
                        AND (b.category_id IS NULL OR t.category_id = b.category_id)
                ), 0) AS spent
         FROM budgets b
         LEFT JOIN categories c
            ON c.id = b.category_id
            AND (c.user_id = b.user_id OR c.user_id IS NULL)
         WHERE b.user_id = :user_id
            AND b.currency_code = :currency
            AND b.period = \'monthly\'
            AND b.start_date <= :today
            AND (b.end_date IS NULL OR b.end_date >= :today_for_end)
         ORDER BY b.name
         LIMIT 4'
    );
    $budgetStatement->execute([
        'month_start' => $monthStart,
        'next_month' => $nextMonth,
        'user_id' => $userId,
        'currency' => $currency,
        'today' => $today->format('Y-m-d'),
        'today_for_end' => $today->format('Y-m-d'),
    ]);
    $budgets = $budgetStatement->fetchAll();

    $goalStatement = $pdo->prepare(
        'SELECT g.id, g.name, g.target_amount, g.target_date, g.currency_code,
                COALESCE(SUM(sc.amount), 0) AS saved
         FROM savings_goals g
         LEFT JOIN savings_contributions sc ON sc.savings_goal_id = g.id
         WHERE g.user_id = :user_id
            AND g.currency_code = :currency
            AND g.status = \'active\'
         GROUP BY g.id, g.name, g.target_amount, g.target_date, g.currency_code
         ORDER BY g.target_date IS NULL, g.target_date
         LIMIT 3'
    );
    $goalStatement->execute(['user_id' => $userId, 'currency' => $currency]);
    $goals = $goalStatement->fetchAll();

    $recentStatement = $pdo->prepare(
        'SELECT t.id, t.transaction_type, t.amount, t.description, t.transaction_date,
                source.name AS account_name,
                destination.name AS destination_name,
                COALESCE(c.name, \'Uncategorized\') AS category_name
         FROM transactions t
         INNER JOIN accounts source
            ON source.id = t.account_id
            AND source.user_id = t.user_id
         LEFT JOIN accounts destination
            ON destination.id = t.to_account_id
            AND destination.user_id = t.user_id
         LEFT JOIN categories c
            ON c.id = t.category_id
            AND (c.user_id = t.user_id OR c.user_id IS NULL)
         WHERE t.user_id = :user_id
            AND source.currency_code = :currency
            AND source.is_archived = 0
         ORDER BY t.transaction_date DESC, t.id DESC
         LIMIT 6'
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
$activePage = 'overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f8f6">
    <title>Overview | Smart Wallet</title>
    <link rel="stylesheet" href="assets/js/sileo-toast.css?v=<?= filemtime(__DIR__ . '/assets/js/sileo-toast.css') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body data-app-shell="true" class="min-h-screen bg-[#f6f8f6] font-sans text-slate-900 antialiased">
<div id="app-content" class="min-h-screen lg:grid lg:grid-cols-[248px_minmax(0,1fr)]">
    <aside class="hidden border-r border-slate-200/80 bg-white lg:fixed lg:inset-y-0 lg:left-0 lg:z-20 lg:flex lg:w-[248px] lg:flex-col lg:overflow-y-auto">
        <a href="index.php" class="flex items-center gap-3 px-6 py-6 text-lg font-semibold tracking-tight">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#102820] text-emerald-300">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 17.5 9.2 12l3.5 3.2L20 7.5M14.8 7.5H20v5.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            Smart Wallet
        </a>
        <?php $navigationLayout = 'sidebar'; require __DIR__ . '/includes/main_navigation.php'; ?>
        <div class="mt-auto border-t border-slate-100 p-4">
            <div class="mb-4 truncate px-3 text-sm text-slate-500"><?= wallet_escape($name) ?></div>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                <button type="submit" class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                    Sign out
                </button>
            </form>
        </div>
    </aside>

    <div class="min-w-0 lg:col-start-2">
        <header class="sticky top-0 z-10 border-b border-slate-200/80 bg-white/90 backdrop-blur">
            <div class="mx-auto flex max-w-[1440px] items-center justify-between gap-4 px-5 py-4 sm:px-8">
                <div class="flex items-center gap-3">
                    <a href="index.php" class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#102820] text-emerald-300 lg:hidden" aria-label="Smart Wallet home">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 17.5 9.2 12l3.5 3.2L20 7.5M14.8 7.5H20v5.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <div>
                        <p class="text-sm font-semibold text-slate-900">Overview</p>
                        <p class="hidden text-xs text-slate-500 sm:block">Your financial snapshot</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <?php if (count($currencies) > 1): ?>
                        <form method="get">
                            <label for="currency" class="sr-only">Dashboard currency</label>
                            <select id="currency" name="currency" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 outline-none focus:border-emerald-600">
                                <?php foreach ($currencies as $availableCurrency): ?>
                                    <option value="<?= wallet_escape((string) $availableCurrency) ?>" <?= $availableCurrency === $currency ? 'selected' : '' ?>>
                                        <?= wallet_escape((string) $availableCurrency) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                    <div class="hidden text-right sm:block">
                        <p class="text-sm font-medium"><?= wallet_escape($firstName) ?></p>
                        <p class="text-xs text-slate-500"><?= $today->format('l, F j') ?></p>
                    </div>
                    <form method="post" action="logout.php" class="lg:hidden">
                        <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                        <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">Sign out</button>
                    </form>
                </div>
            </div>
        </header>
        <?php $navigationLayout = 'mobile'; require __DIR__ . '/includes/main_navigation.php'; ?>

        <main id="overview" tabindex="-1" class="mx-auto max-w-[1440px] space-y-7 px-5 py-8 outline-none sm:px-8 sm:py-10">
            <section class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                <div>
                    <p class="text-sm font-medium text-emerald-800"><?= $today->format('l, F j, Y') ?></p>
                    <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Good day, <?= wallet_escape($firstName) ?></h1>
                    <p class="mt-2 text-sm text-slate-500">Here’s how your money is looking.</p>
                </div>
                <?php if ($hasAccounts): ?>
                    <span class="inline-flex w-fit items-center gap-2 rounded-full bg-white px-3 py-1.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        <?= wallet_escape($monthLabel) ?> overview
                    </span>
                <?php endif; ?>
            </section>

            <?php if (!$hasAccounts): ?>
                <section class="rounded-3xl bg-[#102820] px-7 py-9 text-white sm:px-10 sm:py-12">
                    <div class="max-w-2xl">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 text-emerald-300">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 9h18m-14 6h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                        </span>
                        <h2 class="mt-5 text-2xl font-semibold tracking-tight">Your money overview starts with a wallet.</h2>
                        <p class="mt-3 text-sm leading-6 text-slate-300">
                            Your dashboard is ready. Once you create a wallet, you’ll see your balance, monthly activity, expense insights, and savings progress here.
                        </p>
                    </div>
                </section>
                <section class="grid gap-4 sm:grid-cols-3">
                    <?php foreach (['Total balance', 'Income this month', 'Expenses this month'] as $label): ?>
                        <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30">
                            <p class="text-sm font-medium text-slate-500"><?= wallet_escape($label) ?></p>
                            <p class="mt-4 text-2xl font-semibold tracking-tight text-slate-300">—</p>
                            <p class="mt-2 text-xs text-slate-400">Create a wallet to see this summary</p>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php else: ?>
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Financial summary">
                    <article class="rounded-2xl bg-[#123a2c] p-5 text-white shadow-sm sm:col-span-2 xl:col-span-1">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-emerald-100">Total balance</p>
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-white/10 text-emerald-200">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 8.5h15a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H5a2 2 0 0 1-2-2v-11a1 1 0 0 1 1-1h14" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/><path d="M16 13.5h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            </span>
                        </div>
                        <p class="mt-5 text-3xl font-semibold tracking-tight"><?= wallet_escape($formatMoney($balance)) ?></p>
                        <p class="mt-2 text-xs text-emerald-100/80">Across <?= count($accounts) ?> active <?= count($accounts) === 1 ? 'wallet' : 'wallets' ?></p>
                    </article>
                    <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-slate-500">Income this month</p>
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 19V5m-6 6 6-6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </div>
                        <p class="mt-5 text-2xl font-semibold tracking-tight"><?= wallet_escape($formatMoney($monthlyIncome)) ?></p>
                        <p class="mt-2 text-xs text-slate-400">Since <?= wallet_escape($today->modify('first day of this month')->format('M j')) ?></p>
                    </article>
                    <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-slate-500">Expenses this month</p>
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-rose-50 text-rose-600">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 5v14m6-6-6 6-6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </div>
                        <p class="mt-5 text-2xl font-semibold tracking-tight"><?= wallet_escape($formatMoney($monthlyExpenses)) ?></p>
                        <p class="mt-2 text-xs text-slate-400">Across your active wallets</p>
                    </article>
                    <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-slate-500">Net cash flow</p>
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-50 text-sky-700">
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
                    <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30 sm:p-6">
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

                    <article id="wallet" class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30 sm:p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="font-semibold tracking-tight">Create wallet</h2>
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
                    </article>
                </section>

                <section class="grid gap-6 xl:grid-cols-2">
                    <article id="expenses" class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30 sm:p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="font-semibold tracking-tight">Expenses</h2>
                                <p class="mt-1 text-sm text-slate-500">Your monthly expense limits</p>
                            </div>
                            <svg class="h-5 w-5 text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 19V5m0 14h16M8 15v-3m4 3V7m4 8v-5m4 5V4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                        <?php if ($budgets === []): ?>
                            <p class="mt-6 rounded-xl bg-slate-50 px-4 py-5 text-sm text-slate-500">No active monthly budgets yet.</p>
                        <?php else: ?>
                            <div class="mt-5 space-y-5">
                                <?php foreach ($budgets as $budget): ?>
                                    <?php
                                    $budgetAmount = (float) $budget['amount'];
                                    $spent = (float) $budget['spent'];
                                    $progress = $budgetAmount > 0 ? min(100, (int) round($spent / $budgetAmount * 100)) : 0;
                                    $overBudget = $spent > $budgetAmount;
                                    ?>
                                    <div>
                                        <div class="mb-2 flex items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-medium"><?= wallet_escape((string) $budget['name']) ?></p>
                                                <p class="mt-0.5 text-xs text-slate-500"><?= wallet_escape((string) $budget['category_name']) ?></p>
                                            </div>
                                            <p class="shrink-0 text-right text-xs <?= $overBudget ? 'font-semibold text-rose-600' : 'text-slate-500' ?>">
                                                <?= wallet_escape($formatMoney($spent)) ?> / <?= wallet_escape($formatMoney($budgetAmount)) ?>
                                            </p>
                                        </div>
                                        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full <?= $overBudget ? 'bg-rose-500' : 'bg-emerald-500' ?>" style="width: <?= $progress ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>

                    <article id="savings" class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30 sm:p-6">
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

                <section class="rounded-2xl border border-slate-200/80 bg-white shadow-sm shadow-slate-200/30">
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
                                $isIncome = $transaction['transaction_type'] === 'income';
                                $isTransfer = $transaction['transaction_type'] === 'transfer';
                                $amountPrefix = $isIncome ? '+' : ($isTransfer ? '' : '−');
                                $amountClass = $isIncome ? 'text-emerald-700' : ($isTransfer ? 'text-slate-600' : 'text-slate-900');
                                $detail = $isTransfer
                                    ? 'Transfer' . ($transaction['destination_name'] !== null ? ' · ' . $transaction['destination_name'] : '')
                                    : $transaction['category_name'];
                                ?>
                                <div class="flex items-center justify-between gap-4 px-5 py-4 sm:px-6">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl <?= $isIncome ? 'bg-emerald-50 text-emerald-700' : ($isTransfer ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-600') ?>">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <?php if ($isIncome): ?>
                                                    <path d="M12 19V5m-6 6 6-6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                                <?php elseif ($isTransfer): ?>
                                                    <path d="M7 7h13m0 0-3-3m3 3-3 3M17 17H4m0 0 3 3m-3-3 3-3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
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
                                        <p class="text-sm font-semibold <?= $amountClass ?>"><?= $amountPrefix ?><?= wallet_escape($formatMoney((float) $transaction['amount'])) ?></p>
                                        <p class="mt-0.5 text-xs text-slate-500"><?= wallet_escape(date('M j, Y', strtotime((string) $transaction['transaction_date']))) ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <footer class="pb-4 text-center text-xs text-slate-400">&copy; <?= date('Y') ?> Smart Wallet</footer>
        </main>
    </div>
</div>
<div id="sileo-toaster"></div>
<script src="assets/js/sileo-toast.js?v=<?= filemtime(__DIR__ . '/assets/js/sileo-toast.js') ?>"></script>
<script src="assets/js/app-navigation.js?v=<?= filemtime(__DIR__ . '/assets/js/app-navigation.js') ?>"></script>
</body>
</html>
