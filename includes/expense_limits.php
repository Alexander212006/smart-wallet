<?php

declare(strict_types=1);

require_once __DIR__ . '/database_procedures.php';

function wallet_expense_limit_cents(string $amount): int
{
    if (preg_match('/\A(\d{1,16})(?:\.(\d{1,2}))?\z/', $amount, $parts) !== 1
        || (int) $parts[1] > intdiv(PHP_INT_MAX - 99, 100)) {
        throw new UnexpectedValueException('Invalid expense limit total.');
    }

    return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
}

function wallet_expense_limit_format_cents(int $cents): string
{
    $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', (string) intdiv($cents, 100));

    return $whole . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** SQL owns recurrence, ownership, category/currency scope and period spending. */
function wallet_load_expense_limits(PDO $pdo, int $userId, DateTimeImmutable $today): array
{
    $limits = wallet_call_procedure($pdo, 'sp_get_expense_limits', [$userId, $today->format('Y-m-d')]);
    foreach ($limits as &$limit) {
        $limit['range_active'] = (bool) $limit['range_active'];
        $limit['limit_cents'] = wallet_expense_limit_cents((string) $limit['amount']);
        $limit['spent_cents'] = wallet_expense_limit_cents((string) $limit['spent']);
        $limit['spent'] = $limit['spent_cents'] / 100;
        $limit['over_cents'] = max(0, $limit['spent_cents'] - $limit['limit_cents']);
    }
    unset($limit);
    return $limits;
}

function wallet_overspending_alerts(array $limits): array
{
    return array_values(array_filter($limits, static fn (array $limit): bool =>
        $limit['range_active'] && $limit['over_cents'] > 0
    ));
}

/** Notify once per period, then again if the overspend increases or is resolved and recurs. */
function wallet_take_overspending_notifications(array $alerts, int $userId): array
{
    $state = $_SESSION['overspending_notifications'] ?? null;
    $previous = is_array($state) && ($state['user_id'] ?? null) === $userId
        && is_array($state['limits'] ?? null) ? $state['limits'] : [];
    $current = [];
    $notifications = [];
    foreach ($alerts as $alert) {
        $key = $alert['id'] . ':' . $alert['range_start'] . ':' . $alert['range_end'];
        $current[$key] = $alert['over_cents'];
        if (!isset($previous[$key]) || $alert['over_cents'] > $previous[$key]) {
            $notifications[] = $alert;
        }
    }
    $_SESSION['overspending_notifications'] = ['user_id' => $userId, 'limits' => $current];

    return $notifications;
}

function wallet_overspending_message(array $notifications): string
{
    if (count($notifications) !== 1) {
        return 'Overspending alert: ' . count($notifications)
            . ' expense limits are over budget. Review your expense limits for details.';
    }
    $alert = $notifications[0];

    return 'Overspending alert: ' . $alert['name'] . ' is ' . $alert['currency_code'] . ' '
        . wallet_expense_limit_format_cents($alert['over_cents']) . ' over its ' . $alert['period'] . ' limit.';
}
