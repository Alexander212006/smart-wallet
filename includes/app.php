<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

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

/**
 * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
 */
function wallet_expense_limit_date_range(
    string $period,
    DateTimeImmutable $startDate,
    DateTimeImmutable $today,
    bool $isRecurring,
    ?DateTimeImmutable $endDate = null
): ?array {
    $periodStartAt = static function (int $offset) use ($period, $startDate): DateTimeImmutable {
        if ($period === 'weekly') {
            return $startDate->modify('+' . ($offset * 7) . ' days');
        }

        if ($period === 'monthly') {
            $monthStart = $startDate->modify('first day of this month')->modify('+' . $offset . ' months');
            $day = min((int) $startDate->format('j'), (int) $monthStart->format('t'));

            return $monthStart->setDate(
                (int) $monthStart->format('Y'),
                (int) $monthStart->format('n'),
                $day
            );
        }

        if ($period === 'yearly') {
            $yearStart = $startDate->setDate(
                (int) $startDate->format('Y') + $offset,
                (int) $startDate->format('n'),
                1
            );
            $day = min((int) $startDate->format('j'), (int) $yearStart->format('t'));

            return $yearStart->setDate(
                (int) $yearStart->format('Y'),
                (int) $yearStart->format('n'),
                $day
            );
        }

        throw new InvalidArgumentException('Unsupported expense limit period.');
    };

    if ($today < $startDate || ($endDate !== null && $today > $endDate)) {
        return null;
    }

    $firstPeriodEnd = $periodStartAt(1);
    if (!$isRecurring) {
        $rangeEnd = $firstPeriodEnd;
    } else {
        if ($period === 'weekly') {
            $periodOffset = intdiv((int) $startDate->diff($today)->days, 7);
        } elseif ($period === 'monthly') {
            $periodOffset = ((int) $today->format('Y') - (int) $startDate->format('Y')) * 12
                + (int) $today->format('n') - (int) $startDate->format('n');
        } else {
            $periodOffset = (int) $today->format('Y') - (int) $startDate->format('Y');
        }

        if ($periodStartAt($periodOffset) > $today) {
            $periodOffset--;
        }

        $rangeStart = $periodStartAt($periodOffset);
        $rangeEnd = $periodStartAt($periodOffset + 1);
    }

    if (!$isRecurring) {
        $rangeStart = $startDate;
    }

    if ($endDate !== null && $rangeEnd > $endDate->modify('+1 day')) {
        $rangeEnd = $endDate->modify('+1 day');
    }

    return $today < $rangeEnd ? ['start' => $rangeStart, 'end' => $rangeEnd] : null;
}

function wallet_valid_signed_amount(string $amount): bool
{
    return preg_match('/\A-?(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?\z/', $amount) === 1;
}

function wallet_apply_transaction_to_current_balance(
    PDO $pdo,
    int $userId,
    int $walletId,
    string $type,
    string $amount,
    bool $reverse = false
): void {
    if (!in_array($type, ['income', 'expense'], true) || !wallet_valid_amount($amount)) {
        throw new InvalidArgumentException('Invalid wallet transaction balance adjustment.');
    }

    $subtract = ($type === 'expense') !== $reverse;
    $delta = ($subtract ? '-' : '') . $amount;
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
         FROM wallets
         WHERE user_id = :user_id
         ORDER BY currency_code'
    );
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll(PDO::FETCH_COLUMN);
}
