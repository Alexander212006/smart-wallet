<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

wallet_start_session();
wallet_redirect_authenticated_user();

$email = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(wallet_post_string('email'));
    $password = $_POST['password'] ?? '';

    if (!wallet_has_valid_csrf_token()) {
        $error = 'Your session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password)) {
        $error = 'The email or password you entered is incorrect.';
    } else {
        $statement = getDatabaseConnection()->prepare(
            'SELECT id, name, email, password_hash FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        if ($user === false || !password_verify($password, $user['password_hash'])) {
            $error = 'The email or password you entered is incorrect.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int) $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
            ];

            header('Location: index.php');
            exit;
        }
    }
}

$pageTitle = 'Welcome back';
$pageDescription = 'Sign in to your personal workspace.';
require __DIR__ . '/includes/auth_header.php';
?>
<?php if (isset($_GET['registered']) && $_GET['registered'] === '1'): ?>
    <div class="ui-inline-alert" data-variant="success" role="status"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="m8 12 3 3 5-6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg><p>Your account is ready. Sign in to continue.</p></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="ui-inline-alert" data-variant="error" role="alert"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 8v5m0 3h.01" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg><p><?= wallet_escape($error) ?></p></div>
<?php endif; ?>

<form method="post" action="login.php" class="space-y-5">
    <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">

    <div>
        <label for="email" class="mb-2 block text-sm font-medium text-slate-700">Email address</label>
        <input
            id="email"
            name="email"
            type="email"
            autocomplete="email"
            maxlength="255"
            required
            value="<?= wallet_escape($email) ?>"
            placeholder="you@example.com"
            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10"
        >
    </div>

    <div>
        <label for="password" class="mb-2 block text-sm font-medium text-slate-700">Password</label>
        <div class="ui-password">
        <input
            id="password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            placeholder="Enter your password"
            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10"
        >
            <button type="button" data-toggle-password="password" aria-controls="password" aria-pressed="false" aria-label="Show password">Show</button>
        </div>
    </div>

    <button
        type="submit"
        class="ui-auth-submit flex w-full items-center justify-center bg-[#123a2c] px-4 py-3.5 text-sm font-semibold text-white transition hover:bg-[#0d3024] focus:outline-none focus:ring-4 focus:ring-emerald-700/20"
    >
        Sign in
        <span aria-hidden="true" class="ml-2">&rarr;</span>
    </button>
</form>

<?php require __DIR__ . '/includes/auth_footer.php'; ?>
