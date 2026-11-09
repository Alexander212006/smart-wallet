<?php

declare(strict_types=1);

require_once __DIR__ . '/expense_limit_periods.php';

/**
 * Parse a strict local calendar date in the supplied timezone.
 */
function wallet_report_date(string $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

    return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
}

function wallet_report_date_range_label(DateTimeImmutable $start, DateTimeImmutable $endExclusive): string
{
    $end = $endExclusive->modify('-1 day');
    if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
        return $start->format('M j, Y');
    }

    if ($start->format('Y-m') === $end->format('Y-m')) {
        return $start->format('M j') . '–' . $end->format('j, Y');
    }

    if ($start->format('Y') === $end->format('Y')) {
        return $start->format('M j') . '–' . $end->format('M j, Y');
    }

    return $start->format('M j, Y') . '–' . $end->format('M j, Y');
}

function wallet_report_default_selection(string $type, DateTimeImmutable $today): string
{
    return match ($type) {
        'weekly' => $today->format('o-\WW'),
        'yearly' => $today->format('Y'),
        default => $today->format('Y-m'),
    };
}

/**
 * Resolve a validated weekly, monthly, or yearly reporting window.
 * All returned ranges are half-open local-calendar ranges.
 *
 * @return array<string, mixed>
 * @throws InvalidArgumentException
 */
function wallet_financial_report_period(
    string $type,
    string $selection,
    DateTimeImmutable $today
): array {
    if (!in_array($type, ['weekly', 'monthly', 'yearly'], true)) {
        throw new InvalidArgumentException('Choose a weekly, monthly, or yearly report.');
    }

    $today = $today->setTime(0, 0);
    $timezone = $today->getTimezone();
    $typeLabel = ucfirst($type);

    if ($type === 'weekly') {
        if (preg_match('/\A(\d{4})-W(\d{2})\z/', $selection, $parts) !== 1
            || (int) $parts[1] < 1001) {
            throw new InvalidArgumentException('Choose a valid ISO week.');
        }
        $start = (new DateTimeImmutable($parts[1] . '-01-04', $timezone))
            ->setISODate((int) $parts[1], (int) $parts[2], 1)
            ->setTime(0, 0);
        if ($start->format('o-\WW') !== $selection) {
            throw new InvalidArgumentException('Choose a valid ISO week.');
        }
        $end = $start->modify('+7 days');
        $previousStart = $start->modify('-7 days');
        $label = wallet_report_date_range_label($start, $end);
        $previousLabel = wallet_report_date_range_label($previousStart, $start);
    } elseif ($type === 'monthly') {
        if (preg_match('/\A\d{4}-\d{2}\z/', $selection) !== 1
            || (int) substr($selection, 0, 4) < 1001) {
            throw new InvalidArgumentException('Choose a valid month.');
        }
        $start = wallet_report_date($selection . '-01', $timezone);
        if ($start === null || $start->format('Y-m') !== $selection) {
            throw new InvalidArgumentException('Choose a valid month.');
        }
        $end = $start->modify('+1 month');
        $previousStart = $start->modify('-1 month');
        $label = $start->format('F Y');
        $previousLabel = $previousStart->format('F Y');
    } else {
        if (preg_match('/\A\d{4}\z/', $selection) !== 1 || (int) $selection < 1001) {
            throw new InvalidArgumentException('Choose a valid year.');
        }
        $start = wallet_report_date($selection . '-01-01', $timezone);
        if ($start === null || $start->format('Y') !== $selection) {
            throw new InvalidArgumentException('Choose a valid year.');
        }
        $end = $start->modify('+1 year');
        $previousStart = $start->modify('-1 year');
        $label = $selection;
        $previousLabel = $previousStart->format('Y');
    }

    if ($start > $today) {
        throw new InvalidArgumentException('Reports cannot begin in the future.');
    }

    $tomorrow = $today->modify('+1 day');
    $effectiveEnd = $end < $tomorrow ? $end : $tomorrow;
    $isPartial = $effectiveEnd < $end;
    $daysElapsed = (int) $start->diff($effectiveEnd)->days;
    $previousEnd = $start;
    if ($isPartial) {
        $previousCandidateEnd = $previousStart->modify('+' . $daysElapsed . ' days');
        $previousEffectiveEnd = $previousCandidateEnd < $previousEnd ? $previousCandidateEnd : $previousEnd;
    } else {
        $previousEffectiveEnd = $previousEnd;
    }

    $effectiveLabel = $label;
    if ($isPartial) {
        $effectiveLabel .= ' (through ' . $effectiveEnd->modify('-1 day')->format('M j, Y') . ')';
    }

    $periodNoun = ['weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$type];
    $comparisonNote = $isPartial
        ? 'Partial result compared with the first ' . $daysElapsed . ' '
            . ($daysElapsed === 1 ? 'day' : 'days') . ' of the previous ' . $periodNoun . '.'
        : 'Compared with the complete immediately preceding ' . $periodNoun . '.';

    return [
        'type' => $type,
        'type_label' => $typeLabel,
        'selection' => $selection,
        'label' => $label,
        'effective_label' => $effectiveLabel,
        'previous_label' => $previousLabel,
        'start' => $start,
        'end' => $end,
        'effective_end' => $effectiveEnd,
        'previous_start' => $previousStart,
        'previous_end' => $previousEnd,
        'previous_effective_end' => $previousEffectiveEnd,
        'is_partial' => $isPartial,
        'days_elapsed' => $daysElapsed,
        'comparison_note' => $comparisonNote,
    ];
}

/**
 * Validate report query parameters and fall back to a current valid period if
 * a supplied value is invalid. Errors are intended for safe display.
 *
 * @param array<string, mixed> $query
 * @param list<string> $currencies
 * @return array{period: array<string, mixed>, currency: ?string, errors: list<string>}
 */
function wallet_resolve_financial_report_filters(
    array $query,
    array $currencies,
    DateTimeImmutable $today
): array {
    $errors = [];
    $requestedType = $query['period'] ?? null;
    if ($requestedType === null) {
        $type = 'monthly';
    } elseif (is_string($requestedType) && in_array($requestedType, ['weekly', 'monthly', 'yearly'], true)) {
        $type = $requestedType;
    } else {
        $type = 'monthly';
        $errors[] = 'The requested report period was invalid, so the current month is shown.';
    }

    $selectionKey = ['weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$type];
    $defaultSelection = wallet_report_default_selection($type, $today);
    $requestedSelection = $query[$selectionKey] ?? null;
    $selection = $requestedSelection === null
        ? $defaultSelection
        : (is_string($requestedSelection) ? $requestedSelection : '');

    try {
        $period = wallet_financial_report_period($type, $selection, $today);
    } catch (InvalidArgumentException $exception) {
        $period = wallet_financial_report_period($type, $defaultSelection, $today);
        $periodNoun = ['weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$type];
        $errors[] = $exception->getMessage() . ' The current ' . $periodNoun . ' is shown instead.';
    }

    $validCurrencies = array_values(array_filter(
        $currencies,
        static fn (mixed $currency): bool => is_string($currency)
            && preg_match('/\A[A-Z]{3}\z/', $currency) === 1
    ));
    $requestedCurrency = $query['currency'] ?? null;
    if ($requestedCurrency === null) {
        $currency = $validCurrencies[0] ?? null;
    } elseif (is_string($requestedCurrency) && in_array($requestedCurrency, $validCurrencies, true)) {
        $currency = $requestedCurrency;
    } else {
        $currency = $validCurrencies[0] ?? null;
        $errors[] = 'The requested currency is not available in your active wallets.';
    }

    return ['period' => $period, 'currency' => $currency, 'errors' => $errors];
}

/**
 * Convert a DECIMAL(14,2)-style value to signed integer cents without using
 * binary floating-point arithmetic.
 */
function wallet_report_decimal_to_cents(mixed $value): ?int
{
    if (is_int($value)) {
        $value = (string) $value;
    } elseif (is_float($value)) {
        if (!is_finite($value)) {
            return null;
        }
        $value = number_format($value, 2, '.', '');
    }
    if (!is_string($value) || preg_match('/\A([+-]?)(\d+)(?:\.(\d{1,2}))?\z/', $value, $parts) !== 1) {
        return null;
    }

    $significantWhole = ltrim($parts[2], '0');
    if (strlen($significantWhole) > 12) {
        return null;
    }
    $cents = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

    return ($parts[1] ?? '') === '-' ? -$cents : $cents;
}

/**
 * Format integer cents without converting the value to a floating-point number.
 */
function wallet_report_format_cents(int $cents, bool $groupThousands = true): string
{
    $digits = (string) $cents;
    $negative = str_starts_with($digits, '-');
    if ($negative) {
        $digits = substr($digits, 1);
    }
    $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);
    $whole = substr($digits, 0, -2);
    $fraction = substr($digits, -2);
    if ($groupThousands) {
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
        $whole = is_string($grouped) ? $grouped : $whole;
    }

    return ($negative ? '-' : '') . $whole . '.' . $fraction;
}

function wallet_report_savings_rate(int $incomeCents, int $netCents): ?float
{
    return $incomeCents === 0 ? null : $netCents / $incomeCents * 100.0;
}

/**
 * @return array{income_cents: int, expense_cents: int, net_cents: int, savings_rate: ?float, transaction_count: int}
 */
function wallet_report_empty_summary(): array
{
    return [
        'income_cents' => 0,
        'expense_cents' => 0,
        'net_cents' => 0,
        'savings_rate' => null,
        'transaction_count' => 0,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function wallet_report_comparison(array $current, array $previous): array
{
    $definitions = [
        'income' => ['label' => 'Total income', 'summary_key' => 'income_cents', 'is_rate' => false],
        'expenses' => ['label' => 'Total expenses', 'summary_key' => 'expense_cents', 'is_rate' => false],
        'net' => ['label' => 'Net cash flow', 'summary_key' => 'net_cents', 'is_rate' => false],
        'savings_rate' => ['label' => 'Savings rate', 'summary_key' => 'savings_rate', 'is_rate' => true],
    ];
    $comparison = [];

    foreach ($definitions as $key => $definition) {
        $currentValue = $current[$definition['summary_key']];
        $previousValue = $previous[$definition['summary_key']];
        $difference = $currentValue === null || $previousValue === null
            ? null : $currentValue - $previousValue;
        $percentChange = $difference === null || (float) $previousValue === 0.0
            ? null : $difference / abs((float) $previousValue) * 100.0;
        $comparison[$key] = [
            'label' => $definition['label'],
            'current' => $currentValue,
            'previous' => $previousValue,
            'difference' => $difference,
            'absolute_difference' => $difference === null ? null : abs($difference),
            'percent_change' => $percentChange,
            'is_rate' => $definition['is_rate'],
        ];
    }

    return $comparison;
}

function wallet_report_budget_start_offset(
    string $period,
    DateTimeImmutable $budgetStart,
    DateTimeImmutable $target
): int {
    if ($target <= $budgetStart) {
        return 0;
    }

    if ($period === 'weekly') {
        $offset = intdiv((int) $budgetStart->diff($target)->days, 7);
    } elseif ($period === 'monthly') {
        $offset = ((int) $target->format('Y') - (int) $budgetStart->format('Y')) * 12
            + (int) $target->format('n') - (int) $budgetStart->format('n');
    } else {
        $offset = (int) $target->format('Y') - (int) $budgetStart->format('Y');
    }
    $offset = max(0, $offset);

    while ($offset > 0 && wallet_expense_limit_period_start_date($period, $budgetStart, $offset) > $target) {
        $offset--;
    }
    while (wallet_expense_limit_period_start_date($period, $budgetStart, $offset + 1) <= $target) {
        $offset++;
    }

    return $offset;
}

/**
 * Enumerate budget-cycle intersections with the effective report range.
 * Allocation is prorated by covered calendar days for every partial cycle.
 *
 * @return list<array<string, mixed>>
 */
function wallet_report_budget_segments(
    array $budget,
    DateTimeImmutable $reportStart,
    DateTimeImmutable $reportEnd
): array {
    $period = (string) ($budget['period'] ?? '');
    if (!in_array($period, ['weekly', 'monthly', 'yearly'], true)) {
        return [];
    }
    $timezone = $reportStart->getTimezone();
    $budgetStart = wallet_report_date((string) ($budget['start_date'] ?? ''), $timezone);
    if ($budgetStart === null || $budgetStart >= $reportEnd) {
        return [];
    }
    $recordEnd = ($budget['end_date'] ?? null) === null || $budget['end_date'] === ''
        ? null
        : wallet_report_date((string) $budget['end_date'], $timezone);
    if (($budget['end_date'] ?? null) !== null && $budget['end_date'] !== '' && $recordEnd === null) {
        return [];
    }
    $recordEndExclusive = $recordEnd?->modify('+1 day');
    if ($recordEndExclusive !== null && $recordEndExclusive <= $reportStart) {
        return [];
    }

    $isRecurring = (bool) ($budget['is_recurring'] ?? false);
    $offset = $isRecurring
        ? wallet_report_budget_start_offset($period, $budgetStart, $reportStart)
        : 0;
    $segments = [];

    for ($guard = 0; $guard < 400; $guard++, $offset++) {
        if (!$isRecurring && $offset > 0) {
            break;
        }
        $cycleStart = wallet_expense_limit_period_start_date($period, $budgetStart, $offset);
        $cycleEnd = wallet_expense_limit_period_start_date($period, $budgetStart, $offset + 1);
        if ($cycleStart >= $reportEnd || ($recordEndExclusive !== null && $cycleStart >= $recordEndExclusive)) {
            break;
        }
        $activeCycleEnd = $recordEndExclusive !== null && $recordEndExclusive < $cycleEnd
            ? $recordEndExclusive : $cycleEnd;
        $overlapStart = $cycleStart > $reportStart ? $cycleStart : $reportStart;
        $overlapEnd = $activeCycleEnd < $reportEnd ? $activeCycleEnd : $reportEnd;
        if ($overlapStart < $overlapEnd) {
            $cycleDays = (int) $cycleStart->diff($cycleEnd)->days;
            $overlapDays = (int) $overlapStart->diff($overlapEnd)->days;
            $segments[] = [
                'cycle_start' => $cycleStart,
                'cycle_end' => $cycleEnd,
                'overlap_start' => $overlapStart,
                'overlap_end' => $overlapEnd,
                'cycle_days' => $cycleDays,
                'overlap_days' => $overlapDays,
                'is_full_cycle' => $overlapStart == $cycleStart && $overlapEnd == $cycleEnd,
            ];
        }
    }

    return $segments;
}

/**
 * @param list<array{date: DateTimeImmutable, amount_cents: int, category_key: ?string}> $expenses
 * @return list<array<string, mixed>>
 */
function wallet_report_budget_performance(
    array $budgetRows,
    array $expenses,
    DateTimeImmutable $reportStart,
    DateTimeImmutable $reportEnd
): array {
    $performance = [];

    foreach ($budgetRows as $budget) {
        $amountCents = wallet_report_decimal_to_cents($budget['amount'] ?? null);
        if ($amountCents === null || $amountCents <= 0) {
            continue;
        }
        $segments = wallet_report_budget_segments($budget, $reportStart, $reportEnd);
        if ($segments === []) {
            continue;
        }

        $allocationCents = 0;
        $coveredDays = 0;
        $cycleDays = 0;
        $allFullCycles = true;
        foreach ($segments as $segment) {
            $allocationCents += intdiv(
                $amountCents * $segment['overlap_days'] + intdiv($segment['cycle_days'], 2),
                $segment['cycle_days']
            );
            $coveredDays += $segment['overlap_days'];
            $cycleDays += $segment['cycle_days'];
            $allFullCycles = $allFullCycles && $segment['is_full_cycle'];
        }
        if ($allocationCents <= 0) {
            continue;
        }

        $category = trim((string) ($budget['category'] ?? ''));
        $categoryKey = mb_strtolower($category, 'UTF-8');
        $spentCents = 0;
        foreach ($expenses as $expense) {
            $expenseCategoryKey = $expense['category_key'] ?? null;
            if ($expenseCategoryKey === null && isset($expense['category'])) {
                $expenseCategory = trim((string) $expense['category']);
                $expenseCategoryKey = $expenseCategory === ''
                    ? null : mb_strtolower($expenseCategory, 'UTF-8');
            }
            if ($category !== '' && $expenseCategoryKey !== $categoryKey) {
                continue;
            }
            foreach ($segments as $segment) {
                if ($expense['date'] >= $segment['overlap_start'] && $expense['date'] < $segment['overlap_end']) {
                    $spentCents += $expense['amount_cents'];
                    break;
                }
            }
        }

        $remainingCents = $allocationCents - $spentCents;
        $percentUsed = $spentCents / $allocationCents * 100.0;
        $status = $percentUsed > 100.0
            ? 'Over Budget'
            : ($percentUsed >= 80.0 ? 'Near Limit' : 'Within Budget');
        $cycleCount = count($segments);
        $periodLabel = rtrim((string) $budget['period'], 'ly');
        $allocationNote = $allFullCycles
            ? $cycleCount . ' full ' . $periodLabel . ($cycleCount === 1 ? ' cycle' : ' cycles')
            : 'Daily prorated: ' . $coveredDays . ' of ' . $cycleDays
                . ' applicable cycle days across ' . $cycleCount . ' '
                . ($cycleCount === 1 ? 'cycle' : 'cycles');

        $performance[] = [
            'id' => isset($budget['id']) ? (int) $budget['id'] : null,
            'name' => trim((string) ($budget['name'] ?? '')) ?: 'Unnamed budget',
            'scope' => $category === '' ? 'All categories' : $category,
            'period' => (string) $budget['period'],
            'allocation_cents' => $allocationCents,
            'spent_cents' => $spentCents,
            'remaining_cents' => $remainingCents,
            'percent_used' => $percentUsed,
            'status' => $status,
            'coverage_label' => wallet_report_date_range_label(
                $segments[0]['overlap_start'],
                $segments[array_key_last($segments)]['overlap_end']
            ),
            'allocation_note' => $allocationNote,
            'is_prorated' => !$allFullCycles,
            'cycle_count' => $cycleCount,
        ];
    }

    usort($performance, static fn (array $left, array $right): int =>
        strcasecmp($left['name'], $right['name'])
        ?: strcasecmp($left['scope'], $right['scope'])
    );

    return $performance;
}

/**
 * Build the complete report from transaction and budget records.
 *
 * @return array<string, mixed>
 */
function wallet_build_financial_report(
    array $transactionRows,
    array $budgetRows,
    array $period,
    string $currency,
    DateTimeImmutable $generatedAt
): array {
    $current = wallet_report_empty_summary();
    $previous = wallet_report_empty_summary();
    $categories = [];
    $currentExpenses = [];
    $timezone = $period['start']->getTimezone();

    foreach ($transactionRows as $row) {
        $dateValue = substr((string) ($row['transaction_date'] ?? ''), 0, 10);
        $date = wallet_report_date($dateValue, $timezone);
        $amountCents = wallet_report_decimal_to_cents($row['amount'] ?? null);
        if ($date === null || $amountCents === null || $amountCents === 0) {
            continue;
        }

        $target = null;
        $isCurrentRow = false;
        if ($date >= $period['start'] && $date < $period['effective_end']) {
            $target = &$current;
            $isCurrentRow = true;
        } elseif ($date >= $period['previous_start'] && $date < $period['previous_effective_end']) {
            $target = &$previous;
        }
        if ($target === null) {
            continue;
        }

        if ($amountCents > 0) {
            $target['income_cents'] += $amountCents;
        } else {
            $expenseCents = -$amountCents;
            $target['expense_cents'] += $expenseCents;
            if ($isCurrentRow) {
                $rawCategory = $row['category'] ?? null;
                $storedCategory = $rawCategory === null ? '' : trim((string) $rawCategory);
                $displayCategory = $storedCategory === '' ? 'Uncategorized' : $storedCategory;
                $displayCategoryKey = mb_strtolower($displayCategory, 'UTF-8');
                if (!isset($categories[$displayCategoryKey])) {
                    $categories[$displayCategoryKey] = [
                        'name' => $displayCategory,
                        'total_cents' => 0,
                        'count' => 0,
                        'share_percent' => 0.0,
                    ];
                }
                $categories[$displayCategoryKey]['total_cents'] += $expenseCents;
                $categories[$displayCategoryKey]['count']++;
                $currentExpenses[] = [
                    'date' => $date,
                    'amount_cents' => $expenseCents,
                    'category_key' => $storedCategory === ''
                        ? null : mb_strtolower($storedCategory, 'UTF-8'),
                ];
            }
        }
        $target['transaction_count']++;
        unset($target);
    }

    foreach ([$current, $previous] as $index => $summary) {
        $summary['net_cents'] = $summary['income_cents'] - $summary['expense_cents'];
        $summary['savings_rate'] = wallet_report_savings_rate($summary['income_cents'], $summary['net_cents']);
        if ($index === 0) {
            $current = $summary;
        } else {
            $previous = $summary;
        }
    }

    foreach ($categories as &$category) {
        $category['share_percent'] = $current['expense_cents'] === 0
            ? 0.0 : $category['total_cents'] / $current['expense_cents'] * 100.0;
    }
    unset($category);
    $categoryList = array_values($categories);
    usort($categoryList, static fn (array $left, array $right): int =>
        $right['total_cents'] <=> $left['total_cents']
        ?: $right['count'] <=> $left['count']
        ?: strcasecmp($left['name'], $right['name'])
    );

    return [
        'period' => $period,
        'currency' => $currency,
        'generated_at' => $generatedAt,
        'summary' => $current,
        'previous_summary' => $previous,
        'categories' => $categoryList,
        'budgets' => wallet_report_budget_performance(
            $budgetRows,
            $currentExpenses,
            $period['start'],
            $period['effective_end']
        ),
        'comparison' => wallet_report_comparison($current, $previous),
        'policy_note' => 'Active wallets in the selected currency only; future-dated transactions are excluded.',
    ];
}

/**
 * Load one authenticated user's report using defense-in-depth wallet ownership
 * checks and one calculation source shared by the dashboard and exports.
 *
 * @return array<string, mixed>
 */
function wallet_load_financial_report(
    PDO $pdo,
    int $userId,
    string $currency,
    array $period,
    ?DateTimeImmutable $generatedAt = null
): array {
    if ($userId <= 0 || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
        throw new InvalidArgumentException('Invalid report owner or currency.');
    }

    $transactionStatement = $pdo->prepare(
        'SELECT t.id, t.amount, t.category, t.transaction_date
         FROM transactions t
         INNER JOIN wallets w ON w.id = t.wallet_id AND w.user_id = t.user_id
         WHERE t.user_id = :transaction_user_id
            AND w.user_id = :wallet_user_id
            AND w.currency_code = :currency
            AND w.is_archived = 0
            AND t.transaction_date >= :range_start
            AND t.transaction_date < :range_end
         ORDER BY t.transaction_date, t.id'
    );
    $transactionStatement->execute([
        'transaction_user_id' => $userId,
        'wallet_user_id' => $userId,
        'currency' => $currency,
        'range_start' => $period['previous_start']->format('Y-m-d H:i:s'),
        'range_end' => $period['effective_end']->format('Y-m-d H:i:s'),
    ]);

    $budgetStatement = $pdo->prepare(
        'SELECT id, name, category, amount, currency_code, period, start_date, end_date, is_recurring
         FROM expense_limits
         WHERE user_id = :user_id
            AND currency_code = :currency
            AND start_date < :report_end
            AND (end_date IS NULL OR end_date >= :report_start)
         ORDER BY name, id'
    );
    $budgetStatement->execute([
        'user_id' => $userId,
        'currency' => $currency,
        'report_start' => $period['start']->format('Y-m-d'),
        'report_end' => $period['effective_end']->format('Y-m-d'),
    ]);

    return wallet_build_financial_report(
        $transactionStatement->fetchAll(PDO::FETCH_ASSOC),
        $budgetStatement->fetchAll(PDO::FETCH_ASSOC),
        $period,
        $currency,
        $generatedAt ?? new DateTimeImmutable('now', $period['start']->getTimezone())
    );
}
