<?php
// Prints the value for admin_password_hash in config.php.
if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
echo 'Admin password: ';
$pass = trim((string) fgets(STDIN));
if (strlen($pass) < 12) {
    exit("Use at least 12 characters.\n");
}
echo password_hash($pass, PASSWORD_DEFAULT), "\n";
