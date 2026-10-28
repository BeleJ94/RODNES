-- ETHAN — logistique : expéditions (plusieurs PO fournisseurs, un client), jalons scellés, POD.

-- Le PO client suit aussi la livraison (quantités reçues sur POD).
ALTER TABLE client_orders DROP CONSTRAINT chk_co_status;
ALTER TABLE client_orders ADD CONSTRAINT chk_co_status CHECK (status IN ('received', 'ordering', 'ordered', 'partially_delivered', 'delivered', 'closed', 'cancelled'));

CREATE TABLE shipments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(20) NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'planned',
    carrier_type VARCHAR(10) NOT NULL DEFAULT 'forwarder',
    carrier_name VARCHAR(160) NULL,
    tracking_reference VARCHAR(80) NULL,
    origin VARCHAR(160) NULL,
    destination VARCHAR(255) NULL,
    planned_pickup_on DATE NULL,
    eta DATE NULL,
    picked_up_at DATETIME NULL,
    delivered_at DATETIME NULL,
    last_event_hash CHAR(64) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipments_reference (reference),
    KEY ix_shipments_status (status, eta),
    CONSTRAINT fk_sh_client FOREIGN KEY (client_id) REFERENCES clients (id),
    CONSTRAINT fk_sh_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_sh_status CHECK (status IN ('planned', 'picked_up', 'in_transit', 'at_border', 'cleared', 'delivered', 'cancelled')),
    CONSTRAINT chk_sh_carrier CHECK (carrier_type IN ('forwarder', 'supplier'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quantité expédiée par ligne de PO fournisseur (jamais plus que commandé, hors expéditions annulées).
CREATE TABLE shipment_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT UNSIGNED NOT NULL,
    supplier_order_line_id INT UNSIGNED NOT NULL,
    quantity_milli BIGINT UNSIGNED NOT NULL,
    received_milli BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_shl (shipment_id, supplier_order_line_id),
    KEY ix_shl_sol (supplier_order_line_id),
    CONSTRAINT fk_shl_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id) ON DELETE CASCADE,
    CONSTRAINT fk_shl_sol FOREIGN KEY (supplier_order_line_id) REFERENCES supplier_order_lines (id),
    CONSTRAINT chk_shl_qty CHECK (quantity_milli > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jalons scellés : hash = HMAC(APP_KEY, prev_hash + contenu canonique). Ajout seul.
CREATE TABLE shipment_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT UNSIGNED NOT NULL,
    type VARCHAR(10) NOT NULL,
    status_to VARCHAR(12) NULL,
    occurred_at DATETIME NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    location VARCHAR(160) NULL,
    note VARCHAR(500) NULL,
    payload LONGTEXT NULL CHECK (payload IS NULL OR JSON_VALID(payload)),
    user_id INT UNSIGNED NULL,
    prev_hash CHAR(64) NOT NULL,
    hash CHAR(64) NOT NULL,
    KEY ix_she_shipment (shipment_id, id),
    CONSTRAINT fk_she_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id),
    CONSTRAINT fk_she_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT chk_she_type CHECK (type IN ('created', 'milestone', 'pod', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preuve de livraison : une par expédition, document signé obligatoire (empreinte SHA-256 dans le journal scellé).
CREATE TABLE pods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT UNSIGNED NOT NULL,
    received_at DATETIME NOT NULL,
    receiver_name VARCHAR(120) NOT NULL,
    receiver_reference VARCHAR(80) NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pods_shipment (shipment_id),
    CONSTRAINT fk_pod_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id),
    CONSTRAINT fk_pod_file FOREIGN KEY (file_id) REFERENCES files (id),
    CONSTRAINT fk_pod_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
