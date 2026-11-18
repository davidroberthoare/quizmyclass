<?php

declare(strict_types=1);

use QuizMyClass\Support\Backup;

$runtime = require dirname(__DIR__) . '/app/bootstrap.php';
$backup = new Backup(
    $runtime['database'],
    $runtime['config']['paths']['backups'],
);

$path = $backup->create();
echo 'Backup created: ' . $path . "\n";

