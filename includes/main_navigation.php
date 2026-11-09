<?php

declare(strict_types=1);

$navigationItems = [
    'overview' => ['label' => 'Overview', 'url' => 'index.php', 'icon' => 'overview'],
    'accounts' => ['label' => 'Create wallet', 'url' => 'wallet.php', 'icon' => 'accounts'],
    'expenses' => ['label' => 'Expenses', 'url' => 'expenses.php', 'icon' => 'expenses'],
    'savings' => ['label' => 'Savings', 'url' => 'savings.php', 'icon' => 'goals'],
    'income' => ['label' => 'Income', 'url' => 'income.php', 'icon' => 'income'],
];
$navigationLayout = $navigationLayout ?? 'sidebar';
$navigationClasses = $navigationLayout === 'mobile'
    ? 'flex gap-2 overflow-x-auto border-b border-slate-200/80 bg-white px-5 py-2 lg:hidden'
    : 'mt-8 space-y-1 px-4';
$navigationLinkClasses = $navigationLayout === 'mobile'
    ? 'flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium'
    : 'flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition';
?>
<nav class="<?= wallet_escape($navigationClasses) ?>" aria-label="Main navigation">
    <?php foreach ($navigationItems as $page => $item): ?>
        <?php
        $isActive = $activePage === $page;
        $stateClasses = $isActive
            ? 'bg-emerald-50 font-semibold text-emerald-900'
            : ($navigationLayout === 'mobile'
                ? 'text-slate-500 hover:bg-slate-50'
                : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900');
        ?>
        <a
            href="<?= wallet_escape($item['url']) ?>"
            class="<?= wallet_escape($navigationLinkClasses . ' ' . $stateClasses) ?>"
            <?= $isActive ? 'aria-current="page"' : '' ?>
        >
            <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <?php if ($item['icon'] === 'overview'): ?>
                    <rect x="3.5" y="3.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/>
                    <rect x="13.5" y="3.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/>
                    <rect x="3.5" y="13.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/>
                    <rect x="13.5" y="13.5" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.7"/>
                <?php elseif ($item['icon'] === 'accounts'): ?>
                    <rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/>
                    <path d="M3 9h18m-14 6h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                <?php elseif ($item['icon'] === 'budgets' || $item['icon'] === 'expenses'): ?>
                    <path d="M4 19V5m0 14h16M8 15v-3m4 3V7m4 8v-5m4 5V4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                <?php elseif ($item['icon'] === 'income'): ?>
                    <path d="M12 5v14m-5-5 5 5 5-5M4 4h16" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                <?php else: ?>
                    <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.7"/>
                    <circle cx="12" cy="12" r="4.5" stroke="currentColor" stroke-width="1.7"/>
                    <circle cx="12" cy="12" r="1" fill="currentColor"/>
                <?php endif; ?>
            </svg>
            <span><?= wallet_escape($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
