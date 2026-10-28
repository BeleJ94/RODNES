<?php

declare(strict_types=1);

// Génère une valeur pour APP_KEY (32 octets aléatoires, base64).
// Changer APP_KEY invalide la vérification des journaux de livraison déjà scellés : la conserver précieusement.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

echo base64_encode(random_bytes(32)), PHP_EOL;
