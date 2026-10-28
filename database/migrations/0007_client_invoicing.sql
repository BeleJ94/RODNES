-- ETHAN — finance (volet client) : factures sur quantités livrées, encaissements, affectations.
-- Numéro de facture attribué à l'émission seulement (série continue, sans trou) ; une facture émise ne se modifie plus.

CREATE TABLE client_invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NULL,
    client_id INT UNSIGNED NOT NULL,
    client_order_id INT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    issue_date DATE NULL,
    due_date DATE NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'USD',
    vat_applicable TINYINT(1) NOT NULL DEFAULT 1,
    vat_rate_bp SMALLINT UNSIGNED NOT NULL DEFAULT 1600,
    subtotal_minor BIGINT UNSIGNED NULL,
    vat_minor BIGINT UNSIGNED NULL,
    total_minor BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    issued_at DATETIME NULL,
    issued_by INT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancelled_reason VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_invoices_reference (reference),
    KEY ix_client_invoices_client (client_id, status, due_date),
    KEY ix_client_invoices_order (client_order_id),
    CONSTRAINT fk_ci_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_ci_order FOREIGN KEY (client_order_id) REFERENCES client_orders (id),
    CONSTRAINT fk_ci_currency FOREIGN KEY (currency_code) REFERENCES currencies (code),
    CONSTRAINT fk_ci_issuer FOREIGN KEY (issued_by) REFERENCES users (id),
    CONSTRAINT fk_ci_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_ci_status CHECK (status IN ('draft', 'issued', 'partially_paid', 'paid', 'cancelled')),
    CONSTRAINT chk_ci_issued CHECK (status = 'draft' OR (reference IS NOT NULL AND total_minor IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_invoice_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    client_order_line_id INT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL,
    quantity_milli BIGINT UNSIGNED NOT NULL,
    unit VARCHAR(8) NOT NULL DEFAULT 'u',
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_cil_no (invoice_id, line_no),
    KEY ix_cil_order_line (client_order_line_id),
    CONSTRAINT fk_cil_invoice FOREIGN KEY (invoice_id) REFERENCES client_invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_cil_order_line FOREIGN KEY (client_order_line_id) REFERENCES client_order_lines (id),
    CONSTRAINT chk_cil_qty CHECK (quantity_milli > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    received_on DATE NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    method VARCHAR(14) NOT NULL DEFAULT 'bank_transfer',
    bank_reference VARCHAR(80) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_payments_reference (reference),
    KEY ix_client_payments_client (client_id, received_on),
    CONSTRAINT fk_cp_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_cp_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_cp_amount CHECK (amount_minor > 0),
    CONSTRAINT chk_cp_method CHECK (method IN ('bank_transfer', 'cheque', 'cash', 'mobile_money', 'other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un encaissement peut solder plusieurs factures ; une facture peut être réglée en plusieurs fois.
CREATE TABLE client_payment_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_cpa (payment_id, invoice_id),
    KEY ix_cpa_invoice (invoice_id),
    CONSTRAINT fk_cpa_payment FOREIGN KEY (payment_id) REFERENCES client_payments (id) ON DELETE CASCADE,
    CONSTRAINT fk_cpa_invoice FOREIGN KEY (invoice_id) REFERENCES client_invoices (id),
    CONSTRAINT chk_cpa_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
