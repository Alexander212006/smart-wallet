<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$pdo = getDatabaseConnection();
$periods = ['weekly', 'monthly', 'yearly'];
$defaultCategories = ['Food & dining', 'Transportation', 'Housing', 'Utilities', 'Healthcare', 'Shopping', 'Entertainment', 'Education', 'Travel'];
$currencies = wallet_currency_options($pdo, $walletUserId);
$today = new DateTimeImmutable('today');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wallet_require_valid_post();

    $action = wallet_post_string('action');
    $budgetId = filter_var($_POST['budget_id'] ?? null, FILTER_VALIDATE_INT);

    if (in_array($action, ['add_expense', 'update_expense'], true)) {
        $expenseId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT);
        $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
        $categoryValue = wallet_post_string('expense_category_id');
        $customCategory = trim(wallet_post_string('custom_category'));
        $isOtherCategory = $categoryValue === 'other';
        $isDefaultCategory = substr($categoryValue, 0, 8) === 'default:';
        $defaultCategory = $isDefaultCategory ? substr($categoryValue, 8) : '';
        $categoryId = $categoryValue === '' || $isOtherCategory || $isDefaultCategory
            ? null
            : filter_var($categoryValue, FILTER_VALIDATE_INT);
        $amount = wallet_post_string('expense_amount');
        $description = wallet_post_string('expense_description');
        $expenseDate = wallet_post_string('expense_date');

        $validAccount = false;
        if ($accountId !== false) {
            $accountStatement = $pdo->prepare(
                'SELECT id FROM wallets WHERE id = :id AND user_id = :user_id AND is_archived = 0'
            );
            $accountStatement->execute(['id' => $accountId, 'user_id' => $walletUserId]);
            $validAccount = $accountStatement->fetchColumn() !== false;
        }

        $validCategory = $categoryId === null
            || ($isDefaultCategory && in_array($defaultCategory, $defaultCategories, true));
        if ($categoryId !== null && $categoryId !== false) {
            $validCategory = wallet_owned_expense_category($pdo, $walletUserId, (int) $categoryId);
        }

        $existingExpense = true;
        if ($action === 'update_expense' && $expenseId !== false) {
            $existingExpenseStatement = $pdo->prepare(
                'SELECT id FROM transactions
                 WHERE id = :id AND user_id = :user_id AND transaction_type = \'expense\''
            );
            $existingExpenseStatement->execute(['id' => $expenseId, 'user_id' => $walletUserId]);
            $existingExpense = $existingExpenseStatement->fetchColumn() !== false;
        }

        $invalid = !$validAccount
            || $categoryValue === ''
            || ($categoryValue !== '' && ($categoryId === false || !$validCategory))
            || ($isOtherCategory && ($customCategory === '' || strlen($customCategory) > 80))
            || ($isDefaultCategory && !in_array($defaultCategory, $defaultCategories, true))
            || !wallet_valid_amount($amount)
            || strlen($description) > 255
            || !wallet_valid_date($expenseDate)
            || ($action === 'update_expense' && ($expenseId === false || !$existingExpense));

        if ($invalid) {
            wallet_set_flash('error', 'Check the expense details and try again.');
        } else {
            if ($isOtherCategory || $isDefaultCategory) {
                $categoryId = wallet_get_or_create_category(
                    $pdo,
                    $walletUserId,
                    'expense',
                    $isOtherCategory ? $customCategory : $defaultCategory
                );
            }
            $parameters = [
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'amount' => $amount,
                'description' => $description === '' ? null : $description,
                'transaction_date' => $expenseDate . ' 12:00:00',
                'user_id' => $walletUserId,
            ];

            if ($action === 'add_expense') {
                $statement = $pdo->prepare(
                    'INSERT INTO transactions
                        (user_id, wallet_id, category, transaction_type, amount, description, transaction_date)
                     VALUES
                        (:user_id, :account_id, :category_id, \'expense\', :amount, :description, :transaction_date)'
                );
                $statement->execute($parameters);
                wallet_set_flash('success', 'Expense added to your wallet.');
            } else {
                $parameters['id'] = $expenseId;
                $statement = $pdo->prepare(
                    'UPDATE transactions
                     SET wallet_id = :account_id,
                        category = :category_id,
                        amount = :amount,
                        description = :description,
                        transaction_date = :transaction_date
                     WHERE id = :id AND user_id = :user_id AND transaction_type = \'expense\''
                );
                $statement->execute($parameters);
                wallet_set_flash('success', 'Expense updated.');
            }
        }
    } elseif ($action === 'delete_expense') {
        $expenseId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT);
        if ($expenseId === false) {
            wallet_set_flash('error', 'That expense could not be found.');
        } else {
            $statement = $pdo->prepare(
                'DELETE FROM transactions
                 WHERE id = :id AND user_id = :user_id AND transaction_type = \'expense\''
            );
            $statement->execute(['id' => $expenseId, 'user_id' => $walletUserId]);
            $deleted = $statement->rowCount() > 0;
            wallet_set_flash(
                $deleted ? 'success' : 'error',
                $deleted ? 'Expense deleted.' : 'That expense could not be found.'
            );
        }
    } elseif (in_array($action, ['create', 'update'], true)) {
        $name = wallet_post_string('name');
        $amount = wallet_post_string('amount');
        $currency = strtoupper(wallet_post_string('currency_code'));
        $period = wallet_post_string('period');
        $startDate = wallet_post_string('start_date');
        $endDate = wallet_post_string('end_date');
        $recurringValue = $_POST['is_recurring'] ?? null;
        $isRecurring = $recurringValue === '1';
        $categoryValue = wallet_post_string('category_id');
        $isDefaultCategory = substr($categoryValue, 0, 8) === 'default:';
        $defaultCategory = $isDefaultCategory ? substr($categoryValue, 8) : '';
        $categoryId = $categoryValue === '' || $isDefaultCategory
            ? null
            : filter_var($categoryValue, FILTER_VALIDATE_INT);
        $invalid = $name === ''
            || strlen($name) > 120
            || !wallet_valid_amount($amount)
            || !wallet_valid_currency($currency)
            || !in_array($currency, $currencies, true)
            || !in_array($period, $periods, true)
            || !wallet_valid_date($startDate)
            || ($endDate !== '' && (!wallet_valid_date($endDate) || $endDate < $startDate))
            || ($recurringValue !== null && $recurringValue !== '1')
            || ($isDefaultCategory && !in_array($defaultCategory, $defaultCategories, true))
            || ($categoryValue !== '' && !$isDefaultCategory
                && ($categoryId === false || !wallet_owned_expense_category($pdo, $walletUserId, (int) $categoryId)))
            || ($action === 'update' && $budgetId === false);

        if ($invalid) {
            wallet_set_flash('error', 'Check the budget details and try again. Category must be an expense category you can use.');
        } else {
            if ($isDefaultCategory) {
                $categoryId = wallet_get_or_create_category($pdo, $walletUserId, 'expense', $defaultCategory);
            }

            if ($action === 'create') {
                $statement = $pdo->prepare(
                    'INSERT INTO expense_limits
                        (user_id, category_id, name, amount, currency_code, period, start_date, end_date, is_recurring)
                     VALUES
                        (:user_id, :category_id, :name, :amount, :currency_code, :period, :start_date, :end_date, :is_recurring)'
                );
                $statement->execute([
                    'user_id' => $walletUserId,
                    'category_id' => $categoryId,
                    'name' => $name,
                    'amount' => $amount,
                    'currency_code' => $currency,
                    'period' => $period,
                    'start_date' => $startDate,
                    'end_date' => $endDate === '' ? null : $endDate,
                    'is_recurring' => $isRecurring ? 1 : 0,
                ]);
                wallet_set_flash('success', 'Budget created.');
            } else {
                $statement = $pdo->prepare(
                    'UPDATE expense_limits
                     SET category_id = :category_id,
                        name = :name,
                        amount = :amount,
                        currency_code = :currency_code,
                        period = :period,
                        start_date = :start_date,
                        end_date = :end_date,
                        is_recurring = :is_recurring
                     WHERE id = :id AND user_id = :user_id'
                );
                $statement->execute([
                    'category_id' => $categoryId,
                    'name' => $name,
                    'amount' => $amount,
                    'currency_code' => $currency,
                    'period' => $period,
                    'start_date' => $startDate,
                    'end_date' => $endDate === '' ? null : $endDate,
                    'is_recurring' => $isRecurring ? 1 : 0,
                    'id' => $budgetId,
                    'user_id' => $walletUserId,
                ]);
                wallet_set_flash('success', 'Budget updated.');
            }
        }
    } elseif ($action === 'delete' && $budgetId !== false) {
        $statement = $pdo->prepare('DELETE FROM expense_limits WHERE id = :id AND user_id = :user_id');
        $statement->execute(['id' => $budgetId, 'user_id' => $walletUserId]);
        wallet_set_flash('success', 'Budget deleted.');
    } else {
        wallet_set_flash('error', 'That budget action could not be completed.');
    }

    header('Location: expenses.php');
    exit;
}

$editExpenseId = filter_input(INPUT_GET, 'edit_expense', FILTER_VALIDATE_INT);
$editExpense = null;
if ($editExpenseId !== false && $editExpenseId !== null) {
    $editExpenseStatement = $pdo->prepare(
        'SELECT id, wallet_id AS account_id, category AS category_id, amount, description, DATE(transaction_date) AS expense_date
         FROM transactions
         WHERE id = :id AND user_id = :user_id AND transaction_type = \'expense\''
    );
    $editExpenseStatement->execute(['id' => $editExpenseId, 'user_id' => $walletUserId]);
    $editExpense = $editExpenseStatement->fetch() ?: null;
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editBudget = null;
if ($editId !== false && $editId !== null) {
    $editStatement = $pdo->prepare('SELECT * FROM expense_limits WHERE id = :id AND user_id = :user_id');
    $editStatement->execute(['id' => $editId, 'user_id' => $walletUserId]);
    $editBudget = $editStatement->fetch() ?: null;
}

$categoryStatement = $pdo->prepare(
    'SELECT id, name FROM categories
     WHERE category_type = \'expense\' AND (user_id = :user_id OR user_id IS NULL)
     ORDER BY user_id IS NULL, name'
);
$categoryStatement->execute(['user_id' => $walletUserId]);
$categories = $categoryStatement->fetchAll();
$existingCategoryNames = array_map(
    static fn (array $category): string => strtolower((string) $category['name']),
    $categories
);

$accountStatement = $pdo->prepare(
    'SELECT id, name, currency_code
     FROM wallets
     WHERE user_id = :user_id AND is_archived = 0
     ORDER BY name'
);
$accountStatement->execute(['user_id' => $walletUserId]);
$activeAccounts = $accountStatement->fetchAll();

$expenseEntriesStatement = $pdo->prepare(
    'SELECT t.id, t.amount, t.description, t.transaction_date,
            a.name AS account_name, a.currency_code, a.is_archived,
            COALESCE(c.name, \'Uncategorized\') AS category_name
     FROM transactions t
     INNER JOIN wallets a ON a.id = t.wallet_id AND a.user_id = t.user_id
     LEFT JOIN categories c
        ON c.id = t.category AND (c.user_id = t.user_id OR c.user_id IS NULL)
     WHERE t.user_id = :user_id AND t.transaction_type = \'expense\'
     ORDER BY t.transaction_date DESC, t.id DESC
     LIMIT 100'
);
$expenseEntriesStatement->execute(['user_id' => $walletUserId]);
$expenseEntries = $expenseEntriesStatement->fetchAll();

$budgetStatement = $pdo->prepare(
    'SELECT b.id, b.name, b.amount, b.currency_code, b.period, b.start_date, b.end_date, b.is_recurring, b.category_id,
            COALESCE(c.name, \'All categories\') AS category_name
     FROM expense_limits b
     LEFT JOIN categories c ON c.id = b.category_id
     WHERE b.user_id = :user_id
     ORDER BY b.start_date DESC, b.name'
);
$budgetStatement->execute(['user_id' => $walletUserId]);
$budgets = $budgetStatement->fetchAll();

$expenseStatement = $pdo->prepare(
    'SELECT COALESCE(SUM(t.amount), 0)
     FROM transactions t
     INNER JOIN wallets a ON a.id = t.wallet_id
     WHERE t.user_id = :user_id
        AND a.user_id = :account_user_id
        AND a.currency_code = :currency
        AND a.is_archived = 0
        AND t.transaction_type = \'expense\'
        AND t.transaction_date >= :range_start
        AND t.transaction_date < :range_end
        AND (:include_all_categories = 1 OR t.category = :category_id)'
);

foreach ($budgets as &$budget) {
    $period = (string) $budget['period'];
    $activeStart = new DateTimeImmutable((string) $budget['start_date']);
    $activeEnd = $budget['end_date'] !== null
        ? new DateTimeImmutable((string) $budget['end_date'])
        : null;
    $range = wallet_expense_limit_date_range(
        $period,
        $activeStart,
        $today,
        (bool) $budget['is_recurring'],
        $activeEnd
    );

    $budget['spent'] = 0.0;
    $budget['range_active'] = $range !== null;
    if ($range !== null) {
        $expenseStatement->execute([
            'user_id' => $walletUserId,
            'account_user_id' => $walletUserId,
            'currency' => $budget['currency_code'],
            'range_start' => $range['start']->format('Y-m-d'),
            'range_end' => $range['end']->format('Y-m-d'),
            'include_all_categories' => $budget['category_id'] === null ? 1 : 0,
            'category_id' => $budget['category_id'],
        ]);
        $budget['spent'] = (float) $expenseStatement->fetchColumn();
    }
}
unset($budget);

$defaultStart = $today->format('Y-m-d');
$appTitle = 'Expenses';
$activePage = 'expenses';
require __DIR__ . '/includes/app_shell_start.php';
?>
<section class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <p class="text-sm font-medium text-emerald-800">Stay in control</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Expenses</h1>
        <p class="mt-2 text-sm text-slate-500">Record expenses to a wallet and manage limits for each period.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($activeAccounts !== []): ?>
            <button type="button" data-open-expense-dialog="expense" class="inline-flex items-center gap-2 rounded-xl bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#0d3024] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">
                <span aria-hidden="true">+</span><?= $editExpense !== null ? 'Edit expense' : 'Add expense' ?>
            </button>
        <?php endif; ?>
        <?php if ($currencies !== []): ?>
            <button type="button" data-open-expense-dialog="limit" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">
                <?= $editBudget !== null ? 'Edit limit' : 'Create expense limit' ?>
            </button>
        <?php endif; ?>
    </div>
</section>

<?php if ($currencies === []): ?>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-8 text-center shadow-sm">
        <h2 class="font-semibold">Create your wallet first</h2>
        <p class="mt-2 text-sm text-slate-500">Expense limits use your wallet currencies so totals stay accurate.</p>
        <a href="wallet.php" class="mt-5 inline-flex rounded-xl bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#0d3024]">Go to Create wallet</a>
    </section>
<?php else: ?>
    <?php if ($activeAccounts === []): ?>
        <section class="rounded-2xl border border-slate-200/80 bg-white p-8 text-center shadow-sm">
            <h2 class="font-semibold">Create an active wallet to add expenses</h2>
            <p class="mt-2 text-sm text-slate-500">Expenses are recorded directly to a wallet, so archived wallets cannot be selected.</p>
            <a href="wallet.php" class="mt-5 inline-flex rounded-xl bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#0d3024]">Go to Create wallet</a>
        </section>
    <?php else: ?>
            <section class="space-y-4">
                <div>
                    <h2 class="font-semibold tracking-tight">Recent expenses</h2>
                    <div class="mt-1 flex items-center justify-between gap-4">
                        <p class="text-sm text-slate-500">Expenses are grouped by the wallet they affect.</p>
                        <span class="shrink-0 rounded-full bg-white px-3 py-1.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200"><?= count($expenseEntries) ?> expenses</span>
                    </div>
                </div>
                <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm shadow-slate-200/30">
                    <?php if ($expenseEntries === []): ?>
                    <div class="px-5 py-10 text-center">
                        <h3 class="font-semibold">No expenses recorded yet</h3>
                        <p class="mt-2 text-sm text-slate-500">Add your first expense using the form.</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($expenseEntries as $expense): ?>
                            <article class="border-b border-slate-100 px-5 py-4 last:border-b-0">
                                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                    <div class="min-w-0">
                                        <h3 class="truncate text-sm font-semibold"><?= wallet_escape((string) ($expense['description'] ?: $expense['category_name'])) ?></h3>
                                        <p class="mt-1 text-xs text-slate-500">
                                            <?= wallet_escape((string) $expense['account_name']) ?><?= $expense['is_archived'] ? ' · Archived wallet' : '' ?>
                                            <span class="px-1 text-slate-300">·</span>
                                            <?= wallet_escape((string) $expense['category_name']) ?>
                                            <span class="px-1 text-slate-300">·</span>
                                            <?= wallet_escape(date('M j, Y', strtotime((string) $expense['transaction_date']))) ?>
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center justify-between gap-3 sm:justify-end">
                                        <span class="text-sm font-semibold tabular-nums text-rose-600">−<?= wallet_escape((string) $expense['currency_code']) ?> <?= number_format((float) $expense['amount'], 2) ?></span>
                                        <?php if (!$expense['is_archived']): ?>
                                            <a href="expenses.php?edit_expense=<?= (int) $expense['id'] ?>" class="rounded-lg px-2.5 py-2 text-xs font-medium text-slate-600 transition hover:bg-slate-100">Edit</a>
                                        <?php endif; ?>
                                        <form method="post" action="expenses.php" data-confirm-title="Delete this expense?" data-confirm-description="This expense will be permanently removed from your records." data-confirm-label="Delete expense" data-confirm-variant="danger">
                                            <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_expense">
                                            <input type="hidden" name="expense_id" value="<?= (int) $expense['id'] ?>">
                                            <button type="submit" class="rounded-lg px-2.5 py-2 text-xs font-medium text-rose-600 transition hover:bg-rose-50">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
        </section>
    <?php endif; ?>

    <?php if ($currencies !== []): ?>
    <section class="space-y-4">
        <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-end">
            <div>
                <h2 class="font-semibold tracking-tight">Expense limits</h2>
                <p class="mt-1 text-sm text-slate-500">Set a target and track expenses over time.</p>
            </div>
            <span class="w-fit rounded-full bg-white px-3 py-1.5 text-xs font-medium text-slate-600 ring-1 ring-slate-200"><?= count($budgets) ?> limits</span>
        </div>
            <?php if ($budgets === []): ?>
                <div class="rounded-2xl border border-slate-200/80 bg-white px-5 py-10 text-center shadow-sm">
                    <h2 class="font-semibold">No expense limits yet</h2>
                    <p class="mt-2 text-sm text-slate-500">Create an expense limit to set a helpful boundary for your expenses.</p>
                </div>
            <?php endif; ?>
            <?php foreach ($budgets as $budget): ?>
                <?php
                $limit = (float) $budget['amount'];
                $spent = (float) $budget['spent'];
                $progress = $limit > 0 ? min(100, (int) round($spent / $limit * 100)) : 0;
                $isOver = $spent > $limit;
                ?>
                <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30 sm:p-6">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                        <div>
                            <h2 class="font-semibold"><?= wallet_escape((string) $budget['name']) ?></h2>
                            <p class="mt-1 text-sm text-slate-500"><?= wallet_escape((string) $budget['category_name']) ?> · <?= wallet_escape(ucfirst((string) $budget['period'])) ?></p>
                            <p class="mt-1 text-xs text-slate-400">
                                <?= (bool) $budget['is_recurring'] ? 'Repeats ' : 'One-time ' ?><?= wallet_escape((string) $budget['period']) ?>
                                · Starts <?= wallet_escape((string) $budget['start_date']) ?>
                                <?= $budget['end_date'] !== null ? ' · Ends ' . wallet_escape((string) $budget['end_date']) : '' ?>
                                <?= !$budget['range_active'] ? ' · Not currently active' : '' ?>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="expenses.php?edit=<?= (int) $budget['id'] ?>" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100">Edit</a>
                            <form method="post" action="expenses.php" data-confirm-title="Delete this expense limit?" data-confirm-description="This limit will be permanently removed." data-confirm-label="Delete limit" data-confirm-variant="danger">
                                <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="budget_id" value="<?= (int) $budget['id'] ?>">
                                <button type="submit" class="rounded-lg px-3 py-2 text-xs font-medium text-rose-600 hover:bg-rose-50">Delete</button>
                            </form>
                        </div>
                    </div>
                    <div class="mt-5 flex items-center justify-between gap-3 text-sm">
                        <span class="<?= $isOver ? 'font-semibold text-rose-600' : 'font-medium text-slate-700' ?>"><?= wallet_escape((string) $budget['currency_code']) ?> <?= number_format($spent, 2) ?> spent</span>
                        <span class="text-slate-500">of <?= wallet_escape((string) $budget['currency_code']) ?> <?= number_format($limit, 2) ?></span>
                    </div>
                    <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full <?= $isOver ? 'bg-rose-500' : 'bg-emerald-500' ?>" style="width: <?= $progress ?>%"></div>
                    </div>
                    <p class="mt-2 text-xs <?= $isOver ? 'text-rose-600' : 'text-slate-400' ?>"><?= $isOver ? 'You’re over this expense limit.' : number_format($progress) . '% of your limit used' ?></p>
                </article>
            <?php endforeach; ?>
    </section>
    <?php endif; ?>
<?php endif; ?>
    <style>
        @keyframes expense-modal-enter {
            from { opacity: 0; transform: translateY(0.5rem) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes expense-modal-exit {
            to { opacity: 0; transform: translateY(0.25rem) scale(0.98); }
        }
        @keyframes expense-backdrop-enter {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes expense-backdrop-exit {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        dialog[data-expense-modal] {
            position: fixed;
            inset: auto;
            top: var(--expense-modal-top, 50%);
            left: 50%;
            margin: 0;
            translate: -50% -50%;
        }
        dialog[data-expense-modal][open] { animation: expense-modal-enter 160ms ease-out both; }
        dialog[data-expense-modal][open]::backdrop { animation: expense-backdrop-enter 160ms ease-out both; }
        dialog[data-expense-modal][data-closing] { animation: expense-modal-exit 120ms ease-in both; }
        dialog[data-expense-modal][data-closing]::backdrop { animation: expense-backdrop-exit 120ms ease-in both; }
        @media (prefers-reduced-motion: reduce) {
            dialog[data-expense-modal][open],
            dialog[data-expense-modal][open]::backdrop,
            dialog[data-expense-modal][data-closing],
            dialog[data-expense-modal][data-closing]::backdrop {
                animation-duration: 1ms;
            }
        }
    </style>
    <?php if ($activeAccounts !== []): ?>
        <dialog id="expense-modal-expense" data-expense-modal <?= $editExpense !== null ? 'data-open-on-load' : '' ?> aria-labelledby="expense-modal-expense-title" class="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/40">
            <div class="p-5 sm:p-6">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <h2 id="expense-modal-expense-title" class="text-lg font-semibold tracking-tight"><?= $editExpense !== null ? 'Edit expense' : 'Add expense' ?></h2>
                        <p class="mt-1 text-sm text-slate-500">Choose which wallet this expense should affect.</p>
                    </div>
                    <button type="button" data-close-expense-modal aria-label="Close dialog" class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">×</button>
                </div>
                <form method="post" action="expenses.php" class="space-y-4" <?= $editExpense === null ? 'data-confirm-title="Add this expense?" data-confirm-description="This expense will be recorded in the selected wallet." data-confirm-label="Add expense"' : '' ?>>
                    <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editExpense !== null ? 'update_expense' : 'add_expense' ?>">
                    <?php if ($editExpense !== null): ?><input type="hidden" name="expense_id" value="<?= (int) $editExpense['id'] ?>"><?php endif; ?>
                    <div>
                        <label for="expense_account_id" class="mb-1.5 block text-sm font-medium text-slate-700">Wallet</label>
                        <select id="expense_account_id" name="account_id" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                            <?php foreach ($activeAccounts as $account): ?>
                                <option value="<?= (int) $account['id'] ?>" <?= (int) ($editExpense['account_id'] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>>
                                    <?= wallet_escape((string) $account['name']) ?> (<?= wallet_escape((string) $account['currency_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="expense_amount" class="mb-1.5 block text-sm font-medium text-slate-700">Amount</label>
                        <input id="expense_amount" name="expense_amount" type="number" min="0.01" step="0.01" required value="<?= wallet_escape((string) ($editExpense['amount'] ?? '')) ?>" placeholder="0.00" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                    </div>
                    <div class="space-y-3" data-category-control>
                        <div>
                            <label for="expense_category_id" class="mb-1.5 block text-sm font-medium text-slate-700">Category</label>
                            <select id="expense_category_id" name="expense_category_id" required data-custom-category-select class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                                <option value="" disabled <?= empty($editExpense['category_id']) ? 'selected' : '' ?>>Select a category</option>
                                <?php foreach ($defaultCategories as $categoryName): ?>
                                    <?php if (!in_array(strtolower($categoryName), $existingCategoryNames, true)): ?>
                                        <option value="default:<?= wallet_escape($categoryName) ?>"><?= wallet_escape($categoryName) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['id'] ?>" <?= (int) ($editExpense['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= wallet_escape((string) $category['name']) ?></option>
                                <?php endforeach; ?>
                                <option value="other">Other (enter a category)</option>
                            </select>
                        </div>
                        <div data-custom-category-field class="hidden">
                            <label for="expense_custom_category" class="mb-1.5 block text-sm font-medium text-slate-700">Category name</label>
                            <input id="expense_custom_category" name="custom_category" type="text" maxlength="80" data-custom-category-input placeholder="e.g. Pet care" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                        </div>
                    </div>
                    <div>
                        <label for="expense_date" class="mb-1.5 block text-sm font-medium text-slate-700">Date</label>
                        <input id="expense_date" name="expense_date" type="date" required value="<?= wallet_escape((string) ($editExpense['expense_date'] ?? $today->format('Y-m-d'))) ?>" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                    </div>
                    <div>
                        <label for="expense_description" class="mb-1.5 block text-sm font-medium text-slate-700">Description <span class="font-normal text-slate-400">optional</span></label>
                        <input id="expense_description" name="expense_description" type="text" maxlength="255" value="<?= wallet_escape((string) ($editExpense['description'] ?? '')) ?>" placeholder="Groceries, transport..." class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                    </div>
                    <div class="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end">
                        <button type="button" data-close-expense-modal class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="rounded-xl bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#0d3024]">
                            <?= $editExpense !== null ? 'Save changes' : 'Add expense' ?>
                        </button>
                    </div>
                </form>
            </div>
        </dialog>
    <?php endif; ?>
    <?php if ($currencies !== []): ?>
        <dialog id="expense-modal-limit" data-expense-modal <?= $editBudget !== null ? 'data-open-on-load' : '' ?> aria-labelledby="expense-modal-limit-title" class="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/40">
            <div class="p-5 sm:p-6">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <h2 id="expense-modal-limit-title" class="text-lg font-semibold tracking-tight"><?= $editBudget !== null ? 'Edit expense limit' : 'Create expense limit' ?></h2>
                        <p class="mt-1 text-sm text-slate-500">Choose a period, amount, and optional expense category.</p>
                    </div>
                    <button type="button" data-close-expense-modal aria-label="Close dialog" class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">×</button>
                </div>
                <form method="post" action="expenses.php" class="space-y-4" <?= $editBudget === null ? 'data-confirm-title="Create this expense limit?" data-confirm-description="The limit will be added to your expense tracker." data-confirm-label="Create limit"' : '' ?>>
                    <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editBudget !== null ? 'update' : 'create' ?>">
                    <?php if ($editBudget !== null): ?><input type="hidden" name="budget_id" value="<?= (int) $editBudget['id'] ?>"><?php endif; ?>
                    <div>
                        <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700">Expense limit name</label>
                        <input id="name" name="name" maxlength="120" required value="<?= wallet_escape((string) ($editBudget['name'] ?? '')) ?>" placeholder="Monthly essentials" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="amount" class="mb-1.5 block text-sm font-medium text-slate-700">Limit amount</label>
                            <input id="amount" name="amount" type="number" min="0.01" step="0.01" required value="<?= wallet_escape((string) ($editBudget['amount'] ?? '')) ?>" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                        </div>
                        <div>
                            <label for="currency_code" class="mb-1.5 block text-sm font-medium text-slate-700">Currency</label>
                            <select id="currency_code" name="currency_code" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                                <?php foreach ($currencies as $currency): ?>
                                    <option value="<?= wallet_escape((string) $currency) ?>" <?= ($editBudget['currency_code'] ?? $currencies[0]) === $currency ? 'selected' : '' ?>><?= wallet_escape((string) $currency) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="category_id" class="mb-1.5 block text-sm font-medium text-slate-700">Expense category</label>
                        <select id="category_id" name="category_id" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                            <option value="">All categories</option>
                            <?php foreach ($defaultCategories as $categoryName): ?>
                                <?php if (!in_array(strtolower($categoryName), $existingCategoryNames, true)): ?>
                                    <option value="default:<?= wallet_escape($categoryName) ?>"><?= wallet_escape($categoryName) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>" <?= (int) ($editBudget['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>><?= wallet_escape((string) $category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="period" class="mb-1.5 block text-sm font-medium text-slate-700">Period</label>
                        <select id="period" name="period" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm capitalize outline-none focus:border-emerald-600">
                            <?php foreach ($periods as $period): ?>
                                <option value="<?= wallet_escape($period) ?>" <?= ($editBudget['period'] ?? 'monthly') === $period ? 'selected' : '' ?>><?= wallet_escape(ucfirst($period)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="start_date" class="mb-1.5 block text-sm font-medium text-slate-700">Start date</label>
                        <input id="start_date" name="start_date" type="date" required value="<?= wallet_escape((string) ($editBudget['start_date'] ?? $defaultStart)) ?>" class="w-full rounded-xl border border-slate-200 px-3 py-3 text-sm outline-none focus:border-emerald-600">
                    </div>
                    <div>
                        <label for="end_date" class="mb-1.5 block text-sm font-medium text-slate-700">End date <span class="font-normal text-slate-400">optional</span></label>
                        <input id="end_date" name="end_date" type="date" value="<?= wallet_escape((string) ($editBudget['end_date'] ?? '')) ?>" class="w-full rounded-xl border border-slate-200 px-3 py-3 text-sm outline-none focus:border-emerald-600">
                    </div>
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3.5">
                        <input type="checkbox" name="is_recurring" value="1" <?= (bool) ($editBudget['is_recurring'] ?? false) ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                        <span>
                            <span class="block text-sm font-medium text-slate-700">Recurring expense limit</span>
                            <span class="mt-0.5 block text-xs text-slate-500">Repeat this limit every selected period from the start date, until the end date if set.</span>
                        </span>
                    </label>
                    <div class="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end">
                        <button type="button" data-close-expense-modal class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="rounded-xl bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#0d3024]">
                            <?= $editBudget !== null ? 'Save changes' : 'Create expense limit' ?>
                        </button>
                    </div>
                    <?php if ($editBudget !== null): ?><a href="expenses.php" class="block text-center text-sm font-medium text-slate-500 hover:text-slate-800">Cancel editing</a><?php endif; ?>
                </form>
            </div>
        </dialog>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/app_shell_end.php'; ?>
