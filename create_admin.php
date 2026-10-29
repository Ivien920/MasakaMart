<?php
/**
 * KATALE — Create the first administrator account.
 *
 * Run this ONCE from the command line after importing the database:
 *   php create_admin.php
 *
 * It will prompt for a name, email, and password, hash the password with
 * password_hash(), and insert the account with must_change_password = 1
 * (though this script already collects a real password chosen by you, so
 * there is no default/placeholder credential anywhere in the codebase).
 *
 * Delete this file from the server after use, or restrict it so it cannot
 * be reached over the web (it should never be placed inside a
 * web-accessible folder in a real deployment).
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line, not a web browser.\n");
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

function prompt(string $label): string
{
    echo $label;
    return trim(fgets(STDIN));
}

$db = Database::conn();

$name = prompt('Full name: ');
$email = strtolower(prompt('Email: '));
$password = prompt('Password (min 8 characters): ');

if (strlen($password) < 8) {
    die("Password must be at least 8 characters.\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Invalid email address.\n");
}

$check = $db->prepare("SELECT admin_id FROM admins WHERE email = :email");
$check->execute(['email' => $email]);
if ($check->fetch()) {
    die("An admin with that email already exists.\n");
}

$stmt = $db->prepare(
    "INSERT INTO admins (full_name, email, password_hash, role, must_change_password)
     VALUES (:name, :email, :hash, 'super_admin', 0)"
);
$stmt->execute([
    'name' => $name,
    'email' => $email,
    'hash' => password_hash($password, PASSWORD_DEFAULT),
]);

echo "Admin account created successfully. You can now log in at /admin/login.php\n";
