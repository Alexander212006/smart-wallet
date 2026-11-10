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

    return [
        'status' => (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE),
        'content_type' => (string) (curl_getinfo($client, CURLINFO_CONTENT_TYPE) ?: ''),
        'body' => $body,
    ];
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

function workflow_overspending_count(string $html): int
{
    return workflow_xpath($html)->query('//section[@aria-labelledby="overspending-alert-title"]/ul/li')->length;
}

function workflow_overspending_toasts(string $html): int
{
    return workflow_xpath($html)->query('//div[@data-flash-message and @data-type="warning" and starts-with(@data-message,"Overspending alert:")]')->length;
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
    workflow_assert(
        workflow_request($anonymous, 'reports.php?period=monthly&month=' . $month . '&currency=PHP&export=pdf')['status'] === 302,
        'Financial report exports require authentication.'
    );
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
        'csrf_token' => $csrf, 'action' => 'create', 'name' => 'Workflow <limit> & meals ' . $suffix,
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
    workflow_assert(str_contains($limitCard, $categoryName)
        && str_contains($limitCard, 'PHP 25.00 spent / PHP 100.00 limit')
        && str_contains($limitCard, 'PHP 75.00 remaining'),
        'Category-scoped limit sums negative expenses as positive spending.');
    workflow_assert(workflow_overspending_count($limitDashboard['body']) === 0, 'Spending below a limit does not alert.');

    $ignoredInsert = $pdo->prepare('INSERT INTO transactions (user_id,wallet_id,category,amount,transaction_date) VALUES (?,?,?,?,?)');
    $ignoredIds = [];
    foreach ([
        [$userId, $wallets['php'], $categoryName, '-500.00', $today->modify('+1 day')],
        [$userId, $wallets['usd'], $categoryName, '-500.00', $today],
        [$userId, $wallets['archived'], $categoryName, '-500.00', $today],
        [$foreignUserId, $wallets['foreign'], $foreignCategoryName, '-500.00', $today],
        [$userId, $wallets['php'], 'Another category', '-500.00', $today],
        [$userId, $wallets['php'], $categoryName, '500.00', $today],
    ] as [$owner, $wallet, $ignoredCategory, $ignoredAmount, $date]) {
        $ignoredInsert->execute([$owner, $wallet, $ignoredCategory, $ignoredAmount, $date->format('Y-m-d') . ' 12:00:00']);
        $ignoredIds[] = (int) $pdo->lastInsertId();
    }
    $scopedLimits = workflow_request($client, 'expenses.php');
    workflow_assert($scopedLimits['status'] === 200 && workflow_overspending_count($scopedLimits['body']) === 0,
        'Future, archived, foreign-user, other-currency and other-category entries do not cause a false alert.');

    $limitFields['action'] = 'update';
    $limitFields['budget_id'] = $limit['id'];
    $limitFields['amount'] = '20.00';
    workflow_assert(workflow_request($client, 'expenses.php', $limitFields)['status'] === 302, 'Lowering a limit redirects.');
    $overPage = workflow_request($client, 'expenses.php');
    workflow_assert($overPage['status'] === 200 && workflow_overspending_count($overPage['body']) === 1,
        'An exceeded category limit displays a persistent alert.');
    $overText = workflow_text($overPage['body'], '//section[@aria-labelledby="overspending-alert-title"]');
    workflow_assert(str_contains($overText, 'PHP 5.00 over limit') && str_contains($overText, 'PHP 25.00 spent / 20.00 limit'),
        'Alert shows the exact excess and scoped spending total.');
    workflow_assert(workflow_overspending_toasts($overPage['body']) === 1, 'A newly exceeded limit creates one warning toast.');
    workflow_assert(str_contains($overPage['body'], htmlspecialchars($limitFields['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        'Limit names are escaped in alerts.');
    $overDashboard = workflow_request($client, 'index.php?currency=PHP');
    workflow_assert(workflow_overspending_count($overDashboard['body']) === 1 && workflow_overspending_toasts($overDashboard['body']) === 0,
        'Overview retains the warning without repeating the same toast.');
    $limitFields['amount'] = '15.00';
    workflow_request($client, 'expenses.php', $limitFields);
    $worsePage = workflow_request($client, 'expenses.php');
    workflow_assert(workflow_overspending_toasts($worsePage['body']) === 1
        && str_contains(workflow_text($worsePage['body'], '//section[@aria-labelledby="overspending-alert-title"]'), 'PHP 10.00 over limit'),
        'Increasing the excess produces a fresh warning.');
    $limitFields['amount'] = '25.00';
    workflow_request($client, 'expenses.php', $limitFields);
    $atLimitPage = workflow_request($client, 'expenses.php');
    workflow_assert(workflow_overspending_count($atLimitPage['body']) === 0 && workflow_overspending_toasts($atLimitPage['body']) === 0,
        'Exactly at the limit clears the alert.');

    $pennyFields = $fields;
    $pennyFields['action'] = 'add_expense';
    $pennyFields['expense_amount'] = '0.01';
    $pennyFields['expense_description'] = 'Overspending penny ' . $suffix;
    unset($pennyFields['expense_id']);
    workflow_assert(workflow_request($client, 'expenses.php', $pennyFields)['status'] === 302, 'A one-cent expense saves.');
    $pennyPage = workflow_request($client, 'expenses.php');
    workflow_assert(workflow_overspending_toasts($pennyPage['body']) === 1
        && str_contains(workflow_text($pennyPage['body'], '//section[@aria-labelledby="overspending-alert-title"]'), 'PHP 0.01 over limit'),
        'Saving an expense one cent over a resolved limit triggers the alert again.');
    $pennyQuery = $pdo->prepare('SELECT id FROM transactions WHERE user_id=? AND description=?');
    $pennyQuery->execute([$userId, $pennyFields['expense_description']]);
    $pennyId = (int) $pennyQuery->fetchColumn();
    workflow_request($client, 'expenses.php', ['csrf_token' => $csrf, 'action' => 'delete_expense', 'expense_id' => $pennyId]);
    $resolvedPage = workflow_request($client, 'expenses.php');
    workflow_assert(workflow_overspending_count($resolvedPage['body']) === 0, 'Deleting the excess expense clears the warning.');
    $limitFields['category'] = '';
    $limitFields['amount'] = '20.00';
    workflow_request($client, 'expenses.php', $limitFields);
    $allCategoriesPage = workflow_request($client, 'expenses.php');
    workflow_assert(str_contains(workflow_text($allCategoriesPage['body'], '//section[@aria-labelledby="overspending-alert-title"]'), 'PHP 505.00 over limit'),
        'An all-category limit includes other spending categories.');
    $limitFields['category'] = 'category:' . $categoryName;
    $limitFields['amount'] = '100.00';
    workflow_request($client, 'expenses.php', $limitFields);
    workflow_request($client, 'expenses.php');

    $extraLimitInsert = $pdo->prepare('INSERT INTO expense_limits (user_id,name,category,amount,currency_code,period,start_date,end_date,is_recurring) VALUES (?,?,?,?,?,?,?,?,?)');
    $extraLimitIds = [];
    foreach ([
        [$userId, 'Weekly warning', $categoryName, '5.00', 'PHP', 'weekly', $today, null, 1],
        [$userId, 'Yearly warning', $categoryName, '5.00', 'PHP', 'yearly', $today, null, 1],
        [$userId, 'USD warning', $categoryName, '5.00', 'USD', 'monthly', $today, null, 1],
        [$foreignUserId, 'Private foreign limit', $foreignCategoryName, '1.00', 'PHP', 'monthly', $today, null, 1],
        [$userId, 'Expired limit', $categoryName, '1.00', 'PHP', 'weekly', $today->modify('-21 days'), $today->modify('-15 days'), 0],
        [$userId, 'Stopped limit', $categoryName, '1.00', 'PHP', 'weekly', $today->modify('-7 days'), $today->modify('-1 day'), 1],
        [$userId, 'Future limit', $categoryName, '1.00', 'PHP', 'weekly', $today->modify('+1 day'), null, 1],
        [$userId, 'Prior-period spending', 'Rollover only', '100.00', 'PHP', 'weekly', $today->modify('-7 days'), null, 1],
    ] as [$owner, $limitName, $limitCategory, $limitAmount, $limitCurrency, $limitPeriod, $start, $end, $recurring]) {
        $extraLimitInsert->execute([$owner, $limitName, $limitCategory, $limitAmount, $limitCurrency, $limitPeriod,
            $start->format('Y-m-d'), $end?->format('Y-m-d'), $recurring]);
        $extraLimitIds[] = (int) $pdo->lastInsertId();
    }
    $ignoredInsert->execute([$userId, $wallets['php'], 'Rollover only', '-1000.00', $today->modify('-1 day')->format('Y-m-d') . ' 12:00:00']);
    $ignoredIds[] = (int) $pdo->lastInsertId();
    $multiPage = workflow_request($client, 'index.php?currency=PHP');
    workflow_assert($multiPage['status'] === 200 && workflow_overspending_count($multiPage['body']) === 3,
        'Weekly, yearly and other-currency limits alert; inactive, foreign and prior-period limits do not.');
    $overviewLimitNodes = workflow_xpath($multiPage['body'])->query('//article[@id="expenses"]//*[@data-overview-expense-limit]');
    workflow_assert($overviewLimitNodes !== false && $overviewLimitNodes->length === 4,
        'Overview displays all active limits for its selected currency without a monthly-only filter or card cap.');
    $overviewLimitText = workflow_text($multiPage['body'], '//article[@id="expenses"]');
    workflow_assert(str_contains($overviewLimitText, 'Weekly warning')
        && str_contains($overviewLimitText, 'Yearly warning')
        && str_contains($overviewLimitText, 'Prior-period spending')
        && !str_contains($overviewLimitText, 'Expired limit')
        && !str_contains($overviewLimitText, 'Stopped limit')
        && !str_contains($overviewLimitText, 'Future limit')
        && !str_contains($overviewLimitText, 'USD warning'),
        'Overview includes active weekly/yearly/recurring limits and excludes inactive or other-currency limits.');
    $overviewWeekly = workflow_text(
        $multiPage['body'],
        '//article[@id="expenses"]//*[@data-overview-expense-limit][.//p[normalize-space(.)="Weekly warning"]]'
    );
    $expensesWithLimits = workflow_request($client, 'expenses.php');
    $expensesWeekly = workflow_text(
        $expensesWithLimits['body'],
        '//section[@id="expense-limits"]//article[.//h2[normalize-space(.)="Weekly warning"]]'
    );
    foreach ([$overviewWeekly, $expensesWeekly] as $weeklyText) {
        workflow_assert(str_contains($weeklyText, $categoryName)
            && str_contains($weeklyText, 'Weekly')
            && str_contains($weeklyText, 'PHP 25.00 spent')
            && str_contains($weeklyText, 'PHP 5.00')
            && str_contains($weeklyText, 'PHP 20.00 over limit'),
            'Overview and Expenses use the same category, period, spent, limit and overspending values.');
    }
    $multiText = workflow_text($multiPage['body'], '//section[@aria-labelledby="overspending-alert-title"]');
    workflow_assert(str_contains($multiText, 'Weekly warning') && str_contains($multiText, 'Yearly warning')
        && str_contains($multiText, 'PHP 20.00 over limit') && str_contains($multiText, 'USD 495.00 over limit'),
        'Each currency and period keeps its own excess amount.');
    workflow_assert(workflow_overspending_toasts($multiPage['body']) === 1, 'Multiple exceeded limits produce one summarized toast.');
    $switchedCurrency = workflow_request($client, 'index.php?currency=USD');
    workflow_assert(workflow_overspending_count($switchedCurrency['body']) === 3 && workflow_overspending_toasts($switchedCurrency['body']) === 0,
        'Switching dashboard currency does not reset or duplicate alerts.');
    $usdOverviewLimits = workflow_xpath($switchedCurrency['body'])->query('//article[@id="expenses"]//*[@data-overview-expense-limit]');
    workflow_assert($usdOverviewLimits !== false && $usdOverviewLimits->length === 1
        && str_contains((string) $usdOverviewLimits->item(0)->textContent, 'USD warning'),
        'Overview limits remain scoped to the selected wallet currency.');
    $pdo->prepare('DELETE FROM expense_limits WHERE id IN (' . implode(',', array_fill(0, count($extraLimitIds), '?')) . ')')->execute($extraLimitIds);
    $pdo->prepare('DELETE FROM transactions WHERE id IN (' . implode(',', array_fill(0, count($ignoredIds), '?')) . ')')->execute($ignoredIds);

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

    $reportPage = workflow_request($client, 'reports.php?period=monthly&month=' . $month . '&currency=PHP');
    workflow_assert($reportPage['status'] === 200, 'Financial Reports page renders with validated filters.');
    workflow_assert(workflow_text($reportPage['body'], '//h1') === 'Financial Reports', 'Financial Reports heading renders.');
    $reportSummary = workflow_text($reportPage['body'], '//section[@aria-label="Financial summary"]');
    workflow_assert(
        str_contains($reportSummary, 'PHP 444.00') && str_contains($reportSummary, 'PHP 40.00')
            && !str_contains($reportSummary, 'PHP 900.00'),
        'Financial report summary scopes active owned wallets and excludes future transactions.'
    );
    workflow_assert(
        !str_contains($reportPage['body'], $foreignCategoryName) && str_contains($reportPage['body'], 'Previous-period comparison'),
        'Financial report isolates user data and renders its comparison section.'
    );

    $csvExport = workflow_request(
        $client,
        'reports.php?period=monthly&month=' . $month . '&currency=PHP&export=csv'
    );
    workflow_assert(
        $csvExport['status'] === 200 && str_starts_with($csvExport['content_type'], 'text/csv')
            && str_starts_with($csvExport['body'], "\xEF\xBB\xBF")
            && str_contains($csvExport['body'], 'Financial summary')
            && str_contains($csvExport['body'], '444.00'),
        'Authenticated CSV export returns matching UTF-8 report data.'
    );
    $pdfExport = workflow_request(
        $client,
        'reports.php?period=monthly&month=' . $month . '&currency=PHP&export=pdf'
    );
    workflow_assert(
        $pdfExport['status'] === 200 && str_starts_with($pdfExport['content_type'], 'application/pdf')
            && str_starts_with($pdfExport['body'], '%PDF-1.4')
            && str_ends_with($pdfExport['body'], "%%EOF\n"),
        'Authenticated PDF export returns a complete PDF document.'
    );

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
    workflow_assert($balanceQuery->fetchColumn() === '517.00', 'Deleting an expense reverses its amount while preserving other trigger-managed transactions.');
    $deletedExpense = $pdo->prepare('SELECT id FROM transactions WHERE id=? AND user_id=?');
    $deletedExpense->execute([$transactionId, $userId]);
    workflow_assert($deletedExpense->fetch() === false, 'Deleted expense is removed.');

    $walletFields = [
        'csrf_token' => $csrf, 'action' => 'create', 'name' => 'Procedure wallet ' . $suffix,
        'account_type' => 'cash', 'current_balance' => '123.45', 'currency_code' => 'PHP',
    ];
    workflow_assert(workflow_request($client, 'wallet.php', $walletFields)['status'] === 302, 'Wallet form calls the creation procedure.');
    $createdWalletQuery = $pdo->prepare('SELECT id,name,current_balance,is_archived FROM wallets WHERE user_id=? AND name=?');
    $createdWalletQuery->execute([$userId, $walletFields['name']]);
    $createdWallet = $createdWalletQuery->fetch();
    workflow_assert($createdWallet !== false && $createdWallet['current_balance'] === '123.45', 'Wallet procedure preserves the starting balance.');
    $walletFields['action'] = 'update';
    $walletFields['account_id'] = $createdWallet['id'];
    $walletFields['name'] .= ' renamed';
    workflow_assert(workflow_request($client, 'wallet.php', $walletFields)['status'] === 302, 'Wallet form calls the update procedure.');
    foreach (['archive' => 1, 'restore' => 0] as $walletAction => $expectedArchived) {
        workflow_assert(workflow_request($client, 'wallet.php', [
            'csrf_token' => $csrf, 'action' => $walletAction, 'account_id' => $createdWallet['id'],
        ])['status'] === 302, 'Wallet archive/restore calls its procedure.');
        $createdWalletQuery->execute([$userId, $walletFields['name']]);
        workflow_assert((int) $createdWalletQuery->fetch()['is_archived'] === $expectedArchived, 'Wallet archive state persists.');
    }
    $goalFields = [
        'csrf_token' => $csrf, 'action' => 'create', 'name' => 'Procedure goal ' . $suffix,
        'description' => 'Database integration test', 'target_amount' => '100.00', 'currency_code' => 'PHP',
        'target_date' => $today->modify('+1 year')->format('Y-m-d'),
    ];
    workflow_assert(workflow_request($client, 'savings.php', $goalFields)['status'] === 302, 'Savings form calls the goal procedure.');
    $createdGoalQuery = $pdo->prepare('SELECT id,status,saved,contribution_count FROM vw_savings_goal_progress WHERE user_id=? AND name=?');
    $createdGoalQuery->execute([$userId, $goalFields['name']]);
    $createdGoal = $createdGoalQuery->fetch();
    workflow_assert($createdGoal !== false && $createdGoal['saved'] === '0.00', 'Savings view supplies the new goal progress.');
    $goalFields['action'] = 'update';
    $goalFields['goal_id'] = $createdGoal['id'];
    workflow_assert(workflow_request($client, 'savings.php', $goalFields)['status'] === 302, 'Savings form calls the update procedure.');
    foreach (['paused', 'active'] as $goalStatus) {
        workflow_assert(workflow_request($client, 'savings.php', [
            'csrf_token' => $csrf, 'action' => 'status', 'goal_id' => $createdGoal['id'], 'status' => $goalStatus,
        ])['status'] === 302, 'Goal status calls its procedure.');
    }
    foreach (['40.00', '60.00'] as $contributionAmount) {
        workflow_assert(workflow_request($client, 'savings.php', [
            'csrf_token' => $csrf, 'action' => 'contribute', 'goal_id' => $createdGoal['id'],
            'account_id' => $createdWallet['id'], 'amount' => $contributionAmount, 'note' => 'Procedure test',
        ])['status'] === 302, 'Contribution form calls its procedure.');
    }
    $createdGoalQuery->execute([$userId, $goalFields['name']]);
    $goalProgress = $createdGoalQuery->fetch();
    workflow_assert($goalProgress['status'] === 'completed' && $goalProgress['saved'] === '100.00'
        && (int) $goalProgress['contribution_count'] === 2, 'Contribution trigger completes the goal and the view summarizes it.');
    $balanceQuery->execute([$createdWallet['id']]);
    workflow_assert($balanceQuery->fetchColumn() === '123.45', 'Savings contributions remain separate from wallet balance changes.');
    $savingsPage = workflow_request($client, 'savings.php?view=achieved');
    workflow_assert($savingsPage['status'] === 200 && str_contains($savingsPage['body'], $goalFields['name']), 'Savings page renders the procedure-backed completed goal.');
    $contributionQuery=$pdo->prepare('SELECT * FROM savings_contributions WHERE savings_goal_id=? ORDER BY id LIMIT 1');
    $contributionQuery->execute([$createdGoal['id']]);
    $editableContribution=$contributionQuery->fetch();
    workflow_assert(str_contains($savingsPage['body'],'edit_contribution='.$editableContribution['id']), 'Recent contributions offer an Edit link on completed goals.');
    $editPath='savings.php?edit_contribution='.$editableContribution['id'];
    $editPage=workflow_request($client,$editPath);
    workflow_assert($editPage['status']===200 && str_contains($editPage['body'],'id="contribution-editor"'), 'Contribution edit form opens for an owned completed goal.');
    workflow_assert(workflow_text($editPage['body'],'//section[@id="contribution-editor"]//h2')==='Edit contribution', 'Contribution editor has its own heading.');
    $editFields=[
        'csrf_token'=>workflow_csrf($editPage['body']), 'action'=>'update_contribution', 'goal_id'=>$createdGoal['id'],
        'contribution_id'=>$editableContribution['id'], 'account_id'=>'', 'amount'=>'10.00',
        'contribution_date'=>$today->modify('-1 day')->format('Y-m-d'), 'note'=>'Correction <extra> & savings',
    ];
    workflow_assert(workflow_request($client,'savings.php',$editFields)['status']===302, 'Contribution edit saves through its procedure.');
    $createdGoalQuery->execute([$userId,$goalFields['name']]);
    $correctedGoal=$createdGoalQuery->fetch();
    workflow_assert($correctedGoal['saved']==='70.00' && $correctedGoal['status']==='active' && (int)$correctedGoal['contribution_count']===2, 'Correcting a completed contribution updates the total and reopens the goal without adding a record.');
    $contributionQuery->execute([$createdGoal['id']]);
    $correctedContribution=$contributionQuery->fetch();
    workflow_assert($correctedContribution['wallet_id']===null && $correctedContribution['note']===$editFields['note'] && substr($correctedContribution['contribution_date'],0,10)===$editFields['contribution_date'], 'Edited account, date and note are stored.');
    $correctedPage=workflow_request($client,'savings.php');
    workflow_assert(str_contains($correctedPage['body'],'Correction &lt;extra&gt; &amp; savings'), 'Edited contribution notes are escaped in recent history.');
    foreach (['amount'=>'0.00','contribution_date'=>'not-a-date','account_id'=>$wallets['foreign']] as $field=>$invalidValue) {
        workflow_assert(workflow_request($client,'savings.php',array_replace($editFields,[$field=>$invalidValue]))['status']===302, 'Invalid contribution edit returns safely.');
        $contributionQuery->execute([$createdGoal['id']]);
        workflow_assert($contributionQuery->fetch()['amount']==='10.00', 'Invalid edits leave the contribution unchanged.');
    }
    workflow_assert(workflow_request($client,'savings.php',array_replace($editFields,['csrf_token'=>'invalid']))['status']===403, 'Contribution editing requires a valid CSRF token.');
    $otherClient=workflow_client();
    workflow_login($otherClient,$emails['foreign'],$password);
    $foreignEdit=workflow_request($otherClient,$editPath);
    workflow_assert(!str_contains($foreignEdit['body'],'id="contribution-editor"') && !str_contains($foreignEdit['body'],$goalFields['name']), 'Another user cannot open the contribution editor or see its goal.');
    $foreignFields=array_replace($editFields,['csrf_token'=>workflow_csrf($foreignEdit['body'])]);
    workflow_assert(workflow_request($otherClient,'savings.php',$foreignFields)['status']===302, 'Forged contribution edit is rejected safely.');
    $contributionQuery->execute([$createdGoal['id']]);
    workflow_assert($contributionQuery->fetch()['amount']==='10.00','Another user cannot modify the contribution.');
    $balanceQuery->execute([$createdWallet['id']]);
    workflow_assert($balanceQuery->fetchColumn()==='123.45','Contribution corrections preserve wallet balances.');
    $editFields['amount']='40.00';
    workflow_assert(workflow_request($client,'savings.php',$editFields)['status']===302,'The corrected contribution can reach the goal target again.');
    $createdGoalQuery->execute([$userId,$goalFields['name']]);
    workflow_assert($createdGoalQuery->fetch()['status']==='completed','Contribution edits complete reached goals again.');
    workflow_assert(workflow_request($client, 'savings.php', [
        'csrf_token' => $csrf, 'action' => 'delete', 'goal_id' => $createdGoal['id'],
    ])['status'] === 302, 'Savings delete calls its procedure.');
    $remainingContributions = $pdo->prepare('SELECT COUNT(*) FROM savings_contributions WHERE savings_goal_id=?');
    $remainingContributions->execute([$createdGoal['id']]);
    workflow_assert((int) $remainingContributions->fetchColumn() === 0, 'Deleting a savings goal removes its contributions.');

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
