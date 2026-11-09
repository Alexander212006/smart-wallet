<?php

declare(strict_types=1);

function wallet_spending_month(?string $requestedMonth, DateTimeImmutable $today): DateTimeImmutable
{
    $currentMonth = $today->modify('first day of this month')->setTime(0, 0);
    if ($requestedMonth === null || preg_match('/\A\d{4}-\d{2}\z/', $requestedMonth) !== 1) {
        return $currentMonth;
    }

    $month = DateTimeImmutable::createFromFormat('!Y-m', $requestedMonth, $today->getTimezone());
    if ($month === false || $month->format('Y-m') !== $requestedMonth
        || $month < $currentMonth->modify('-11 months') || $month > $currentMonth) {
        return $currentMonth;
    }

    return $month;
}

function wallet_load_spending_analysis(
    PDO $pdo,
    int $userId,
    string $currency,
    DateTimeImmutable $month,
    DateTimeImmutable $today
): array {
    $month = wallet_spending_month($month->format('Y-m'), $today);
    $periodEnd = min($month->modify('+1 month'), $today->setTime(0, 0)->modify('+1 day'));
    $statement = $pdo->prepare(
        'SELECT t.transaction_date, t.amount, c.id AS category_id,
                COALESCE(c.name, \'Uncategorized\') AS category_name, t.expense_nature
         FROM transactions t
         INNER JOIN wallets w ON w.id = t.wallet_id AND w.user_id = t.user_id
         LEFT JOIN categories c ON c.id = t.category AND c.category_type = \'expense\'
            AND (c.user_id = t.user_id OR c.user_id IS NULL)
         WHERE t.user_id = :user_id AND w.currency_code = :currency
            AND w.is_archived = 0 AND t.transaction_type = \'expense\'
            AND t.transaction_date >= :period_start AND t.transaction_date < :period_end
         ORDER BY t.transaction_date, t.id'
    );
    $statement->execute([
        'user_id' => $userId,
        'currency' => $currency,
        'period_start' => $month->modify('-5 months')->format('Y-m-d H:i:s'),
        'period_end' => $periodEnd->format('Y-m-d H:i:s'),
    ]);

    return wallet_build_spending_analysis($statement->fetchAll(PDO::FETCH_ASSOC), $month, $today);
}

function wallet_spending_amount_cents(string $amount): ?int
{
    if (preg_match('/\A(0|[1-9]\d{0,11})(?:\.(\d{1,2}))?\z/', $amount, $parts) !== 1) {
        return null;
    }

    $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

    return $cents > 0 ? $cents : null;
}

/**
 * Build all summaries from one set of expenses. Dates represent local calendar days;
 * the current day is included, and later calendar dates are excluded.
 */
function wallet_build_spending_analysis(
    array $rows,
    DateTimeImmutable $month,
    DateTimeImmutable $today
): array {
    $month = wallet_spending_month($month->format('Y-m'), $today);
    $today = $today->setTime(0, 0);
    $isCurrentMonth = $month->format('Y-m') === $today->format('Y-m');
    $periodEnd = min($month->modify('+1 month'), $today->modify('+1 day'));
    $daysElapsed = (int) $month->diff($periodEnd)->days;
    $previousMonth = $month->modify('-1 month');
    $comparisonDays = $isCurrentMonth
        ? min($daysElapsed, (int) $previousMonth->format('t'))
        : (int) $previousMonth->format('t');
    $comparisonEnd = $previousMonth->modify('+' . $comparisonDays . ' days');
    $trendStart = $month->modify('-5 months');

    $months = [];
    for ($offset = 0; $offset < 6; $offset++) {
        $trendMonth = $trendStart->modify('+' . $offset . ' months');
        $key = $trendMonth->format('Y-m');
        $months[$key] = [
            'month' => $key,
            'label' => $trendMonth->format('M Y'),
            'total_cents' => 0,
            'is_partial' => $isCurrentMonth && $key === $month->format('Y-m'),
        ];
    }

    $weeks = [];
    $weekStart = $month;
    while ($weekStart < $periodEnd) {
        $daysToMonday = 8 - (int) $weekStart->format('N');
        $weekEnd = min($weekStart->modify('+' . $daysToMonday . ' days'), $periodEnd);
        $key = $weekStart->format('Y-m-d');
        $weeks[$key] = [
            'start' => $key,
            'end' => $weekEnd->modify('-1 day')->format('Y-m-d'),
            'total_cents' => 0,
            'count' => 0,
        ];
        $weekStart = $weekEnd;
    }

    $nature = array_fill_keys(['essential', 'non_essential', 'unclassified'], [
        'total_cents' => 0,
        'count' => 0,
        'share_percent' => 0.0,
    ]);
    $totalCents = 0;
    $transactionCount = 0;
    $previousTotalCents = 0;
    $categories = [];
    $days = [];

    foreach ($rows as $row) {
        $dateValue = substr((string) ($row['transaction_date'] ?? ''), 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, $month->getTimezone());
        $amountCents = wallet_spending_amount_cents((string) ($row['amount'] ?? ''));
        if ($date === false || $date->format('Y-m-d') !== $dateValue || $amountCents === null
            || $date < $trendStart || $date >= $periodEnd) {
            continue;
        }

        $months[$date->format('Y-m')]['total_cents'] += $amountCents;
        if ($date >= $previousMonth && $date < $comparisonEnd) {
            $previousTotalCents += $amountCents;
        }
        if ($date < $month) {
            continue;
        }

        $totalCents += $amountCents;
        $transactionCount++;
        $categoryId = isset($row['category_id']) ? (int) $row['category_id'] : null;
        $categoryName = trim((string) ($row['category_name'] ?? ''));
        $categoryKey = $categoryId === null ? 'uncategorized' : (string) $categoryId;
        if (!isset($categories[$categoryKey])) {
            $categories[$categoryKey] = [
                'id' => $categoryId,
                'name' => $categoryName === '' ? 'Uncategorized' : $categoryName,
                'count' => 0,
                'total_cents' => 0,
                'share_percent' => 0.0,
            ];
        }
        $categories[$categoryKey]['count']++;
        $categories[$categoryKey]['total_cents'] += $amountCents;

        $natureKey = in_array($row['expense_nature'] ?? null, ['essential', 'non_essential'], true)
            ? $row['expense_nature'] : 'unclassified';
        $nature[$natureKey]['total_cents'] += $amountCents;
        $nature[$natureKey]['count']++;

        if (!isset($days[$dateValue])) {
            $days[$dateValue] = ['date' => $dateValue, 'total_cents' => 0, 'count' => 0];
        }
        $days[$dateValue]['total_cents'] += $amountCents;
        $days[$dateValue]['count']++;

        $monday = $date->modify('-' . ((int) $date->format('N') - 1) . ' days');
        $weekKey = max($monday, $month)->format('Y-m-d');
        $weeks[$weekKey]['total_cents'] += $amountCents;
        $weeks[$weekKey]['count']++;
    }

    foreach ($categories as &$category) {
        $category['share_percent'] = $totalCents === 0 ? 0.0 : $category['total_cents'] / $totalCents * 100.0;
    }
    unset($category);
    foreach ($nature as &$natureSummary) {
        $natureSummary['share_percent'] = $totalCents === 0 ? 0.0 : $natureSummary['total_cents'] / $totalCents * 100.0;
    }
    unset($natureSummary);
    $categories = array_values($categories);
    usort($categories, static fn (array $left, array $right): int =>
        $right['count'] <=> $left['count']
        ?: $right['total_cents'] <=> $left['total_cents']
        ?: strcasecmp($left['name'], $right['name'])
        ?: ($left['id'] ?? 0) <=> ($right['id'] ?? 0)
    );

    $peakDay = null;
    ksort($days);
    foreach ($days as $day) {
        if ($peakDay === null || $day['total_cents'] > $peakDay['total_cents']) {
            $peakDay = $day;
        }
    }
    $peakWeek = null;
    foreach ($weeks as $week) {
        if ($week['count'] > 0 && ($peakWeek === null || $week['total_cents'] > $peakWeek['total_cents'])) {
            $peakWeek = $week;
        }
    }

    return [
        'month' => $month,
        'period_end' => $periodEnd,
        'is_current_month' => $isCurrentMonth,
        'days_elapsed' => $daysElapsed,
        'total_cents' => $totalCents,
        'transaction_count' => $transactionCount,
        'previous_total_cents' => $previousTotalCents,
        'change_percent' => $previousTotalCents === 0
            ? null : ($totalCents - $previousTotalCents) / $previousTotalCents * 100.0,
        'comparison_label' => $isCurrentMonth ? 'same days last month' : 'last month',
        'categories' => $categories,
        'peak_day' => $peakDay,
        'weeks' => array_values($weeks),
        'peak_week' => $peakWeek,
        'nature' => $nature,
        'months' => array_values($months),
    ];
}
