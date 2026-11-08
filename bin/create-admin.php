<?php

declare(strict_types=1);

// Creates a console user, or resets an existing user's password (which also turns
// off their two-factor sign-in, so this is the way back in after losing a phone).
// Usage: php bin/create-admin.php <username> [viewer|operator|admin]
// The password is read from the terminal (or stdin when piped).

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use Shepherd\Auth\AdminAuth;
use Shepherd\Db;

$username = $argv[1] ?? '';
$role = $argv[2] ?? 'admin';
if (!preg_match('/^[A-Za-z0-9._@\-]{2,64}$/', $username) || !in_array($role, ['viewer', 'operator', 'admin'], true)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <username> [viewer|operator|admin]\n");
    exit(2);
}

function readPassword(string $prompt): string
{
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    fwrite(STDERR, $prompt);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }
    return rtrim((string) $line, "\r\n");
}

$password = readPassword('Password: ');
if (function_exists('posix_isatty') && posix_isatty(STDIN) && readPassword('Again: ') !== $password) {
    fwrite(STDERR, "The passwords don't match.\n");
    exit(1);
}
if (($err = AdminAuth::validatePassword($password)) !== null) {
    fwrite(STDERR, "$err\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = Db::one('SELECT * FROM admins WHERE username = :u', ['u' => $username]);
if ($existing !== null && ($existing['sso_provider'] ?? null) !== null) {
    fwrite(STDERR, "$username signs in with single sign-on ({$existing['sso_provider']}); pick another username.\n");
    exit(1);
}
if ($existing !== null) {
    $changes = ['password_hash' => $hash, 'role' => $role, 'disabled' => 0];
    $had2fa = !empty($existing['totp_secret'] ?? null);
    if (array_key_exists('totp_secret', $existing)) {
        $changes += ['totp_secret' => null, 'totp_last_step' => null, 'totp_recovery' => null];
    }
    Db::update('admins', $changes, ['id' => $existing['id']]);
    echo "Updated $username ($role)" . ($had2fa ? '; two-factor sign-in is now off' : '') . ".\n";
} else {
    Db::insert('admins', ['username' => $username, 'password_hash' => $hash, 'role' => $role]);
    echo "Created $username ($role).\n";
}
