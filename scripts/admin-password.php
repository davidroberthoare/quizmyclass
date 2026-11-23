<?php

declare(strict_types=1);

// Prints an Apache htpasswd line (bcrypt) and, for local development, a config hash.
//   php scripts/admin-password.php [username]
$user = $argv[1] ?? 'admin';
if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $user)) {
    fwrite(STDERR, "Username may use letters, numbers, dot, dash and underscore only.\n");
    exit(1);
}
fwrite(STDERR, 'Password (typed text is visible): ');
$password = trim((string) fgets(STDIN));
if (strlen($password) < 12) {
    fwrite(STDERR, "Use at least 12 characters.\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_BCRYPT);
echo "\nAdd this line to your .htpasswd file (outside the web root):\n$user:$hash\n";
echo "\nDevelopment only (php -S ignores .htaccess): add to config/local.php\n";
echo "    'admin' => ['dev_password_hash' => '$hash'],\n";
echo "then sign in as user \"admin\".\n";
