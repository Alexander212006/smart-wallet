<?php

declare(strict_types=1);

$appTitle = $appTitle ?? 'Smart Wallet';
$activePage = $activePage ?? '';
$appName = is_string($_SESSION['user']['name'] ?? null) ? $_SESSION['user']['name'] : 'Account';
$flash = wallet_take_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f8f6">
    <title><?= wallet_escape($appTitle) ?> | Smart Wallet</title>
    <link rel="stylesheet" href="assets/css/app-alert.css?v=<?= filemtime(__DIR__ . '/../assets/css/app-alert.css') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body data-app-shell="true" class="min-h-screen bg-[#f6f8f6] font-sans text-slate-900 antialiased">
<div id="app-content" class="min-h-screen lg:grid lg:grid-cols-[248px_minmax(0,1fr)]">
    <aside class="hidden border-r border-slate-200/80 bg-white lg:fixed lg:inset-y-0 lg:left-0 lg:z-20 lg:flex lg:w-[248px] lg:flex-col lg:overflow-y-auto">
        <a href="index.php" class="flex items-center gap-3 px-6 py-6 text-lg font-semibold tracking-tight">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#102820] text-emerald-300">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 17.5 9.2 12l3.5 3.2L20 7.5M14.8 7.5H20v5.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            Smart Wallet
        </a>
        <?php $navigationLayout = 'sidebar'; require __DIR__ . '/main_navigation.php'; ?>
        <div class="mt-auto border-t border-slate-100 p-4">
            <div class="mb-4 truncate px-3 text-sm text-slate-500"><?= wallet_escape($appName) ?></div>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                <button type="submit" class="w-full rounded-xl px-3 py-2.5 text-left text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">Sign out</button>
            </form>
        </div>
    </aside>
    <div class="min-w-0 lg:col-start-2">
        <header class="sticky top-0 z-10 border-b border-slate-200/80 bg-white/90 backdrop-blur">
            <div class="mx-auto flex max-w-[1440px] items-center justify-between gap-4 px-5 py-4 sm:px-8">
                <div class="flex items-center gap-3">
                    <a href="index.php" class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#102820] text-emerald-300 lg:hidden" aria-label="Smart Wallet home">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 17.5 9.2 12l3.5 3.2L20 7.5M14.8 7.5H20v5.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <div>
                        <p class="text-sm font-semibold text-slate-900"><?= wallet_escape($appTitle) ?></p>
                        <p class="hidden text-xs text-slate-500 sm:block">Your financial workspace</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="hidden text-sm font-medium text-slate-600 sm:block"><?= wallet_escape($appName) ?></span>
                    <form method="post" action="logout.php" class="lg:hidden">
                        <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                        <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">Sign out</button>
                    </form>
                </div>
            </div>
        </header>
        <?php $navigationLayout = 'mobile'; require __DIR__ . '/main_navigation.php'; ?>
        <main tabindex="-1" class="mx-auto max-w-[1440px] space-y-6 px-5 py-8 outline-none sm:px-8 sm:py-10">
            <?php if ($flash !== null): ?>
                <div
                    data-flash-message
                    data-type="<?= wallet_escape((string) ($flash['type'] ?? 'info')) ?>"
                    data-message="<?= wallet_escape((string) ($flash['message'] ?? '')) ?>"
                    hidden
                ></div>
            <?php endif; ?>
