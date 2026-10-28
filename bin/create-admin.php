<?php

declare(strict_types=1);

// Crée (ou réinitialise) un compte administrateur.
// php bin/create-admin.php --email=admin@societe.cd --name="Prénom Nom"
// Mot de passe : variable d'environnement ETHAN_ADMIN_PASSWORD (12 caractères minimum), sinon généré et affiché une seule fois.

use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$container = require dirname(__DIR__) . '/app/bootstrap.php';
$opts = getopt('', ['email:', 'name:']);
$email = mb_strtolower(trim((string) ($opts['email'] ?? '')));
$name = trim((string) ($opts['name'] ?? ''));
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '') {
    fwrite(STDERR, "Usage : php bin/create-admin.php --email=… --name=\"…\"\n");
    exit(1);
}

$given = getenv('ETHAN_ADMIN_PASSWORD');
if (is_string($given) && $given !== '' && mb_strlen($given) < 12) {
    fwrite(STDERR, "Attention : mot de passe de moins de 12 caractères, à remplacer par une phrase de passe plus longue.\n");
}
$password = is_string($given) && $given !== '' ? $given : rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');

$db = $container->get(Database::class);
$db->run(
    "INSERT INTO users (email, name, role, password_hash, password_changed_at) VALUES (:e, :n, 'admin', :h, NOW())
     ON DUPLICATE KEY UPDATE name = VALUES(name), role = 'admin', is_active = 1, password_hash = VALUES(password_hash),
                             password_changed_at = NOW(), session_version = session_version + 1",
    ['e' => $email, 'n' => $name, 'h' => password_hash($password, PASSWORD_ARGON2ID)],
);

echo "Administrateur {$email} prêt." . PHP_EOL;
if (!is_string($given) || $given === '') {
    echo "Mot de passe initial (affiché une seule fois) : {$password}" . PHP_EOL;
}
