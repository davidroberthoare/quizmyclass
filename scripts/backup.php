<?php

declare(strict_types=1);

use QuizMyClass\Support\Backup;

// Usage: php scripts/backup.php [--keep=14]
// Schedule daily with cron; --keep deletes older automatic backups beyond that many.
$keep = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--keep=(\d{1,4})$/', $argument, $matches) === 1 && (int) $matches[1] >= 1) {
        $keep = (int) $matches[1];
    } else {
        fwrite(STDERR, "Usage: php scripts/backup.php [--keep=N]\n");
        exit(1);
    }
}

$runtime = require dirname(__DIR__) . '/app/bootstrap.php';
$backup = new Backup(
    $runtime['database'],
    $runtime['config']['paths']['backups'],
);

$path = $backup->create();
echo 'Backup created: ' . $path . "\n";
if ($keep !== null) {
    $removed = $backup->prune($keep);
    echo 'Removed ' . count($removed) . " old backup(s).\n";
}
