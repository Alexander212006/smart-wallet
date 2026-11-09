<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/expense_limit_periods.php';

wallet_start_session();

if (!isset($_SESSION['user']['id'])) {
    header('Location: login.php');
    exit;
}

$walletUserId = (int) $_SESSION['user']['id'];
$userStatement = getDatabaseConnection()->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
$userStatement->execute(['id' => $walletUserId]);
if ($userStatement->fetchColumn() === false) {
    unset($_SESSION['user'], $_SESSION['flash_message']);
    session_regenerate_id(true);
    header('Location: login.php');
    exit;
}

$walletUserName = is_string($_SESSION['user']['name'] ?? null)
    ? $_SESSION['user']['name']
    : 'there';

function wallet_set_flash(string $type, string $message): void
{
    $_SESSION['flash_message'] = ['type' => $type, 'message' => $message];
}

function wallet_take_flash(): ?array
{
    $flash = $_SESSION['flash_message'] ?? null;
    unset($_SESSION['flash_message']);

    return is_array($flash) ? $flash : null;
}

function wallet_valid_amount(string $amount): bool
{
    return preg_match('/\A(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?\z/', $amount) === 1
        && (float) $amount > 0;
}

function wallet_valid_currency(string $currency): bool
{
    return preg_match('/\A[A-Z]{3}\z/', $currency) === 1;
}

function wallet_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false
        && $parsed->format('Y-m-d') === $date
        && DateTimeImmutable::getLastErrors() === false;
}

function wallet_valid_signed_amount(string $amount): bool
{
    return preg_match('/\A-?(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?\z/', $amount) === 1;
}

function wallet_apply_transaction_to_current_balance(
    PDO $pdo,
    int $userId,
    int $walletId,
    string $amount,
    bool $reverse = false
): void {
    if (!wallet_valid_signed_amount($amount) || !wallet_valid_amount(ltrim($amount, '-'))) {
        throw new InvalidArgumentException('Invalid wallet transaction balance adjustment.');
    }

    $delta = $reverse
        ? (str_starts_with($amount, '-') ? substr($amount, 1) : '-' . $amount)
        : $amount;
    $statement = $pdo->prepare(
        'UPDATE wallets
         SET current_balance = current_balance + :delta
         WHERE id = :wallet_id AND user_id = :user_id'
    );
    $statement->execute([
        'delta' => $delta,
        'wallet_id' => $walletId,
        'user_id' => $userId,
    ]);

    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Wallet current balance could not be updated.');
    }
}

function wallet_require_valid_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method not allowed.');
    }

    if (!wallet_has_valid_csrf_token()) {
        http_response_code(403);
        exit('Invalid request token.');
    }
}

function wallet_valid_category(string $category): bool
{
    return trim($category) !== '' && mb_strlen($category, 'UTF-8') <= 80;
}

function wallet_category_options(PDO $pdo, int $userId, bool $expenses): array
{
    $comparison = $expenses ? '<' : '>';
    $sql = 'SELECT DISTINCT category FROM transactions
            WHERE user_id = :user_id AND amount ' . $comparison . ' 0
                AND category IS NOT NULL AND TRIM(category) <> \'\'';
    $parameters = ['user_id' => $userId];
    if ($expenses) {
        $sql .= ' UNION SELECT category FROM expense_limits
                  WHERE user_id = :limit_user_id AND category IS NOT NULL AND TRIM(category) <> \'\'';
        $parameters['limit_user_id'] = $userId;
    }
    $statement = $pdo->prepare(
        $sql . ' ORDER BY category'
    );
    $statement->execute($parameters);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function wallet_currency_options(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT currency_code
         FROM wallets
         WHERE user_id = :user_id
         ORDER BY currency_code'
    );
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}
