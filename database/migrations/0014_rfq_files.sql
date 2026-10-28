-- ETHAN — pièces jointes d'une RFQ : le document du client (demande de prix, cahier des charges, e-mail…).
-- Obligatoire à la création (contrôle applicatif) ; d'autres fichiers peuvent s'ajouter tant que la RFQ est ouverte.

CREATE TABLE rfq_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rfq_id INT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rfq_files_file (file_id),
    KEY ix_rfq_files_rfq (rfq_id),
    CONSTRAINT fk_rfq_files_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs (id),
    CONSTRAINT fk_rfq_files_file FOREIGN KEY (file_id) REFERENCES files (id),
    CONSTRAINT fk_rfq_files_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
