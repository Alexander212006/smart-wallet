<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Explicit opt-in: php tests/spending_workflow_test.php --run [http://127.0.0.1:8765]
// Uses the configured database and removes only its uniquely created fixture users.
if (($argv[1] ?? '') !== '--run') {
    fwrite(STDERR, "Usage: php tests/spending_workflow_test.php --run [local base URL]\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';

$baseUrl = rtrim($argv[2] ?? 'http://127.0.0.1:8765', '/');
$baseParts = parse_url($baseUrl);
if (!is_array($baseParts) || !in_array($baseParts['scheme'] ?? '', ['http', 'https'], true)
    || !in_array($baseParts['host'] ?? '', ['127.0.0.1', 'localhost', '::1', '[::1]'], true)
    || isset($baseParts['user']) || isset($baseParts['pass']) || isset($baseParts['query']) || isset($baseParts['fragment'])) {
    fwrite(STDERR, "The integration test requires a local HTTP base URL.\n");
    exit(2);
}
if (!extension_loaded('curl') || !extension_loaded('dom')) {
    fwrite(STDERR, "The integration test requires PHP curl and DOM.\n");
    exit(2);
}

$assertions = 0;
function workflow_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function workflow_request(CurlHandle $client, string $path, ?array $fields = null): array
{
    global $baseUrl;
    curl_setopt_array($client, [
        CURLOPT_URL => $baseUrl . '/' . ltrim($path, '/'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($fields === null) {
        curl_setopt($client, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($client, CURLOPT_POST, true);
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = curl_exec($client);
    if (!is_string($body)) {
        throw new RuntimeException('Local HTTP request failed; check that the PHP server is running.');
    }

    return ['status' => (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE), 'body' => $body];
}

function workflow_xpath(string $html): DOMXPath
{
    $document = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
    }

    return new DOMXPath($document);
}

function workflow_text(string $html, string $query): string
{
    $nodes = workflow_xpath($html)->query($query);
    workflow_assert($nodes !== false && $nodes->length === 1, 'Expected one rendered element: ' . $query);

    return trim((string) preg_replace('/\s+/u', ' ', $nodes->item(0)->textContent));
}

function workflow_csrf(string $html): string
{
    $nodes = workflow_xpath($html)->query('//input[@name="csrf_token"]/@value');
    workflow_assert($nodes !== false && $nodes->length > 0, 'Page provides a CSRF token.');

    return (string) $nodes->item(0)->nodeValue;
}

function workflow_client(): CurlHandle
{
    $client = curl_init();
    if (!$client instanceof CurlHandle) {
        throw new RuntimeException('Cannot initialize HTTP client.');
    }
    // An empty COOKIEFILE enables the cookie engine without reading/writing files.
    curl_setopt($client, CURLOPT_COOKIEFILE, '');

    return $client;
}

function workflow_login(CurlHandle $client, string $email, string $password): void
{
    $login = workflow_request($client, 'login.php');
    workflow_assert($login['status'] === 200, 'Login form is accessible.');
    $response = workflow_request($client, 'login.php', [
        'csrf_token' => workflow_csrf($login['body']), 'email' => $email, 'password' => $password,
    ]);
    workflow_assert($response['status'] === 302, 'Login accepts fixture credentials and CSRF.');
}

$pdo = getDatabaseConnection();
$fixtureUsers = [];
$cleanup = static function () use ($pdo, &$fixtureUsers): void {
    if ($fixtureUsers === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($fixtureUsers), '?'));
    // Transactions must be removed before cascading deletion reaches restricted wallets.
    $pdo->prepare('DELETE FROM transactions WHERE user_id IN (' . $placeholders . ')')->execute($fixtureUsers);
    $pdo->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')')->execute($fixtureUsers);
    $fixtureUsers = [];
};
register_shutdown_function(static function () use ($cleanup): void {
    try {
        $cleanup();
    } catch (Throwable) {
        fwrite(STDERR, "Fixture cleanup failed; check the disposable spending-workflow users.\n");
    }
});

try {
    $suffix = bin2hex(random_bytes(8));
    $password = bin2hex(random_bytes(24));
    $userInsert = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
    $emails = [];
    foreach (['main', 'foreign', 'empty'] as $role) {
        $emails[$role] = 'spending-workflow-' . $suffix . '-' . $role . '@example.test';
        $userInsert->execute(['Workflow ' . $role, $emails[$role], password_hash($password, PASSWORD_DEFAULT)]);
        $fixtureUsers[] = (int) $pdo->lastInsertId();
    }
    [$userId, $foreignUserId, $emptyUserId] = $fixtureUsers;
    $walletInsert = $pdo->prepare(
        'INSERT INTO wallets (user_id, name, wallet_type, current_balance, currency_code, is_archived)
         VALUES (?, ?, \'cash\', 1000.00, ?, ?)'
    );
    $wallets = [];
    foreach ([['php', $userId, 'PHP', 0], ['usd', $userId, 'USD', 0],
        ['archived', $userId, 'PHP', 1], ['foreign', $foreignUserId, 'PHP', 0]] as [$key, $owner, $currency, $archived]) {
        $walletInsert->execute([$owner, 'Workflow ' . $key . ' ' . $suffix, $currency, $archived]);
        $wallets[$key] = (int) $pdo->lastInsertId();
    }
    $categoryName = 'Workflow <food> & meals ' . $suffix;
    $foreignCategoryName = 'Private workflow category ' . $suffix;

    $today = new DateTimeImmutable('today');
    $month = $today->format('Y-m');
    $previousMonth = $today->modify('first day of this month')->modify('-1 month');
    $anonymous = workflow_client();
    workflow_assert(workflow_request($anonymous, 'spending.php')['status'] === 302, 'Analysis requires authentication.');
    $client = workflow_client();
    workflow_login($client, $emails['main'], $password);
    $form = workflow_request($client, 'expenses.php');
    workflow_assert($form['status'] === 200, 'Authenticated expenses page renders.');
    $csrf = workflow_csrf($form['body']);
    $fields = [
        'csrf_token' => $csrf, 'action' => 'add_expense', 'account_id' => $wallets['php'],
        'expense_category' => 'other', 'custom_category' => $categoryName, 'expense_amount' => '20.00',
        'expense_description' => 'Workflow expense ' . $suffix, 'expense_date' => $today->format('Y-m-d'),
        'expense_nature' => 'essential',
    ];
    $transactionQuery = $pdo->prepare('SELECT id, category, amount, expense_nature FROM transactions WHERE user_id = ? ORDER BY id');
    $balanceQuery = $pdo->prepare('SELECT current_balance FROM wallets WHERE id = ?');
    $assertState = static function (string $balance, int $count, ?string $nature) use (
        $pdo, $transactionQuery, $balanceQuery, $userId, $wallets, $categoryName
    ): array {
        $transactionQuery->execute([$userId]);
        $entries = $transactionQuery->fetchAll();
        workflow_assert(count($entries) === $count, 'Expense mutation leaves expected transaction count.');
        if ($count === 1) {
            workflow_assert($entries[0]['expense_nature'] === $nature, 'Expense classification persists correctly.');
            workflow_assert($entries[0]['category'] === $categoryName, 'Category is stored directly as a string.');
            workflow_assert(str_starts_with($entries[0]['amount'], '-'), 'Expense is stored as a negative amount.');
        }
        $balanceQuery->execute([$wallets['php']]);
        workflow_assert($balanceQuery->fetchColumn() === $balance, 'Wallet balance reflects the expense exactly once.');

        return $entries;
    };
    workflow_assert(workflow_request($client, 'expenses.php', $fields)['status'] === 302, 'Expense create redirects.');
    $entries = $assertState('980.00', 1, 'essential');
    $transactionId = (int) $entries[0]['id'];
    $fields['expense_category'] = 'category:' . $categoryName;
    unset($fields['custom_category']);
    $edit = workflow_request($client, 'expenses.php?edit_expense=' . $transactionId);
    workflow_assert($edit['status'] === 200, 'Edit expense form renders.');
    workflow_assert(workflow_text($edit['body'], '//select[@name="expense_nature"]/option[@selected]') === 'Essential',
        'Edit form selects the stored expense type.');
    workflow_assert(workflow_text($edit['body'], '//select[@name="expense_category"]/option[@selected]') === $categoryName,
        'Edit form selects the stored category name.');
    workflow_assert(workflow_text($edit['body'], '//input[@name="expense_amount"]/@value') === '20.00',
        'Negative stored expenses are edited as positive amounts.');
    $fields['action'] = 'update_expense';
    $fields['expense_id'] = $transactionId;
    $fields['expense_amount'] = '25.00';
    $fields['expense_nature'] = 'non_essential';
    workflow_assert(workflow_request($client, 'expenses.php', $fields)['status'] === 302, 'Expense edit redirects.');
    $assertState('975.00', 1, 'non_essential');
    $fields['expense_nature'] = '';
    workflow_assert(workflow_request($client, 'expenses.php', $fields)['status'] === 302, 'Unclassifying an expense redirects.');
    $assertState('975.00', 1, null);
    foreach (['bogus', ['essential']] as $invalidNature) {
        $invalidFields = $fields;
        $invalidFields['expense_nature'] = $invalidNature;
        $invalidFields['expense_amount'] = '50.00';
        workflow_assert(workflow_request($client, 'expenses.php', $invalidFields)['status'] === 302,
            'Invalid expense type follows validation redirect.');
        $assertState('975.00', 1, null);
    }
    foreach (['foreign', 'archived'] as $invalidWallet) {
        $invalidFields = $fields;
        $invalidFields['account_id'] = $wallets[$invalidWallet];
        workflow_assert(workflow_request($client, 'expenses.php', $invalidFields)['status'] === 302,
            'Unavailable wallet follows validation redirect.');
        $assertState('975.00', 1, null);
    }
    $invalidFields = $fields;
    $invalidFields['csrf_token'] = 'invalid-token';
    workflow_assert(workflow_request($client, 'expenses.php', $invalidFields)['status'] === 403, 'Invalid CSRF is rejected.');
    $assertState('975.00', 1, null);

    foreach (['', 'category:' . str_repeat('x', 81), ['category:Food']] as $invalidCategory) {
        $invalidFields = $fields;
        $invalidFields['expense_category'] = $invalidCategory;
        workflow_assert(workflow_request($client, 'expenses.php', $invalidFields)['status'] === 302,
            'Invalid category names follow the validation redirect.');
        $assertState('975.00', 1, null);
    }
    $movedExpense = $fields;
    $movedExpense['account_id'] = $wallets['usd'];
    $movedExpense['expense_amount'] = '30.00';
    workflow_assert(workflow_request($client, 'expenses.php', $movedExpense)['status'] === 302, 'Expense can move between owned wallets.');
    $balanceQuery->execute([$wallets['php']]);
    workflow_assert($balanceQuery->fetchColumn() === '1000.00', 'Moving expense refunds the previous wallet.');
    $balanceQuery->execute([$wallets['usd']]);
    workflow_assert($balanceQuery->fetchColumn() === '970.00', 'Moving expense debits the new wallet.');
    workflow_assert(workflow_request($client, 'expenses.php', $fields)['status'] === 302, 'Expense can move back.');
    $assertState('975.00', 1, null);
    $balanceQuery->execute([$wallets['usd']]);
    workflow_assert($balanceQuery->fetchColumn() === '1000.00', 'Moving expense back restores the other wallet.');

    $incomeFields = [
        'csrf_token' => $csrf, 'action' => 'create', 'account_id' => $wallets['php'],
        'category' => 'other', 'custom_category' => 'other', 'amount' => '50.00',
        'description' => 'Workflow income', 'transaction_date' => $today->format('Y-m-d'),
    ];
    workflow_assert(workflow_request($client, 'income.php', $incomeFields)['status'] === 302, 'Income create redirects.');
    $incomeQuery = $pdo->prepare('SELECT id,category,amount FROM transactions WHERE user_id=? AND amount>0');
    $incomeQuery->execute([$userId]);
    $income = $incomeQuery->fetch();
    workflow_assert($income !== false && $income['category'] === 'other' && $income['amount'] === '50.00',
        'Income stores a positive amount and literal category name.');
    $balanceQuery->execute([$wallets['php']]);
    workflow_assert($balanceQuery->fetchColumn() === '1025.00', 'Income credits its wallet.');
    $incomeEdit = workflow_request($client, 'income.php?edit=' . $income['id']);
    workflow_assert($incomeEdit['status'] === 200
        && workflow_text($incomeEdit['body'], '//select[@name="category"]/option[@selected]') === 'other',
        'A category literally named other remains selectable during editing.');
    $incomeFields['action'] = 'update';
    $incomeFields['transaction_id'] = $income['id'];
    $incomeFields['category'] = 'category:other';
    unset($incomeFields['custom_category']);
    $incomeFields['amount'] = '75.00';
    workflow_assert(workflow_request($client, 'income.php', $incomeFields)['status'] === 302, 'Income edit redirects.');
    $balanceQuery->execute([$wallets['php']]);
    workflow_assert($balanceQuery->fetchColumn() === '1050.00', 'Income edit reverses the previous positive amount.');
    $incomeFields['amount'] = '40.00';
    $incomeFields['account_id'] = $wallets['usd'];
    workflow_assert(workflow_request($client, 'income.php', $incomeFields)['status'] === 302, 'Income can move between wallets.');
    $balanceQuery->execute([$wallets['php']]);
    workflow_assert($balanceQuery->fetchColumn() === '975.00', 'Moving income debits its old wallet.');
    $balanceQuery->execute([$wallets['usd']]);
    workflow_assert($balanceQuery->fetchColumn() === '1040.00', 'Moving income credits its new wallet.');
    $wrongIncome = $incomeFields;
    $wrongIncome['transaction_id'] = $transactionId;
    workflow_assert(workflow_request($client, 'income.php', $wrongIncome)['status'] === 302, 'Income endpoint rejects editing an expense.');
    $wrongExpense = $fields;
    $wrongExpense['expense_id'] = $income['id'];
    workflow_assert(workflow_request($client, 'expenses.php', $wrongExpense)['status'] === 302, 'Expense endpoint rejects editing income.');
    $balanceQuery->execute([$wallets['usd']]);
    workflow_assert($balanceQuery->fetchColumn() === '1040.00', 'Rejected cross-direction edits preserve the balance.');
    workflow_assert(workflow_request($client, 'income.php', [
        'csrf_token' => $csrf, 'action' => 'delete', 'transaction_id' => $income['id'],
    ])['status'] === 302, 'Income delete redirects.');
    $balanceQuery->execute([$wallets['usd']]);
    workflow_assert($balanceQuery->fetchColumn() === '1000.00', 'Deleting income reverses the positive amount.');
    $assertState('975.00', 1, null);

    foreach (['0', str_repeat('食', 80)] as $edgeCategory) {
        $edgeIncome = [
            'csrf_token' => $csrf, 'action' => 'create', 'account_id' => $wallets['php'],
            'category' => 'other', 'custom_category' => $edgeCategory, 'amount' => '1.00',
            'transaction_date' => $today->format('Y-m-d'),
        ];
        workflow_assert(workflow_request($client, 'income.php', $edgeIncome)['status'] === 302,
            'Numeric and eighty-character Unicode category names are accepted.');
        $incomeQuery->execute([$userId]);
        $edgeRecord = $incomeQuery->fetch();
        workflow_assert($edgeRecord !== false && $edgeRecord['category'] === $edgeCategory,
            'Category names retain their exact string value.');
        $edgeEdit = workflow_request($client, 'income.php?edit=' . $edgeRecord['id']);
        workflow_assert($edgeEdit['status'] === 200
            && workflow_text($edgeEdit['body'], '//select[@name="category"]/option[@selected]') === $edgeCategory,
            'Edge category has exactly one selected option.');
        workflow_assert(workflow_request($client, 'income.php', [
            'csrf_token' => $csrf, 'action' => 'delete', 'transaction_id' => $edgeRecord['id'],
        ])['status'] === 302, 'Edge-case income can be deleted.');
    }
    $assertState('975.00', 1, null);

    $limitFields = [
        'csrf_token' => $csrf, 'action' => 'create', 'name' => 'Workflow limit ' . $suffix,
        'category' => 'category:' . $categoryName, 'amount' => '100.00', 'currency_code' => 'PHP',
        'period' => 'monthly', 'start_date' => $today->modify('first day of this month')->format('Y-m-d'),
        'end_date' => '', 'is_recurring' => '1',
    ];
    workflow_assert(workflow_request($client, 'expenses.php', $limitFields)['status'] === 302, 'Category-scoped limit can be created.');
    $limitQuery = $pdo->prepare('SELECT id,category FROM expense_limits WHERE user_id=?');
    $limitQuery->execute([$userId]);
    $limit = $limitQuery->fetch();
    workflow_assert($limit !== false && $limit['category'] === $categoryName, 'Limits store category names directly.');
    $limitDashboard = workflow_request($client, 'index.php?currency=PHP');
    $limitCard = workflow_text($limitDashboard['body'], '//article[@id="expenses"]');
    workflow_assert(str_contains($limitCard, $categoryName) && str_contains($limitCard, 'PHP 25.00 / PHP 100.00'),
        'Category-scoped limit sums negative expenses as positive spending.');
    $limitFields['action'] = 'update';
    $limitFields['budget_id'] = $limit['id'];
    $limitFields['category'] = '';
    workflow_assert(workflow_request($client, 'expenses.php', $limitFields)['status'] === 302, 'Limit can switch to all categories.');
    $limitQuery->execute([$userId]);
    workflow_assert($limitQuery->fetch()['category'] === null, 'All-category limits store NULL.');
    workflow_assert(workflow_request($client, 'expenses.php', [
        'csrf_token' => $csrf, 'action' => 'delete', 'budget_id' => $limit['id'],
    ])['status'] === 302, 'Limit delete redirects.');

    $expenseInsert = $pdo->prepare(
        'INSERT INTO transactions (user_id, wallet_id, category, amount, description, transaction_date, expense_nature)
         VALUES (?, ?, ?, ?, \'Workflow scoped fixture\', ?, ?)'
    );
    $currentDate = $today->format('Y-m-d') . ' 12:00:00';
    foreach ([
        [$userId, $wallets['php'], $categoryName, '-5.00', $currentDate, 'essential'],
        [$userId, $wallets['php'], $categoryName, '-10.00', $currentDate, 'non_essential'],
        [$userId, $wallets['usd'], $categoryName, '-120.00', $currentDate, 'essential'],
        [$userId, $wallets['archived'], $categoryName, '-700.00', $currentDate, null],
        [$foreignUserId, $wallets['foreign'], $foreignCategoryName, '-800.00', $currentDate, null],
        [$userId, $wallets['php'], $categoryName, '444.00', $currentDate, null],
        [$userId, $wallets['php'], $categoryName, '-12.00', $previousMonth->format('Y-m-d') . ' 12:00:00', null],
        [$userId, $wallets['php'], $categoryName, '-900.00', $today->modify('+1 day')->format('Y-m-d') . ' 12:00:00', null],
    ] as $fixture) {
        $expenseInsert->execute($fixture);
    }

    $analysisPage = workflow_request($client, 'spending.php?currency=PHP&month=' . $month);
    workflow_assert($analysisPage['status'] === 200, 'Spending page renders with filters.');
    workflow_assert(workflow_text($analysisPage['body'], '//h1') === 'Spending analysis', 'Analysis heading renders.');
    $highlight = workflow_text($analysisPage['body'], '//article[.//p[normalize-space(.)="Spent this month so far"]]');
    workflow_assert(str_contains($highlight, 'PHP 40.00') && str_contains($highlight, '3 expenses recorded'),
        'Current total scopes owner, active wallet, currency, expense type and current date.');
    foreach (['Essential' => 'PHP 5.00', 'Non-essential' => 'PHP 10.00', 'Unclassified' => 'PHP 25.00'] as $label => $amount) {
        $row = workflow_text($analysisPage['body'], '//article[.//h2[normalize-space(.)="Essential vs. non-essential"]]//p[normalize-space(.)="'
            . $label . '"]/parent::div/parent::div');
        workflow_assert(str_contains($row, $amount) && str_contains($row, '1 expense'), 'Rendered type breakdown matches stored expenses.');
    }
    $categoryRow = workflow_text($analysisPage['body'], '//tbody/tr[1]');
    workflow_assert(str_contains($categoryRow, $categoryName) && str_contains($categoryRow, 'PHP 40.00')
        && str_contains($categoryRow, '100.0%'), 'Category frequency table uses the same scoped total.');
    workflow_assert(!str_contains($analysisPage['body'], $foreignCategoryName), 'Another user category never renders.');
    workflow_assert(str_contains($analysisPage['body'], htmlspecialchars($categoryName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        'Stored category names are escaped when rendered.');
    foreach (['Highest spending day', 'Highest spending week', 'Spending across months', 'Weekly spending pattern'] as $heading) {
        workflow_assert(str_contains($analysisPage['body'], $heading), 'Analysis renders ' . $heading . '.');
    }
    $dashboard = workflow_request($client, 'index.php?currency=PHP');
    workflow_assert($dashboard['status'] === 200, 'Dashboard renders with analysis.');
    $summary = workflow_text($dashboard['body'], '//section[@aria-labelledby="spending-summary-title"]');
    workflow_assert(str_contains($summary, 'Spending behavior') && str_contains($summary, $categoryName)
        && str_contains($summary, 'PHP 40.00'), 'Dashboard summary uses the scoped category and peak-day amount.');
    workflow_assert(str_contains($summary, '13% / 25%') && str_contains($summary, '63% unclassified'),
        'Dashboard shows rounded essential, non-essential and unclassified shares.');

    $usd = workflow_request($client, 'spending.php?currency=USD&month=' . $month);
    workflow_assert($usd['status'] === 200, 'Currency filter renders.');
    workflow_assert(str_contains(workflow_text($usd['body'], '//article[.//p[normalize-space(.)="Spent this month so far"]]'), 'USD 120.00'),
        'Currency filter switches the analysis total.');
    $previous = workflow_request($client, 'spending.php?currency=PHP&month=' . $previousMonth->format('Y-m'));
    workflow_assert($previous['status'] === 200, 'Historical month filter renders.');
    workflow_assert(str_contains(workflow_text($previous['body'], '//article[.//p[normalize-space(.)="Total monthly spending"]]'), 'PHP 12.00'),
        'Month filter switches to the complete historical month.');
    foreach (['month=invalid&currency=ZZZ', 'month%5B%5D=invalid&currency%5B%5D=USD'] as $invalidQuery) {
        $fallback = workflow_request($client, 'spending.php?' . $invalidQuery);
        workflow_assert($fallback['status'] === 200, 'Invalid scalar/array filters safely fall back.');
        workflow_assert(str_contains(workflow_text($fallback['body'], '//article[.//p[normalize-space(.)="Spent this month so far"]]'), 'PHP 40.00'),
            'Invalid filters default to current month and an owned active currency.');
    }

    workflow_assert(workflow_request($client, 'expenses.php', [
        'csrf_token' => $csrf, 'action' => 'delete_expense', 'expense_id' => $transactionId,
    ])['status'] === 302, 'Expense delete redirects.');
    $balanceQuery->execute([$wallets['php']]);
    workflow_assert($balanceQuery->fetchColumn() === '1000.00', 'Deleting a negative expense restores the wallet balance.');
    $deletedExpense = $pdo->prepare('SELECT id FROM transactions WHERE id=? AND user_id=?');
    $deletedExpense->execute([$transactionId, $userId]);
    workflow_assert($deletedExpense->fetch() === false, 'Deleted expense is removed.');

    $emptyClient = workflow_client();
    workflow_login($emptyClient, $emails['empty'], $password);
    $emptyAnalysis = workflow_request($emptyClient, 'spending.php');
    workflow_assert($emptyAnalysis['status'] === 200 && str_contains($emptyAnalysis['body'], 'Your spending story starts with a wallet'),
        'New user without wallets sees the analysis starting state.');
    $emptyDashboard = workflow_request($emptyClient, 'index.php');
    workflow_assert($emptyDashboard['status'] === 200 && str_contains($emptyDashboard['body'], 'Your money overview starts with a wallet.'),
        'New user dashboard renders its starting state.');
    $walletInsert->execute([$emptyUserId, 'Workflow empty ' . $suffix, 'PHP', 0]);
    $emptyAnalysis = workflow_request($emptyClient, 'spending.php');
    workflow_assert($emptyAnalysis['status'] === 200 && str_contains($emptyAnalysis['body'], 'No expenses recorded for'),
        'User with an empty wallet sees the zero-spending state.');
    workflow_assert(str_contains(workflow_text($emptyAnalysis['body'], '//article[.//p[normalize-space(.)="Spent this month so far"]]'), 'PHP 0.00'),
        'Empty analysis renders a zero total.');
    $emptyDashboard = workflow_request($emptyClient, 'index.php');
    workflow_assert($emptyDashboard['status'] === 200 && str_contains(
        workflow_text($emptyDashboard['body'], '//section[@aria-labelledby="spending-summary-title"]'), 'Record expenses to discover'
    ), 'Dashboard spending summary handles an empty wallet.');
} catch (Throwable $error) {
    fwrite(STDERR, 'Spending workflow test failed: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    $cleanup();
}

echo 'Spending workflow tests passed (' . $assertions . " assertions); fixture users removed.\n";
