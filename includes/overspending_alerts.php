<?php

declare(strict_types=1);

if ($overspendingAlerts === []) {
    return;
}
?>
<?php if ($overspendingNotifications !== []): ?>
    <div data-flash-message data-type="warning" data-message="<?= wallet_escape(wallet_overspending_message($overspendingNotifications)) ?>" hidden></div>
<?php endif; ?>
<section aria-labelledby="overspending-alert-title" class="rounded-2xl border border-rose-200 bg-rose-50 p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-rose-100 text-rose-700" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m12 3 10 18H2L12 3Z" />
                    <path d="M12 9v4m0 4h.01" />
                </svg>
            </span>
            <div>
                <h2 id="overspending-alert-title" class="font-semibold text-rose-950">Overspending alert</h2>
                <p class="mt-1 text-sm leading-6 text-rose-800"><?= count($overspendingAlerts) === 1 ? 'You have exceeded an active expense limit.' : 'You have exceeded ' . count($overspendingAlerts) . ' active expense limits.' ?> Spending includes active wallets through today.</p>
            </div>
        </div>
        <button type="button" data-open-expense-dialog="review" aria-haspopup="dialog" aria-controls="expense-modal-review" class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-rose-800 ring-1 ring-rose-200 transition hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">Review limits &rarr;</button>
    </div>
    <ul class="mt-4 divide-y divide-rose-200/70">
        <?php foreach ($overspendingAlerts as $overspendingAlert): ?>
            <li class="flex flex-col justify-between gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start">
                <div class="min-w-0">
                    <a href="expenses.php?edit=<?= (int) $overspendingAlert['id'] ?>" class="break-words text-sm font-semibold text-rose-950 hover:underline"><?= wallet_escape((string) $overspendingAlert['name']) ?></a>
                    <p class="mt-1 text-xs leading-5 text-rose-700"><?= wallet_escape(ucfirst((string) $overspendingAlert['period'])) ?> &middot; <?= wallet_escape((string) $overspendingAlert['category_name']) ?> &middot; <?= wallet_escape((string) $overspendingAlert['range_start']) ?>&ndash;<?= wallet_escape((new DateTimeImmutable($overspendingAlert['range_end']))->modify('-1 day')->format('Y-m-d')) ?></p>
                </div>
                <div class="shrink-0 text-sm sm:text-right">
                    <p class="font-semibold text-rose-800"><?= wallet_escape((string) $overspendingAlert['currency_code']) ?> <?= wallet_expense_limit_format_cents($overspendingAlert['over_cents']) ?> over limit</p>
                    <p class="mt-1 text-xs leading-5 text-rose-700"><?= wallet_escape((string) $overspendingAlert['currency_code']) ?> <?= wallet_expense_limit_format_cents($overspendingAlert['spent_cents']) ?> spent / <?= wallet_expense_limit_format_cents($overspendingAlert['limit_cents']) ?> limit</p>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<dialog id="expense-modal-review" data-expense-modal data-limit-review-modal aria-labelledby="limit-review-title" aria-describedby="limit-review-description" class="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-3xl overflow-hidden rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/40">
    <div class="flex max-h-[calc(100dvh-2rem-2px)] flex-col">
        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-slate-100 p-5 sm:p-6">
            <div>
                <h2 id="limit-review-title" class="text-lg font-semibold tracking-tight">Review expense limits</h2>
                <p id="limit-review-description" class="mt-1 text-sm leading-6 text-slate-500">These limits are over budget. Review spending for the current period or edit a limit.</p>
            </div>
            <button type="button" data-close-expense-modal aria-label="Close limit review" class="shrink-0 rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">&times;</button>
        </div>
        <div class="min-h-0 overflow-y-auto p-5 sm:p-6" data-limit-review-content>
            <ul class="space-y-4">
                <?php foreach ($overspendingAlerts as $reviewLimit): ?>
                    <?php $reviewPercent = $reviewLimit['limit_cents'] > 0 ? $reviewLimit['spent_cents'] / $reviewLimit['limit_cents'] * 100 : null; ?>
                    <li class="rounded-xl border border-slate-200 p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="break-words font-semibold"><?= wallet_escape((string) $reviewLimit['name']) ?></h3>
                                <p class="mt-1 break-words text-xs leading-5 text-slate-500"><?= wallet_escape(ucfirst((string) $reviewLimit['period'])) ?> &middot; <?= wallet_escape((string) $reviewLimit['category_name']) ?></p>
                            </div>
                            <a href="expenses.php?edit=<?= (int) $reviewLimit['id'] ?>" class="shrink-0 rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900 transition hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Edit limit &rarr;</a>
                        </div>
                        <p class="mt-3 text-xs leading-5 text-slate-500">Current period: <?= wallet_escape((string) $reviewLimit['range_start']) ?>&ndash;<?= wallet_escape((new DateTimeImmutable($reviewLimit['range_end']))->modify('-1 day')->format('Y-m-d')) ?></p>
                        <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                            <div class="min-w-0 rounded-lg bg-slate-50 p-3">
                                <dt class="text-xs text-slate-500">Limit</dt>
                                <dd class="mt-1 break-words text-sm font-semibold"><?= wallet_escape((string) $reviewLimit['currency_code']) ?> <?= wallet_expense_limit_format_cents($reviewLimit['limit_cents']) ?></dd>
                            </div>
                            <div class="min-w-0 rounded-lg bg-slate-50 p-3">
                                <dt class="text-xs text-slate-500">Spent through today</dt>
                                <dd class="mt-1 break-words text-sm font-semibold"><?= wallet_escape((string) $reviewLimit['currency_code']) ?> <?= wallet_expense_limit_format_cents($reviewLimit['spent_cents']) ?></dd>
                            </div>
                            <div class="min-w-0 rounded-lg bg-rose-50 p-3">
                                <dt class="text-xs text-rose-700">Over limit</dt>
                                <dd class="mt-1 break-words text-sm font-semibold text-rose-800"><?= wallet_escape((string) $reviewLimit['currency_code']) ?> <?= wallet_expense_limit_format_cents($reviewLimit['over_cents']) ?></dd>
                            </div>
                        </dl>
                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-rose-100" aria-hidden="true">
                            <div class="h-full w-full rounded-full bg-rose-500"></div>
                        </div>
                        <p class="mt-2 break-words text-xs font-medium text-rose-700"><?= $reviewPercent !== null ? number_format($reviewPercent, 1) . '% of your limit used' : 'Limit exceeded' ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="flex shrink-0 justify-end border-t border-slate-100 bg-slate-50 px-5 py-4 sm:px-6">
            <button type="button" data-close-expense-modal class="rounded-xl bg-[#123a2c] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#0d3024] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">Done</button>
        </div>
    </div>
</dialog>
