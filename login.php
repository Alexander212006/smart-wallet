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
$pageDescription = 'Sign in to pick up where you left off.';
require __DIR__ . '/includes/auth_header.php';
?>
<?php if (isset($_GET['registered']) && $_GET['registered'] === '1'): ?>
    <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
        Your account is ready. Sign in to continue.
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">
        <?= wallet_escape($error) ?>
    </div>
<?php endif; ?>

<form method="post" action="login.php" class="mt-8 space-y-5">
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
        <input
            id="password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            placeholder="Enter your password"
            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10"
        >
    </div>

    <button
        type="submit"
        class="flex w-full items-center justify-center rounded-xl bg-[#123a2c] px-4 py-3.5 text-sm font-semibold text-white transition hover:bg-[#0d3024] focus:outline-none focus:ring-4 focus:ring-emerald-700/20"
    >
        Sign in
        <span aria-hidden="true" class="ml-2">&rarr;</span>
    </button>
</form>

<p class="mt-8 text-center text-sm text-slate-500">
    New to Smart Wallet?
    <a href="register.php" class="font-semibold text-emerald-800 hover:text-emerald-950">Create an account</a>
</p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
