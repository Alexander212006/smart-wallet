<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/database_procedures.php';

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
 * @throws InvalidArgumentException
 */
function wallet_expense_limit_period_start_date(
    string $period,
    DateTimeImmutable $startDate,
    int $offset
): DateTimeImmutable {
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
}

function wallet_expense_limit_minimum_end_date(
    string $period,
    DateTimeImmutable $startDate
): DateTimeImmutable {
    return wallet_expense_limit_period_start_date($period, $startDate, 1)->modify('-1 day');
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
    $periodStartAt = static fn (int $offset): DateTimeImmutable =>
        wallet_expense_limit_period_start_date($period, $startDate, $offset);

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
