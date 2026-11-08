<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

wallet_start_session();
wallet_redirect_authenticated_user();

$name = '';
$email = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = wallet_post_string('name');
    $email = strtolower(wallet_post_string('email'));
    $password = $_POST['password'] ?? '';
    $passwordConfirmation = $_POST['password_confirmation'] ?? '';

    if (!wallet_has_valid_csrf_token()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($name === '' || !preg_match('/\A.{1,120}\z/us', $name)) {
        $error = 'Please enter a name of 1 to 120 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $error = 'Please enter a valid email address.';
    } elseif (!is_string($password) || strlen($password) < 8) {
        $error = 'Your password must be at least 8 characters.';
    } elseif (strlen($password) > 72) {
        $error = 'Your password must be no more than 72 bytes.';
    } elseif (!is_string($passwordConfirmation) || $password !== $passwordConfirmation) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $statement = getDatabaseConnection()->prepare(
                'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            header('Location: login.php?registered=1');
            exit;
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            $error = 'An account with that email already exists.';
        }
    }
}

$pageTitle = 'Create your account';
$pageDescription = 'Start building a clearer picture of your money.';
require __DIR__ . '/includes/auth_header.php';
?>
<?php if ($error !== ''): ?>
    <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">
        <?= wallet_escape($error) ?>
    </div>
<?php endif; ?>

<form method="post" action="register.php" class="mt-8 space-y-5">
    <input type="hidden" name="csrf_token" value="<?= wallet_escape(wallet_csrf_token()) ?>">

    <div>
        <label for="name" class="mb-2 block text-sm font-medium text-slate-700">Full name</label>
        <input
            id="name"
            name="name"
            type="text"
            autocomplete="name"
            maxlength="120"
            required
            value="<?= wallet_escape($name) ?>"
            placeholder="Alex Morgan"
            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10"
        >
    </div>

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
            autocomplete="new-password"
            minlength="8"
            maxlength="72"
            required
            placeholder="At least 8 characters"
            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10"
        >
    </div>

    <div>
        <label for="password_confirmation" class="mb-2 block text-sm font-medium text-slate-700">Confirm password</label>
        <input
            id="password_confirmation"
            name="password_confirmation"
            type="password"
            autocomplete="new-password"
            minlength="8"
            maxlength="72"
            required
            placeholder="Enter your password again"
            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/10"
        >
    </div>

    <button
        type="submit"
        class="flex w-full items-center justify-center rounded-xl bg-[#123a2c] px-4 py-3.5 text-sm font-semibold text-white transition hover:bg-[#0d3024] focus:outline-none focus:ring-4 focus:ring-emerald-700/20"
    >
        Create account
        <span aria-hidden="true" class="ml-2">&rarr;</span>
    </button>
</form>

<p class="mt-8 text-center text-sm text-slate-500">
    Already have an account?
    <a href="login.php" class="font-semibold text-emerald-800 hover:text-emerald-950">Sign in</a>
</p>
<?php require __DIR__ . '/includes/auth_footer.php'; ?>
