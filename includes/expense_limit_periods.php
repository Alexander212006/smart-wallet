<?php

declare(strict_types=1);

/**
 * Move an expense-limit anchor by a number of complete budget periods.
 * Monthly and yearly anchors keep the original day where possible and clamp
 * it to the last valid day when a month is shorter.
 *
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
 * Return the active half-open budget cycle containing $today.
 *
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
