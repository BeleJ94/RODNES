-- ETHAN — suivi documentaire des expéditions.
-- Référentiel des types de document (facture commerciale, liste de colisage, lettre de transport…), chacun
-- obligatoire ou non par défaut ; chaque expédition reprend la liste des documents exigés (modifiable),
-- et chaque document fourni porte sa référence, sa date et, le cas échéant, le fichier.

CREATE TABLE shipment_document_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(24) NOT NULL,
    name VARCHAR(80) NOT NULL,
    name_en VARCHAR(80) NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipment_document_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO shipment_document_types (code, name, name_en, is_required, sort_order) VALUES
    ('COMMERCIAL_INVOICE', 'Facture commerciale', 'Commercial invoice', 1, 10),
    ('PACKING_LIST', 'Liste de colisage', 'Packing list', 1, 20),
    ('WAYBILL', 'Lettre de transport (CMR / LTA / BL)', 'Waybill (CMR / AWB / B/L)', 1, 30),
    ('CERT_ORIGIN', 'Certificat d''origine', 'Certificate of origin', 0, 40),
    ('CUSTOMS_DECL', 'Déclaration en douane', 'Customs declaration', 0, 50),
    ('DELIVERY_NOTE', 'Bon de livraison signé', 'Signed delivery note', 0, 60);

-- Documents exigés pour une expédition (repris du référentiel à la création, modifiables ensuite).
CREATE TABLE shipment_required_documents (
    shipment_id INT UNSIGNED NOT NULL,
    doc_type_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (shipment_id, doc_type_id),
    CONSTRAINT fk_srd_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id),
    CONSTRAINT fk_srd_type FOREIGN KEY (doc_type_id) REFERENCES shipment_document_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documents fournis : type, référence, date ; le fichier devient facultatif (référence seule possible).
ALTER TABLE shipment_files
    ADD COLUMN doc_type_id INT UNSIGNED NULL AFTER shipment_id,
    ADD COLUMN reference VARCHAR(80) NULL AFTER doc_type_id,
    ADD COLUMN document_date DATE NULL AFTER reference,
    MODIFY file_id BIGINT UNSIGNED NULL,
    ADD KEY ix_shipment_files_type (doc_type_id),
    ADD CONSTRAINT fk_shipment_files_type FOREIGN KEY (doc_type_id) REFERENCES shipment_document_types (id);

-- Expéditions existantes : documents obligatoires du référentiel.
INSERT INTO shipment_required_documents (shipment_id, doc_type_id)
SELECT sh.id, t.id FROM shipments sh CROSS JOIN shipment_document_types t WHERE t.is_required = 1;
