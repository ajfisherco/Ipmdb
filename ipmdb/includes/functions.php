<?php
declare(strict_types=1);

if (!function_exists('ipmdb_config')) {
    function ipmdb_config(): array
    {
        $path = is_file(dirname(__DIR__) . '/config.local.php')
            ? dirname(__DIR__) . '/config.local.php'
            : dirname(__DIR__) . '/config.php';

        if (!is_file($path)) {
            throw new RuntimeException('IPMdb config file missing.');
        }

        return require $path;
    }
}

/*
|--------------------------------------------------------------------------
| IPMdb Shared Functions
| /httpdocs/ipmdb/includes/functions.php
|--------------------------------------------------------------------------
| Titles identify ideas.
| Asset IDs identify records.
|--------------------------------------------------------------------------
*/

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ipmdb_asset_title(array $asset): string
{
    $title = trim((string)($asset['title'] ?? ''));

    if ($title !== '') {
        return $title;
    }

    $idea = trim((string)($asset['idea'] ?? ''));

    if ($idea !== '') {
        return mb_substr($idea, 0, 80);
    }

    return 'Untitled Asset';
}

function ipmdb_asset_id(array $asset): string
{
    return trim((string)($asset['asset_id'] ?? $asset['id'] ?? ''));
}

function ipmdb_asset_status(array $asset): string
{
    return trim((string)($asset['status'] ?? 'Draft'));
}

function ipmdb_asset_category(array $asset): string
{
    return trim((string)($asset['category'] ?? 'Uncategorized'));
}

function ipmdb_asset_version(array $asset): string
{
    $version = trim((string)($asset['version'] ?? ''));

    if ($version === '') {
        return 'Version 1.0';
    }

    return str_starts_with(strtolower($version), 'version')
        ? $version
        : 'Version ' . $version;
}

function ipmdb_format_date(?string $date): string
{
    $date = trim((string)$date);

    if ($date === '') {
        return '';
    }

    $time = strtotime($date);

    if ($time === false) {
        return $date;
    }

    return date('M j, Y', $time);
}

function ipmdb_asset_meta_line(array $asset): string
{
    $parts = [];

    $status = ipmdb_asset_status($asset);
    $category = ipmdb_asset_category($asset);
    $version = ipmdb_asset_version($asset);
    $date = ipmdb_format_date($asset['created_at'] ?? $asset['updated_at'] ?? '');

    if ($status !== '') {
        $parts[] = $status;
    }

    if ($category !== '') {
        $parts[] = $category;
    }

    if ($version !== '') {
        $parts[] = $version;
    }

    if ($date !== '') {
        $parts[] = $date;
    }

    return implode(' • ', $parts);
}

function ipmdb_asset_url(array $asset): string
{
    $assetId = ipmdb_asset_id($asset);

    if ($assetId === '') {
        return '#';
    }

    return 'viewer.php?asset_id=' . rawurlencode($assetId);
}

function ipmdb_render_asset_header(array $asset): string
{
    $title = ipmdb_asset_title($asset);
    $meta = ipmdb_asset_meta_line($asset);
    $assetId = ipmdb_asset_id($asset);

    ob_start();
    ?>
    <section class="ipmdb-asset-header">
        <h1 class="ipmdb-asset-title"><?= h($title) ?></h1>

        <?php if ($meta !== ''): ?>
            <div class="ipmdb-asset-meta"><?= h($meta) ?></div>
        <?php endif; ?>

        <?php if ($assetId !== ''): ?>
            <button class="ipmdb-asset-id" type="button" data-copy="<?= h($assetId) ?>" onclick="navigator.clipboard.writeText(this.dataset.copy)">
                <?= h($assetId) ?> ⧉
            </button>
        <?php endif; ?>
    </section>
    <?php
    return (string)ob_get_clean();
}

function ipmdb_render_asset_card(array $asset): string
{
    $title = ipmdb_asset_title($asset);
    $meta = ipmdb_asset_meta_line($asset);
    $assetId = ipmdb_asset_id($asset);
    $url = ipmdb_asset_url($asset);
    $idea = trim((string)($asset['idea'] ?? ''));

    ob_start();
    ?>
    <article class="ipmdb-asset-card">
        <a class="ipmdb-asset-card-title" href="<?= h($url) ?>">
            <?= h($title) ?>
        </a>

        <?php if ($meta !== ''): ?>
            <div class="ipmdb-asset-meta"><?= h($meta) ?></div>
        <?php endif; ?>

        <?php if ($idea !== ''): ?>
            <p class="ipmdb-asset-snippet"><?= h(mb_substr($idea, 0, 220)) ?></p>
        <?php endif; ?>

        <?php if ($assetId !== ''): ?>
            <button class="ipmdb-asset-id" type="button" data-copy="<?= h($assetId) ?>" onclick="navigator.clipboard.writeText(this.dataset.copy)">
                <?= h($assetId) ?> ⧉
            </button>
        <?php endif; ?>
    </article>
    <?php
    return (string)ob_get_clean();
}

function ipmdb_render_asset_styles(): string
{
    return <<<CSS
<style>
.ipmdb-asset-header,
.ipmdb-asset-card {
    border: 1px solid rgba(148, 163, 184, .22);
    border-radius: 18px;
    padding: 18px;
    margin: 16px 0;
    background: rgba(15, 23, 42, .74);
}

.ipmdb-asset-card {
    position: relative;
    cursor: pointer;
    transition: border-color .18s ease, background .18s ease, transform .18s ease;
}

.ipmdb-asset-card:hover {
    border-color: rgba(56, 189, 248, .62);
    background: rgba(15, 23, 42, .9);
    transform: translateY(-1px);
}

.ipmdb-asset-card:focus-within {
    outline: 3px solid rgba(56, 189, 248, .72);
    outline-offset: 3px;
}

.ipmdb-asset-title,
.ipmdb-asset-card-title {
    display: block;
    margin: 0 0 8px;
    color: #f8fafc;
    font-size: clamp(1.7rem, 5vw, 3rem);
    font-weight: 900;
    line-height: 1.05;
    text-decoration: none;
}

.ipmdb-asset-card-title {
    font-size: clamp(1.25rem, 4vw, 2rem);
}

.ipmdb-asset-card-title::after {
    position: absolute;
    inset: 0;
    border-radius: 18px;
    content: '';
}

.ipmdb-asset-meta {
    color: #cbd5e1;
    font-size: .95rem;
    margin: 6px 0 10px;
}

.ipmdb-asset-snippet {
    color: #e2e8f0;
    font-size: 1rem;
    line-height: 1.45;
    margin: 10px 0;
}

.ipmdb-asset-id {
    position: relative;
    z-index: 1;
    display: inline-block;
    margin-top: 8px;
    padding: 6px 9px;
    border: 1px solid rgba(148, 163, 184, .25);
    border-radius: 999px;
    background: rgba(2, 6, 23, .52);
    color: #94a3b8;
    font-size: .72rem;
    letter-spacing: .04em;
    cursor: pointer;
}
</style>
CSS;
}
