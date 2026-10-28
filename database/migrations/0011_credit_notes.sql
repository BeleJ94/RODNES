-- ETHAN — avoirs clients : correction d'une facture émise (retour, manquant, remise, erreur de prix).
-- Numéro AV-AAAA-NNNN attribué à l'émission ; un avoir ne se modifie ni ne se supprime (série continue).
-- Il solde d'abord le reste dû de sa facture ; le surplus (facture déjà payée) reste un crédit client à imputer.

CREATE TABLE client_credit_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    issue_date DATE NOT NULL,
    reason VARCHAR(255) NOT NULL,
    is_return TINYINT(1) NOT NULL DEFAULT 0,
    vat_applicable TINYINT(1) NOT NULL,
    vat_rate_bp SMALLINT UNSIGNED NOT NULL,
    subtotal_minor BIGINT UNSIGNED NOT NULL,
    vat_minor BIGINT UNSIGNED NOT NULL,
    total_minor BIGINT UNSIGNED NOT NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_credit_notes_reference (reference),
    KEY ix_credit_notes_client (client_id, issue_date),
    KEY ix_credit_notes_invoice (invoice_id),
    CONSTRAINT fk_cn_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_cn_invoice FOREIGN KEY (invoice_id) REFERENCES client_invoices (id),
    CONSTRAINT fk_cn_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_cn_total CHECK (total_minor = subtotal_minor + vat_minor AND total_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ligne créditée : quantité × prix unitaire crédité (prix ≤ prix facturé : une remise crédite toute la quantité à un prix réduit).
CREATE TABLE client_credit_note_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_note_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    invoice_line_id INT UNSIGNED NOT NULL,
    client_order_line_id INT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL,
    quantity_milli BIGINT UNSIGNED NOT NULL,
    unit VARCHAR(8) NOT NULL DEFAULT 'u',
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_cnl_no (credit_note_id, line_no),
    KEY ix_cnl_invoice_line (invoice_line_id),
    KEY ix_cnl_order_line (client_order_line_id),
    CONSTRAINT fk_cnl_note FOREIGN KEY (credit_note_id) REFERENCES client_credit_notes (id) ON DELETE CASCADE,
    CONSTRAINT fk_cnl_invoice_line FOREIGN KEY (invoice_line_id) REFERENCES client_invoice_lines (id),
    CONSTRAINT fk_cnl_order_line FOREIGN KEY (client_order_line_id) REFERENCES client_order_lines (id),
    CONSTRAINT chk_cnl_qty CHECK (quantity_milli > 0 AND unit_price_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_credit_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_note_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cca (credit_note_id, invoice_id),
    KEY ix_cca_invoice (invoice_id),
    CONSTRAINT fk_cca_note FOREIGN KEY (credit_note_id) REFERENCES client_credit_notes (id),
    CONSTRAINT fk_cca_invoice FOREIGN KEY (invoice_id) REFERENCES client_invoices (id),
    CONSTRAINT chk_cca_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
