<?php

declare(strict_types=1);

$navigationItems = [
    'overview' => ['label' => 'Overview', 'url' => 'index.php', 'icon' => 'overview'],
    'accounts' => ['label' => 'Wallets', 'url' => 'wallet.php', 'icon' => 'accounts'],
    'income' => ['label' => 'Income', 'url' => 'income.php', 'icon' => 'income'],
    'expenses' => ['label' => 'Expenses', 'url' => 'expenses.php', 'icon' => 'expenses'],
    'savings' => ['label' => 'Savings', 'url' => 'savings.php', 'icon' => 'goals'],
    'spending' => ['label' => 'Spending analysis', 'url' => 'spending.php', 'icon' => 'spending'],
    'reports' => ['label' => 'Reports', 'url' => 'reports.php', 'icon' => 'reports'],
];
$navigationLayout = $navigationLayout ?? 'sidebar';
?>
<nav class="ui-nav" aria-label="Main navigation">
    <p class="ui-nav-label">Workspace</p>
    <?php foreach ($navigationItems as $page => $item): ?>
        <?php
        $isActive = $activePage === $page;
        ?>
        <?php if ($page === 'spending'): ?><p class="ui-nav-label mt-8">Insights</p><?php endif; ?>
        <a
            href="<?= wallet_escape($item['url']) ?>"
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
                <?php elseif ($item['icon'] === 'spending'): ?>
                    <path d="M4 19h16M6 15v-4m6 4V5m6 10V8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                    <path d="m4 8 5-4 5 2 6-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                <?php elseif ($item['icon'] === 'reports'): ?>
                    <path d="M6 3.5h9l3 3V20.5H6z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                    <path d="M15 3.5v3h3M9 16v-3m3 3V9m3 7v-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
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
