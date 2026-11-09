<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

wallet_start_session();

if (!isset($_SESSION['user']['id'])) {
    header('Location: login.php');
    exit;
}

$walletUserId = (int) $_SESSION['user']['id'];
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

function wallet_owned_expense_category(PDO $pdo, int $userId, int $categoryId): bool
{
    $statement = $pdo->prepare(
        'SELECT id FROM categories
         WHERE id = :category_id
            AND category_type = \'expense\'
            AND (user_id = :user_id OR user_id IS NULL)
         LIMIT 1'
    );
    $statement->execute(['category_id' => $categoryId, 'user_id' => $userId]);

    return $statement->fetchColumn() !== false;
}

function wallet_get_or_create_category(PDO $pdo, int $userId, string $type, string $name): int
{
    $statement = $pdo->prepare(
        'SELECT id FROM categories
         WHERE user_id = :user_id AND category_type = :category_type
            AND LOWER(name) = LOWER(:name)
         LIMIT 1'
    );
    $statement->execute([
        'user_id' => $userId,
        'category_type' => $type,
        'name' => $name,
    ]);
    $categoryId = $statement->fetchColumn();
    if ($categoryId !== false) {
        return (int) $categoryId;
    }

    $statement = $pdo->prepare(
        'INSERT INTO categories (user_id, name, category_type)
         VALUES (:user_id, :name, :category_type)'
    );
    $statement->execute([
        'user_id' => $userId,
        'name' => $name,
        'category_type' => $type,
    ]);

    return (int) $pdo->lastInsertId();
}

function wallet_currency_options(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT currency_code
         FROM accounts
         WHERE user_id = :user_id
         ORDER BY currency_code'
    );
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}
