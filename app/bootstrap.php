<?php

declare(strict_types=1);

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/ArrService.php';
require_once __DIR__ . '/src/BatchSyncService.php';
require_once __DIR__ . '/src/Auth.php';

$dataDir = getenv('ARRVIEW_DATA') ?: (__DIR__ . '/data');
$db = new Database(rtrim($dataDir, '/') . '/arrview.sqlite');
$pdo = $db->pdo;
$arr = new ArrService($pdo);
$batchSync = new BatchSyncService($pdo);
$auth = new Auth($pdo);

// Lightweight self-healing reconciliation: normal pages read SQLite only.
// At most once every 12 hours per instance, queue a background full sync
// to catch any changes that may have been missed by webhooks.
if (PHP_SAPI !== 'cli') {
    try {
        $stale = $pdo->query("SELECT id FROM instances
            WHERE enabled=1
              AND (last_full_sync_at IS NULL OR datetime(last_full_sync_at) < datetime('now','-12 hours'))")->fetchAll();

        foreach ($stale as $row) {
            $instanceId = (int)$row['id'];
            $active = $pdo->prepare("SELECT id FROM sync_jobs
                WHERE instance_id=? AND status IN ('queued','running')
                ORDER BY id DESC LIMIT 1");
            $active->execute([$instanceId]);
            if ($active->fetchColumn()) continue;

            $pdo->prepare("INSERT INTO sync_jobs(instance_id,status,message)
                VALUES(?, 'queued', 'Automatic reconciliation queued')")->execute([$instanceId]);
            $jobId = (int)$pdo->lastInsertId();

            $cmd = escapeshellarg(PHP_BINARY) . ' ' .
                escapeshellarg(__DIR__ . '/bin/sync-job.php') . ' ' .
                $jobId . ' > /tmp/arrview-sync-' . $jobId . '.log 2>&1 &';
            exec($cmd);
        }
    } catch (Throwable) {
        // Reconciliation must never block the UI.
    }
}


function asset_url(string $path): string
{
    $separator = str_contains($path, '?') ? '&' : '?';
    return $path . $separator . 'v=' . rawurlencode(ARRVIEW_VERSION);
}

function brand_head(): string
{
    return '<link rel="icon" href="' . e(asset_url('/assets/arrview-icon.svg')) . '" type="image/svg+xml">'
        . '<link rel="apple-touch-icon" href="' . e(asset_url('/assets/arrview-icon.svg')) . '">'
        . '<link rel="manifest" href="' . e(asset_url('/manifest.webmanifest')) . '">'
        . '<meta name="theme-color" content="#0b111c" id="theme-color-meta">'
        . '<script>(function(){try{var t=localStorage.getItem("arrview-theme")||"system";var d=t==="system"?(matchMedia("(prefers-color-scheme: light)").matches?"light":"dark"):t;document.documentElement.dataset.theme=d;document.documentElement.dataset.themePreference=t;}catch(e){document.documentElement.dataset.theme="dark";}})();</script>'
        . '<script defer src="' . e(asset_url('/assets/app.js')) . '"></script>';
}

function brand_logo(bool $version = true): string
{
    $versionHtml = $version ? ' <small class="version-chip">v' . e(ARRVIEW_VERSION) . '</small>' : '';
    return '<a class="brand brand-logo" href="/">'
        . '<img src="' . e(asset_url('/assets/arrview-icon.svg')) . '" alt="" aria-hidden="true">'
        . '<span class="brand-word">Arr<span>View</span></span>'
        . $versionHtml
        . '</a>';
}

function apply_branding(string $html): string
{
    if (!str_contains($html, '</head>')) return $html;

    // Always version static assets so browsers and reverse proxies cannot serve stale UI from an older release.
    $html = preg_replace(
        '~href="/assets/style\.css(?:\?[^"]*)?"~',
        'href="' . e(asset_url('/assets/style.css')) . '"',
        $html
    ) ?? $html;

    $html = str_replace(
        'src="/assets/arrview-logo.svg"',
        'src="' . e(asset_url('/assets/arrview-logo.svg')) . '"',
        $html
    );

    if (!str_contains($html, 'rel="icon"')) {
        $html = preg_replace('/<\/head>/i', brand_head() . '</head>', $html, 1) ?? $html;
    }

    $html = preg_replace(
        '~<a class="brand" href="/">ArrView(?:\s*<small class="version-chip">v<\?=e\(ARRVIEW_VERSION\)\?><\/small>)?<\/a>~',
        brand_logo(),
        $html
    ) ?? $html;

    // Shared application chrome: mobile menu, theme control, and standard footer.
    if (str_contains($html, '<header class="topbar">') && !str_contains($html, 'class="nav-toggle"')) {
        $html = preg_replace(
            '~(<header class="topbar">\s*' . preg_quote(brand_logo(), '~') . ')~',
            '$1<button type="button" class="nav-toggle" aria-label="Open navigation" aria-expanded="false"><span></span><span></span><span></span></button>',
            $html,
            1
        ) ?? $html;

        $html = preg_replace(
            '~(<header class="topbar">.*?<nav>)(.*?)(</nav></header>)~s',
            '$1$2<button type="button" class="theme-toggle" aria-label="Change theme" title="Theme"><span class="theme-icon" aria-hidden="true">◐</span><span class="theme-label">Theme</span></button>$3',
            $html,
            1
        ) ?? $html;
    }

    $standardFooter = '<footer class="app-footer"><div><span>ArrView v' . e(ARRVIEW_VERSION) . '</span><span class="footer-dot">•</span><span>Designed &amp; Developed by <a href="https://www.kasunindika.com" target="_blank" rel="noopener noreferrer">Kasun Indika</a></span></div></footer>';
    if (preg_match('~<footer\b[^>]*>.*?</footer>~s', $html)) {
        $html = preg_replace('~<footer\b[^>]*>.*?</footer>~s', $standardFooter, $html) ?? $html;
    } elseif (str_contains($html, '</body>')) {
        $html = str_replace('</body>', $standardFooter . '</body>', $html);
    }

    if (str_contains($html, 'class="auth-shell"') && !str_contains($html, 'class="auth-theme-toggle"')) {
        $authTheme = '<button type="button" class="theme-toggle auth-theme-toggle" aria-label="Change theme" title="Theme"><span class="theme-icon" aria-hidden="true">◐</span><span class="theme-label">Theme</span></button>';
        $html = str_replace('<body>', '<body>' . $authTheme, $html);
    }

    $html = str_replace(
        '<a class="brand auth-brand" href="/">ArrView</a>',
        '<img class="auth-logo" src="/assets/arrview-logo.svg" alt="ArrView">',
        $html
    );

    if (str_contains($html, 'FIRST-TIME SETUP') && !str_contains($html, 'class="auth-logo"')) {
        $html = str_replace(
            '<section class="auth-card">',
            '<section class="auth-card"><img class="auth-logo" src="/assets/arrview-logo.svg" alt="ArrView">',
            $html
        );
    }

    return $html;
}

ob_start('apply_branding');

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}


function csrf_field(): string
{
    global $auth;
    return '<input type="hidden" name="csrf_token" value="' . e($auth->csrfToken()) . '">';
}
