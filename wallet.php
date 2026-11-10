<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$pdo = getDatabaseConnection();
$accountTypes = ['cash', 'checking', 'savings', 'credit', 'investment', 'other'];
$supportedCurrencies = ['PHP', 'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'SGD'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wallet_require_valid_post();
    $action = wallet_post_string('action');
    try {
        $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
        if ($action === 'create') {
            $name = wallet_post_string('name');
            $type = wallet_post_string('account_type');
            $currentBalance = wallet_post_string('current_balance');
            $currency = strtoupper(wallet_post_string('currency_code'));
            if ($name === '' || strlen($name) > 120 || !in_array($type, $accountTypes, true)) {
                wallet_set_flash('error', 'Enter a valid account name and type.');
            } elseif (!wallet_valid_signed_amount($currentBalance)) {
                wallet_set_flash('error', 'Enter a valid current balance with up to two decimal places.');
            } elseif (!in_array($currency, $supportedCurrencies, true)) {
                wallet_set_flash('error', 'Choose a supported currency.');
            } else {
                wallet_call_procedure($pdo, 'sp_create_wallet', [$walletUserId, $name, $type, $currentBalance, $currency]);
                wallet_set_flash('success', 'Account created.');
            }
        } elseif ($action === 'update' && $accountId !== false) {
            $name = wallet_post_string('name');
            $type = wallet_post_string('account_type');
            if ($name === '' || strlen($name) > 120 || !in_array($type, $accountTypes, true)) {
                wallet_set_flash('error', 'Enter a valid account name and type.');
            } else {
                wallet_call_procedure($pdo, 'sp_update_wallet', [$walletUserId, $accountId, $name, $type]);
                wallet_set_flash('success', 'Account updated.');
            }
        } elseif (in_array($action, ['archive', 'restore'], true) && $accountId !== false) {
            wallet_call_procedure($pdo, 'sp_set_wallet_archived', [$walletUserId, $accountId, $action === 'archive' ? 1 : 0]);
            wallet_set_flash('success', $action === 'archive' ? 'Account archived.' : 'Account restored.');
        } else {
            wallet_set_flash('error', 'That account action could not be completed.');
        }
    } catch (PDOException $error) {
        wallet_set_flash('error', wallet_procedure_error($error));
    }
    header('Location: wallet.php' . ($action === 'restore' ? '?view=archived' : ''));
    exit;
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editAccount = null;
if ($editId !== false && $editId !== null) {
    $editStatement = $pdo->prepare(
        'SELECT id, name, wallet_type AS account_type FROM wallets WHERE id = :id AND user_id = :user_id'
    );
    $editStatement->execute(['id' => $editId, 'user_id' => $walletUserId]);
    $editAccount = $editStatement->fetch() ?: null;
}

$accountStatement = $pdo->prepare(
    'SELECT a.id, a.name, a.wallet_type AS account_type, a.current_balance, a.currency_code, a.is_archived
     FROM wallets a
     WHERE a.user_id = :user_id
     ORDER BY a.is_archived, a.name'
);
$accountStatement->execute(['user_id' => $walletUserId]);
$accounts = $accountStatement->fetchAll();
$viewArchived = ($_GET['view'] ?? '') === 'archived';
$activeAccounts = array_values(array_filter(
    $accounts,
    static fn (array $account): bool => !(bool) $account['is_archived']
));
$archivedAccounts = array_values(array_filter(
    $accounts,
    static fn (array $account): bool => (bool) $account['is_archived']
));
$visibleAccounts = $viewArchived ? $archivedAccounts : $activeAccounts;

$appTitle = 'Wallets';
$activePage = 'accounts';
require __DIR__ . '/includes/app_shell_start.php';
?>
<section class="ui-page-heading flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Wallets</h1>
        <p class="mt-2 text-sm text-slate-500">Your accounts and balances, in one place.</p>
    </div>
    <div class="flex w-fit items-center gap-2">
        <a
            href="wallet.php<?= $viewArchived ? '' : '?view=archived' ?>"
            class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
        >
            <?= $viewArchived ? 'View active wallets' : 'View archived wallets' ?>
            <span class="ml-1.5 text-slate-500">(<?= $viewArchived ? count($activeAccounts) : count($archivedAccounts) ?>)</span>
        </a>
        <span class="rounded-full bg-white px-3 py-1.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200">
            <?= count(array_filter($accounts, static fn (array $account): bool => !(bool) $account['is_archived'])) ?> active
        </span>
    </div>
</section>

<div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.65fr)]">
    <section class="ui-card rounded-2xl border border-slate-200/80 bg-white">
        <div class="flex flex-col justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:px-6">
            <div>
                <h2 class="font-semibold tracking-tight"><?= $viewArchived ? 'Archived wallets' : 'Active wallets' ?></h2>
                <p class="mt-1 text-sm text-slate-500">Balances include recorded income, expenses, and transfers.</p>
            </div>
        </div>
        <?php if ($visibleAccounts === []): ?>
            <?php if ($accounts === []): ?>
            <p class="px-5 py-10 text-center text-sm text-slate-500">You haven’t created any wallets yet.</p>
            <?php else: ?>
                <p class="px-5 py-10 text-center text-sm text-slate-500"><?= $viewArchived ? 'There are no archived wallets.' : 'There are no active wallets.' ?></p>
            <?php endif; ?>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($visibleAccounts as $account): ?>
                    <article class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl <?= $account['is_archived'] ? 'bg-slate-100 text-slate-500' : 'bg-emerald-50 text-emerald-800' ?>">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3 9h18m-14 6h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold"><?= wallet_escape((string) $account['name']) ?></p>
                                <p class="mt-1 text-xs capitalize text-slate-500">
                                    <?= wallet_escape((string) $account['account_type']) ?> · <?= wallet_escape((string) $account['currency_code']) ?>
                                    <?= $account['is_archived'] ? ' · Archived' : '' ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 sm:justify-end">
                            <p class="text-sm font-semibold"><?= wallet_escape($account['currency_code'] . ' ' . number_format((float) $account['current_balance'], 2)) ?></p>
                            <div class="flex items-center gap-2">
                                <?php if (!$account['is_archived']): ?>
                                    <a href="wallet.php?edit=<?= (int) $account['id'] ?>" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100">Edit</a>
                                <?php endif; ?>
                                <form method="post" action="wallet.php" <?= !$account['is_archived'] ? 'data-confirm-archive="true"' : '' ?>>
                                    <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                                    <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                    <input type="hidden" name="action" value="<?= $account['is_archived'] ? 'restore' : 'archive' ?>">
                                    <button type="submit" class="rounded-lg px-3 py-2 text-xs font-medium <?= $account['is_archived'] ? 'text-emerald-700 hover:bg-emerald-50' : 'text-slate-500 hover:bg-slate-100' ?>">
                                        <?= $account['is_archived'] ? 'Restore' : 'Archive' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="ui-form-panel ui-card rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
        <h2 class="font-semibold tracking-tight"><?= $editAccount !== null ? 'Edit wallet' : 'Create wallet' ?></h2>
        <p class="mt-1 text-sm text-slate-500">
            <?= $editAccount !== null ? 'Update its name and wallet type.' : 'Wallets in different currencies stay separate.' ?>
        </p>
        <form method="post" action="wallet.php" class="mt-5 space-y-4" <?= $editAccount === null ? 'data-confirm-title="Create this wallet?" data-confirm-description="The wallet will be added to your account." data-confirm-label="Create wallet"' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $editAccount !== null ? 'update' : 'create' ?>">
            <?php if ($editAccount !== null): ?>
                <input type="hidden" name="account_id" value="<?= (int) $editAccount['id'] ?>">
            <?php endif; ?>
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700">Wallet name</label>
                <input id="name" name="name" type="text" maxlength="120" required value="<?= wallet_escape((string) ($editAccount['name'] ?? '')) ?>" placeholder="Everyday checking" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10">
            </div>
            <div>
                <label for="account_type" class="mb-1.5 block text-sm font-medium text-slate-700">Wallet type</label>
                <select id="account_type" name="account_type" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm capitalize outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10">
                    <?php foreach ($accountTypes as $type): ?>
                        <option value="<?= wallet_escape($type) ?>" <?= ($editAccount['account_type'] ?? 'checking') === $type ? 'selected' : '' ?>><?= wallet_escape(ucfirst($type)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($editAccount === null): ?>
                <div>
                    <label for="current_balance" class="mb-1.5 block text-sm font-medium text-slate-700">Current balance</label>
                    <input id="current_balance" name="current_balance" type="number" step="0.01" value="0.00" required class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10">
                    <p class="mt-1.5 text-xs text-slate-400">Use a negative amount for an account that starts in debt.</p>
                </div>
                <div>
                    <label for="currency_code" class="mb-1.5 block text-sm font-medium text-slate-700">Currency code</label>
                    <select id="currency_code" name="currency_code" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10">
                        <?php foreach ($supportedCurrencies as $currencyOption): ?>
                            <option value="<?= wallet_escape($currencyOption) ?>" <?= $currencyOption === 'PHP' ? 'selected' : '' ?>><?= wallet_escape($currencyOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1.5 text-xs text-slate-400">Choose the currency used by this wallet.</p>
                </div>
            <?php endif; ?>
            <button type="submit" class="w-full rounded-xl bg-[#123a2c] px-4 py-3 text-sm font-semibold text-white transition hover:bg-[#0d3024]">
                <?= $editAccount !== null ? 'Save changes' : 'Create wallet' ?>
            </button>
            <?php if ($editAccount !== null): ?>
                <a href="wallet.php" class="block text-center text-sm font-medium text-slate-500 hover:text-slate-800">Cancel</a>
            <?php endif; ?>
        </form>
    </section>
</div>
<?php require __DIR__ . '/includes/app_shell_end.php'; ?>
