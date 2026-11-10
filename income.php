<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$pdo = getDatabaseConnection();
$today = new DateTimeImmutable('today');
$defaultCategories = ['Salary', 'Business income', 'Investment returns', 'Gift', 'Refund'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wallet_require_valid_post();
    $action = wallet_post_string('action');
    try {
        $transactionId = filter_var($_POST['transaction_id'] ?? null, FILTER_VALIDATE_INT);
        if (in_array($action, ['create', 'update'], true)) {
            $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
            $categoryValue = wallet_post_string('category');
            $category = trim($categoryValue === 'other'
                ? wallet_post_string('custom_category')
                : (str_starts_with($categoryValue, 'category:') ? substr($categoryValue, 9) : ''));

            $amount = wallet_post_string('amount');
            $description = wallet_post_string('description');
            $transactionDate = wallet_post_string('transaction_date');
            if ($accountId === false || !wallet_valid_category($category) || !wallet_valid_amount($amount)
                || strlen($description) > 255 || !wallet_valid_date($transactionDate)
                || ($action === 'update' && $transactionId === false)) {
                wallet_set_flash('error', 'Check the income details and try again.');
            } else {
                wallet_call_procedure($pdo, 'sp_save_transaction', [
                    $walletUserId, $action === 'create' ? null : $transactionId, $accountId, $category,
                    $amount, $description === '' ? null : $description, $transactionDate . ' 12:00:00', null,
                ]);
                wallet_set_flash('success', $action === 'create' ? 'Income recorded.' : 'Income updated.');
            }
        } elseif ($action === 'delete' && $transactionId !== false) {
            wallet_call_procedure($pdo, 'sp_delete_transaction', [$walletUserId, $transactionId, 'income']);
            wallet_set_flash('success', 'Income deleted.');
        } else {
            wallet_set_flash('error', 'That income action could not be completed.');
        }
    } catch (PDOException $error) {
        wallet_set_flash('error', wallet_procedure_error($error));
    }
    header('Location: income.php');
    exit;
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editIncome = null;
if ($editId !== false && $editId !== null) {
    $editStatement = $pdo->prepare(
        'SELECT id, wallet_id AS account_id, category, amount, description, DATE(transaction_date) AS transaction_date
         FROM transactions
         WHERE id = :id AND user_id = :user_id AND amount > 0'
    );
    $editStatement->execute(['id' => $editId, 'user_id' => $walletUserId]);
    $editIncome = $editStatement->fetch() ?: null;
}

$accountStatement = $pdo->prepare(
    'SELECT id, name, currency_code
     FROM wallets
     WHERE user_id = :user_id AND is_archived = 0
     ORDER BY name'
);
$accountStatement->execute(['user_id' => $walletUserId]);
$accounts = $accountStatement->fetchAll();

$categories = wallet_category_options($pdo, $walletUserId, false);
if ($editIncome !== null && $editIncome['category'] !== null
    && !in_array($editIncome['category'], $categories, true)) {
    $categories[] = $editIncome['category'];
}
$existingCategoryNames = array_map('strtolower', $categories);

$incomeEntries = $pdo->prepare(
    'SELECT id, amount, description, transaction_date, category, account_name, currency_code, is_archived, category_name
     FROM vw_transaction_details WHERE user_id = :user_id AND amount > 0
     ORDER BY transaction_date DESC, id DESC LIMIT 100'
);
$incomeEntries->execute(['user_id' => $walletUserId]);
$incomeEntries = $incomeEntries->fetchAll();

$appTitle = 'Income';
$activePage = 'income';
require __DIR__ . '/includes/app_shell_start.php';
?>
<section class="ui-page-heading flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Income</h1>
        <p class="mt-2 text-sm text-slate-500">Track what comes in and where it goes.</p>
    </div>
    <div class="flex flex-wrap items-center gap-3">
    <span class="w-fit rounded-full bg-white px-3 py-1.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200"><?= count($incomeEntries) ?> records</span>
        <?php if ($accounts !== []): ?>
            <button type="button" data-open-app-dialog="income-modal" aria-haspopup="dialog" aria-controls="income-modal" class="inline-flex items-center gap-2 rounded-lg bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#0d3024] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"><span aria-hidden="true">+</span><?= $editIncome !== null ? 'Edit income' : 'Add income' ?></button>
        <?php endif; ?>
    </div>
</section>

<?php if ($accounts === []): ?>
    <section class="ui-card rounded-2xl border border-slate-200/80 bg-white p-8 text-center shadow-sm">
        <h2 class="font-semibold">Create an active wallet first</h2>
        <p class="mt-2 text-sm text-slate-500">Income is recorded directly into one of your wallets.</p>
        <a href="wallet.php" class="mt-5 inline-flex rounded-xl bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#0d3024]">Create a wallet</a>
    </section>
<?php else: ?>
    <div class="space-y-6">
        <section class="ui-ledger">
            <div class="ui-ledger-heading"><h2 class="font-semibold">Recent income</h2><p class="mt-1 text-xs text-slate-400">Your latest income across all wallets.</p></div>
            <?php if ($incomeEntries === []): ?>
                <div class="ui-card rounded-2xl border border-slate-200/80 bg-white px-5 py-10 text-center shadow-sm">
                    <h2 class="font-semibold">No income recorded yet</h2>
                    <p class="mt-2 text-sm text-slate-500">Your income entries will appear here after you add one.</p>
                </div>
            <?php endif; ?>
            <?php foreach ($incomeEntries as $entry): ?>
                <article class="ui-card rounded-2xl border border-slate-200/80 bg-white p-5 sm:p-6">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div class="min-w-0">
                            <h2 class="truncate font-semibold"><?= wallet_escape((string) ($entry['description'] ?: $entry['category_name'])) ?></h2>
                            <p class="mt-1 text-sm text-slate-500">
                                <?= wallet_escape((string) $entry['account_name']) ?><?= $entry['is_archived'] ? ' · Archived wallet' : '' ?>
                                · <?= wallet_escape((string) $entry['category_name']) ?>
                            </p>
                            <p class="mt-1 text-xs text-slate-400"><?= wallet_escape(date('M j, Y', strtotime((string) $entry['transaction_date']))) ?></p>
                        </div>
                        <div class="flex items-center justify-between gap-3 sm:justify-end">
                            <span class="font-semibold text-emerald-700">+<?= wallet_escape((string) $entry['currency_code']) ?> <?= number_format((float) $entry['amount'], 2) ?></span>
                            <?php if (!$entry['is_archived']): ?>
                                <a href="income.php?edit=<?= (int) $entry['id'] ?>" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100">Edit</a>
                            <?php endif; ?>
                            <form method="post" action="income.php" data-confirm-title="Delete this income record?" data-confirm-description="This income will be permanently removed from your records." data-confirm-label="Delete income" data-confirm-variant="danger">
                                <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="transaction_id" value="<?= (int) $entry['id'] ?>">
                                <button type="submit" class="rounded-lg px-3 py-2 text-xs font-medium text-rose-600 hover:bg-rose-50">Delete</button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
        <dialog id="income-modal" data-app-modal <?= $editIncome !== null || ($_GET['new'] ?? '') === 'income' ? 'data-open-on-load' : '' ?> aria-labelledby="income-modal-title" aria-describedby="income-modal-description" class="app-modal">
            <div class="app-modal-content">
                <div class="app-modal-header">
                    <span class="app-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 7h16v13H4V7ZM8 7V4h8v3m-4 4v5m-3-3 3 3 3-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    <div class="app-modal-heading">
                        <h2 id="income-modal-title"><?= $editIncome !== null ? 'Edit income' : 'Add income' ?></h2>
                        <p id="income-modal-description">Choose the wallet, amount, and date for this income.</p>
                    </div>
                    <button type="button" data-close-app-modal class="app-modal-close" aria-label="Close dialog"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
                </div>
                <form method="post" action="income.php" class="app-modal-form" <?= $editIncome === null ? 'data-confirm-title="Add this income?" data-confirm-description="This income will be recorded in the selected wallet." data-confirm-label="Add income"' : '' ?>>
                    <div class="app-modal-body">
                        <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                        <input type="hidden" name="action" value="<?= $editIncome !== null ? 'update' : 'create' ?>">
                        <?php if ($editIncome !== null): ?><input type="hidden" name="transaction_id" value="<?= (int) $editIncome['id'] ?>"><?php endif; ?>
                        <div>
                            <label for="account_id" class="mb-1.5 block text-sm font-medium text-slate-700">Wallet</label>
                            <select id="account_id" name="account_id" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= (int) $account['id'] ?>" <?= (int) ($editIncome['account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>>
                                        <?= wallet_escape((string) $account['name']) ?> (<?= wallet_escape((string) $account['currency_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="app-form-grid">
                            <div>
                                <label for="amount" class="mb-1.5 block text-sm font-medium text-slate-700">Amount</label>
                                <input id="amount" name="amount" type="number" min="0.01" step="0.01" required value="<?= wallet_escape((string) ($editIncome['amount'] ?? '')) ?>" placeholder="0.00" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                            </div>
                            <div>
                                <label for="transaction_date" class="mb-1.5 block text-sm font-medium text-slate-700">Date</label>
                                <input id="transaction_date" name="transaction_date" type="date" required value="<?= wallet_escape((string) ($editIncome['transaction_date'] ?? $today->format('Y-m-d'))) ?>" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                            </div>
                        </div>
                        <div class="space-y-3" data-category-control>
                            <div>
                                <label for="income_category" class="mb-1.5 block text-sm font-medium text-slate-700">Category</label>
                                <select id="income_category" name="category" required data-custom-category-select class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                                    <option value="" disabled <?= ($editIncome['category'] ?? '') === '' ? 'selected' : '' ?>>Select a category</option>
                                    <?php foreach ($defaultCategories as $categoryName): ?>
                                        <?php if (!in_array(strtolower($categoryName), $existingCategoryNames, true)): ?>
                                            <option value="category:<?= wallet_escape($categoryName) ?>"><?= wallet_escape($categoryName) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="category:<?= wallet_escape($category) ?>" <?= ($editIncome['category'] ?? '') === $category ? 'selected' : '' ?>><?= wallet_escape($category) ?></option>
                                    <?php endforeach; ?>
                                    <option value="other">Other (enter a category)</option>
                                </select>
                            </div>
                            <div data-custom-category-field class="hidden">
                                <label for="income_custom_category" class="mb-1.5 block text-sm font-medium text-slate-700">Category name</label>
                                <input id="income_custom_category" name="custom_category" type="text" maxlength="80" data-custom-category-input placeholder="e.g. Commission" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                            </div>
                        </div>

                        <div>
                            <label for="description" class="mb-1.5 block text-sm font-medium text-slate-700">Description <span class="font-normal text-slate-400">optional</span></label>
                            <input id="description" name="description" type="text" maxlength="255" value="<?= wallet_escape((string) ($editIncome['description'] ?? '')) ?>" placeholder="Salary, freelance work..." class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                        </div>
                    </div>
                    <div class="app-modal-footer">
                        <?php if ($editIncome !== null): ?>
                            <a href="income.php" class="app-modal-button app-modal-secondary">Cancel</a>
                        <?php else: ?>
                            <button type="button" data-close-app-modal class="app-modal-button app-modal-secondary">Cancel</button>
                        <?php endif; ?>
                        <button type="submit" class="app-modal-button app-modal-primary">
                    <?= $editIncome !== null ? 'Save changes' : 'Add income' ?>
                </button>
                    </div>
                </form>
            </div>
        </dialog>
<?php endif; ?>
<?php require __DIR__ . '/includes/app_shell_end.php'; ?>
