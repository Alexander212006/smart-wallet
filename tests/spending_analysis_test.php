<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/spending_analysis.php';

$assertions = 0;
function spending_assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function spending_row(
    string $date,
    string $amount,
    string $categoryName = 'Uncategorized',
    ?string $nature = null
): array {
    return [
        'transaction_date' => $date . ' 12:00:00',
        'amount' => $amount,
        'category_name' => $categoryName,
        'expense_nature' => $nature,
    ];
}

$today = new DateTimeImmutable('2024-03-10', new DateTimeZone('Asia/Taipei'));
foreach ([null, '', '2024-3', '2024-13', '2024-04', '2023-03', '2024-02-01'] as $invalidMonth) {
    spending_assert_same('2024-03-01', wallet_spending_month($invalidMonth, $today)->format('Y-m-d'),
        'Invalid or out-of-range month falls back to current month');
}
spending_assert_same('2023-04-01', wallet_spending_month('2023-04', $today)->format('Y-m-d'),
    'Earliest of twelve selectable months is accepted');

$rows = [
    spending_row('2024-02-01', '10.00'),
    spending_row('2024-02-10', '20.00'),
    spending_row('2024-02-11', '300.00'),
    spending_row('2024-03-01', '0.10', 'Groceries', 'essential'),
    spending_row('2024-03-03', '0.20', 'groceries', 'essential'),
    spending_row('2024-03-04', '20.00', 'Custom hobby', 'non_essential'),
    spending_row('2024-03-10', '10.00', '', null),
    spending_row('2024-03-11', '999.00', 'Custom hobby', 'non_essential'),
    spending_row('2024-04-01', '999.00'),
    spending_row('2023-09-30', '999.00'),
];
$analysis = wallet_build_spending_analysis($rows, wallet_spending_month('2024-03', $today), $today);
spending_assert_same(3030, $analysis['total_cents'], 'Amounts accumulate exactly in integer cents');
spending_assert_same(4, $analysis['transaction_count'], 'Future expenses are excluded');
spending_assert_same('2024-03-11', $analysis['period_end']->format('Y-m-d'), 'Current day is included');
spending_assert_same(10, $analysis['days_elapsed'], 'Current period counts elapsed days');
spending_assert_same(3000, $analysis['previous_total_cents'], 'Partial month compares equal elapsed days');
spending_assert_same(1.0, $analysis['change_percent'], 'Partial month percentage uses matching comparison');
spending_assert_same('same days last month', $analysis['comparison_label'], 'Partial comparison label');
spending_assert_same(['Groceries', 'Custom hobby', 'Uncategorized'], array_column($analysis['categories'], 'name'),
    'Category frequency ranks ahead of spending amount and preserves custom categories');
spending_assert_same('Uncategorized', $analysis['categories'][2]['name'], 'Unknown category has a readable label');
spending_assert_same('2024-03-04', $analysis['peak_day']['date'], 'Peak day uses spending amount');
spending_assert_same([
    ['start' => '2024-03-01', 'end' => '2024-03-03', 'total_cents' => 30, 'count' => 2],
    ['start' => '2024-03-04', 'end' => '2024-03-10', 'total_cents' => 3000, 'count' => 2],
], $analysis['weeks'], 'Weeks run Monday-Sunday and clip to selected effective month');
spending_assert_same($analysis['weeks'][1], $analysis['peak_week'], 'Peak week sums its calendar days');
spending_assert_same(30, $analysis['nature']['essential']['total_cents'], 'Essential spend total');
spending_assert_same(2000, $analysis['nature']['non_essential']['total_cents'], 'Non-essential spend total');
spending_assert_same(1000, $analysis['nature']['unclassified']['total_cents'], 'Legacy null values stay unclassified');
spending_assert_same(2, $analysis['nature']['essential']['count'], 'Nature frequency is retained');
spending_assert_same(['2023-10', '2023-11', '2023-12', '2024-01', '2024-02', '2024-03'],
    array_column($analysis['months'], 'month'), 'Trend has six chronological months including empty months');
spending_assert_same(33000, $analysis['months'][4]['total_cents'], 'Historical trend month retains full month');
spending_assert_same(true, $analysis['months'][5]['is_partial'], 'Current trend month is marked partial');

$leapToday = new DateTimeImmutable('2024-03-31');
$leap = wallet_build_spending_analysis([
    spending_row('2024-02-29', '12.00'),
    spending_row('2024-03-31', '6.00'),
], new DateTimeImmutable('2024-03-01'), $leapToday);
spending_assert_same(1200, $leap['previous_total_cents'], 'Comparison clamps to leap February end');
spending_assert_same(-50.0, $leap['change_percent'], 'Comparison percentage may decrease');
spending_assert_same('2024-03-31', $leap['weeks'][4]['end'], 'Last calendar week clips to month end');

$historical = wallet_build_spending_analysis([
    spending_row('2024-01-01', '1.00'),
    spending_row('2024-01-31', '2.00'),
    spending_row('2024-02-29', '3.00'),
    spending_row('2024-03-01', '99.00'),
], new DateTimeImmutable('2024-02-01'), $today);
spending_assert_same(300, $historical['total_cents'], 'Historical selected month includes leap day only');
spending_assert_same(300, $historical['previous_total_cents'], 'Historical comparison includes entire previous month');
spending_assert_same(29, $historical['days_elapsed'], 'Historical leap month has 29 days');
spending_assert_same(false, $historical['is_current_month'], 'Historical month is complete');
spending_assert_same('last month', $historical['comparison_label'], 'Historical comparison label');
spending_assert_same(false, $historical['months'][5]['is_partial'], 'Historical trend end is complete');

$empty = wallet_build_spending_analysis([], new DateTimeImmutable('2024-03-01'), $today);
spending_assert_same(null, $empty['change_percent'], 'No previous expenses has no percentage comparison');
spending_assert_same(null, $empty['peak_day'], 'Empty month has no peak day');
spending_assert_same(null, $empty['peak_week'], 'Empty month has no peak week');
spending_assert_same([], $empty['categories'], 'Empty month has no categories');
spending_assert_same(0.0, $empty['nature']['unclassified']['share_percent'], 'Empty breakdown avoids division by zero');
$newSpending = wallet_build_spending_analysis([spending_row('2024-03-01', '5.00')],
    new DateTimeImmutable('2024-03-01'), $today);
spending_assert_same(null, $newSpending['change_percent'], 'First spending after zero baseline has no misleading percentage');
$noSpending = wallet_build_spending_analysis([spending_row('2024-02-01', '5.00')],
    new DateTimeImmutable('2024-03-01'), $today);
spending_assert_same(-100.0, $noSpending['change_percent'], 'No current spending is a full decrease from a positive baseline');

// Stored-procedure query isolation is covered in database_programs_test.php.

echo 'Spending analysis tests passed (' . $assertions . " assertions).\n";
