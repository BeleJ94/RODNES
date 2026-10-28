# ETHAN

Suivi des affaires d'un sous-traitant minier du Grand Katanga, du RFQ au paiement : RFQ, sourcing, quotations,
PO, expéditions, POD, facturation et paiements — *built by BELEJ-CONSULTING*.

Architecture, modèle de données, sécurité et feuille de route : [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Démarrage local

Prérequis : PHP ≥ 8.2 (`pdo_mysql`, `mbstring`, `intl`, `fileinfo`), MariaDB, Composer, Node ≥ 20.

```bash
composer install
cp .env.example .env                 # puis renseigner DB_* et APP_KEY
php bin/keygen.php                   # valeur pour APP_KEY
bash bin/fetch-vendor-assets.sh      # bibliothèques front (vérifiées par bin/vendor-assets.lock)
php bin/migrate.php --create         # crée la base et applique les migrations
php bin/seed-demo.php                # données fictives (refusé en production)
composer serve                       # http://127.0.0.1:8080
```

Comptes de démonstration (`Ethan-Demo-2026!`) : `direction@` (approuve les quotations), `commercial@`, `achats@`,
`logistique@`, `finance@` — tous en `@demo.local`. Administrateur : `php bin/create-admin.php` (ci-dessous).

Sous XAMPP sans vhost : `APP_BASE_PATH=/Rodnes/public` puis `http://localhost/Rodnes/public/`.

## Commandes

| Commande | Rôle |
|---|---|
| `php bin/migrate.php [--test] [--create]` | Applique les migrations (base de test avec `--test`). |
| `php bin/create-admin.php --email=… --name="…"` | Crée ou réinitialise un administrateur (mot de passe via `ETHAN_ADMIN_PASSWORD`, sinon généré). |
| `composer test` / `npm test` | Tests PHPUnit / tests JavaScript (`node:test`). |
