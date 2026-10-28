-- ETHAN — fichiers de l'offre fournisseur (PDF, scan, e-mail…) joints à la saisie de l'offre.

CREATE TABLE supplier_quote_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_quote_id INT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sq_files_file (file_id),
    KEY ix_sq_files_quote (supplier_quote_id),
    CONSTRAINT fk_sq_files_quote FOREIGN KEY (supplier_quote_id) REFERENCES supplier_quotes (id) ON DELETE CASCADE,
    CONSTRAINT fk_sq_files_file FOREIGN KEY (file_id) REFERENCES files (id),
    CONSTRAINT fk_sq_files_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
