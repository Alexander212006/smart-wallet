<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/financial_reports.php';
require_once __DIR__ . '/../includes/financial_report_exports.php';

$assertions = 0;

function report_assert_same(mixed $expected, mixed $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function report_assert_true(bool $condition, string $message): void
{
    report_assert_same(true, $condition, $message);
}

function report_assert_close(float $expected, float $actual, string $message, float $tolerance = 0.0001): void
{
    global $assertions;
    $assertions++;
    if (abs($expected - $actual) > $tolerance) {
        throw new RuntimeException(
            $message . "\nExpected approximately: " . $expected . "\nActual: " . $actual
        );
    }
}

function report_row(string $date, string $amount, ?string $category = null, int $id = 1): array
{
    return [
        'id' => $id,
        'transaction_date' => $date . ' 12:00:00',
        'amount' => $amount,
        'category' => $category,
    ];
}

function report_build(
    string $type,
    string $selection,
    string $today,
    array $rows,
    array $budgets = [],
    string $currency = 'PHP'
): array {
    $timezone = new DateTimeZone('UTC');
    $todayDate = new DateTimeImmutable($today, $timezone);
    $period = wallet_financial_report_period($type, $selection, $todayDate);

    return wallet_build_financial_report(
        $rows,
        $budgets,
        $period,
        $currency,
        new DateTimeImmutable($today . ' 18:30:00', $timezone)
    );
}

$timezone = new DateTimeZone('UTC');

// Exact decimal handling.
report_assert_same(12345, wallet_report_decimal_to_cents('123.45'), 'Positive decimals convert to exact cents');
report_assert_same(-12345, wallet_report_decimal_to_cents('-123.45'), 'Negative decimals convert to exact cents');
report_assert_same(120, wallet_report_decimal_to_cents('1.2'), 'One decimal digit is padded exactly');
report_assert_same(0, wallet_report_decimal_to_cents('-0.00'), 'Signed zero normalizes to zero cents');
report_assert_same(null, wallet_report_decimal_to_cents('1.234'), 'More than two decimals are rejected');
report_assert_same(null, wallet_report_decimal_to_cents('1000000000000.00'), 'Values beyond DECIMAL(14,2) are rejected');
report_assert_same(
    '1,234,567,890,123.45',
    wallet_report_format_cents(123456789012345),
    'Large cent values format exactly without floating-point conversion'
);

// Weekly periods use ISO Monday-through-Sunday boundaries.
$weekly = wallet_financial_report_period(
    'weekly',
    '2024-W10',
    new DateTimeImmutable('2024-03-10', $timezone)
);
report_assert_same('2024-03-04', $weekly['start']->format('Y-m-d'), 'Weekly report starts on Monday');
report_assert_same('2024-03-11', $weekly['end']->format('Y-m-d'), 'Weekly report ends after Sunday');
report_assert_same('2024-02-26', $weekly['previous_start']->format('Y-m-d'), 'Previous week starts seven days earlier');
report_assert_same(false, $weekly['is_partial'], 'A report generated on Sunday contains the complete week');

$weeklyReport = report_build('weekly', '2024-W10', '2024-03-10', [
    report_row('2024-03-03', '999.00', null, 1),
    report_row('2024-03-04', '100.00', null, 2),
    report_row('2024-03-10', '-30.00', 'Food', 3),
    report_row('2024-03-11', '-999.00', 'Outside', 4),
]);
report_assert_same(10000, $weeklyReport['summary']['income_cents'], 'Weekly income includes the exact lower boundary');
report_assert_same(3000, $weeklyReport['summary']['expense_cents'], 'Weekly expenses include Sunday');
report_assert_same(7000, $weeklyReport['summary']['net_cents'], 'Weekly net cash flow is income minus expenses');
report_assert_close(70.0, $weeklyReport['summary']['savings_rate'], 'Weekly savings rate is calculated correctly');

// Monthly boundaries and zero/negative income behavior.
$monthly = wallet_financial_report_period(
    'monthly',
    '2024-03',
    new DateTimeImmutable('2024-04-15', $timezone)
);
report_assert_same('2024-03-01', $monthly['start']->format('Y-m-d'), 'Monthly report starts on the first day');
report_assert_same('2024-04-01', $monthly['end']->format('Y-m-d'), 'Monthly report uses an exclusive next-month boundary');
$monthlyReport = report_build('monthly', '2024-03', '2024-04-15', [
    report_row('2024-02-29', '500.00', null, 1),
    report_row('2024-03-01', '10.00', null, 2),
    report_row('2024-03-31', '-15.00', 'Bills', 3),
    report_row('2024-04-01', '-500.00', 'Outside', 4),
]);
report_assert_same(1000, $monthlyReport['summary']['income_cents'], 'Monthly income excludes the prior month');
report_assert_same(1500, $monthlyReport['summary']['expense_cents'], 'Monthly expenses include the final calendar day');
report_assert_same(-500, $monthlyReport['summary']['net_cents'], 'Expenses can produce negative net savings');
report_assert_close(-50.0, $monthlyReport['summary']['savings_rate'], 'Savings rate can be negative and is not capped');

$zeroIncome = report_build('monthly', '2024-03', '2024-04-15', [
    report_row('2024-03-05', '-12.34', 'Bills'),
]);
report_assert_same(0, $zeroIncome['summary']['income_cents'], 'Expense-only report has zero income');
report_assert_same(-1234, $zeroIncome['summary']['net_cents'], 'Expense-only report retains negative net cash flow');
report_assert_same(null, $zeroIncome['summary']['savings_rate'], 'Zero income produces an N/A savings rate');

// Yearly boundaries include leap day and remain half-open.
$yearly = wallet_financial_report_period(
    'yearly',
    '2024',
    new DateTimeImmutable('2025-02-01', $timezone)
);
report_assert_same(366, (int) $yearly['start']->diff($yearly['end'])->days, 'Leap-year report contains 366 days');
$yearlyReport = report_build('yearly', '2024', '2025-02-01', [
    report_row('2023-12-31', '900.00', null, 1),
    report_row('2024-01-01', '200.00', null, 2),
    report_row('2024-02-29', '-20.00', 'Leap day', 3),
    report_row('2024-12-31', '-30.00', 'Year end', 4),
    report_row('2025-01-01', '-900.00', 'Outside', 5),
]);
report_assert_same(20000, $yearlyReport['summary']['income_cents'], 'Yearly income includes January 1');
report_assert_same(5000, $yearlyReport['summary']['expense_cents'], 'Yearly expenses include leap day and December 31');

// Null/blank categories are Uncategorized and exact category cents reconcile.
$categoryReport = report_build('monthly', '2024-03', '2024-04-15', [
    report_row('2024-03-01', '-1.01', null, 1),
    report_row('2024-03-02', '-2.02', '', 2),
    report_row('2024-03-03', '-3.03', '   ', 3),
    report_row('2024-03-04', '-4.04', 'Food', 4),
    report_row('2024-03-05', '-5.05', 'food', 5),
]);
report_assert_same(2, count($categoryReport['categories']), 'Blank values group with null while category case variants group together');
$uncategorized = array_values(array_filter(
    $categoryReport['categories'],
    static fn (array $category): bool => $category['name'] === 'Uncategorized'
))[0];
report_assert_same(606, $uncategorized['total_cents'], 'Null and blank category totals reconcile under Uncategorized');
report_assert_same(3, $uncategorized['count'], 'Uncategorized transaction count is exact');
report_assert_same(
    $categoryReport['summary']['expense_cents'],
    array_sum(array_column($categoryReport['categories'], 'total_cents')),
    'Category cents reconcile exactly to total expenses'
);
report_assert_close(
    100.0,
    array_sum(array_column($categoryReport['categories'], 'share_percent')),
    'Category shares reconcile to 100 percent'
);

$uncategorizedBudgetReport = report_build('monthly', '2024-03', '2024-04-15', [
    report_row('2024-03-01', '-10.00', null, 1),
    report_row('2024-03-02', '-20.00', 'Uncategorized', 2),
], [
    [
        'id' => 1, 'name' => 'Literal category', 'category' => 'Uncategorized', 'amount' => '100.00',
        'currency_code' => 'PHP', 'period' => 'monthly', 'start_date' => '2024-03-01',
        'end_date' => null, 'is_recurring' => 0,
    ],
    [
        'id' => 2, 'name' => 'All expenses', 'category' => null, 'amount' => '100.00',
        'currency_code' => 'PHP', 'period' => 'monthly', 'start_date' => '2024-03-01',
        'end_date' => null, 'is_recurring' => 0,
    ],
]);
$uncategorizedBudgets = array_column($uncategorizedBudgetReport['budgets'], null, 'name');
report_assert_same(
    2000,
    $uncategorizedBudgets['Literal category']['spent_cents'],
    'A literal Uncategorized budget does not absorb null-category expenses'
);
report_assert_same(
    3000,
    $uncategorizedBudgets['All expenses']['spent_cents'],
    'An all-category budget still includes null and literal Uncategorized expenses'
);

// Partial periods compare the same elapsed day count and exclude all future dates.
$partialPeriod = wallet_financial_report_period(
    'monthly',
    '2024-03',
    new DateTimeImmutable('2024-03-10', $timezone)
);
report_assert_same(true, $partialPeriod['is_partial'], 'Current month is identified as partial');
report_assert_same(10, $partialPeriod['days_elapsed'], 'Partial month includes today in elapsed days');
report_assert_same('2024-03-11', $partialPeriod['effective_end']->format('Y-m-d'), 'Current data ends exclusively after today');
report_assert_same('2024-02-11', $partialPeriod['previous_effective_end']->format('Y-m-d'), 'Previous comparison uses the same ten elapsed days');
report_assert_true(str_contains($partialPeriod['comparison_note'], 'first 10 days'), 'Partial comparison method is clearly labelled');
$partialReport = wallet_build_financial_report([
    report_row('2024-02-01', '50.00', null, 1),
    report_row('2024-02-10', '-5.00', 'Food', 2),
    report_row('2024-02-11', '-999.00', 'Excluded previous future window', 3),
    report_row('2024-03-01', '100.00', null, 4),
    report_row('2024-03-10', '-20.00', 'Food', 5),
    report_row('2024-03-11', '-999.00', 'Future', 6),
], [], $partialPeriod, 'PHP', new DateTimeImmutable('2024-03-10 18:00:00', $timezone));
report_assert_same(10000, $partialReport['summary']['income_cents'], 'Partial current income includes data through today');
report_assert_same(2000, $partialReport['summary']['expense_cents'], 'Future current transactions are excluded');
report_assert_same(5000, $partialReport['previous_summary']['income_cents'], 'Previous income uses the fair elapsed window');
report_assert_same(500, $partialReport['previous_summary']['expense_cents'], 'Previous expenses stop at the matching exclusive boundary');
report_assert_same(5000, $partialReport['comparison']['income']['difference'], 'Comparison stores signed absolute-unit difference');
report_assert_close(100.0, $partialReport['comparison']['income']['percent_change'], 'Percentage change uses the previous absolute baseline');
report_assert_same(null, $zeroIncome['comparison']['income']['percent_change'], 'A zero previous-period baseline produces N/A change');

$partialLeapYear = wallet_financial_report_period(
    'yearly',
    '2024',
    new DateTimeImmutable('2024-02-29', $timezone)
);
report_assert_same(60, $partialLeapYear['days_elapsed'], 'Leap-day year-to-date includes sixty elapsed days');
report_assert_same('2023-03-02', $partialLeapYear['previous_effective_end']->format('Y-m-d'), 'Leap-year comparison preserves elapsed day count');

// Invalid/future filters are rejected safely and valid defaults are returned.
$resolved = wallet_resolve_financial_report_filters(
    ['period' => 'weekly', 'week' => '2024-W99', 'currency' => 'EUR'],
    ['PHP', 'USD'],
    new DateTimeImmutable('2024-03-10', $timezone)
);
report_assert_same('2024-W10', $resolved['period']['selection'], 'Invalid week falls back to the current ISO week');
report_assert_same('PHP', $resolved['currency'], 'Unavailable currency falls back to the first active currency');
report_assert_same(2, count($resolved['errors']), 'Invalid date and currency both produce safe validation errors');
$futureResolved = wallet_resolve_financial_report_filters(
    ['period' => 'yearly', 'year' => '2025'],
    ['PHP'],
    new DateTimeImmutable('2024-03-10', $timezone)
);
report_assert_same('2024', $futureResolved['period']['selection'], 'A future report period falls back to the current year');
$minimumYearRejected = false;
try {
    wallet_financial_report_period('yearly', '1000', new DateTimeImmutable('2024-03-10', $timezone));
} catch (InvalidArgumentException) {
    $minimumYearRejected = true;
}
report_assert_same(true, $minimumYearRejected, 'Reports reject periods whose previous window predates MySQL date support');

// Budget allocations are based on applicable cycle intersections and partial cycles are prorated.
$budgetRows = [
    [
        'id' => 1, 'name' => 'Food monthly', 'category' => 'Food', 'amount' => '310.00',
        'currency_code' => 'PHP', 'period' => 'monthly', 'start_date' => '2024-01-01',
        'end_date' => null, 'is_recurring' => 1,
    ],
    [
        'id' => 2, 'name' => 'All weekly', 'category' => null, 'amount' => '70.00',
        'currency_code' => 'PHP', 'period' => 'weekly', 'start_date' => '2024-03-04',
        'end_date' => null, 'is_recurring' => 1,
    ],
    [
        'id' => 3, 'name' => 'Old one-time', 'category' => null, 'amount' => '500.00',
        'currency_code' => 'PHP', 'period' => 'monthly', 'start_date' => '2024-01-01',
        'end_date' => null, 'is_recurring' => 0,
    ],
    [
        'id' => 4, 'name' => 'Stopped monthly', 'category' => null, 'amount' => '310.00',
        'currency_code' => 'PHP', 'period' => 'monthly', 'start_date' => '2024-03-01',
        'end_date' => '2024-03-05', 'is_recurring' => 1,
    ],
];
$budgetReport = report_build('monthly', '2024-03', '2024-03-10', [
    report_row('2024-03-02', '-10.00', 'Food', 1),
    report_row('2024-03-05', '-20.00', 'food', 2),
    report_row('2024-03-08', '-40.00', 'Travel', 3),
], $budgetRows);
report_assert_same(3, count($budgetReport['budgets']), 'Only budget records with applicable cycles are reported');
$budgetByName = array_column($budgetReport['budgets'], null, 'name');
report_assert_same(10000, $budgetByName['Food monthly']['allocation_cents'], 'Ten of thirty-one monthly days prorate exactly');
report_assert_same(3000, $budgetByName['Food monthly']['spent_cents'], 'Category budget matches relevant expenses case-insensitively');
report_assert_same(7000, $budgetByName['Food monthly']['remaining_cents'], 'Budget remaining amount is exact');
report_assert_close(30.0, $budgetByName['Food monthly']['percent_used'], 'Budget percentage used is calculated from prorated allocation');
report_assert_same('Within Budget', $budgetByName['Food monthly']['status'], 'Low utilization is within budget');
report_assert_same(true, $budgetByName['Food monthly']['is_prorated'], 'Partial monthly allocation is marked prorated');
report_assert_true(str_contains($budgetByName['Food monthly']['allocation_note'], 'Daily prorated'), 'Proration basis is documented');
report_assert_same(7000, $budgetByName['All weekly']['allocation_cents'], 'A complete selected weekly cycle receives its full allocation');
report_assert_same(6000, $budgetByName['All weekly']['spent_cents'], 'All-category budget includes each expense in its active weekly cycle once');
report_assert_same(5000, $budgetByName['Stopped monthly']['allocation_cents'], 'Stopped recurring budget prorates only its five active days');
report_assert_same(3000, $budgetByName['Stopped monthly']['spent_cents'], 'Stopped budget excludes spending after its inclusive end date');
report_assert_same(7000, $budgetReport['summary']['expense_cents'], 'Overall expense total is never derived by summing overlapping budgets');
report_assert_true(
    array_sum(array_column($budgetReport['budgets'], 'spent_cents')) > $budgetReport['summary']['expense_cents'],
    'Independent overlapping budget rows can share expenses without double-counting report totals'
);

$fullBudgetReport = report_build('monthly', '2024-02', '2024-03-15', [], [[
    'id' => 5, 'name' => 'Leap month', 'category' => null, 'amount' => '290.00',
    'currency_code' => 'PHP', 'period' => 'monthly', 'start_date' => '2024-02-01',
    'end_date' => null, 'is_recurring' => 0,
]]);
report_assert_same(29000, $fullBudgetReport['budgets'][0]['allocation_cents'], 'Full leap-month cycle keeps its full budget allocation');
report_assert_same(false, $fullBudgetReport['budgets'][0]['is_prorated'], 'Full budget cycle is not labelled prorated');

$multiCycleReport = report_build('yearly', '2024', '2025-01-05', [], [
    [
        'id' => 10, 'name' => 'Recurring month', 'category' => null, 'amount' => '100.00',
        'currency_code' => 'PHP', 'period' => 'monthly', 'start_date' => '2024-01-01',
        'end_date' => null, 'is_recurring' => 1,
    ],
    [
        'id' => 11, 'name' => 'Recurring week', 'category' => null, 'amount' => '70.00',
        'currency_code' => 'PHP', 'period' => 'weekly', 'start_date' => '2024-01-01',
        'end_date' => null, 'is_recurring' => 1,
    ],
]);
$multiCycleBudgets = array_column($multiCycleReport['budgets'], null, 'name');
report_assert_same(120000, $multiCycleBudgets['Recurring month']['allocation_cents'], 'A yearly report sums twelve full monthly cycles');
report_assert_same(12, $multiCycleBudgets['Recurring month']['cycle_count'], 'Monthly cycle count spans the whole report year');
report_assert_same(366000, $multiCycleBudgets['Recurring week']['allocation_cents'], 'A leap-year report sums weekly cycles and prorates the final two days');
report_assert_same(53, $multiCycleBudgets['Recurring week']['cycle_count'], 'Weekly cycle count includes the intersecting partial final cycle');
report_assert_same(true, $multiCycleBudgets['Recurring week']['is_prorated'], 'A partially covered final weekly cycle is disclosed');

$nearOverBudgets = wallet_report_budget_performance([
    [
        'id' => 6, 'name' => 'Near', 'category' => 'Near', 'amount' => '100.00',
        'period' => 'weekly', 'start_date' => '2024-03-04', 'end_date' => null, 'is_recurring' => 0,
    ],
    [
        'id' => 7, 'name' => 'Over', 'category' => 'Over', 'amount' => '100.00',
        'period' => 'weekly', 'start_date' => '2024-03-04', 'end_date' => null, 'is_recurring' => 0,
    ],
], [
    ['date' => new DateTimeImmutable('2024-03-05', $timezone), 'amount_cents' => 8000, 'category' => 'Near'],
    ['date' => new DateTimeImmutable('2024-03-05', $timezone), 'amount_cents' => 10001, 'category' => 'Over'],
], new DateTimeImmutable('2024-03-04', $timezone), new DateTimeImmutable('2024-03-11', $timezone));
$nearOverByName = array_column($nearOverBudgets, null, 'name');
report_assert_same('Near Limit', $nearOverByName['Near']['status'], 'Eighty percent utilization is near the limit');
report_assert_same('Over Budget', $nearOverByName['Over']['status'], 'Utilization over one hundred percent is over budget');

// Query loader: currency, archived-wallet, future-date, ownership, and user isolation.
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE wallets (id INTEGER PRIMARY KEY, user_id INTEGER, currency_code TEXT, is_archived INTEGER)');
$pdo->exec('CREATE TABLE transactions (
    id INTEGER PRIMARY KEY, user_id INTEGER, wallet_id INTEGER, category TEXT, amount TEXT, transaction_date TEXT
)');
$pdo->exec('CREATE TABLE expense_limits (
    id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, category TEXT, amount TEXT, currency_code TEXT,
    period TEXT, start_date TEXT, end_date TEXT, is_recurring INTEGER
)');
$pdo->exec("INSERT INTO wallets VALUES
    (1, 1, 'PHP', 0), (2, 1, 'USD', 0), (3, 1, 'PHP', 1), (4, 2, 'PHP', 0)");
$insertTransaction = $pdo->prepare('INSERT INTO transactions VALUES (?, ?, ?, ?, ?, ?)');
$fixtures = [
    [1, 1, 1, 'Salary', '100.00', '2024-03-01 09:00:00'],
    [2, 1, 1, '=Private formula', '-25.00', '2024-03-15 12:00:00'],
    [3, 1, 1, 'Future', '-900.00', '2024-03-16 00:00:00'],
    [4, 1, 3, 'Archived', '-800.00', '2024-03-05 12:00:00'],
    [5, 1, 2, 'USD expense', '-7.00', '2024-03-05 12:00:00'],
    [6, 2, 4, 'Other user private', '-600.00', '2024-03-05 12:00:00'],
    [7, 1, 4, 'Cross-owned wallet', '-500.00', '2024-03-05 12:00:00'],
    [8, 1, 1, 'Prior income', '50.00', '2024-02-01 12:00:00'],
    [9, 1, 1, 'Prior expense', '-10.00', '2024-02-15 23:59:59'],
    [10, 1, 1, 'Outside prior elapsed window', '-400.00', '2024-02-16 00:00:00'],
];
foreach ($fixtures as $fixture) {
    $insertTransaction->execute($fixture);
}
$pdo->exec("INSERT INTO expense_limits VALUES
    (1, 1, '+PHP budget', NULL, '310.00', 'PHP', 'monthly', '2024-03-01', NULL, 0),
    (2, 1, 'USD budget', NULL, '100.00', 'USD', 'monthly', '2024-03-01', NULL, 0),
    (3, 2, 'Other user budget', NULL, '999.00', 'PHP', 'monthly', '2024-03-01', NULL, 0)");
$loaderPeriod = wallet_financial_report_period(
    'monthly',
    '2024-03',
    new DateTimeImmutable('2024-03-15', $timezone)
);
$loadedPhp = wallet_load_financial_report(
    $pdo,
    1,
    'PHP',
    $loaderPeriod,
    new DateTimeImmutable('2024-03-15 18:00:00', $timezone)
);
report_assert_same(10000, $loadedPhp['summary']['income_cents'], 'Loader includes current active-wallet income');
report_assert_same(2500, $loadedPhp['summary']['expense_cents'], 'Loader excludes future, archived, other-user, and cross-owned expenses');
report_assert_same(5000, $loadedPhp['previous_summary']['income_cents'], 'Loader includes matching previous-period income');
report_assert_same(1000, $loadedPhp['previous_summary']['expense_cents'], 'Loader includes the last second of the elapsed prior window');
report_assert_same(1, count($loadedPhp['budgets']), 'Budget query is isolated by user and currency');
report_assert_same('+PHP budget', $loadedPhp['budgets'][0]['name'], 'Correct user budget is loaded');
report_assert_same(false, in_array('Other user private', array_column($loadedPhp['categories'], 'name'), true), 'Other-user category data does not leak');

$loadedUsd = wallet_load_financial_report(
    $pdo,
    1,
    'USD',
    $loaderPeriod,
    new DateTimeImmutable('2024-03-15 18:00:00', $timezone)
);
report_assert_same(700, $loadedUsd['summary']['expense_cents'], 'Different currencies are reported independently');
report_assert_same(0, $loadedUsd['summary']['income_cents'], 'No cross-currency income is combined');
report_assert_same(1, count($loadedUsd['budgets']), 'Only the selected-currency budget is returned');

$loadedOtherUser = wallet_load_financial_report(
    $pdo,
    2,
    'PHP',
    $loaderPeriod,
    new DateTimeImmutable('2024-03-15 18:00:00', $timezone)
);
report_assert_same(60000, $loadedOtherUser['summary']['expense_cents'], 'A second user sees only their owned-wallet data');
report_assert_same(1, count($loadedOtherUser['budgets']), 'A second user sees only their own budget');

$empty = wallet_load_financial_report(
    $pdo,
    1,
    'AUD',
    $loaderPeriod,
    new DateTimeImmutable('2024-03-15 18:00:00', $timezone)
);
report_assert_same(0, $empty['summary']['transaction_count'], 'Empty datasets produce a valid zero report');
report_assert_same([], $empty['categories'], 'Empty datasets have no fabricated categories');
report_assert_same([], $empty['budgets'], 'Empty datasets have no fabricated budgets');

// CSV export integrity and formula-injection protection.
$csv = wallet_financial_report_csv($loadedPhp);
report_assert_same("\xEF\xBB\xBF", substr($csv, 0, 3), 'CSV begins with a UTF-8 BOM');
report_assert_true(str_contains($csv, 'Smart Wallet Financial Report'), 'CSV contains the report heading');
report_assert_true(str_contains($csv, 'Financial summary'), 'CSV contains the summary section');
report_assert_true(str_contains($csv, 'Expense categories'), 'CSV contains the category section');
report_assert_true(str_contains($csv, 'Budget performance'), 'CSV contains the budget section');
report_assert_true(str_contains($csv, 'Previous-period comparison'), 'CSV contains the comparison section');
report_assert_true(str_contains($csv, "'=Private formula"), 'CSV neutralizes a category formula prefix');
report_assert_true(str_contains($csv, "'+PHP budget"), 'CSV neutralizes a budget-name formula prefix');
report_assert_true(str_contains($csv, '100.00,PHP'), 'CSV writes exact numeric income values without locale formatting');
report_assert_true(
    str_contains($csv, 'Percent; difference in percentage points'),
    'CSV identifies savings-rate differences as percentage points'
);
foreach (['=1+1', '+SUM(A1:A2)', '-CMD', '@IMPORT', "\t=HIDDEN"] as $formulaText) {
    report_assert_true(
        str_starts_with(wallet_report_export_safe_csv_text($formulaText), "'"),
        'CSV formula protection covers the prefix in ' . json_encode($formulaText)
    );
}

$roundTripReport = $loadedPhp;
$roundTripCategory = "=SUM(1,2)\n\"quoted\" 雪";
$roundTripReport['categories'][0]['name'] = $roundTripCategory;
$roundTripCsv = wallet_financial_report_csv($roundTripReport);
$roundTripStream = fopen('php://temp', 'w+b');
report_assert_true(is_resource($roundTripStream), 'CSV round-trip stream opens');
fwrite($roundTripStream, substr($roundTripCsv, 3));
rewind($roundTripStream);
$roundTripFound = false;
while (($roundTripRow = fgetcsv($roundTripStream, 0, ',', '"', '')) !== false) {
    if (($roundTripRow[0] ?? null) === "'" . $roundTripCategory) {
        $roundTripFound = true;
        break;
    }
}
fclose($roundTripStream);
report_assert_same(true, $roundTripFound, 'CSV round-trips commas, quotes, newlines, Unicode, and formula protection');

$negativeCsv = wallet_financial_report_csv($monthlyReport);
report_assert_true(str_contains($negativeCsv, '-5.00,PHP'), 'Internally generated negative amounts remain numeric rather than formula-escaped');

// PDF output remains valid, contains all report sections, and does not leak another user's data.
$pdf = wallet_financial_report_pdf($loadedPhp);
report_assert_same('%PDF-1.4', substr($pdf, 0, 8), 'PDF has a valid PDF 1.4 signature');
report_assert_true(str_ends_with($pdf, "%%EOF\n"), 'PDF has a complete EOF marker');
report_assert_true(str_contains($pdf, '(Financial Report)'), 'PDF contains the report heading');
report_assert_true(str_contains($pdf, '(Financial summary)'), 'PDF contains the summary section');
report_assert_true(str_contains($pdf, '(Expense breakdown)'), 'PDF contains the expense breakdown');
report_assert_true(str_contains($pdf, '(Budget performance)'), 'PDF contains budget performance');
report_assert_true(str_contains($pdf, '(Previous-period comparison)'), 'PDF contains the comparison section');
report_assert_true(str_contains($pdf, '-5.00 pp'), 'PDF identifies a savings-rate difference as percentage points');
report_assert_same(false, str_contains($pdf, 'Other user private'), 'User-isolated PDF bytes do not leak another user category');
$startXrefMatched = preg_match('/startxref\s+(\d+)\s+%%EOF\s*\z/', $pdf, $startXrefParts) === 1;
report_assert_same(true, $startXrefMatched, 'PDF declares a startxref offset');
report_assert_same(
    'xref',
    $startXrefMatched ? substr($pdf, (int) $startXrefParts[1], 4) : '',
    'PDF startxref resolves to the cross-reference table'
);

$longReport = $loadedPhp;
$longReport['categories'] = [];
for ($index = 1; $index <= 90; $index++) {
    $longReport['categories'][] = [
        'name' => 'Very long category name ' . $index . ' that must wrap safely across a paginated financial report',
        'total_cents' => $index,
        'share_percent' => 1.0,
        'count' => 1,
    ];
}
$longPdf = wallet_financial_report_pdf($longReport);
report_assert_true(preg_match('/\/Type \/Pages \/Count ([2-9]|[1-9][0-9]+)/', $longPdf) === 1, 'Long PDF tables paginate onto multiple pages');
report_assert_true(str_contains($longPdf, 'Very long category'), 'Long category labels are retained in PDF output');

echo 'Financial reports tests passed (' . $assertions . " assertions).\n";
