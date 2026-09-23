<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$currentUser=$auth->requireAdmin();

$message=(string)($_SESSION['flash_success']??'');
$error=(string)($_SESSION['flash_error']??'');
unset($_SESSION['flash_success'],$_SESSION['flash_error']);

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=(string)($_POST['action']??'');
    try{
        $auth->requireCsrf($_POST['csrf_token']??null);

        if($action==='save_system'){
            $interval=max(0,min(168,(int)($_POST['sync_interval_hours']??12)));
            $pageSize=(int)($_POST['library_page_size']??100);
            if(!in_array($pageSize,[25,50,100,250,500],true))$pageSize=100;
            $ttl=max(1,min(1440,(int)($_POST['diagnostic_cache_ttl_minutes']??15)));
            $langs=array_values(array_unique(array_filter(array_map(
                static fn($v)=>trim((string)$v),
                preg_split('/[,\n]+/',(string)($_POST['preferred_audio_languages']??''))?:[]
            ))));
            set_app_setting('sync_interval_hours',(string)$interval);
            set_app_setting('library_page_size',(string)$pageSize);
            set_app_setting('diagnostic_cache_ttl_minutes',(string)$ttl);
            set_app_setting('viewer_vod_enabled',isset($_POST['viewer_vod_enabled'])?'1':'0');
            set_app_setting('preferred_language_warning',isset($_POST['preferred_language_warning'])?'1':'0');
            set_app_setting('preferred_audio_languages',implode(', ',$langs));
            $message='System settings saved.';
        } elseif($action==='set_encryption'){
            $enable=($_POST['enable_encryption']??'0')==='1';
            $secret->setEnabled($enable);
            $message=$enable
                ? 'API credential encryption enabled and existing credentials migrated.'
                : 'API credential encryption disabled and credentials returned to plaintext storage.';
        } elseif($action==='add_vod_mapping'){
            $instanceId=(int)($_POST['instance_id']??0);
            $localRoot=trim((string)($_POST['local_root']??''));
            $baseUrl=rtrim(trim((string)($_POST['public_base_url']??'')),'/');
            $priority=max(0,min(9999,(int)($_POST['priority']??100)));
            if($localRoot===''||$baseUrl==='')throw new RuntimeException('Local root and public base URL are required.');
            if(!preg_match('#^https?://#i',$baseUrl))throw new RuntimeException('Public base URL must start with http:// or https://.');
            if($instanceId>0){
                $chk=$pdo->prepare('SELECT COUNT(*) FROM instances WHERE id=?');
                $chk->execute([$instanceId]);
                if(!(int)$chk->fetchColumn())throw new RuntimeException('Selected instance does not exist.');
            }
            $pdo->prepare('INSERT INTO vod_mappings(instance_id,local_root,public_base_url,priority,enabled) VALUES(?,?,?,?,1)')
                ->execute([$instanceId>0?$instanceId:null,rtrim($localRoot,"/\\"),$baseUrl,$priority]);
            $message='VOD path mapping added.';
        } elseif($action==='delete_vod_mapping'){
            $pdo->prepare('DELETE FROM vod_mappings WHERE id=?')->execute([(int)($_POST['id']??0)]);
            $message='VOD path mapping deleted.';
        } elseif($action==='toggle_vod_mapping'){
            $pdo->prepare('UPDATE vod_mappings SET enabled=CASE enabled WHEN 1 THEN 0 ELSE 1 END WHERE id=?')
                ->execute([(int)($_POST['id']??0)]);
            $message='VOD path mapping updated.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$settings=[
    'sync_interval_hours'=>app_setting('sync_interval_hours','12'),
    'library_page_size'=>app_setting('library_page_size','100'),
    'diagnostic_cache_ttl_minutes'=>app_setting('diagnostic_cache_ttl_minutes','15'),
    'viewer_vod_enabled'=>app_setting('viewer_vod_enabled','0'),
    'preferred_audio_languages'=>app_setting('preferred_audio_languages',''),
    'preferred_language_warning'=>app_setting('preferred_language_warning','1'),
];
$instances=$pdo->query('SELECT id,name,type,enabled,last_sync_at,last_full_sync_at FROM instances ORDER BY type,name')->fetchAll();
$vodMappings=$pdo->query('SELECT v.*,i.name instance_name,i.type instance_type FROM vod_mappings v LEFT JOIN instances i ON i.id=v.instance_id ORDER BY v.priority,v.id')->fetchAll();
$syncHistory=$pdo->query('SELECT j.*,i.name instance_name,i.type instance_type FROM sync_jobs j JOIN instances i ON i.id=j.instance_id ORDER BY j.id DESC LIMIT 75')->fetchAll();
$activeSyncs=array_values(array_filter($syncHistory,fn($j)=>in_array($j['status'],['queued','running'],true)));

function systemDate(?string $value):string{
    if(!$value)return '—';
    try{return(new DateTimeImmutable($value))->format('Y-m-d H:i:s');}catch(Throwable){return$value;}
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System · ArrView</title><link rel="stylesheet" href="/assets/style.css"></head><body>
<header class="topbar"><a class="brand" href="/">ArrView <small class="version-chip">v<?=e(ARRVIEW_VERSION)?></small></a><nav><a href="/">Library</a><a href="/admin.php">Instances</a><a href="/users.php">Users</a><a class="active" href="/system.php">System</a><a href="/support.php">Support</a><span class="user-chip"><?=e($currentUser['username'])?></span><a href="/logout.php">Logout</a></nav></header>
<main class="wrap admin-wrap">
<section class="catalog-head"><div><p class="eyebrow">ADMINISTRATION</p><h1>System</h1><p class="meta">Sync, backup, VOD, language and security controls.</p></div></section>
<?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>

<section class="panel">
<div class="panel-heading-inline"><div><p class="eyebrow">AUTOMATION</p><h2>Library & diagnostics</h2></div><span class="instance-count"><?=count($activeSyncs)?> active sync<?=count($activeSyncs)===1?'':'s'?></span></div>
<form method="post" class="system-settings-grid"><?=csrf_field()?><input type="hidden" name="action" value="save_system">
<label>Automatic full sync
<select name="sync_interval_hours">
<option value="0" <?=$settings['sync_interval_hours']==='0'?'selected':''?>>Disabled</option>
<?php foreach([1,3,6,12,24,48,72,168] as $hours):?><option value="<?=$hours?>" <?=(int)$settings['sync_interval_hours']===$hours?'selected':''?>>Every <?=$hours?> hour<?=$hours===1?'':'s'?></option><?php endforeach;?>
</select><small>Webhooks still update changed items instantly.</small></label>
<label>Library page size
<select name="library_page_size"><?php foreach([25,50,100,250,500] as $size):?><option value="<?=$size?>" <?=(int)$settings['library_page_size']===$size?'selected':''?>><?=$size?> rows</option><?php endforeach;?></select>
<small>Pagination keeps very large libraries fast.</small></label>
<label>Diagnostic cache TTL
<input type="number" min="1" max="1440" name="diagnostic_cache_ttl_minutes" value="<?=e($settings['diagnostic_cache_ttl_minutes'])?>"><small>Minutes before a cached diagnostic becomes stale.</small></label>
<label>Preferred audio languages
<input name="preferred_audio_languages" value="<?=e($settings['preferred_audio_languages'])?>" placeholder="English, Sinhala, Tamil"><small>Comma-separated. Used for warnings, not automatic downloads.</small></label>
<label class="check-setting"><input type="checkbox" name="preferred_language_warning" <?=$settings['preferred_language_warning']==='1'?'checked':''?>><span><strong>Preferred-language warnings</strong><small>Flag available media that does not include one of the preferred languages.</small></span></label>
<label class="check-setting"><input type="checkbox" name="viewer_vod_enabled" <?=$settings['viewer_vod_enabled']==='1'?'checked':''?>><span><strong>Allow viewers to see VOD links</strong><small>Admins always see configured VOD links.</small></span></label>
<div class="form-actions"><button class="primary" type="submit">Save System Settings</button></div>
</form>
</section>

<section class="panel">
<div class="panel-heading-inline"><div><p class="eyebrow">SECURITY</p><h2>API-key encryption at rest</h2></div><span class="instance-count"><?=$secret->enabled()?'Enabled':'Optional'?></span></div>
<div class="security-summary-grid">
<div><span>libsodium</span><strong><?=$secret->supported()?'Available':'Unavailable'?></strong></div>
<div><span>Encryption key</span><strong><?=$secret->configured()?'Configured':'Not configured'?></strong></div>
<div><span>Credential storage</span><strong><?=$secret->enabled()?'Encrypted':'Plaintext'?></strong></div>
</div>
<p class="muted">Set <code>ARRVIEW_ENCRYPTION_KEY</code> in Docker first. When enabled, ArrView migrates Radarr/Sonarr API keys and the personal TMDB credential in one transaction.</p>
<form method="post" class="inline-actions"><?=csrf_field()?><input type="hidden" name="action" value="set_encryption"><input type="hidden" name="enable_encryption" value="<?=$secret->enabled()?'0':'1'?>">
<button type="submit" class="<?=$secret->enabled()?'danger-button':'primary'?>" <?=$secret->enabled()||$secret->configured()?'':'disabled'?>><?=$secret->enabled()?'Disable Encryption':'Enable Encryption'?></button>
</form>
</section>

<section class="panel">
<div class="panel-heading-inline"><div><p class="eyebrow">VOD</p><h2>Path mappings</h2></div><span class="instance-count"><?=count($vodMappings)?> mapping<?=count($vodMappings)===1?'':'s'?></span></div>
<p class="muted">Use multiple mappings when different Radarr/Sonarr instances or storage roots need different public/VOD base URLs. Instance-specific mappings take priority over global mappings.</p>
<form method="post" class="vod-mapping-form"><?=csrf_field()?><input type="hidden" name="action" value="add_vod_mapping">
<label>Instance<select name="instance_id"><option value="0">All instances (global)</option><?php foreach($instances as $i):?><option value="<?=(int)$i['id']?>"><?=e($i['name'])?> · <?=e(ucfirst($i['type']))?></option><?php endforeach;?></select></label>
<label>Local root<input name="local_root" placeholder="/mnt/Movies" required></label>
<label>Public base URL<input name="public_base_url" placeholder="https://media.example.com/movies" required></label>
<label>Priority<input type="number" name="priority" min="0" max="9999" value="100"></label>
<button class="primary" type="submit">Add Mapping</button>
</form>
<?php if($vodMappings):?><div class="mapping-list"><?php foreach($vodMappings as $m):?><article class="mapping-row">
<div><span class="type-pill <?=$m['instance_type']?e($m['instance_type']):'global'?>"><?=$m['instance_name']?e($m['instance_name']):'GLOBAL'?></span><strong><?=e($m['local_root'])?></strong><small>→ <?=e($m['public_base_url'])?> · priority <?=(int)$m['priority']?></small></div>
<div class="inline-actions"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle_vod_mapping"><input type="hidden" name="id" value="<?=(int)$m['id']?>"><button type="submit"><?=$m['enabled']?'Disable':'Enable'?></button></form>
<form method="post" onsubmit="return confirm('Delete this VOD mapping?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_vod_mapping"><input type="hidden" name="id" value="<?=(int)$m['id']?>"><button type="submit">Delete</button></form></div>
</article><?php endforeach;?></div><?php endif;?>
</section>

<section class="panel">
<div class="panel-heading-inline"><div><p class="eyebrow">RECOVERY</p><h2>Backup & restore</h2></div><span class="instance-count">SQLite</span></div>
<div class="backup-grid">
<article class="backup-card"><h3>Download backup</h3><p>Creates an integrity-checked snapshot containing ArrView users, settings, instances, cached media, mappings and history.</p><form method="post" action="/backup-download.php"><?=csrf_field()?><button class="primary" type="submit">Download Backup</button></form></article>
<article class="backup-card"><h3>Restore backup</h3><p>Validates the SQLite backup, creates a pre-restore safety snapshot, then restores compatible ArrView tables.</p><form method="post" action="/restore.php" enctype="multipart/form-data" onsubmit="return confirm('Restore this backup? Current ArrView data will be replaced.')"><?=csrf_field()?><input type="file" name="backup_file" accept=".sqlite,.db,application/vnd.sqlite3" required><button class="danger-button" type="submit">Restore Backup</button></form></article>
</div>
</section>

<section class="panel">
<div class="panel-heading-inline"><div><p class="eyebrow">SYNC HISTORY</p><h2>Recent jobs</h2></div><span class="instance-count"><?=count($syncHistory)?> shown</span></div>
<?php if(!$syncHistory):?><div class="empty compact"><p>No sync jobs yet.</p></div><?php else:?><div class="sync-history-list">
<?php foreach($syncHistory as $job):
$isCancelled=(int)($job['cancel_requested']??0)===1 && str_contains(strtolower((string)$job['message']),'cancel');
$label=$isCancelled?'cancelled':$job['status'];
?>
<article class="sync-history-row">
<div><span class="type-pill <?=e($job['instance_type'])?>"><?=e(ucfirst($job['instance_type']))?></span><strong><?=e($job['instance_name'])?></strong><small>#<?=(int)$job['id']?> · <?=e($job['source']??'manual')?> · <?=e(systemDate($job['created_at']))?></small></div>
<div class="sync-history-state"><span class="status-pill status-<?=e($label)?>"><?=e(ucfirst($label))?></span><small><?=e((string)($job['message']??''))?></small></div>
<div><strong><?=number_format((int)$job['current_item'])?> / <?=number_format((int)$job['total_items'])?></strong><small><?=e(systemDate($job['finished_at']??$job['started_at']))?></small></div>
<?php if(in_array($job['status'],['queued','running'],true)):?><button type="button" class="cancel-sync-btn danger-button" data-job-id="<?=(int)$job['id']?>">Cancel</button><?php endif;?>
</article>
<?php endforeach;?></div><?php endif;?>
</section>
</main>
<footer>ArrView v<?=e(ARRVIEW_VERSION)?> · System administration</footer>
<script>
document.querySelectorAll('.cancel-sync-btn').forEach(btn=>btn.addEventListener('click',async()=>{
 if(!confirm('Stop this running sync?'))return;
 btn.disabled=true;btn.textContent='Cancelling...';
 try{
  const body=new URLSearchParams({job_id:btn.dataset.jobId,csrf_token:'<?=e($auth->csrfToken())?>'});
  const r=await fetch('/sync-cancel.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
  const d=await r.json();if(!d.ok)throw new Error(d.message||'Could not cancel sync');
  btn.textContent='Cancel requested';setTimeout(()=>location.reload(),1200);
 }catch(e){btn.disabled=false;btn.textContent='Cancel';alert(e.message);}
}));
</script>
</body></html>
