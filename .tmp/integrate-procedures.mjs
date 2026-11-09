import {readFile,writeFile} from 'node:fs/promises';
const read=async path=>(await readFile(path,'utf8')).replaceAll('\r\n','\n');
const block=(source,start,end,replacement)=>{const a=source.indexOf(start),b=source.indexOf(end,a);if(a<0||b<0)throw new Error('Missing block: '+start);return source.slice(0,a)+replacement+source.slice(b);};
const post=(body,redirect)=>`if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wallet_require_valid_post();
    $action = wallet_post_string('action');
    try {
${body}
    } catch (PDOException $error) {
        wallet_set_flash('error', wallet_procedure_error($error));
    }
    header('Location: ${redirect}');
    exit;
}

`;
const category=(field,optional=false)=>`        $categoryValue = wallet_post_string('${field}');
        $category = ${optional?"$categoryValue === '' ? null : ":''}trim($categoryValue === 'other'
            ? wallet_post_string('custom_category')
            : (str_starts_with($categoryValue, 'category:') ? substr($categoryValue, 9) : ''));
`;
let source=await read('income.php');
source=block(source,"if ($_SERVER['REQUEST_METHOD'] === 'POST') {",'$editId =',post(`        $transactionId = filter_var($_POST['transaction_id'] ?? null, FILTER_VALIDATE_INT);
        if (in_array($action, ['create', 'update'], true)) {
            $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
${category('category')}
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
        }`,'income.php'));
source=block(source,'$incomeStatement = $pdo->prepare(','$appTitle =',`$incomeEntries = $pdo->prepare(
    'SELECT id, amount, description, transaction_date, category, account_name, currency_code, is_archived, category_name
     FROM vw_transaction_details WHERE user_id = :user_id AND amount > 0
     ORDER BY transaction_date DESC, id DESC LIMIT 100'
);
$incomeEntries->execute(['user_id' => $walletUserId]);
$incomeEntries = $incomeEntries->fetchAll();

`);
await writeFile('income.php',source);

source=await read('wallet.php');
source=block(source,"if ($_SERVER['REQUEST_METHOD'] === 'POST') {",'$editId =',post(`        $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
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
        }`,"wallet.php' . ($action === 'restore' ? '?view=archived' : '') . '"));
await writeFile('wallet.php',source);

source=await read('savings.php');
source=block(source,"if ($_SERVER['REQUEST_METHOD'] === 'POST') {",'$editId =',post(`        $goalId = filter_var($_POST['goal_id'] ?? null, FILTER_VALIDATE_INT);
        if (in_array($action, ['create', 'update'], true)) {
            $name = wallet_post_string('name');
            $description = wallet_post_string('description');
            $targetAmount = wallet_post_string('target_amount');
            $currency = strtoupper(wallet_post_string('currency_code'));
            $targetDate = wallet_post_string('target_date');
            if ($name === '' || strlen($name) > 120 || strlen($description) > 65535
                || !wallet_valid_amount($targetAmount) || !in_array($currency, $currencies, true)
                || ($action === 'create' && !wallet_valid_date($targetDate))
                || ($targetDate !== '' && !wallet_valid_date($targetDate))
                || ($action === 'update' && $goalId === false)) {
                wallet_set_flash('error', 'Check the goal details and try again.');
            } else {
                wallet_call_procedure($pdo, 'sp_save_savings_goal', [
                    $walletUserId, $action === 'create' ? null : $goalId, $name, $description === '' ? null : $description,
                    $targetAmount, $currency, $targetDate === '' ? null : $targetDate,
                ]);
                wallet_set_flash('success', $action === 'create' ? 'Savings goal created.' : 'Savings goal updated.');
            }
        } elseif ($action === 'status' && $goalId !== false) {
            $status = wallet_post_string('status');
            if (!in_array($status, $statuses, true)) {
                wallet_set_flash('error', 'Choose a valid goal status.');
            } else {
                wallet_call_procedure($pdo, 'sp_set_savings_goal_status', [$walletUserId, $goalId, $status]);
                wallet_set_flash('success', 'Goal status updated.');
            }
        } elseif ($action === 'delete' && $goalId !== false) {
            wallet_call_procedure($pdo, 'sp_delete_savings_goal', [$walletUserId, $goalId]);
            wallet_set_flash('success', 'Savings goal and its contributions deleted.');
        } elseif ($action === 'contribute' && $goalId !== false) {
            $amount = wallet_post_string('amount');
            $note = wallet_post_string('note');
            $accountValue = wallet_post_string('account_id');
            $accountId = $accountValue === '' ? null : filter_var($accountValue, FILTER_VALIDATE_INT);
            if (!wallet_valid_amount($amount) || strlen($note) > 255 || $accountId === false) {
                wallet_set_flash('error', 'Enter a valid contribution and choose a matching, active account.');
            } else {
                $result = wallet_call_procedure($pdo, 'sp_contribute_savings', [
                    $walletUserId, $goalId, $accountId, $amount, date('Y-m-d H:i:s'), $note === '' ? null : $note,
                ]);
                wallet_set_flash('success', ($result[0]['status'] ?? '') === 'completed'
                    ? 'Contribution recorded. Goal target reached!' : 'Contribution recorded.');
            }
        } else {
            wallet_set_flash('error', 'That goal action could not be completed.');
        }`,'savings.php'));
source=block(source,'$goalStatement = $pdo->prepare(','foreach ($goals as &$goal)',`$goalStatement = $pdo->prepare(
    'SELECT * FROM vw_savings_goal_progress WHERE user_id = :user_id
     ORDER BY FIELD(status, \\'active\\', \\'paused\\', \\'completed\\', \\'cancelled\\'), target_date IS NULL, target_date'
);
$goalStatement->execute(['user_id' => $walletUserId]);
$goals = $goalStatement->fetchAll();
`);
await writeFile('savings.php',source);

source=await read('expenses.php');
source=block(source,"if ($_SERVER['REQUEST_METHOD'] === 'POST') {",'$editExpenseId =',post(`        $budgetId = filter_var($_POST['budget_id'] ?? null, FILTER_VALIDATE_INT);
        if (in_array($action, ['add_expense', 'update_expense'], true)) {
            $expenseId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT);
            $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
${category('expense_category')}
            $amount = wallet_post_string('expense_amount');
            $description = wallet_post_string('expense_description');
            $expenseDate = wallet_post_string('expense_date');
            $nature = wallet_post_string('expense_nature');
            if ($accountId === false || !wallet_valid_category($category) || !wallet_valid_amount($amount)
                || strlen($description) > 255 || !wallet_valid_date($expenseDate)
                || !is_string($_POST['expense_nature'] ?? '') || !array_key_exists($nature, $expenseNatureOptions)
                || ($action === 'update_expense' && $expenseId === false)) {
                wallet_set_flash('error', 'Check the expense details and try again.');
            } else {
                wallet_call_procedure($pdo, 'sp_save_transaction', [
                    $walletUserId, $action === 'add_expense' ? null : $expenseId, $accountId, $category,
                    '-' . $amount, $description === '' ? null : $description, $expenseDate . ' 12:00:00',
                    $nature === '' ? null : $nature,
                ]);
                wallet_set_flash('success', $action === 'add_expense' ? 'Expense added to your wallet.' : 'Expense updated.');
            }
        } elseif ($action === 'delete_expense') {
            $expenseId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT);
            if ($expenseId === false) {
                wallet_set_flash('error', 'That expense could not be found.');
            } else {
                wallet_call_procedure($pdo, 'sp_delete_transaction', [$walletUserId, $expenseId, 'expense']);
                wallet_set_flash('success', 'Expense deleted.');
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
            $existingLimit = null;
            if ($action === 'update' && $budgetId !== false) {
                $query = $pdo->prepare('SELECT is_recurring,end_date FROM expense_limits WHERE id=:id AND user_id=:user_id');
                $query->execute(['id' => $budgetId, 'user_id' => $walletUserId]);
                $existingLimit = $query->fetch() ?: null;
            }
            if ($isRecurring) {
                $endDate = $existingLimit !== null && (bool) $existingLimit['is_recurring'] && $existingLimit['end_date'] !== null
                    ? (string) $existingLimit['end_date'] : '';
            }
${category('category',true)}
            $invalid = $name === '' || strlen($name) > 120 || !wallet_valid_amount($amount)
                || !in_array($currency, $currencies, true) || !in_array($period, $periods, true)
                || !wallet_valid_date($startDate)
                || ($endDate !== '' && (!wallet_valid_date($endDate) || $endDate < $startDate))
                || (!$isRecurring && $endDate !== '' && wallet_valid_date($startDate) && wallet_valid_date($endDate)
                    && new DateTimeImmutable($endDate) < wallet_expense_limit_minimum_end_date($period, new DateTimeImmutable($startDate)))
                || ($recurringValue !== null && $recurringValue !== '1')
                || !is_string($_POST['category'] ?? '') || ($category !== null && !wallet_valid_category($category))
                || ($action === 'update' && ($budgetId === false || $existingLimit === null));
            if ($invalid) {
                wallet_set_flash('error', 'Check the limit details. An end date must include at least one full selected period after the start date.');
            } else {
                wallet_call_procedure($pdo, 'sp_save_expense_limit', [
                    $walletUserId, $action === 'create' ? null : $budgetId, $name, $category, $amount, $currency,
                    $period, $startDate, $endDate === '' ? null : $endDate, $isRecurring ? 1 : 0,
                ]);
                wallet_set_flash('success', $action === 'create' ? 'Budget created.' : 'Budget updated.');
            }
        } elseif (in_array($action, ['stop_recurring', 'resume_recurring'], true) && $budgetId !== false) {
            wallet_call_procedure($pdo, 'sp_set_expense_limit_recurring', [
                $walletUserId, $budgetId, $action === 'resume_recurring' ? 1 : 0, $today->format('Y-m-d'),
            ]);
            wallet_set_flash('success', $action === 'stop_recurring' ? 'Recurring expense limit stopped.' : 'Recurring expense limit resumed.');
        } elseif ($action === 'delete' && $budgetId !== false) {
            wallet_call_procedure($pdo, 'sp_delete_expense_limit', [$walletUserId, $budgetId]);
            wallet_set_flash('success', 'Budget deleted.');
        } else {
            wallet_set_flash('error', 'That budget action could not be completed.');
        }`,'expenses.php'));
source=block(source,'$expenseEntriesStatement = $pdo->prepare(','$budgets = wallet_load_expense_limits',`$expenseEntriesStatement = $pdo->prepare(
    'SELECT id,-amount AS amount,description,transaction_date,expense_nature,account_name,currency_code,is_archived,category_name
     FROM vw_transaction_details WHERE user_id=:user_id AND amount<0
     ORDER BY transaction_date DESC,id DESC LIMIT 100'
);
$expenseEntriesStatement->execute(['user_id' => $walletUserId]);
$expenseEntries = $expenseEntriesStatement->fetchAll();

`);
await writeFile('expenses.php',source);

source=await read('includes/app.php');
source=source.replace("require_once __DIR__ . '/auth.php';","require_once __DIR__ . '/auth.php';\nrequire_once __DIR__ . '/database_procedures.php';");
source=block(source,'function wallet_apply_transaction_to_current_balance(','function wallet_require_valid_post()', '');
await writeFile('includes/app.php',source);

source=await read('includes/expense_limits.php');
source=source.replace('declare(strict_types=1);','declare(strict_types=1);\n\nrequire_once __DIR__ . \'/database_procedures.php\';');
source=block(source,'/** Load every limit','function wallet_overspending_alerts',`/** SQL owns recurrence, ownership, category/currency scope and period spending. */
function wallet_load_expense_limits(PDO $pdo, int $userId, DateTimeImmutable $today): array
{
    $limits = wallet_call_procedure($pdo, 'sp_get_expense_limits', [$userId, $today->format('Y-m-d')]);
    foreach ($limits as &$limit) {
        $limit['range_active'] = (bool) $limit['range_active'];
        $limit['limit_cents'] = wallet_expense_limit_cents((string) $limit['amount']);
        $limit['spent_cents'] = wallet_expense_limit_cents((string) $limit['spent']);
        $limit['spent'] = $limit['spent_cents'] / 100;
        $limit['over_cents'] = max(0, $limit['spent_cents'] - $limit['limit_cents']);
    }
    unset($limit);
    return $limits;
}

`);
await writeFile('includes/expense_limits.php',source);

source=await read('includes/spending_analysis.php');
source=source.replace('declare(strict_types=1);','declare(strict_types=1);\n\nrequire_once __DIR__ . \'/database_procedures.php\';');
source=block(source,'    $statement = $pdo->prepare(','\n}\n\nfunction wallet_spending_amount_cents',`    $rows = wallet_call_procedure($pdo, 'sp_get_spending_rows', [
        $userId, $currency, $month->modify('-5 months')->format('Y-m-d'), $periodEnd->format('Y-m-d'),
    ]);
    return wallet_build_spending_analysis($rows, $month, $today);`);
await writeFile('includes/spending_analysis.php',source);

source=await read('index.php');
source=block(source,'    $monthlyStatement = $pdo->prepare(','    $activityByDate =',`    $monthlyTotals = wallet_call_procedure($pdo, 'sp_get_monthly_summary', [$userId, $currency, $monthStart])[0];
    $balance = (float) $monthlyTotals['balance'];
    $monthlyIncome = (float) $monthlyTotals['income'];
    $monthlyExpenses = (float) $monthlyTotals['expenses'];
    $activityRows = wallet_call_procedure($pdo, 'sp_get_cash_flow', [
        $userId, $currency, $cashFlowStart, $today->modify('+1 day')->format('Y-m-d'),
    ]);
`);
source=source.replace('foreach ($activityStatement->fetchAll() as $row)', 'foreach ($activityRows as $row)');
source=block(source,'    $categoryStatement = $pdo->prepare(','    $budgets = array_values',`    $expensesByCategory = array_slice(wallet_call_procedure($pdo, 'sp_get_category_spending', [$userId, $currency, $monthStart]), 0, 4);

`);
source=block(source,'    $goalStatement = $pdo->prepare(','    $recentStatement = $pdo->prepare(',`    $goalStatement = $pdo->prepare(
        'SELECT id,name,target_amount,target_date,currency_code,saved FROM vw_savings_goal_progress
         WHERE user_id=:user_id AND currency_code=:currency AND status=\\'active\\'
         ORDER BY target_date IS NULL,target_date LIMIT 3'
    );
    $goalStatement->execute(['user_id' => $userId, 'currency' => $currency]);
    $goals = $goalStatement->fetchAll();

`);
source=block(source,'    $recentStatement = $pdo->prepare(','    $recentTransactions = $recentStatement->fetchAll();',`    $recentStatement = $pdo->prepare(
        'SELECT id,amount,description,transaction_date,account_name,category_name FROM vw_transaction_details
         WHERE user_id=:user_id AND currency_code=:currency AND is_archived=0
         ORDER BY transaction_date DESC,id DESC LIMIT 6'
    );
    $recentStatement->execute(['user_id' => $userId, 'currency' => $currency]);
`);
await writeFile('index.php',source);
console.log('Integrated procedures and views into application routes.');
