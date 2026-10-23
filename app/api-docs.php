<?php

declare(strict_types=1);

// API documentation for the manager. Requires a manager login; the Markdown source in
// docs/ is not reachable over the web (docs/.htaccess).

require_once __DIR__ . '/functions.php';

psst_configure_error_output();
header('Cache-Control: no-store');

if (!psst_is_logged_in()) {
    header('Location: manage.php', true, 302);
    exit;
}

$documents = [
    'api' => ['file' => 'api.md', 'title' => 'API'],
    'sites' => ['file' => 'sites.md', 'title' => 'Building sites'],
];
$current = array_key_exists((string) ($_GET['doc'] ?? ''), $documents) ? (string) $_GET['doc'] : 'api';

$markdown = strtr((string) file_get_contents(__DIR__ . '/docs/' . $documents[$current]['file']), [
    '{{BASE_URL}}' => psst_base_url(),
    '{{VERSION}}' => psst_version(),
    '{{MAX_UPLOAD}}' => psst_format_bytes(psst_max_upload_bytes()),
    '{{MAX_FAILED}}' => (string) (int) psst_config()['max_failed_api_auth_per_window'],
    '{{MAX_SITE}}' => psst_format_bytes((int) psst_config()['max_site_bytes']),
    '{{MAX_WRITES}}' => (string) (int) psst_config()['max_site_writes_per_minute'],
    '{{SITE_STORAGE}}' => psst_format_bytes((int) psst_config()['default_site_storage_bytes']),
    '{{VISITOR_STORAGE}}' => psst_format_bytes((int) psst_config()['default_visitor_storage_bytes']),
    '{{VISITOR_TOTAL}}' => psst_format_bytes((int) psst_config()['default_site_visitor_total_bytes']),
    '{{VISITOR_DAYS}}' => (string) (int) psst_config()['default_visitor_lifetime_days'],
]);

psst_send_markdown_page_headers();
psst_page_start('PSST ' . $documents[$current]['title'], 'bg-light min-vh-100 py-4');
?>
    <main class="container psst-page-wide">
        <nav class="d-flex flex-wrap gap-3 mb-3">
            <a href="manage.php">&larr; Back to the manager</a>
            <?php foreach ($documents as $name => $document): ?>
                <?php if ($name === $current): ?>
                    <span class="fw-semibold"><?= psst_html($document['title']) ?></span>
                <?php else: ?>
                    <a href="api-docs.php?doc=<?= psst_html($name) ?>"><?= psst_html($document['title']) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <section class="bg-white border rounded p-3 p-sm-4">
            <article class="psst-markdown"><?= psst_markdown($markdown) ?></article>
        </section>

        <?php psst_render_footer('mt-3'); ?>
    </main>
<?php
psst_page_end();
