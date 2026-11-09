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

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE wallets (id INTEGER PRIMARY KEY, user_id INTEGER, currency_code TEXT, is_archived INTEGER)');
$pdo->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY, user_id INTEGER, wallet_id INTEGER, category TEXT,
    amount TEXT, transaction_date TEXT, expense_nature TEXT)');
$pdo->exec("INSERT INTO wallets VALUES (1, 1, 'PHP', 0), (2, 1, 'USD', 0), (3, 1, 'PHP', 1), (4, 2, 'PHP', 0)");
$insert = $pdo->prepare('INSERT INTO transactions VALUES (?, ?, ?, ?, ?, ?, ?)');
$fixtures = [
    [1, 1, 1, 'Food', '-1.00', '2024-03-01 09:00:00', 'essential'],
    [2, 1, 2, 'Food', '-100.00', '2024-03-01 09:00:00', null],
    [3, 1, 3, 'Food', '-100.00', '2024-03-01 09:00:00', null],
    [4, 2, 4, 'Private other user', '-100.00', '2024-03-01 09:00:00', null],
    [5, 1, 4, 'Food', '-100.00', '2024-03-01 09:00:00', null],
    [6, 1, 1, 'Food', '100.00', '2024-03-01 09:00:00', null],
    [7, 1, 1, 'Food', '-100.00', '2024-03-11 00:00:00', null],
    [8, 1, 1, 'Custom second', '-2.00', '2024-03-01 09:00:00', null],
    [9, 1, 1, 'Custom category', '-3.00', '2024-03-01 09:00:00', 'non_essential'],
    [10, 1, 1, null, '-4.00', '2024-03-01 09:00:00', null],
    [11, 1, 1, null, '-5.00', '2023-09-30 23:59:59', null],
    [12, 1, 1, null, '-6.00', '2023-10-01 00:00:00', null],
    [13, 1, 1, null, '-0.10', '2024-03-10 23:59:59', null],
];
foreach ($fixtures as $fixture) {
    $insert->execute($fixture);
}
$loaded = wallet_load_spending_analysis($pdo, 1, 'PHP', new DateTimeImmutable('2024-03-01'), $today);
spending_assert_same(1010, $loaded['total_cents'], 'Loader scopes user, active owned wallet, currency, expense type and date');
spending_assert_same(5, $loaded['transaction_count'], 'Loader retains valid uncategorized and shared-category expenses');
spending_assert_same('Uncategorized', $loaded['categories'][0]['name'], 'Category names are grouped directly without a category table');
spending_assert_same(false, in_array('Private other user', array_column($loaded['categories'], 'name'), true),
    'Another user category name never leaks into the report');
spending_assert_same(600, $loaded['months'][0]['total_cents'], 'Loader includes exact lower trend boundary');
$otherCurrency = wallet_load_spending_analysis($pdo, 1, 'USD', new DateTimeImmutable('2024-03-01'), $today);
spending_assert_same(10000, $otherCurrency['total_cents'], 'Other currency can be selected independently');
$otherUser = wallet_load_spending_analysis($pdo, 2, 'PHP', new DateTimeImmutable('2024-03-01'), $today);
spending_assert_same(10000, $otherUser['total_cents'], 'Other user sees only their own transactions');

echo 'Spending analysis tests passed (' . $assertions . " assertions).\n";
