<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

wallet_start_session();

if (!isset($_SESSION['user']['id'])) {
    header('Location: login.php');
    exit;
}

$name = is_string($_SESSION['user']['name'] ?? null) ? $_SESSION['user']['name'] : 'there';
$firstName = explode(' ', trim($name))[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f8f6">
    <title>Overview | Smart Wallet</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-[#f6f8f6] font-sans text-slate-900 antialiased">
<header class="border-b border-slate-200/80 bg-white">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 sm:px-8">
        <a href="index.php" class="inline-flex items-center gap-3 text-lg font-semibold tracking-tight">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#102820] text-emerald-300">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 17.5 9.2 12l3.5 3.2L20 7.5M14.8 7.5H20v5.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            Smart Wallet
        </a>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
            <button type="submit" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">
                Sign out
            </button>
        </form>
    </div>
</header>

<main class="mx-auto max-w-7xl px-5 py-12 sm:px-8 sm:py-16">
    <section class="overflow-hidden rounded-3xl bg-[#102820] px-7 py-10 text-white sm:px-12 sm:py-14">
        <p class="text-sm font-medium text-emerald-300">Your money, all in one place</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">Welcome, <?= wallet_escape($firstName) ?>.</h1>
        <p class="mt-4 max-w-xl text-sm leading-6 text-slate-300 sm:text-base">
            Your account is ready. Your spending overview, savings goals, and financial insights will live here.
        </p>
    </section>
</main>
</body>
</html>
