-- ETHAN — finance (volet fournisseur) : factures fournisseurs rapprochées PO ↔ réception (POD) ↔ facture.
-- Une facture porte sur un PO fournisseur, dans sa devise ; le taux de la facture fixe la contre-valeur USD.
-- Conforme au rapprochement → approuvée d'office ; sinon « en attente » jusqu'à validation motivée.

CREATE TABLE supplier_invoices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    supplier_order_id INT UNSIGNED NOT NULL,
    supplier_invoice_number VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'on_hold',
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    currency_code CHAR(3) NOT NULL,
    fx_units_per_usd_micro BIGINT UNSIGNED NOT NULL,
    subtotal_minor BIGINT UNSIGNED NOT NULL,
    vat_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_minor BIGINT UNSIGNED NOT NULL,
    variances LONGTEXT NULL CHECK (variances IS NULL OR JSON_VALID(variances)),
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    approval_note VARCHAR(255) NULL,
    file_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    cancelled_at DATETIME NULL,
    cancelled_reason VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supplier_invoices_reference (reference),
    UNIQUE KEY uq_supplier_invoices_number (supplier_id, supplier_invoice_number),
    KEY ix_supplier_invoices_status (status, due_date),
    KEY ix_supplier_invoices_order (supplier_order_id),
    CONSTRAINT fk_si_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT fk_si_order FOREIGN KEY (supplier_order_id) REFERENCES supplier_orders (id),
    CONSTRAINT fk_si_currency FOREIGN KEY (currency_code) REFERENCES currencies (code),
    CONSTRAINT fk_si_file FOREIGN KEY (file_id) REFERENCES files (id),
    CONSTRAINT fk_si_approver FOREIGN KEY (approved_by) REFERENCES users (id),
    CONSTRAINT fk_si_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_si_status CHECK (status IN ('on_hold', 'approved', 'partially_paid', 'paid', 'cancelled')),
    CONSTRAINT chk_si_fx CHECK (fx_units_per_usd_micro > 0),
    CONSTRAINT chk_si_total CHECK (total_minor = subtotal_minor + vat_minor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- po_unit_price_minor : prix du PO au moment de la saisie (preuve de l'écart de prix éventuel).
CREATE TABLE supplier_invoice_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    supplier_order_line_id INT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL,
    quantity_milli BIGINT UNSIGNED NOT NULL,
    unit VARCHAR(8) NOT NULL DEFAULT 'u',
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    po_unit_price_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_sil_no (invoice_id, line_no),
    KEY ix_sil_po_line (supplier_order_line_id),
    CONSTRAINT fk_sil_invoice FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_sil_po_line FOREIGN KEY (supplier_order_line_id) REFERENCES supplier_order_lines (id),
    CONSTRAINT chk_sil_qty CHECK (quantity_milli > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
