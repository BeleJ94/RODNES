-- ETHAN — documents de transport d'une expédition (liste de colisage, lettre de transport, bon de livraison…).
-- Facultatifs ; joints à la création de l'expédition.

CREATE TABLE shipment_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipment_files_file (file_id),
    KEY ix_shipment_files_shipment (shipment_id),
    CONSTRAINT fk_shipment_files_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id),
    CONSTRAINT fk_shipment_files_file FOREIGN KEY (file_id) REFERENCES files (id),
    CONSTRAINT fk_shipment_files_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
