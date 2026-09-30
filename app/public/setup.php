<?php
require_once dirname(__DIR__) . '/bootstrap.php';
if ($auth->hasUsers()) redirect('/login.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $auth->requireCsrf($_POST['csrf_token'] ?? null);
        $setupToken = new SetupToken($dataDir);
        if (!$setupToken->verify($_POST['setup_token'] ?? null)) {
            throw new RuntimeException('Invalid setup token. Find it in the container logs (docker logs arrview) or in the setup-token.txt file in the data volume.');
        }
        $password = $_POST['password'] ?? '';
        if ($password !== ($_POST['confirm_password'] ?? '')) throw new RuntimeException('Passwords do not match.');

        // Serialise first-admin creation so two concurrent submissions can't both succeed.
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            if ($auth->hasUsers()) {
                $pdo->exec('ROLLBACK');
                $open = false;
                redirect('/login.php');
            }
            $auth->createUser($_POST['username'] ?? '', $password, 'admin');
            $pdo->exec('COMMIT');
            $open = false;
        } finally {
            if ($open) { try { $pdo->exec('ROLLBACK'); } catch (Throwable) {} }
        }
        $setupToken->clear();
        $auth->login($_POST['username'] ?? '', $password);
        redirect('/admin.php');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup · ArrView</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<main class="auth-shell"><section class="auth-card"><p class="eyebrow">FIRST-TIME SETUP</p><h1>Create administrator</h1><p class="muted">Create the first ArrView admin account. There is no default password. Enter the one-time setup token shown in the container logs (<code>docker logs arrview</code>).</p>
<?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="auth-form"><?=csrf_field()?><label>Setup token<input name="setup_token" required autocomplete="off" spellcheck="false" placeholder="From docker logs arrview"></label><label>Admin username<input name="username" required minlength="3" autocomplete="username"></label><label>Password<input name="password" type="password" required minlength="8" autocomplete="new-password"></label><label>Confirm password<input name="confirm_password" type="password" required minlength="8" autocomplete="new-password"></label><button class="primary" type="submit">Create Admin</button></form></section></main></body></html>
