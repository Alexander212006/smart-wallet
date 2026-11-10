<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Smart Wallet';
$pageDescription = $pageDescription ?? 'A clearer view of your finances.';
$isRegistration = basename($_SERVER['SCRIPT_NAME']) === 'register.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f0f5f2">
    <title><?= wallet_escape($pageTitle) ?> | Smart Wallet</title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/ui.css?v=<?= filemtime(__DIR__ . '/../assets/css/ui.css') ?>">
    <link rel="stylesheet" href="assets/css/app-alert.css?v=<?= filemtime(__DIR__ . '/../assets/css/app-alert.css') ?>">
</head>
<body class="ui-auth font-sans antialiased">
<a href="#auth-form" class="ui-skip">Skip to form</a>
<main class="ui-auth-layout">
    <aside class="ui-auth-story" aria-label="About Smart Wallet">
        <?php require __DIR__ . '/brand.php'; ?>
        <div class="ui-auth-story-copy">
            <p class="ui-auth-kicker">A little more clarity</p>
            <h2>Your money.<br>Your plans.<br>One clear picture.</h2>
            <p>Keep track of the everyday, make space for your goals, and feel more in control of what comes next.</p>
            <div class="ui-auth-illustration" aria-hidden="true">
                <div class="ui-auth-illustration-head"><span>Small steps. Steady progress.</span><svg style="width:16px;height:16px;margin:0" viewBox="0 0 24 24" fill="none"><path d="m4 16 6-6 4 3 6-8m-6 0h6v6" stroke="#54816b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                <svg viewBox="0 0 300 108" fill="none" preserveAspectRatio="none">
                    <path d="M0 22h300M0 54h300M0 86h300" stroke="#e1eae4" stroke-dasharray="3 5"/>
                    <path d="M0 91C20 91 23 74 45 75S70 86 90 65 115 72 137 48 160 57 184 36 210 43 232 24 264 29 291 10" stroke="#579675" stroke-width="2.2" stroke-linecap="round"/>
                    <circle cx="291" cy="10" r="4" fill="#579675" stroke="#fff" stroke-width="3"/>
                </svg>
                <div class="ui-auth-illustration-foot"><span></span>A clearer view, one day at a time.</div>
            </div>
        </div>
        <p class="ui-auth-story-footer">&copy; <?= date('Y') ?> Smart Wallet. Keep life in balance.</p>
    </aside>
    <section class="ui-auth-panel">
        <div class="flex items-center justify-between gap-4">
            <div class="ui-auth-mobile-brand"><?php require __DIR__ . '/brand.php'; ?></div>
            <div class="ui-auth-switch">
                <span><?= $isRegistration ? 'Already have an account?' : 'New to Smart Wallet?' ?></span>
                <a href="<?= $isRegistration ? 'login.php' : 'register.php' ?>"><?= $isRegistration ? 'Sign in' : 'Create account' ?></a>
            </div>
        </div>
        <div id="auth-form" class="ui-auth-form">
            <h1><?= wallet_escape($pageTitle) ?></h1>
            <p class="ui-auth-description"><?= wallet_escape($pageDescription) ?></p>
