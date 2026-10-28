-- ETHAN — paiements fournisseurs : dans la devise des factures, au taux du jour du paiement (coût réel en USD, écart de change).
-- Seules les factures « bon à payer » reçoivent une imputation ; jamais au-delà du reste dû.

CREATE TABLE supplier_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    paid_on DATE NOT NULL,
    currency_code CHAR(3) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    fx_units_per_usd_micro BIGINT UNSIGNED NOT NULL,
    method VARCHAR(16) NOT NULL,
    bank_reference VARCHAR(80) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supplier_payments_reference (reference),
    KEY ix_supplier_payments_supplier (supplier_id, paid_on),
    CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT fk_sp_currency FOREIGN KEY (currency_code) REFERENCES currencies (code),
    CONSTRAINT fk_sp_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_sp_amount CHECK (amount_minor > 0),
    CONSTRAINT chk_sp_fx CHECK (fx_units_per_usd_micro > 0),
    CONSTRAINT chk_sp_method CHECK (method IN ('bank_transfer', 'cheque', 'cash', 'mobile_money', 'other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_payment_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_spa (payment_id, invoice_id),
    KEY ix_spa_invoice (invoice_id),
    CONSTRAINT fk_spa_payment FOREIGN KEY (payment_id) REFERENCES supplier_payments (id) ON DELETE CASCADE,
    CONSTRAINT fk_spa_invoice FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id),
    CONSTRAINT chk_spa_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
