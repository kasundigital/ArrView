<?php
require_once dirname(__DIR__) . '/bootstrap.php';
if (!$auth->hasUsers()) redirect('/setup.php');
if ($auth->user()) redirect('/');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($auth->login($_POST['username'] ?? '', $_POST['password'] ?? '')) redirect('/');
    $error = 'Invalid username or password.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login · ArrView</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<main class="auth-shell"><section class="auth-card"><a class="brand auth-brand" href="/">ArrView</a><p class="eyebrow">SIGN IN</p><h1>Welcome back</h1><p class="muted">Sign in to browse your ArrView library.</p>
<?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="auth-form"><label>Username<input name="username" required autocomplete="username" autofocus></label><label>Password<input name="password" type="password" required autocomplete="current-password"></label><button class="primary" type="submit">Sign In</button></form></section></main></body></html>
