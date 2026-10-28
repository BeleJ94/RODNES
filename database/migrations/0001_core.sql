-- ETHAN — socle : utilisateurs, sécurité, journal d'activité, numérotation, fichiers, paramètres société.

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(32) NULL,
    role VARCHAR(20) NOT NULL,
    locale CHAR(2) NOT NULL DEFAULT 'fr',
    password_hash VARCHAR(255) NOT NULL,
    totp_secret VARBINARY(255) NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    password_changed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT chk_users_role CHECK (role IN ('admin', 'manager', 'sales', 'purchasing', 'logistics', 'finance', 'viewer'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip VARBINARY(16) NULL,
    success TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_login_email (email, created_at),
    KEY ix_login_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qui / quoi / quand / avant → après, écrit dans la même transaction que la modification (ajout seul).
CREATE TABLE activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    changes LONGTEXT NULL CHECK (changes IS NULL OR JSON_VALID(changes)),
    ip VARBINARY(16) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_activity_entity (entity_type, entity_id),
    KEY ix_activity_date (created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Références sans trou par type et par année (SELECT … FOR UPDATE) : RFQ-2026-0001, QT-2026-0001…
CREATE TABLE sequences (
    name VARCHAR(32) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    value INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (name, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    storage_key CHAR(64) NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    mime VARCHAR(80) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_files_key (storage_key),
    CONSTRAINT fk_files_user FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paramètres de la société (identité légale, TVA, validation des marges…), clé → valeur.
CREATE TABLE settings (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value VARCHAR(1000) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
