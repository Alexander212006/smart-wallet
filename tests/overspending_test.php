<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/expense_limits.php';

$assertions = 0;
function overspending_assert(mixed $expected, mixed $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

overspending_assert(0, wallet_expense_limit_cents('0.00'), 'Zero is handled exactly.');
overspending_assert(1, wallet_expense_limit_cents('0.01'), 'One cent is retained.');
overspending_assert(110, wallet_expense_limit_cents('1.1'), 'Fractional amounts are padded.');
overspending_assert(99999999999999, wallet_expense_limit_cents('999999999999.99'), 'Maximum transaction amount retains cents.');
overspending_assert(199999999999998, wallet_expense_limit_cents('1999999999999.98'), 'Aggregates may exceed individual transaction precision.');
overspending_assert('0.01', wallet_expense_limit_format_cents(1), 'Formatted cents retain the leading zero.');
overspending_assert('1,999,999,999,999.98', wallet_expense_limit_format_cents(199999999999998), 'Large alert amounts keep their exact cents.');
overspending_assert('92,233,720,368,547,758.07', wallet_expense_limit_format_cents(PHP_INT_MAX), 'Formatting does not lose precision through floats.');
foreach (['-1', '1.234', 'invalid', '99999999999999999.99'] as $invalid) {
    $rejected = false;
    try {
        wallet_expense_limit_cents($invalid);
    } catch (UnexpectedValueException) {
        $rejected = true;
    }
    overspending_assert(true, $rejected, 'Invalid or overflowing totals are rejected.');
}

$limit = [
    'id' => 1, 'name' => 'Food & dining', 'currency_code' => 'PHP', 'period' => 'monthly',
    'range_active' => true, 'range_start' => '2026-11-01', 'range_end' => '2026-12-01',
    'limit_cents' => 10000, 'spent_cents' => 10000, 'over_cents' => 0,
];
overspending_assert([], wallet_overspending_alerts([$limit]), 'Exactly at the limit is not overspending.');
$over = array_replace($limit, ['spent_cents' => 10001, 'over_cents' => 1]);
overspending_assert([$over], wallet_overspending_alerts([$over]), 'A one-cent overspend alerts.');
overspending_assert([], wallet_overspending_alerts([array_replace($over, ['range_active' => false])]), 'Inactive limits never alert.');
overspending_assert('Overspending alert: Food & dining is PHP 0.01 over its monthly limit.',
    wallet_overspending_message([$over]), 'Single warning identifies limit, currency, excess and period.');
$usd = array_replace($over, ['id' => 2, 'currency_code' => 'USD']);
overspending_assert(2, count(wallet_overspending_alerts([$over, $usd])), 'Different currencies remain separate alerts.');
overspending_assert('Overspending alert: 2 expense limits are over budget. Review your expense limits for details.',
    wallet_overspending_message([$over, $usd]), 'Multiple warnings are summarized without adding currencies.');

$_SESSION = [];
overspending_assert([$over], wallet_take_overspending_notifications([$over], 1), 'First detected overspend notifies.');
overspending_assert([], wallet_take_overspending_notifications([$over], 1), 'Unchanged overspending does not repeat.');
$worse = array_replace($over, ['spent_cents' => 10002, 'over_cents' => 2]);
overspending_assert([$worse], wallet_take_overspending_notifications([$worse], 1), 'Increasing overspending notifies again.');
overspending_assert([], wallet_take_overspending_notifications([$over], 1), 'Decreasing overspending does not notify.');
overspending_assert([$worse], wallet_take_overspending_notifications([$worse], 1), 'A later increase after improvement notifies.');
overspending_assert([], wallet_take_overspending_notifications([], 1), 'Resolving overspending clears its state.');
overspending_assert([$over], wallet_take_overspending_notifications([$over], 1), 'Overspending after resolution notifies again.');
$nextPeriod = array_replace($over, ['range_start' => '2026-12-01', 'range_end' => '2027-01-01']);
overspending_assert([$nextPeriod], wallet_take_overspending_notifications([$nextPeriod], 1), 'A new recurring period notifies independently.');
overspending_assert([$nextPeriod], wallet_take_overspending_notifications([$nextPeriod], 2), 'Notification state is scoped to the signed-in user.');
overspending_assert([$usd], wallet_take_overspending_notifications([$nextPeriod, $usd], 2), 'Only newly exceeded limits notify.');

echo 'Overspending tests passed (' . $assertions . " assertions).\n";
