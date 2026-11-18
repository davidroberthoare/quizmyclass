<?php

declare(strict_types=1);

use QuizMyClass\Support\Migrator;

$runtime = require dirname(__DIR__) . '/app/bootstrap.php';
$migrator = new Migrator(
    $runtime['database'],
    $runtime['config']['paths']['migrations'],
);
$applied = $migrator->migrate();

if ($applied === []) {
    echo "Database is already up to date.\n";
    exit(0);
}

echo "Applied migrations:\n";
foreach ($applied as $version) {
    echo '- ' . $version . "\n";
}

