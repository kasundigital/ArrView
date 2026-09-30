<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireLogin();

$webhookInstances = [];
if (($currentUser['role'] ?? '') === 'admin') {
    $webhookInstances = $pdo->query("SELECT id,name,type,webhook_token FROM instances WHERE enabled=1 ORDER BY type,name")->fetchAll();
}
$forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
$scheme = in_array($forwardedProto, ['http','https'], true)
    ? $forwardedProto
    : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
$host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$baseAppUrl = $scheme . '://' . $host;
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
        <?php if($currentUser['role']==='admin'):?><a href="/admin.php">Admin</a><a href="/system.php">System</a><?php endif;?>
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


    <section class="panel webhook-guide">
        <div class="panel-heading-inline">
            <div><p class="eyebrow">AUTOMATIC SYNC</p><h2>How to add the ArrView webhook</h2></div>
            <span class="instance-count">Radarr + Sonarr</span>
        </div>
        <p class="muted">The webhook keeps ArrView updated immediately when media is imported, upgraded, added, or deleted. You only need to configure it once for each Radarr/Sonarr instance.</p>

        <?php if(($currentUser['role'] ?? '') === 'admin' && $webhookInstances):?>
        <div class="webhook-instance-guide-list">
            <?php foreach($webhookInstances as $wi):
                $hook=$baseAppUrl.'/webhook.php?instance='.(int)$wi['id'].'&token='.rawurlencode((string)$wi['webhook_token']);
            ?>
            <article class="webhook-instance-guide">
                <div>
                    <span class="type-pill <?=e($wi['type'])?>"><?=e(ucfirst($wi['type']))?></span>
                    <strong><?=e($wi['name'])?></strong>
                </div>
                <div class="webhook-url-row">
                    <code><?=e($hook)?></code>
                    <button type="button" class="copy-webhook-btn" data-url="<?=e($hook)?>">Copy</button>
                </div>
            </article>
            <?php endforeach;?>
        </div>
        <?php elseif(($currentUser['role'] ?? '') === 'admin'):?>
        <div class="metadata-empty"><strong>No enabled instances yet.</strong><p>Add Radarr or Sonarr in Admin first, then come back here for the generated webhook URL.</p></div>
        <?php endif;?>

        <div class="webhook-steps-grid">
            <article class="support-card webhook-step-card">
                <h3>1. Open Connect settings</h3>
                <p>In Radarr or Sonarr go to <b>Settings → Connect</b>, click <b>+</b>, then choose <b>Webhook</b>.</p>
            </article>
            <article class="support-card webhook-step-card">
                <h3>2. Paste the ArrView URL</h3>
                <p>Copy the generated webhook URL above (or from Admin → Instances) and paste it into the Webhook URL field.</p>
            </article>
            <article class="support-card webhook-step-card">
                <h3>3. Enable the right triggers</h3>
                <p>You do not need every event. Use the checklist below so ArrView refreshes only when library state changes.</p>
            </article>
        </div>

        <div class="webhook-trigger-columns">
            <div class="webhook-trigger-box">
                <h3>Radarr triggers</h3>
                <label><input type="checkbox" checked disabled> On File Import</label>
                <label><input type="checkbox" checked disabled> On File Upgrade</label>
                <label><input type="checkbox" checked disabled> On Movie Added</label>
                <label><input type="checkbox" checked disabled> On Movie Delete</label>
                <label><input type="checkbox" checked disabled> On Movie File Delete</label>
                <label><input type="checkbox" checked disabled> On Movie File Delete For Upgrade</label>
                <p class="meta">Optional: On Rename. On Grab is not required for ArrView library refresh.</p>
            </div>
            <div class="webhook-trigger-box">
                <h3>Sonarr triggers</h3>
                <label><input type="checkbox" checked disabled> On File Import</label>
                <label><input type="checkbox" checked disabled> On File Upgrade</label>
                <label><input type="checkbox" checked disabled> On Series Add</label>
                <label><input type="checkbox" checked disabled> On Series Delete</label>
                <label><input type="checkbox" checked disabled> On Episode File Delete</label>
                <label><input type="checkbox" checked disabled> On Episode File Delete For Upgrade</label>
                <p class="meta">Optional: On Rename. Health/update notifications are not required for ArrView sync.</p>
            </div>
        </div>

        <div class="notice info webhook-save-note">After saving the Webhook in Radarr/Sonarr, use its built-in <b>Test</b> button. A successful test confirms ArrView can receive notifications.</div>
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
<script>
document.querySelectorAll('.copy-webhook-btn').forEach(button=>{
  button.addEventListener('click',async()=>{
    const original=button.textContent;
    try{await navigator.clipboard.writeText(button.dataset.url);button.textContent='Copied';}
    catch(e){button.textContent='Copy failed';}
    setTimeout(()=>button.textContent=original,1500);
  });
});
</script>
</body>
</html>
