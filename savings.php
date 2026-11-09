<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$pdo = getDatabaseConnection();
$currencies = wallet_currency_options($pdo, $walletUserId);
$statuses = ['active', 'paused', 'completed', 'cancelled'];
$today = new DateTimeImmutable('today');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wallet_require_valid_post();
    $action = wallet_post_string('action');
    try {
        $goalId = filter_var($_POST['goal_id'] ?? null, FILTER_VALIDATE_INT);
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
        }
    } catch (PDOException $error) {
        wallet_set_flash('error', wallet_procedure_error($error));
    }
    header('Location: savings.php');
    exit;
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editGoal = null;
if ($editId !== false && $editId !== null) {
    $editStatement = $pdo->prepare(
        'SELECT id, name, description, target_amount, currency_code, target_date
         FROM savings_goals WHERE id = :id AND user_id = :user_id'
    );
    $editStatement->execute(['id' => $editId, 'user_id' => $walletUserId]);
    $editGoal = $editStatement->fetch() ?: null;
}

$goalStatement = $pdo->prepare(
    'SELECT * FROM vw_savings_goal_progress WHERE user_id = :user_id
     ORDER BY FIELD(status, \'active\', \'paused\', \'completed\', \'cancelled\'), target_date IS NULL, target_date'
);
$goalStatement->execute(['user_id' => $walletUserId]);
$goals = $goalStatement->fetchAll();
foreach ($goals as &$goal) {
    $target = (float) $goal['target_amount'];
    $saved = (float) $goal['saved'];
    $goal['prediction_date'] = null;
    $goal['prediction_monthly_rate'] = null;
    $goal['target_plan_monthly_rate'] = null;
    $goal['target_plan_date'] = null;

    if ($saved >= $target) {
        $goal['prediction_status'] = 'complete';
    } elseif ($goal['status'] !== 'active') {
        $goal['prediction_status'] = 'paused';
    } elseif ((int) $goal['contribution_count'] >= 2 && $goal['first_contribution_date'] !== null) {
        $firstContributionDate = new DateTimeImmutable((string) $goal['first_contribution_date']);
        $elapsedDays = (int) $firstContributionDate->diff($today)->days + 1;
        if ($elapsedDays >= 30) {
            $dailyRate = $saved / $elapsedDays;
            $daysRemaining = (int) ceil(($target - $saved) / $dailyRate);
            $goal['prediction_date'] = $today->modify('+' . $daysRemaining . ' days');
            $goal['prediction_monthly_rate'] = $dailyRate * 30.4375;
            $goal['prediction_status'] = 'estimated';
        } else {
            $goal['prediction_status'] = 'target_plan';
        }
    } else {
        $goal['prediction_status'] = 'target_plan';
    }

    if ($goal['prediction_status'] === 'target_plan' && $goal['target_date'] !== null) {
        $targetDate = new DateTimeImmutable((string) $goal['target_date']);
        $daysUntilTarget = (int) $today->diff($targetDate)->days;
        if ($targetDate > $today && $daysUntilTarget > 0) {
            $goal['target_plan_date'] = $targetDate;
            $goal['target_plan_monthly_rate'] = (($target - $saved) / $daysUntilTarget) * 30.4375;
        }
    }
}
unset($goal);

$viewAchieved = ($_GET['view'] ?? '') === 'achieved';
$activeGoals = array_filter($goals, static fn (array $g): bool => $g['status'] !== 'completed');
$achievedGoals = array_filter($goals, static fn (array $g): bool => $g['status'] === 'completed');
$displayGoals = $viewAchieved ? $achievedGoals : $activeGoals;

$accountStatement = $pdo->prepare(
    'SELECT id, name, currency_code
     FROM wallets
     WHERE user_id = :user_id AND is_archived = 0
     ORDER BY name'
);
$accountStatement->execute(['user_id' => $walletUserId]);
$accounts = $accountStatement->fetchAll();

$contributionStatement = $pdo->prepare(
    'SELECT sc.savings_goal_id, sc.amount, sc.contribution_date, sc.note, a.name AS account_name
     FROM savings_contributions sc
     INNER JOIN savings_goals g ON g.id = sc.savings_goal_id AND g.user_id = :user_id
     LEFT JOIN wallets a ON a.id = sc.wallet_id AND a.user_id = :account_user_id
     ORDER BY sc.contribution_date DESC, sc.id DESC
     LIMIT 100'
);
$contributionStatement->execute(['user_id' => $walletUserId, 'account_user_id' => $walletUserId]);
$contributionsByGoal = [];
foreach ($contributionStatement->fetchAll() as $contribution) {
    $contributionsByGoal[$contribution['savings_goal_id']][] = $contribution;
}

$appTitle = 'Savings';
$activePage = 'savings';
require __DIR__ . '/includes/app_shell_start.php';
?>
<section class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
    <div>
        <p class="text-sm font-medium text-emerald-800">Keep moving forward</p>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Savings</h1>
        <p class="mt-2 text-sm text-slate-500">Give your plans a target, then celebrate every contribution.</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="savings.php" class="rounded-lg px-3 py-1.5 text-sm font-medium transition <?= !$viewAchieved ? 'bg-[#123a2c] text-white' : 'text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50' ?>">
            Active <span class="ml-1 <?= !$viewAchieved ? 'text-emerald-300' : 'text-slate-400' ?>"><?= count($activeGoals) ?></span>
        </a>
        <a href="savings.php?view=achieved" class="rounded-lg px-3 py-1.5 text-sm font-medium transition <?= $viewAchieved ? 'bg-[#123a2c] text-white' : 'text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50' ?>">
            Achieved <span class="ml-1 <?= $viewAchieved ? 'text-emerald-300' : 'text-slate-400' ?>"><?= count($achievedGoals) ?></span>
        </a>
    </div>
</section>

<?php if ($currencies === []): ?>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-8 text-center shadow-sm">
        <h2 class="font-semibold">Create your wallet first</h2>
        <p class="mt-2 text-sm text-slate-500">Savings goals use the currency of your wallet.</p>
        <a href="wallet.php" class="mt-5 inline-flex rounded-xl bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#0d3024]">Go to Create wallet</a>
    </section>
<?php else: ?>
    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.7fr)]">
        <section class="space-y-4">
            <?php if ($displayGoals === []): ?>
                <div class="rounded-2xl border border-slate-200/80 bg-white px-5 py-10 text-center shadow-sm">
                    <?php if ($viewAchieved): ?>
                        <span class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>
                        </span>
                        <h2 class="font-semibold">No achieved goals yet</h2>
                        <p class="mt-2 text-sm text-slate-500">When you complete a savings goal, it will appear here.</p>
                        <a href="savings.php" class="mt-5 inline-flex rounded-xl bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#0d3024]">View active goals</a>
                    <?php else: ?>
                        <h2 class="font-semibold">Start with a goal</h2>
                        <p class="mt-2 text-sm text-slate-500">Plan a trip, build an emergency fund, or save for something you love.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php foreach ($displayGoals as $goal): ?>
                <?php
                $target = (float) $goal['target_amount'];
                $saved = (float) $goal['saved'];
                $progress = $target > 0 ? min(100, (int) round($saved / $target * 100)) : 0;
                $predictionStatus = (string) $goal['prediction_status'];
                $goalContributions = array_slice($contributionsByGoal[$goal['id']] ?? [], 0, 3);
                ?>
                <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30 sm:p-6">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-semibold"><?= wallet_escape((string) $goal['name']) ?></h2>
                                <span class="rounded-full px-2.5 py-1 text-[11px] font-medium capitalize <?= $goal['status'] === 'active' ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600' ?>"><?= wallet_escape((string) $goal['status']) ?></span>
                            </div>
                            <?php if ($goal['description'] !== null && $goal['description'] !== ''): ?>
                                <p class="mt-1 text-sm text-slate-500"><?= wallet_escape((string) $goal['description']) ?></p>
                            <?php endif; ?>
                            <p class="mt-1 text-xs text-slate-400"><?= $goal['target_date'] !== null ? 'Target date ' . wallet_escape(date('M j, Y', strtotime((string) $goal['target_date']))) : 'No target date' ?></p>
                        </div>
                        <div class="relative flex items-center gap-2">
                            <a href="savings.php?edit=<?= (int) $goal['id'] ?>" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100">Edit</a>
                            <?php if (in_array($goal['status'], ['active', 'paused'], true)): ?>
                                <form method="post" action="savings.php">
                                    <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $goal['status'] === 'active' ? 'paused' : 'active' ?>">
                                    <button type="submit" class="rounded-lg px-3 py-2 text-xs font-medium text-slate-500 hover:bg-slate-100"><?= $goal['status'] === 'active' ? 'Pause' : 'Resume' ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="savings.php" data-confirm-title="Delete this savings goal?" data-confirm-description="This goal and all of its contribution history will be permanently deleted." data-confirm-label="Delete goal" data-confirm-variant="danger">
                                <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                <button type="submit" class="rounded-lg px-3 py-2 text-xs font-medium text-rose-600 hover:bg-rose-50">Delete</button>
                            </form>
                        </div>
                    </div>
                    <div class="mt-5 flex items-center justify-between gap-3 text-sm">
                        <span class="font-medium text-slate-700"><?= wallet_escape((string) $goal['currency_code']) ?> <?= number_format($saved, 2) ?> saved</span>
                        <span class="text-slate-500">of <?= wallet_escape((string) $goal['currency_code']) ?> <?= number_format($target, 2) ?></span>
                    </div>
                    <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full <?= $goal['status'] === 'completed' ? 'bg-sky-500' : 'bg-emerald-500' ?>" style="width: <?= $progress ?>%"></div>
                    </div>
                    <p class="mt-2 text-xs text-slate-400"><?= $progress ?>% complete</p>
                    <div class="mt-3 rounded-xl bg-emerald-50 px-3.5 py-3 text-sm text-emerald-900">
                        <p class="font-medium">Savings prediction</p>
                        <?php if ($predictionStatus === 'complete'): ?>
                            <p class="mt-1 text-xs text-emerald-800">You’ve reached this goal.</p>
                        <?php elseif ($predictionStatus === 'estimated'): ?>
                            <p class="mt-1 text-xs text-emerald-800">
                                At your current pace of <?= wallet_escape((string) $goal['currency_code']) ?> <?= number_format((float) $goal['prediction_monthly_rate'], 2) ?> per month, you may reach it by <?= wallet_escape($goal['prediction_date']->format('M j, Y')) ?>.
                            </p>
                        <?php elseif ($predictionStatus === 'target_plan' && $goal['target_plan_date'] !== null): ?>
                            <p class="mt-1 text-xs text-emerald-800">
                                To reach your target by <?= wallet_escape($goal['target_plan_date']->format('M j, Y')) ?>, save about <?= wallet_escape((string) $goal['currency_code']) ?> <?= number_format((float) $goal['target_plan_monthly_rate'], 2) ?> per month. A pace-based completion estimate will be available after contributions span 30 days.
                            </p>
                        <?php elseif ($predictionStatus === 'target_plan' && $goal['target_date'] !== null): ?>
                            <p class="mt-1 text-xs text-emerald-800">Your target date has passed. Update it to see a monthly savings plan.</p>
                        <?php elseif ($predictionStatus === 'paused'): ?>
                            <p class="mt-1 text-xs text-emerald-800">Resume this goal to continue its prediction.</p>
                        <?php else: ?>
                            <p class="mt-1 text-xs text-emerald-800">Add contributions on different dates, spanning at least 30 days, to get a pace-based estimate.</p>
                        <?php endif; ?>
                    </div>
                    <?php if ($goalContributions !== []): ?>
                        <div class="mt-5 border-t border-slate-100 pt-4">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Recent contributions</p>
                            <div class="space-y-2">
                                <?php foreach ($goalContributions as $contribution): ?>
                                    <div class="flex items-center justify-between gap-3 text-xs">
                                        <span class="truncate text-slate-500">
                                            <?= wallet_escape((string) ($contribution['note'] ?: ($contribution['account_name'] ?: 'Contribution'))) ?>
                                            · <?= wallet_escape(date('M j, Y', strtotime((string) $contribution['contribution_date']))) ?>
                                        </span>
                                        <span class="shrink-0 font-semibold text-slate-700"><?= wallet_escape((string) $goal['currency_code']) ?> <?= number_format((float) $contribution['amount'], 2) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($goal['status'] === 'active'): ?>
                        <div class="mt-5 flex justify-end">
                            <details class="relative">
                                <summary class="flex w-fit cursor-pointer list-none items-center gap-2 rounded-xl bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#0d3024] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 [&::-webkit-details-marker]:hidden">
                                    Add contribution
                                </summary>
                                <form method="post" action="savings.php" class="absolute bottom-full right-0 z-20 mb-2 grid w-[min(32rem,calc(100vw-3rem))] gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xl sm:grid-cols-[1fr_1.2fr_auto] sm:items-end" data-confirm-title="Add this savings contribution?" data-confirm-description="This contribution will be recorded toward your savings goal." data-confirm-label="Add contribution">
                                    <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="contribute">
                                    <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                    <div>
                                        <label for="amount-<?= (int) $goal['id'] ?>" class="mb-1.5 block text-xs font-medium text-slate-600">Amount</label>
                                        <input id="amount-<?= (int) $goal['id'] ?>" name="amount" type="number" min="0.01" step="0.01" required placeholder="0.00" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-emerald-600">
                                    </div>
                                    <div>
                                        <label for="account-<?= (int) $goal['id'] ?>" class="mb-1.5 block text-xs font-medium text-slate-600">Associated account <span class="font-normal text-slate-400">optional</span></label>
                                        <select id="account-<?= (int) $goal['id'] ?>" name="account_id" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-emerald-600">
                                            <option value="">Not specified</option>
                                            <?php foreach ($accounts as $account): ?>
                                                <?php if ($account['currency_code'] === $goal['currency_code']): ?>
                                                    <option value="<?= (int) $account['id'] ?>"><?= wallet_escape((string) $account['name']) ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="rounded-lg bg-[#123a2c] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#0d3024]">Add</button>
                                    <div class="sm:col-span-3">
                                        <label for="note-<?= (int) $goal['id'] ?>" class="sr-only">Contribution note</label>
                                        <input id="note-<?= (int) $goal['id'] ?>" name="note" type="text" maxlength="255" placeholder="Optional note" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-emerald-600">
                                    </div>
                                    <p class="text-xs leading-5 text-slate-500 sm:col-span-3">Contributions track goal progress only; they don’t create a bank transaction or change your account balance.</p>
                                </form>
                            </details>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/30 sm:p-6">
            <h2 class="font-semibold tracking-tight"><?= $editGoal !== null ? 'Edit savings goal' : 'Create a goal' ?></h2>
            <p class="mt-1 text-sm text-slate-500">Set a target and keep track of each contribution.</p>
            <form method="post" action="savings.php" class="mt-5 space-y-4" <?= $editGoal === null ? 'data-confirm-title="Create this savings goal?" data-confirm-description="The goal will be added to your savings tracker." data-confirm-label="Create goal"' : '' ?>>
                <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $editGoal !== null ? 'update' : 'create' ?>">
                <?php if ($editGoal !== null): ?><input type="hidden" name="goal_id" value="<?= (int) $editGoal['id'] ?>"><?php endif; ?>
                <div>
                    <label for="name" class="mb-1.5 block text-sm font-medium text-slate-700">Goal name</label>
                    <input id="name" name="name" maxlength="120" required value="<?= wallet_escape((string) ($editGoal['name'] ?? '')) ?>" placeholder="Emergency fund" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10">
                </div>
                <div>
                    <label for="description" class="mb-1.5 block text-sm font-medium text-slate-700">Description <span class="font-normal text-slate-400">optional</span></label>
                    <textarea id="description" name="description" rows="3" maxlength="2000" placeholder="What are you saving for?" class="w-full resize-y rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10"><?= wallet_escape((string) ($editGoal['description'] ?? '')) ?></textarea>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="target_amount" class="mb-1.5 block text-sm font-medium text-slate-700">Target amount</label>
                        <input id="target_amount" name="target_amount" type="number" min="0.01" step="0.01" required value="<?= wallet_escape((string) ($editGoal['target_amount'] ?? '')) ?>" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                    </div>
                    <div>
                        <label for="currency_code" class="mb-1.5 block text-sm font-medium text-slate-700">Currency</label>
                        <select id="currency_code" name="currency_code" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                            <?php foreach ($currencies as $currency): ?>
                                <option value="<?= wallet_escape((string) $currency) ?>" <?= ($editGoal['currency_code'] ?? $currencies[0]) === $currency ? 'selected' : '' ?>><?= wallet_escape((string) $currency) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="target_date" class="mb-1.5 block text-sm font-medium text-slate-700">Target date</label>
                    <input id="target_date" name="target_date" type="date" required value="<?= wallet_escape((string) ($editGoal['target_date'] ?? '')) ?>" class="w-full rounded-xl border border-slate-200 px-3.5 py-3 text-sm outline-none focus:border-emerald-600">
                </div>
                <button type="submit" class="w-full rounded-xl bg-[#123a2c] px-4 py-3 text-sm font-semibold text-white transition hover:bg-[#0d3024]"><?= $editGoal !== null ? 'Save changes' : 'Create savings goal' ?></button>
                <?php if ($editGoal !== null): ?><a href="savings.php" class="block text-center text-sm font-medium text-slate-500 hover:text-slate-800">Cancel</a><?php endif; ?>
            </form>
        </section>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/app_shell_end.php'; ?>
