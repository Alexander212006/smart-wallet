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
    $categoryName = 'Workflow food ' . $suffix;
    $pdo->prepare('INSERT INTO categories (user_id, name, category_type) VALUES (?, ?, \'expense\')')
        ->execute([$userId, $categoryName]);
    $categoryId = (int) $pdo->lastInsertId();
    $foreignCategoryName = 'Private workflow category ' . $suffix;
    $pdo->prepare('INSERT INTO categories (user_id, name, category_type) VALUES (?, ?, \'expense\')')
        ->execute([$foreignUserId, $foreignCategoryName]);
    $foreignCategoryId = (int) $pdo->lastInsertId();

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
        'expense_category_id' => $categoryId, 'expense_amount' => '20.00',
        'expense_description' => 'Workflow expense ' . $suffix, 'expense_date' => $today->format('Y-m-d'),
        'expense_nature' => 'essential',
    ];
    $transactionQuery = $pdo->prepare('SELECT id, amount, expense_nature FROM transactions WHERE user_id = ? ORDER BY id');
    $balanceQuery = $pdo->prepare('SELECT current_balance FROM wallets WHERE id = ?');
    $assertState = static function (string $balance, int $count, ?string $nature) use (
        $pdo, $transactionQuery, $balanceQuery, $userId, $wallets
    ): array {
        $transactionQuery->execute([$userId]);
        $entries = $transactionQuery->fetchAll();
        workflow_assert(count($entries) === $count, 'Expense mutation leaves expected transaction count.');
        if ($count === 1) {
            workflow_assert($entries[0]['expense_nature'] === $nature, 'Expense classification persists correctly.');
        }
        $balanceQuery->execute([$wallets['php']]);
        workflow_assert($balanceQuery->fetchColumn() === $balance, 'Wallet balance reflects the expense exactly once.');

        return $entries;
    };
    workflow_assert(workflow_request($client, 'expenses.php', $fields)['status'] === 302, 'Expense create redirects.');
    $entries = $assertState('980.00', 1, 'essential');
    $transactionId = (int) $entries[0]['id'];
    $edit = workflow_request($client, 'expenses.php?edit_expense=' . $transactionId);
    workflow_assert($edit['status'] === 200, 'Edit expense form renders.');
    workflow_assert(workflow_text($edit['body'], '//select[@name="expense_nature"]/option[@selected]') === 'Essential',
        'Edit form selects the stored expense type.');
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

    $expenseInsert = $pdo->prepare(
        'INSERT INTO transactions (user_id, wallet_id, category, transaction_type, amount, description, transaction_date, expense_nature)
         VALUES (?, ?, ?, ?, ?, \'Workflow scoped fixture\', ?, ?)'
    );
    $currentDate = $today->format('Y-m-d') . ' 12:00:00';
    foreach ([
        [$userId, $wallets['php'], $categoryId, 'expense', '5.00', $currentDate, 'essential'],
        [$userId, $wallets['php'], $categoryId, 'expense', '10.00', $currentDate, 'non_essential'],
        [$userId, $wallets['usd'], $categoryId, 'expense', '120.00', $currentDate, 'essential'],
        [$userId, $wallets['archived'], $categoryId, 'expense', '700.00', $currentDate, null],
        [$foreignUserId, $wallets['foreign'], $foreignCategoryId, 'expense', '800.00', $currentDate, null],
        [$userId, $wallets['php'], $categoryId, 'income', '444.00', $currentDate, null],
        [$userId, $wallets['php'], $categoryId, 'expense', '12.00', $previousMonth->format('Y-m-d') . ' 12:00:00', null],
        [$userId, $wallets['php'], $categoryId, 'expense', '900.00', $today->modify('+1 day')->format('Y-m-d') . ' 12:00:00', null],
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
