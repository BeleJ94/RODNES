-- ETHAN — ventes et sourcing : RFQ client → offres fournisseurs → quotation.
-- Chaque ligne garde son lien avec la ligne d'origine : c'est la traçabilité de l'affaire.

CREATE TABLE rfqs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    client_reference VARCHAR(64) NULL,
    received_on DATE NOT NULL,
    due_at DATETIME NULL,
    buyer_name VARCHAR(120) NULL,
    buyer_email VARCHAR(190) NULL,
    delivery_site VARCHAR(160) NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'received',
    owner_id INT UNSIGNED NULL,
    closed_reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rfqs_reference (reference),
    KEY ix_rfqs_status (status, due_at),
    KEY ix_rfqs_client (client_id, received_on),
    CONSTRAINT fk_rfqs_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_rfqs_owner FOREIGN KEY (owner_id) REFERENCES users (id),
    CONSTRAINT fk_rfqs_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_rfqs_status CHECK (status IN ('received', 'sourcing', 'quoted', 'won', 'lost', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rfq_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rfq_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NULL,
    description VARCHAR(500) NOT NULL,
    quantity_milli BIGINT UNSIGNED NOT NULL,
    unit VARCHAR(8) NOT NULL DEFAULT 'u',
    required_by DATE NULL,
    UNIQUE KEY uq_rfq_lines_no (rfq_id, line_no),
    CONSTRAINT fk_rfq_lines_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE CASCADE,
    CONSTRAINT fk_rfq_lines_item FOREIGN KEY (item_id) REFERENCES items (id),
    CONSTRAINT chk_rfq_lines_qty CHECK (quantity_milli > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sourcing : une demande de prix par fournisseur et par RFQ, puis son offre (dans la devise du fournisseur).
CREATE TABLE supplier_quotes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rfq_id INT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    supplier_reference VARCHAR(64) NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'requested',
    requested_on DATE NOT NULL,
    received_on DATE NULL,
    currency_code CHAR(3) NOT NULL,
    incoterm VARCHAR(8) NULL,
    lead_time_days SMALLINT UNSIGNED NULL,
    valid_until DATE NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supplier_quotes (rfq_id, supplier_id),
    CONSTRAINT fk_sq_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs (id),
    CONSTRAINT fk_sq_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT fk_sq_currency FOREIGN KEY (currency_code) REFERENCES currencies (code),
    CONSTRAINT fk_sq_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_sq_status CHECK (status IN ('requested', 'received', 'declined'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_quote_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_quote_id INT UNSIGNED NOT NULL,
    rfq_line_id INT UNSIGNED NOT NULL,
    unit_price_minor BIGINT UNSIGNED NOT NULL,
    lead_time_days SMALLINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    UNIQUE KEY uq_sq_lines (supplier_quote_id, rfq_line_id),
    CONSTRAINT fk_sq_lines_quote FOREIGN KEY (supplier_quote_id) REFERENCES supplier_quotes (id) ON DELETE CASCADE,
    CONSTRAINT fk_sq_lines_rfq_line FOREIGN KEY (rfq_line_id) REFERENCES rfq_lines (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quotation au client, toujours en USD. Une révision = une nouvelle ligne (même référence, version + 1).
CREATE TABLE quotations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL,
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    rfq_id INT UNSIGNED NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    currency_code CHAR(3) NOT NULL DEFAULT 'USD',
    valid_until DATE NOT NULL,
    incoterm VARCHAR(8) NULL,
    delivery_terms VARCHAR(255) NULL,
    payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    vat_applicable TINYINT(1) NOT NULL DEFAULT 1,
    vat_rate_bp SMALLINT UNSIGNED NOT NULL DEFAULT 1600,
    notes TEXT NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    sent_at DATETIME NULL,
    decided_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quotations_version (reference, version),
    KEY ix_quotations_status (status, valid_until),
    CONSTRAINT fk_quotations_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs (id),
    CONSTRAINT fk_quotations_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_quotations_currency FOREIGN KEY (currency_code) REFERENCES currencies (code),
    CONSTRAINT fk_quotations_approver FOREIGN KEY (approved_by) REFERENCES users (id),
    CONSTRAINT fk_quotations_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_quotations_status CHECK (status IN ('draft', 'pending_approval', 'approved', 'sent', 'accepted', 'rejected', 'superseded'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Coût d'origine (devise + taux utilisé) conservé : la marge reste explicable même si le taux change ensuite.
CREATE TABLE quotation_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quotation_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    rfq_line_id INT UNSIGNED NULL,
    supplier_quote_line_id INT UNSIGNED NULL,
    description VARCHAR(500) NOT NULL,
    quantity_milli BIGINT UNSIGNED NOT NULL,
    unit VARCHAR(8) NOT NULL DEFAULT 'u',
    cost_currency CHAR(3) NOT NULL DEFAULT 'USD',
    cost_unit_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    fx_units_per_usd_micro BIGINT UNSIGNED NOT NULL DEFAULT 1000000,
    cost_unit_usd_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    extra_unit_usd_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    unit_price_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_quotation_lines_no (quotation_id, line_no),
    CONSTRAINT fk_ql_quotation FOREIGN KEY (quotation_id) REFERENCES quotations (id) ON DELETE CASCADE,
    CONSTRAINT fk_ql_rfq_line FOREIGN KEY (rfq_line_id) REFERENCES rfq_lines (id),
    CONSTRAINT fk_ql_sq_line FOREIGN KEY (supplier_quote_line_id) REFERENCES supplier_quote_lines (id),
    CONSTRAINT fk_ql_currency FOREIGN KEY (cost_currency) REFERENCES currencies (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
