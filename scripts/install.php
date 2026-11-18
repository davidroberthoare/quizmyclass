<?php

declare(strict_types=1);

use QuizMyClass\Support\Config;
use QuizMyClass\Support\Database;
use QuizMyClass\Support\Migrator;

require_once dirname(__DIR__) . '/app/Support/Config.php';
require_once dirname(__DIR__) . '/app/Support/Database.php';
require_once dirname(__DIR__) . '/app/Support/Migrator.php';

$root = dirname(__DIR__);
$localConfig = $root . '/config/local.php';

if (!file_exists($localConfig)) {
    $secret = bin2hex(random_bytes(32));
    $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
        . "    'app' => [\n"
        . "        'environment' => 'development',\n"
        . "        'debug' => true,\n"
        . "        'session_secret' => '$secret',\n"
        . "        'timezone' => 'UTC',\n"
        . "        'base_url' => 'http://localhost:8080',\n"
        . "    ],\n"
        . "    'google' => [\n"
        . "        'client_id' => '',\n"
        . "        'client_secret' => '',\n"
        . "        'redirect_uri' => 'http://localhost:8080/auth/google/callback.php',\n"
        . "    ],\n"
        . "    'access' => [\n"
        . "        'allowed_domains' => [],\n"
        . "        'allowed_test_emails' => [],\n"
        . "    ],\n"
        . "];\n";

    if (file_put_contents($localConfig, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write config/local.php.');
    }
    chmod($localConfig, 0600);
    echo "Created config/local.php.\n";
}

$config = Config::load($root);
$database = Database::connect($config);
$migrator = new Migrator($database, $config['paths']['migrations']);
$applied = $migrator->migrate();

echo $applied === []
    ? "Database is already up to date.\n"
    : 'Applied ' . count($applied) . " migration(s).\n";
echo "Installation complete.\n";

