<?php
declare(strict_types=1);

$pdo = new PDO('sqlite:/app/data/arrview.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$action = $argv[1] ?? '';

switch ($action) {
    case 'seed':
        $pdo->prepare("INSERT OR IGNORE INTO users(username,password_hash,role,enabled) VALUES(?,?, 'admin',1)")
            ->execute(['ciadmin', password_hash('CiPass123!', PASSWORD_DEFAULT)]);
        $pdo->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('sync_interval_hours','0')
            ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value")->execute();
        $pdo->prepare("INSERT INTO instances(name,type,url,api_key,webhook_token,enabled,last_full_sync_at)
            VALUES(?,?,?,?,?,1,CURRENT_TIMESTAMP)")
            ->execute(['CI Radarr','radarr','http://arrview-ci-mock:7878','ci-key','test-token']);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message,source)
            VALUES(?,'queued','CI sync','manual')")->execute([$id]);
        echo (string)$pdo->lastInsertId();
        break;

    case 'movie-count':
        echo (string)$pdo->query("SELECT COUNT(*) FROM movies")->fetchColumn();
        break;

    case 'upcoming-count':
        echo (string)$pdo->query("SELECT COUNT(*) FROM movies
            WHERE availability_date IS NOT NULL AND datetime(availability_date)>datetime('now')")->fetchColumn();
        break;

    case 'set-marker':
        $pdo->prepare("INSERT INTO app_settings(setting_key,setting_value) VALUES('ci_persistence','survives')
            ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value")->execute();
        echo "ok";
        break;

    case 'get-marker':
        echo (string)$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='ci_persistence'")->fetchColumn();
        break;

    default:
        fwrite(STDERR, "Unknown smoke-db action\n");
        exit(2);
}
