<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser = $auth->requireAdmin();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        $auth->requireCsrf($_POST['csrf_token'] ?? null);
        if ($action === 'save_metadata') {
            $mode = (string)($_POST['metadata_mode'] ?? 'free');
            $key = trim((string)($_POST['tmdb_api_key'] ?? ''));
            if ($mode === 'personal' && $key === '' && $metadata->settings()['tmdb_api_key'] === '') {
                throw new RuntimeException('Enter a TMDB API key or Bearer token before enabling Personal TMDB mode.');
            }
            $metadata->saveSettings($mode, $key !== '' ? $key : null);
            $message = $mode === 'personal'
                ? 'Personal TMDB metadata mode saved.'
                : 'ArrView Free Metadata mode enabled.';
        } elseif ($action === 'remove_tmdb_key') {
            $metadata->removePersonalKey();
            $message = 'Personal TMDB key removed. ArrView Free Metadata is active.';
        } elseif ($action === 'test_metadata') {
            $result = $metadata->testConnection();
            $message = $result['message'] ?? 'TMDB metadata connection successful.';
        } elseif ($action === 'save_public_link') {
            $localRoot = trim($_POST['public_local_root'] ?? '');
            $baseUrl = trim($_POST['public_base_url'] ?? '');
            if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
                throw new RuntimeException('Public base URL must start with http:// or https://');
            }
            $stmt = $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value');
            $stmt->execute(['public_local_root', rtrim($localRoot, "/\\")]);
            $stmt->execute(['public_base_url', rtrim($baseUrl, '/')]);
            $message = 'Public/VOD link settings saved.';
        } elseif ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? '';
            $url = trim($_POST['url'] ?? '');
            $apiKey = trim($_POST['api_key'] ?? '');
            if ($name === '' || !in_array($type, ['radarr','sonarr'], true) || $url === '' || $apiKey === '') throw new RuntimeException('All fields are required.');
            $stmt = $pdo->prepare('INSERT INTO instances(name,type,url,api_key,webhook_token) VALUES(?,?,?,?,?)');
            $stmt->execute([$name,$type,rtrim($url,'/'),$apiKey,bin2hex(random_bytes(24))]);
            $message = 'Instance added.';
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM instances WHERE id=?'); $stmt->execute([(int)($_POST['id'] ?? 0)]); $message='Instance deleted.';
        } elseif ($action === 'toggle') {
            $stmt = $pdo->prepare('UPDATE instances SET enabled = CASE enabled WHEN 1 THEN 0 ELSE 1 END WHERE id=?'); $stmt->execute([(int)($_POST['id'] ?? 0)]); $message='Instance status updated.';
        } elseif ($action === 'test') {
            $stmt = $pdo->prepare('SELECT * FROM instances WHERE id=?'); $stmt->execute([(int)($_POST['id'] ?? 0)]); $instance=$stmt->fetch();
            if (!$instance) throw new RuntimeException('Instance not found.');
            $result = $arr->test($instance);
            if (!$result['ok']) throw new RuntimeException($result['message']);
            $message = $result['message'];
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$instances = $pdo->query('SELECT * FROM instances ORDER BY type,name')->fetchAll();
$settings = [];
foreach ($pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('public_local_root','public_base_url')")->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$publicLocalRoot = $settings['public_local_root'] ?? '';
$publicBaseUrl = $settings['public_base_url'] ?? '';
$metadataSettings = $metadata->settings();
$metadataStats = $metadata->stats();
$hasPersonalTmdbKey = $metadataSettings['tmdb_api_key'] !== '';
$forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
$scheme = in_array($forwardedProto, ['http','https'], true)
    ? $forwardedProto
    : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
$host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$baseAppUrl = $scheme . '://' . $host;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin · ArrView</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header class="topbar"><a class="brand" href="/">ArrView <small class="version-chip">v<?=e(ARRVIEW_VERSION)?></small></a><nav><a href="/">Library</a><a class="active" href="/admin.php">Instances</a><a href="/users.php">Users</a><a href="/support.php">Support</a><span class="user-chip"><?=e($currentUser['username'])?></span><a href="/logout.php">Logout</a></nav></header>
<main class="wrap admin-wrap"><section class="catalog-head"><div><p class="eyebrow">SETTINGS</p><h1>Instances</h1></div></section>
<?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<section class="panel metadata-panel">
  <div class="panel-heading-inline metadata-panel-head">
    <div><p class="eyebrow">METADATA</p><h2>TMDB Metadata</h2></div>
    <span class="instance-count"><?=e(ucfirst($metadataSettings['mode']))?> mode</span>
  </div>
  <p class="muted">ArrView caches TMDB metadata locally. Normal movie/series pages read SQLite only. Use the free ArrView metadata service by default, or add your own TMDB credential for large libraries and heavier usage.</p>

  <form method="post" class="metadata-settings-form">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="save_metadata">
    <label class="metadata-mode-card">
      <input type="radio" name="metadata_mode" value="free" <?=$metadataSettings['mode']==='free'?'checked':''?>>
      <span><strong>ArrView Free Metadata</strong><small>No TMDB key required. Shared cached service with a fair-use monthly quota.</small></span>
    </label>
    <label class="metadata-mode-card">
      <input type="radio" name="metadata_mode" value="personal" <?=$metadataSettings['mode']==='personal'?'checked':''?>>
      <span><strong>Use my own TMDB key</strong><small>Best for very large libraries or higher usage. The credential stays server-side.</small></span>
    </label>
    <label class="metadata-key-field">TMDB API Key / Bearer Token
      <input name="tmdb_api_key" type="password" autocomplete="off" placeholder="<?=$hasPersonalTmdbKey?'Personal key saved — leave blank to keep it':'Paste TMDB API key or bearer token'?>">
    </label>
    <div class="metadata-actions">
      <button class="primary" type="submit">Save Metadata Settings</button>
    </div>
  </form>

  <div class="metadata-stats">
    <div><span>Movies cached</span><strong><?=number_format($metadataStats['movies_cached'])?></strong></div>
    <div><span>Series cached</span><strong><?=number_format($metadataStats['series_cached'])?></strong></div>
    <div><span>Movies pending</span><strong><?=number_format($metadataStats['pending_movies'])?></strong></div>
    <div><span>Series pending</span><strong><?=number_format($metadataStats['pending_series'])?></strong></div>
  </div>

  <div class="metadata-toolbar">
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="test_metadata"><button type="submit">Test Metadata</button></form>
    <?php if($hasPersonalTmdbKey):?><form method="post" onsubmit="return confirm('Remove your personal TMDB key and return to ArrView Free Metadata?')"><?=csrf_field()?><input type="hidden" name="action" value="remove_tmdb_key"><button type="submit">Remove Personal Key</button></form><?php endif;?>
    <button type="button" class="primary metadata-sync-btn">Enrich Metadata</button>
  </div>

  <div class="metadata-progress sync-progress" hidden>
    <div class="sync-progress-head"><strong class="metadata-count">0 / 0</strong><span class="metadata-percent">0%</span></div>
    <div class="sync-track"><div class="sync-fill metadata-fill" style="width:0%"></div></div>
    <div class="metadata-current sync-current">Preparing metadata enrichment...</div>
  </div>

  <div class="metadata-attribution">
    <strong>TMDB Attribution</strong>
    <p>This product uses the TMDB API but is not endorsed or certified by TMDB.</p>
  </div>
</section>

<section class="panel"><h2>Add Radarr / Sonarr</h2><form method="post" class="instance-form"><?=csrf_field()?> <input type="hidden" name="action" value="add"><label>Name<input name="name" placeholder="Radarr Main" required></label><label>Type<select name="type"><option value="radarr">Radarr</option><option value="sonarr">Sonarr</option></select></label><label>URL<input name="url" placeholder="http://192.168.1.10:7878" required></label><label>API Key<input name="api_key" type="password" placeholder="API key" required></label><button class="primary" type="submit">Add Instance</button></form></section>
<section class="panel"><h2>Public / VOD Link</h2>
<p class="muted">Map your local media path to a public streaming URL. ArrView will URL-encode folders and filenames automatically and show Open/Copy links on media details pages.</p>
<form method="post" class="instance-form public-link-form">
<?=csrf_field()?> <input type="hidden" name="action" value="save_public_link">
<label>Local media root<input name="public_local_root" value="<?=e($publicLocalRoot)?>" placeholder="/mnt/media/Movies"></label>
<label>Public base URL<input name="public_base_url" value="<?=e($publicBaseUrl)?>" placeholder="https://vod.example.com/Movies"></label>
<button class="primary" type="submit">Save Public Link</button>
</form>
<?php if($publicLocalRoot && $publicBaseUrl):?><p class="meta">Example mapping: <code><?=e($publicLocalRoot)?></code> → <code><?=e($publicBaseUrl)?></code></p><?php endif;?>
</section>
<section class="panel instance-panel">
  <div class="panel-heading-inline instance-panel-head">
    <div><p class="eyebrow">CONNECTED SERVICES</p><h2>Configured Instances</h2></div>
    <span class="instance-count"><?=count($instances)?> configured</span>
  </div>
  <?php if(!$instances):?>
    <div class="empty compact"><p>No instances configured yet.</p></div>
  <?php else:?>
  <div class="instance-list">
    <?php foreach($instances as $instance):
      $webhookUrl=$baseAppUrl.'/webhook.php?instance='.(int)$instance['id'].'&token='.rawurlencode((string)$instance['webhook_token']);
      $statusOk = str_starts_with((string)$instance['last_status'], 'OK') || str_contains((string)$instance['last_status'], 'Webhook update');
    ?>
    <article class="instance-card" data-instance-id="<?=(int)$instance['id']?>">
      <div class="instance-card-top">
        <div class="instance-identity">
          <div class="instance-icon <?=e($instance['type'])?>"><?=strtoupper(substr((string)$instance['type'],0,1))?></div>
          <div>
            <div class="instance-title">
              <span class="type-pill <?=e($instance['type'])?>"><?=e(ucfirst($instance['type']))?></span>
              <strong><?=e($instance['name'])?></strong>
              <span class="instance-state <?=$instance['enabled']?'enabled':'disabled'?>"><?=$instance['enabled']?'Enabled':'Disabled'?></span>
            </div>
            <a class="instance-url" href="<?=e($instance['url'])?>" target="_blank" rel="noopener noreferrer"><?=e($instance['url'])?> ↗</a>
          </div>
        </div>
        <div class="instance-actions">
          <a class="table-action" href="/instance-edit.php?id=<?=(int)$instance['id']?>">Edit</a>
          <form method="post"><?=csrf_field()?> <input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?=(int)$instance['id']?>"><button type="submit">Test</button></form>
          <button type="button" class="primary sync-btn" data-instance-id="<?=(int)$instance['id']?>">Sync Now</button>
          <form method="post"><?=csrf_field()?> <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=(int)$instance['id']?>"><button type="submit"><?=$instance['enabled']?'Disable':'Enable'?></button></form>
          <form method="post" onsubmit="return confirm('Delete this instance and its cached media?')"><?=csrf_field()?> <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$instance['id']?>"><button type="submit" class="danger">Delete</button></form>
        </div>
      </div>

      <div class="instance-meta-grid">
        <div><span>Last update</span><strong><?=e($instance['last_sync_at'] ?: 'Never')?></strong></div>
        <div><span>Full reconciliation</span><strong><?=e($instance['last_full_sync_at'] ?: 'Never')?></strong></div>
        <div><span>Status</span><strong class="<?=$statusOk?'status-text-ok':'status-text-muted'?>"><?=e($instance['last_status'] ?: 'Waiting for first sync')?></strong></div>
      </div>

      <details class="webhook-details">
        <summary>
          <span>Automatic Sync Webhook</span>
          <small>Instant updates from <?=e(ucfirst($instance['type']))?></small>
        </summary>
        <div class="webhook-box">
          <div class="webhook-url-row">
            <code><?=e($webhookUrl)?></code>
            <button type="button" class="copy-webhook-btn" data-url="<?=e($webhookUrl)?>">Copy</button>
          </div>
          <small>Add this URL in <?=e(ucfirst($instance['type']))?> → Settings → Connect → Webhook. Enable import/download, upgrade, rename and delete events. ArrView refreshes only the affected movie or series.</small>
        </div>
      </details>

      <div class="sync-progress" hidden>
        <div class="sync-progress-head"><strong class="sync-count">0 / 0</strong><span class="sync-percent">0%</span></div>
        <div class="sync-track"><div class="sync-fill" style="width:0%"></div></div>
        <div class="sync-current">Preparing...</div>
      </div>
    </article>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</section></main><footer>ArrView Admin</footer>
<script>
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
async function pollSync(jobId,row,button){
  const box=row.querySelector('.sync-progress'),count=row.querySelector('.sync-count'),percent=row.querySelector('.sync-percent'),fill=row.querySelector('.sync-fill'),current=row.querySelector('.sync-current');
  box.hidden=false; button.disabled=true; button.textContent='Syncing...';
  while(true){
    try{
      const r=await fetch('/sync-status.php?job_id='+encodeURIComponent(jobId),{cache:'no-store'}); const d=await r.json();
      if(!d.ok) throw new Error(d.message||'Unable to read sync status');
      const c=Number(d.current||0),t=Number(d.total||0),p=Math.max(0,Math.min(100,Number(d.percent||0)));
      count.textContent=t>0?`${c.toLocaleString()} / ${t.toLocaleString()}`:`${c.toLocaleString()} items`;
      percent.textContent=p.toFixed(p%1?1:0)+'%'; fill.style.width=p+'%'; current.textContent=d.title||d.message||'Working...';
      if(d.status==='completed'){button.disabled=false;button.textContent='Sync Again';current.textContent=d.message||'Sync completed';break;}
      if(d.status==='failed'){button.disabled=false;button.textContent='Retry Sync';box.classList.add('failed');current.textContent=d.message||'Sync failed';break;}
    }catch(e){current.textContent=e.message||'Progress check failed';}
    await sleep(750);
  }
}
for(const button of document.querySelectorAll('.sync-btn')){
  button.addEventListener('click',async()=>{
    const id=button.dataset.instanceId,row=button.closest('.instance-card'),box=row.querySelector('.sync-progress'); box.classList.remove('failed'); box.hidden=false; row.querySelector('.sync-current').textContent='Starting background sync...'; button.disabled=true;
    try{
      const body=new URLSearchParams({instance_id:id,csrf_token:'<?=e($auth->csrfToken())?>'}); const r=await fetch('/sync-start.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body}); const d=await r.json();
      if(!d.ok) throw new Error(d.message||'Could not start sync'); await pollSync(d.job_id,row,button);
    }catch(e){button.disabled=false;button.textContent='Retry Sync';row.querySelector('.sync-current').textContent=e.message||'Could not start sync';box.classList.add('failed');}
  });
}
const metadataButton=document.querySelector('.metadata-sync-btn');
if(metadataButton){
  metadataButton.addEventListener('click',async()=>{
    const box=document.querySelector('.metadata-progress'),count=document.querySelector('.metadata-count'),percent=document.querySelector('.metadata-percent'),fill=document.querySelector('.metadata-fill'),current=document.querySelector('.metadata-current');
    box.hidden=false; metadataButton.disabled=true; metadataButton.textContent='Enriching...';
    try{
      const body=new URLSearchParams({csrf_token:'<?=e($auth->csrfToken())?>'});
      const start=await fetch('/metadata-start.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
      const started=await start.json(); if(!started.ok) throw new Error(started.message||'Could not start metadata enrichment');
      while(true){
        const r=await fetch('/metadata-status.php?job_id='+encodeURIComponent(started.job_id),{cache:'no-store'});
        const d=await r.json(); if(!d.ok) throw new Error(d.message||'Unable to read metadata status');
        const n=Number(d.current||0),t=Number(d.total||0),p=Math.max(0,Math.min(100,Number(d.percent||0)));
        count.textContent=t>0?`${n.toLocaleString()} / ${t.toLocaleString()}`:`${n.toLocaleString()} items`;
        percent.textContent=p.toFixed(p%1?1:0)+'%'; fill.style.width=p+'%'; current.textContent=d.title||d.message||'Working...';
        if(d.status==='completed'){metadataButton.disabled=false;metadataButton.textContent='Enrich Again';current.textContent=d.message||'Metadata enrichment completed';break;}
        if(d.status==='failed'){metadataButton.disabled=false;metadataButton.textContent='Retry Enrichment';box.classList.add('failed');current.textContent=d.message||'Metadata enrichment failed';break;}
        await sleep(800);
      }
    }catch(e){metadataButton.disabled=false;metadataButton.textContent='Retry Enrichment';current.textContent=e.message||'Metadata enrichment failed';box.classList.add('failed');}
  });
}

document.querySelectorAll('.copy-webhook-btn').forEach(button=>{
  button.addEventListener('click',async()=>{
    const original=button.textContent;
    try{
      await navigator.clipboard.writeText(button.dataset.url);
      button.textContent='Copied';
      button.classList.add('copied');
    }catch(e){
      button.textContent='Copy failed';
    }
    setTimeout(()=>{button.textContent=original;button.classList.remove('copied');},1600);
  });
});
</script></body></html>
