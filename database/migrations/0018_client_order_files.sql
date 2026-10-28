-- ETHAN — pièces jointes d'un PO client : le bon de commande reçu du client (PDF, scan, e-mail…).
-- Obligatoire à la création (contrôle applicatif) ; d'autres fichiers peuvent s'ajouter tant que le PO est ouvert.

CREATE TABLE client_order_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_order_id INT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_order_files_file (file_id),
    KEY ix_client_order_files_order (client_order_id),
    CONSTRAINT fk_client_order_files_order FOREIGN KEY (client_order_id) REFERENCES client_orders (id),
    CONSTRAINT fk_client_order_files_file FOREIGN KEY (file_id) REFERENCES files (id),
    CONSTRAINT fk_client_order_files_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
