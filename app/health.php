<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

psst_db();

header('Content-Type: text/plain; charset=utf-8');
echo 'ok';