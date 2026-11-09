<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/expense_limits.php';

$assertions = 0;
function overview_limit_assert(mixed $expected, mixed $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function overview_limit_fixture(
    int $id,
    string $name,
    string $period,
    string $currency,
    bool $active,
    string $amount,
    string $spent,
    bool $recurring = true
): array {
    return wallet_normalize_expense_limit([
        'id' => $id,
        'name' => $name,
        'period' => $period,
        'currency_code' => $currency,
        'category_name' => 'Food & dining',
        'range_active' => $active,
        'amount' => $amount,
        'spent' => $spent,
        'is_recurring' => $recurring,
    ]);
}

$weekly = overview_limit_fixture(1, 'food', 'weekly', 'PHP', true, '110.00', '250.00');
overview_limit_assert(11000, $weekly['limit_cents'], 'Weekly limit retains exact cents.');
overview_limit_assert(25000, $weekly['spent_cents'], 'Weekly spending retains exact cents.');
overview_limit_assert(14000, $weekly['over_cents'], 'Weekly overspending is calculated once in shared normalization.');
overview_limit_assert(0, $weekly['remaining_cents'], 'An exceeded limit has no remaining allocation.');
overview_limit_assert(true, $weekly['is_over'], 'Exceeded limits expose a shared status flag.');
overview_limit_assert(100, $weekly['progress_percent'], 'Exceeded progress is capped for the progress bar.');
overview_limit_assert('PHP 140.00 over limit', wallet_expense_limit_status($weekly), 'Shared status includes exact excess and currency.');

$monthly = overview_limit_fixture(2, 'Monthly essentials', 'monthly', 'PHP', true, '100.00', '25.00', false);
overview_limit_assert(7500, $monthly['remaining_cents'], 'Available budget is calculated exactly.');
overview_limit_assert(false, $monthly['is_over'], 'A limit below its budget is not marked over.');
overview_limit_assert(25, $monthly['progress_percent'], 'Usage percentage is shared by both pages.');
overview_limit_assert('PHP 75.00 remaining', wallet_expense_limit_status($monthly), 'Shared status includes exact remaining amount.');

$yearly = overview_limit_fixture(3, 'Annual food', 'yearly', 'PHP', true, '1000.00', '250.00');
$expired = overview_limit_fixture(4, 'Expired limit', 'weekly', 'PHP', false, '10.00', '0.00', false);
$stopped = overview_limit_fixture(5, 'Stopped limit', 'monthly', 'PHP', false, '10.00', '0.00');
$future = overview_limit_fixture(6, 'Future limit', 'yearly', 'PHP', false, '10.00', '0.00');
$usd = overview_limit_fixture(7, 'USD food', 'weekly', 'USD', true, '10.00', '2.00');
$all = [$weekly, $monthly, $yearly, $expired, $stopped, $future, $usd];

$phpActive = wallet_active_expense_limits($all, 'PHP');
overview_limit_assert(3, count($phpActive), 'Overview includes every active PHP weekly, monthly, and yearly limit.');
overview_limit_assert(
    ['Annual food', 'food', 'Monthly essentials'],
    array_column($phpActive, 'name'),
    'Active limits have deterministic name ordering.'
);
overview_limit_assert(
    ['yearly', 'weekly', 'monthly'],
    array_column($phpActive, 'period'),
    'No active period type is excluded.'
);
overview_limit_assert(4, count(wallet_active_expense_limits($all)), 'Unscoped active selection retains separate currencies.');
overview_limit_assert([], wallet_active_expense_limits([$expired, $stopped, $future], 'PHP'), 'Expired, stopped, and future limits are excluded.');
overview_limit_assert([$usd], wallet_active_expense_limits($all, 'USD'), 'Dashboard currency selection does not mix currencies.');

echo 'Overview expense limit tests passed (' . $assertions . " assertions).\n";
