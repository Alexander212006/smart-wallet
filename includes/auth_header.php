<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Smart Wallet';
$pageDescription = $pageDescription ?? 'A clearer view of your money starts here.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f6f8f6">
    <title><?= wallet_escape($pageTitle) ?> | Smart Wallet</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-[#f6f8f6] font-sans text-slate-900 antialiased">
<main class="mx-auto flex min-h-screen w-full max-w-7xl items-center px-4 py-8 sm:px-6 lg:px-10">
    <div class="grid w-full overflow-hidden rounded-[2rem] bg-white shadow-[0_24px_80px_-32px_rgba(15,35,28,0.22)] lg:min-h-[680px] lg:grid-cols-[1fr_1fr]">
        <section class="relative hidden flex-col justify-between overflow-hidden bg-[#102820] p-10 text-white lg:flex xl:p-14">
            <div class="absolute -right-28 -top-28 h-80 w-80 rounded-full border border-white/10"></div>
            <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full border border-white/10"></div>
            <div class="absolute -bottom-36 -left-28 h-96 w-96 rounded-full bg-emerald-400/10 blur-2xl"></div>

            <a href="index.php" class="relative inline-flex items-center gap-3 text-lg font-semibold tracking-tight">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-400 text-[#102820]">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M4 17.5 9.2 12l3.5 3.2L20 7.5M14.8 7.5H20v5.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                Smart Wallet
            </a>

            <div class="relative max-w-md py-12">
                <p class="mb-5 text-xs font-semibold uppercase tracking-[0.24em] text-emerald-300">Money, in perspective</p>
                <h1 class="text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">
                    Make room for the things that matter.
                </h1>
                <p class="mt-6 max-w-sm text-base leading-7 text-slate-300">
                    Bring your income, expenses, and savings together in one calmer view of your finances.
                </p>
                <div class="mt-10 flex items-center gap-3 text-sm text-slate-300">
                    <span class="flex -space-x-2" aria-hidden="true">
                        <span class="h-8 w-8 rounded-full border-2 border-[#102820] bg-emerald-200"></span>
                        <span class="h-8 w-8 rounded-full border-2 border-[#102820] bg-teal-300"></span>
                        <span class="h-8 w-8 rounded-full border-2 border-[#102820] bg-lime-200"></span>
                    </span>
                    <span>A more thoughtful way to manage money</span>
                </div>
            </div>

            <p class="relative text-xs text-slate-400">&copy; <?= date('Y') ?> Smart Wallet</p>
        </section>

        <section class="flex items-center justify-center px-5 py-10 sm:px-10 lg:px-12 xl:px-16">
            <div class="w-full max-w-md">
                <a href="index.php" class="mb-10 inline-flex items-center gap-2 text-base font-semibold tracking-tight lg:hidden">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#102820] text-emerald-300">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 17.5 9.2 12l3.5 3.2L20 7.5M14.8 7.5H20v5.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    Smart Wallet
                </a>
                <p class="mb-2 text-sm font-medium text-emerald-700">Smart Wallet</p>
                <h2 class="text-3xl font-semibold tracking-tight"><?= wallet_escape($pageTitle) ?></h2>
                <p class="mt-2 text-sm leading-6 text-slate-500"><?= wallet_escape($pageDescription) ?></p>
