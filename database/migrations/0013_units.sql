-- ETHAN — référentiel des unités de mesure des articles (auparavant liste fixe dans le code).
-- Le code est stable : les lignes de documents (RFQ, devis, PO, factures…) en gardent une copie ;
-- une unité ne se supprime pas, elle se désactive (plus proposée, mais toujours affichée sur l'historique).

CREATE TABLE units (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(8) NOT NULL,
    name VARCHAR(40) NOT NULL,
    name_en VARCHAR(40) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_units_code (code),
    CONSTRAINT chk_units_code CHECK (code REGEXP '^[a-z0-9]+$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO units (code, name, name_en, sort_order) VALUES
    ('u', 'u.', 'pcs', 10),
    ('set', 'jeu', 'set', 20),
    ('pair', 'paire', 'pair', 30),
    ('box', 'boîte', 'box', 40),
    ('roll', 'rouleau', 'roll', 50),
    ('kg', 'kg', 'kg', 60),
    ('t', 't', 't', 70),
    ('m', 'm', 'm', 80),
    ('l', 'L', 'L', 90),
    ('m3', 'm³', 'm³', 100);

-- Un article ne peut porter qu'une unité du référentiel.
ALTER TABLE items ADD CONSTRAINT fk_items_unit FOREIGN KEY (unit) REFERENCES units (code) ON UPDATE RESTRICT ON DELETE RESTRICT;
