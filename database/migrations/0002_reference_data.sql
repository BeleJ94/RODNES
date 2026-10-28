-- ETHAN — référentiel : devises et taux, clients (miniers), fournisseurs, articles.
-- Montants en centimes (BIGINT *_minor), quantités en millièmes (BIGINT *_milli), taux en millionièmes : aucun flottant.

CREATE TABLE currencies (
    code CHAR(3) NOT NULL PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Devises usuelles des fournisseurs ; USD est la monnaie de base (taux implicite 1).
INSERT INTO currencies (code, name) VALUES ('USD', 'Dollar américain'), ('ZAR', 'Rand sud-africain'), ('ZMW', 'Kwacha zambien'), ('EUR', 'Euro'), ('CNY', 'Yuan chinois'), ('CDF', 'Franc congolais');

-- Unités de la devise pour 1 USD, en millionièmes : ZAR 18,25 → 18250000. USD vaut toujours 1000000.
CREATE TABLE exchange_rates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    currency_code CHAR(3) NOT NULL,
    rate_date DATE NOT NULL,
    units_per_usd_micro BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rates_day (currency_code, rate_date),
    CONSTRAINT fk_rates_currency FOREIGN KEY (currency_code) REFERENCES currencies (code),
    CONSTRAINT fk_rates_user FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_rates_positive CHECK (units_per_usd_micro > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(160) NOT NULL,
    rccm VARCHAR(64) NULL,
    nif VARCHAR(64) NULL,
    id_nat VARCHAR(64) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(80) NULL,
    contact_name VARCHAR(120) NULL,
    contact_email VARCHAR(190) NULL,
    contact_phone VARCHAR(32) NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    vat_applicable TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clients_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(160) NOT NULL,
    country CHAR(2) NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'USD',
    contact_name VARCHAR(120) NULL,
    contact_email VARCHAR(190) NULL,
    contact_phone VARCHAR(32) NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppliers_code (code),
    CONSTRAINT fk_suppliers_currency FOREIGN KEY (currency_code) REFERENCES currencies (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(190) NOT NULL,
    unit VARCHAR(8) NOT NULL DEFAULT 'u',
    manufacturer VARCHAR(80) NULL,
    part_number VARCHAR(80) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_items_code (code),
    CONSTRAINT chk_items_unit CHECK (unit IN ('u', 'set', 'pair', 'box', 'roll', 'kg', 't', 'm', 'l', 'm3'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
