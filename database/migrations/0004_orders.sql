-- ETHAN — commandes : PO client (reçu du minier) et PO fournisseurs (émis par nous).
-- Chaque ligne de PO fournisseur couvre une ligne de PO client : c'est le suivi « commandé / à commander ».

CREATE TABLE client_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL,
    client_po_number VARCHAR(64) NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    rfq_id INT UNSIGNED NULL,
    quotation_id INT UNSIGNED NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'received',
    received_on DATE NOT NULL,
    required_by DATE NULL,
    delivery_site VARCHAR(160) NULL,
    incoterm VARCHAR(8) NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    vat_applicable TINYINT(1) NOT NULL DEFAULT 1,
    vat_rate_bp SMALLINT UNSIGNED NOT NULL DEFAULT 1600,
    closed_reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_orders_reference (reference),
    UNIQUE KEY uq_client_orders_po (client_id, client_po_number),
    KEY ix_client_orders_status (status, received_on),
    CONSTRAINT fk_co_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_co_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs (id),
    CONSTRAINT fk_co_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (id),
    CONSTRAINT fk_co_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_co_status CHECK (status IN ('received', 'ordering', 'ordered', 'closed', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prix de vente (USD) du PO client ; coût unitaire estimé repris de la quotation (coût fournisseur + frais).
CREATE TABLE client_order_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_order_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    quotation_line_id INT UNSIGNED NULL,
    rfq_line_id INT UNSIGNED NULL,
    supplier_quote_line_id INT UNSIGNED NULL,
    description VARCHAR(500) NOT NULL,
    quantity_milli BIGINT UNSIGNED NOT NULL,
    unit VARCHAR(8) NOT NULL DEFAULT 'u',
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    est_unit_cost_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    est_extra_unit_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_col_no (client_order_id, line_no),
    CONSTRAINT fk_col_order FOREIGN KEY (client_order_id) REFERENCES client_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_col_ql FOREIGN KEY (quotation_line_id) REFERENCES quotation_lines (id),
    CONSTRAINT fk_col_rfq_line FOREIGN KEY (rfq_line_id) REFERENCES rfq_lines (id),
    CONSTRAINT fk_col_sq_line FOREIGN KEY (supplier_quote_line_id) REFERENCES supplier_quote_lines (id),
    CONSTRAINT chk_col_qty CHECK (quantity_milli > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PO fournisseur, dans la devise du fournisseur ; taux figé à l'émission (coût réel en USD explicable).
-- Livraison directe chez le client : delivery_address reprend le site du client.
CREATE TABLE supplier_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    client_order_id INT UNSIGNED NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    currency_code CHAR(3) NOT NULL,
    fx_units_per_usd_micro BIGINT UNSIGNED NOT NULL,
    issued_on DATE NULL,
    expected_ready_on DATE NULL,
    incoterm VARCHAR(8) NULL,
    delivery_address VARCHAR(255) NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    supplier_reference VARCHAR(64) NULL,
    cancelled_reason VARCHAR(255) NULL,
    notes TEXT NULL,
    sent_at DATETIME NULL,
    confirmed_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supplier_orders_reference (reference),
    KEY ix_supplier_orders_status (status, expected_ready_on),
    KEY ix_supplier_orders_client_order (client_order_id),
    CONSTRAINT fk_so_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT fk_so_client_order FOREIGN KEY (client_order_id) REFERENCES client_orders (id),
    CONSTRAINT fk_so_currency FOREIGN KEY (currency_code) REFERENCES currencies (code),
    CONSTRAINT fk_so_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_so_status CHECK (status IN ('draft', 'sent', 'confirmed', 'cancelled')),
    CONSTRAINT chk_so_rate CHECK (fx_units_per_usd_micro > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_order_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_order_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    client_order_line_id INT UNSIGNED NOT NULL,
    description VARCHAR(500) NOT NULL,
    quantity_milli BIGINT UNSIGNED NOT NULL,
    unit VARCHAR(8) NOT NULL DEFAULT 'u',
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_sol_no (supplier_order_id, line_no),
    KEY ix_sol_client_line (client_order_line_id),
    CONSTRAINT fk_sol_order FOREIGN KEY (supplier_order_id) REFERENCES supplier_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_sol_client_line FOREIGN KEY (client_order_line_id) REFERENCES client_order_lines (id),
    CONSTRAINT chk_sol_qty CHECK (quantity_milli > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
