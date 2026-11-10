<?php

declare(strict_types=1);

$appTitle = $appTitle ?? 'Smart Wallet';
$activePage = $activePage ?? '';
$appName = is_string($_SESSION['user']['name'] ?? null) ? $_SESSION['user']['name'] : 'Account';
$appEmail = is_string($_SESSION['user']['email'] ?? null) ? $_SESSION['user']['email'] : 'Personal account';
preg_match('/^\X/u', trim($appName), $appInitialMatch);
$appInitial = strtoupper($appInitialMatch[0] ?? 'A');
$flash = wallet_take_flash();
$mainId = 'main-content';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f7f8fa">
    <title><?= wallet_escape($appTitle) ?> | Smart Wallet</title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/ui.css?v=<?= filemtime(__DIR__ . '/../assets/css/ui.css') ?>">
    <link rel="stylesheet" href="assets/css/app-alert.css?v=<?= filemtime(__DIR__ . '/../assets/css/app-alert.css') ?>">
    <link rel="stylesheet" href="assets/css/app-dialog.css?v=<?= filemtime(__DIR__ . '/../assets/css/app-dialog.css') ?>">
</head>
<body data-app-shell="true" class="font-sans antialiased">
<a href="#<?= $mainId ?>" class="ui-skip">Skip to content</a>
<div id="app-content" class="ui-frame">
    <aside class="ui-sidebar" aria-label="Workspace sidebar">
        <?php require __DIR__ . '/brand.php'; ?>
        <?php $navigationLayout = 'sidebar'; require __DIR__ . '/main_navigation.php'; ?>
        <div class="ui-sidebar-bottom">
            <div class="ui-sidebar-note">
                <p>A little progress, every day.</p>
                <p>Small steps today. More room for what matters tomorrow.</p>
            </div>
            <div class="ui-account">
                <span class="ui-avatar" aria-hidden="true"><?= wallet_escape($appInitial) ?></span>
                <div class="ui-account-copy">
                    <strong><?= wallet_escape($appName) ?></strong>
                    <span class="truncate"><?= wallet_escape($appEmail) ?></span>
                </div>
                <form method="post" action="logout.php" data-confirm-title="Sign out of Smart Wallet?" data-confirm-description="You'll need to sign in again to access your account." data-confirm-label="Sign out" data-confirm-variant="danger" data-confirm-icon="logout">
                    <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                    <button type="submit" class="ui-icon-button" aria-label="Sign out" title="Sign out">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 4H5v16h4m6-12 4 4-4 4m-7-4h11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>
    <div class="ui-workspace">
        <header class="ui-topbar">
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" class="ui-icon-button ui-mobile-toggle" data-open-navigation aria-label="Open navigation" aria-haspopup="dialog" aria-controls="app-mobile-navigation">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                </button>
                <div class="ui-breadcrumb"><span>Workspace</span><span aria-hidden="true">/</span><strong><?= wallet_escape($appTitle) ?></strong></div>
            </div>
            <div class="ui-topbar-right">
                <?php if ($activePage === 'overview' && count($currencies) > 1): ?>
                    <form method="get" action="index.php">
                        <label for="currency" class="sr-only">Dashboard currency</label>
                        <input type="hidden" name="cash_flow_days" value="<?= (int) $cashFlowDays ?>">
                        <select id="currency" name="currency" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white text-slate-600">
                            <?php foreach ($currencies as $availableCurrency): ?>
                                <option value="<?= wallet_escape((string) $availableCurrency) ?>" <?= $availableCurrency === $currency ? 'selected' : '' ?>><?= wallet_escape((string) $availableCurrency) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
                <span class="ui-topbar-date"><?= date('D, M j, Y') ?></span>
                <span class="h-5 w-px bg-slate-200" aria-hidden="true"></span>
                <span class="ui-avatar" title="<?= wallet_escape($appName) ?>"><?= wallet_escape($appInitial) ?></span>
            </div>
        </header>
        <dialog id="app-mobile-navigation" class="ui-mobile-nav" aria-label="Workspace navigation">
            <div class="ui-mobile-nav-head">
                <?php require __DIR__ . '/brand.php'; ?>
                <button type="button" class="ui-icon-button" data-close-navigation aria-label="Close navigation"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
            </div>
            <?php $navigationLayout = 'mobile'; require __DIR__ . '/main_navigation.php'; ?>
            <form method="post" action="logout.php" data-confirm-title="Sign out of Smart Wallet?" data-confirm-description="You'll need to sign in again to access your account." data-confirm-label="Sign out" data-confirm-variant="danger" data-confirm-icon="logout">
                <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                <button type="submit" class="text-sm font-medium text-slate-500">Sign out</button>
            </form>
        </dialog>
        <main id="<?= $mainId ?>" tabindex="-1" class="ui-main">
            <?php if ($flash !== null): ?>
                <div data-flash-message data-type="<?= wallet_escape((string) ($flash['type'] ?? 'info')) ?>" data-message="<?= wallet_escape((string) ($flash['message'] ?? '')) ?>" hidden></div>
            <?php endif; ?>
