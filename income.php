<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$pdo = getDatabaseConnection();
$today = new DateTimeImmutable('today');
$defaultCategories = ['Salary', 'Business income', 'Investment returns', 'Gift', 'Refund'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wallet_require_valid_post();

    $action = wallet_post_string('action');
    $transactionId = filter_var($_POST['transaction_id'] ?? null, FILTER_VALIDATE_INT);

    if (in_array($action, ['create', 'update'], true)) {
        $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
        $categoryValue = wallet_post_string('category_id');
        $customCategory = trim(wallet_post_string('custom_category'));
        $isOtherCategory = $categoryValue === 'other';
        $isDefaultCategory = substr($categoryValue, 0, 8) === 'default:';
        $defaultCategory = $isDefaultCategory ? substr($categoryValue, 8) : '';
        $categoryId = $categoryValue === '' || $isOtherCategory || $isDefaultCategory
            ? null
            : filter_var($categoryValue, FILTER_VALIDATE_INT);
        $amount = wallet_post_string('amount');
        $description = wallet_post_string('description');
        $transactionDate = wallet_post_string('transaction_date');

        $accountStatement = $pdo->prepare(
            'SELECT id FROM wallets WHERE id = :id AND user_id = :user_id AND is_archived = 0'
        );
        $accountStatement->execute(['id' => $accountId, 'user_id' => $walletUserId]);
        $validAccount = $accountStatement->fetchColumn() !== false;

        $validCategory = $categoryId === null
            || ($isDefaultCategory && in_array($defaultCategory, $defaultCategories, true));
        if ($categoryId !== null && $categoryId !== false) {
            $categoryStatement = $pdo->prepare(
                'SELECT id FROM categories
                 WHERE id = :id AND category_type = \'income\'
                    AND (user_id = :user_id OR user_id IS NULL)'
            );
            $categoryStatement->execute(['id' => $categoryId, 'user_id' => $walletUserId]);
            $validCategory = $categoryStatement->fetchColumn() !== false;
        }

        $invalid = $accountId === false
            || !$validAccount
            || $categoryValue === ''
            || ($categoryValue !== '' && ($categoryId === false || !$validCategory))
            || ($isOtherCategory && ($customCategory === '' || strlen($customCategory) > 80))
            || ($isDefaultCategory && !in_array($defaultCategory, $defaultCategories, true))
            || !wallet_valid_amount($amount)
            || strlen($description) > 255
            || !wallet_valid_date($transactionDate)
            || ($action === 'update' && $transactionId === false);

        if ($invalid) {
            wallet_set_flash('error', 'Check the income details and try again.');
        } else {
            if ($isOtherCategory || $isDefaultCategory) {
                $categoryId = wallet_get_or_create_category(
                    $pdo,
                    $walletUserId,
                    'income',
                    $isOtherCategory ? $customCategory : $defaultCategory
                );
            }
            $parameters = [
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'amount' => $amount,
                'description' => $description === '' ? null : $description,
                'transaction_date' => $transactionDate . ' 12:00:00',
                'user_id' => $walletUserId,
            ];

            if ($action === 'create') {
                $statement = $pdo->prepare(
                    'INSERT INTO transactions
                        (user_id, wallet_id, category, transaction_type, amount, description, transaction_date)
                     VALUES
                        (:user_id, :account_id, :category_id, \'income\', :amount, :description, :transaction_date)'
                );
                $statement->execute($parameters);
                wallet_set_flash('success', 'Income recorded.');
            } else {
                $parameters['id'] = $transactionId;
                $statement = $pdo->prepare(
                    'UPDATE transactions
                     SET wallet_id = :account_id,
                        category = :category_id,
                        amount = :amount,
                        description = :description,
                        transaction_date = :transaction_date
                     WHERE id = :id AND user_id = :user_id AND transaction_type = \'income\''
                );
                $statement->execute($parameters);
                wallet_set_flash('success', 'Income updated.');
            }
        }
    } elseif ($action === 'delete' && $transactionId !== false) {
        $statement = $pdo->prepare(
            'DELETE FROM transactions
             WHERE id = :id AND user_id = :user_id AND transaction_type = \'income\''
        );
        $statement->execute(['id' => $transactionId, 'user_id' => $walletUserId]);
        wallet_set_flash('success', 'Income deleted.');
    } else {
        wallet_set_flash('error', 'That income action could not be completed.');
    }

    header('Location: income.php');
    exit;
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editIncome = null;
if ($editId !== false && $editId !== null) {
    $editStatement = $pdo->prepare(
        'SELECT id, wallet_id AS account_id, category AS category_id, amount, description, DATE(transaction_date) AS transaction_date
         FROM transactions
         WHERE id = :id AND user_id = :user_id AND transaction_type = \'income\''
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

$categoryStatement = $pdo->prepare(
    'SELECT id, name FROM categories
     WHERE category_type = \'income\' AND (user_id = :user_id OR user_id IS NULL)
     ORDER BY user_id IS NULL, name'
);
$categoryStatement->execute(['user_id' => $walletUserId]);
$categories = $categoryStatement->fetchAll();
$existingCategoryNames = array_map(
    static fn (array $category): string => strtolower((string) $category['name']),
    $categories
);

$incomeStatement = $pdo->prepare(
    'SELECT t.id, t.amount, t.description, t.transaction_date, t.category AS category_id,
            a.name AS account_name, a.currency_code, a.is_archived,
            COALESCE(c.name, \'Uncategorized\') AS category_name
     FROM transactions t
     INNER JOIN wallets a ON a.id = t.wallet_id AND a.user_id = t.user_id
     LEFT JOIN categories c
        ON c.id = t.category AND (c.user_id = t.user_id OR c.user_id IS NULL)
     WHERE t.user_id = :user_id AND t.transaction_type = \'income\'
     ORDER BY t.transaction_date DESC, t.id DESC
     LIMIT 100'
);
$incomeStatement->execute(['user_id' => $walletUserId]);
$incomeEntries = $incomeStatement->fetchAll();

$appTitle = 'Income';
$activePage = 'income';
require __DIR__ . '/includes/app_shell_start.php';
?>
<section class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <p class="text-sm font-medium text-emerald-800">Money coming in</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Income</h1>
        <p class="mt-2 text-sm text-slate-500">Record and review income added to your wallets.</p>
    </div>
    <span class="w-fit rounded-full bg-white px-3 py-1.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200"><?= count($incomeEntries) ?> records</span>
</section>

<?php if ($accounts === []): ?>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-8 text-center shadow-sm">
        <h2 class="font-semibold">Create an active wallet first</h2>
        <p class="mt-2 text-sm text-slate-500">Income is recorded directly into one of your wallets.</p>
        <a href="wallet.php" class="mt-5 inline-flex rounded-xl bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#0d3024]">Go to Create wallet</a>
    </section>
<?php else: ?>
    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.7fr)]">
        <section class="space-y-4">
            <?php if ($incomeEntries === []): ?>
                <div class="rounded-2xl border border-slate-200/80 bg-white px-5 py-10 text-center shadow-sm">
                    <h2 class="font-semibold">No income recorded yet</h2>
                    <p class="mt-2 text-sm text-slate-500">Your income entries will appear here after you add one.</p>
                </div>
            <?php endif; ?>
            <?php foreach ($incomeEntries as $entry): ?>
                <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30 sm:p-6">
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

        <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30 sm:p-6">
            <h2 class="font-semibold tracking-tight"><?= $editIncome !== null ? 'Edit income' : 'Add income' ?></h2>
            <p class="mt-1 text-sm text-slate-500">Choose the wallet, amount, and date for this income.</p>
            <form method="post" action="income.php" class="mt-5 space-y-4" <?= $editIncome === null ? 'data-confirm-title="Add this income?" data-confirm-description="This income will be recorded in the selected wallet." data-confirm-label="Add income"' : '' ?>>
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
                <div>
                    <label for="amount" class="mb-1.5 block text-sm font-medium text-slate-700">Amount</label>
                    <input id="amount" name="amount" type="number" min="0.01" step="0.01" required value="<?= wallet_escape((string) ($editIncome['amount'] ?? '')) ?>" placeholder="0.00" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                </div>
                <div class="space-y-3" data-category-control>
                    <div>
                        <label for="income_category_id" class="mb-1.5 block text-sm font-medium text-slate-700">Category</label>
                        <select id="income_category_id" name="category_id" required data-custom-category-select class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                            <option value="" disabled <?= empty($editIncome['category_id']) ? 'selected' : '' ?>>Select a category</option>
                            <?php foreach ($defaultCategories as $categoryName): ?>
                                <?php if (!in_array(strtolower($categoryName), $existingCategoryNames, true)): ?>
                                    <option value="default:<?= wallet_escape($categoryName) ?>"><?= wallet_escape($categoryName) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>" <?= (int) ($editIncome['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= wallet_escape((string) $category['name']) ?></option>
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
                    <label for="transaction_date" class="mb-1.5 block text-sm font-medium text-slate-700">Date</label>
                    <input id="transaction_date" name="transaction_date" type="date" required value="<?= wallet_escape((string) ($editIncome['transaction_date'] ?? $today->format('Y-m-d'))) ?>" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                </div>
                <div>
                    <label for="description" class="mb-1.5 block text-sm font-medium text-slate-700">Description <span class="font-normal text-slate-400">optional</span></label>
                    <input id="description" name="description" type="text" maxlength="255" value="<?= wallet_escape((string) ($editIncome['description'] ?? '')) ?>" placeholder="Salary, freelance work..." class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                </div>
                <button type="submit" class="w-full rounded-xl bg-[#123a2c] px-4 py-3 text-sm font-semibold text-white transition hover:bg-[#0d3024]">
                    <?= $editIncome !== null ? 'Save changes' : 'Add income' ?>
                </button>
                <?php if ($editIncome !== null): ?>
                    <a href="income.php" class="block text-center text-sm font-medium text-slate-500 hover:text-slate-800">Cancel</a>
                <?php endif; ?>
            </form>
        </section>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/app_shell_end.php'; ?>
