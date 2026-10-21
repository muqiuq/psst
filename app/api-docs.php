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

$markdown = strtr((string) file_get_contents(__DIR__ . '/docs/api.md'), [
    '{{BASE_URL}}' => psst_base_url(),
    '{{VERSION}}' => psst_version(),
    '{{MAX_UPLOAD}}' => psst_format_bytes(psst_max_upload_bytes()),
    '{{MAX_FAILED}}' => (string) (int) psst_config()['max_failed_api_auth_per_window'],
]);

psst_send_markdown_page_headers();
psst_page_start('PSST API', 'bg-light min-vh-100 py-4');
?>
    <main class="container psst-page-wide">
        <p class="mb-3"><a href="manage.php">&larr; Back to the manager</a></p>
        <section class="bg-white border rounded p-3 p-sm-4">
            <article class="psst-markdown"><?= psst_markdown($markdown) ?></article>
        </section>

        <?php psst_render_footer('mt-3'); ?>
    </main>
<?php
psst_page_end();
