<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireLogin();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Support ArrView · v<?=e(ARRVIEW_VERSION)?></title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">ArrView <small class="version-chip">v<?=e(ARRVIEW_VERSION)?></small></a>
    <nav>
        <a href="/?type=movies">Movies</a>
        <a href="/?type=series">Series</a>
        <a class="active" href="/support.php">Support</a>
        <?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><?php endif;?>
        <span class="user-chip"><?=e($currentUser['username'])?></span>
        <a href="/logout.php">Logout</a>
    </nav>
</header>

<main class="wrap support-wrap">
    <section class="support-hero">
        <p class="eyebrow">SUPPORT ARRVIEW</p>
        <h1>Enjoying ArrView?</h1>
        <p>ArrView is free and open source. If it saves you time or helps troubleshoot your Radarr/Sonarr library, you can support continued development.</p>
        <div class="support-actions">
            <a class="support-primary" href="https://buymeacoffee.com/kasundigital" target="_blank" rel="noopener noreferrer">☕ Buy Me a Coffee</a>
            <a class="support-secondary" href="https://github.com/kasundigital/ArrView" target="_blank" rel="noopener noreferrer">⭐ Star on GitHub</a>
        </div>
    </section>

    <section class="support-grid">
        <article class="support-card">
            <h2>☕ Donate</h2>
            <p>A small donation helps with development, testing, hosting, and time spent improving ArrView.</p>
            <a href="https://buymeacoffee.com/kasundigital" target="_blank" rel="noopener noreferrer">buymeacoffee.com/kasundigital</a>
        </article>
        <article class="support-card">
            <h2>⭐ Star the project</h2>
            <p>A GitHub star costs nothing and helps other Radarr and Sonarr users discover ArrView.</p>
            <a href="https://github.com/kasundigital/ArrView" target="_blank" rel="noopener noreferrer">github.com/kasundigital/ArrView</a>
        </article>
        <article class="support-card">
            <h2>🐛 Help improve it</h2>
            <p>Bug reports, feature ideas, testing feedback, documentation improvements, and pull requests are all valuable.</p>
            <a href="https://github.com/kasundigital/ArrView/issues" target="_blank" rel="noopener noreferrer">Open an issue</a>
        </article>
    </section>

    <p class="muted center support-note">Thank you for using ArrView. Support is always optional — the app remains free and open source.</p>
</main>

<footer>ArrView v<?=e(ARRVIEW_VERSION)?> · Built by Kasun Indika</footer>
</body>
</html>
