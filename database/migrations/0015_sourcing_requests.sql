-- ETHAN — demande de prix fournisseur : document numéroté (DP-AAAA-NNNN) remis au fournisseur,
-- avec les lignes et quantités demandées (sous-ensemble de la RFQ), une date de réponse et un message.

ALTER TABLE supplier_quotes
    ADD COLUMN reference VARCHAR(20) NULL AFTER id,
    ADD COLUMN response_due DATE NULL AFTER requested_on,
    ADD COLUMN message TEXT NULL AFTER notes,
    ADD UNIQUE KEY uq_supplier_quotes_reference (reference);

CREATE TABLE supplier_quote_requested_lines (
    supplier_quote_id INT UNSIGNED NOT NULL,
    rfq_line_id INT UNSIGNED NOT NULL,
    quantity_milli BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (supplier_quote_id, rfq_line_id),
    KEY ix_sqrl_rfq_line (rfq_line_id),
    CONSTRAINT fk_sqrl_quote FOREIGN KEY (supplier_quote_id) REFERENCES supplier_quotes (id) ON DELETE CASCADE,
    CONSTRAINT fk_sqrl_rfq_line FOREIGN KEY (rfq_line_id) REFERENCES rfq_lines (id),
    CONSTRAINT chk_sqrl_qty CHECK (quantity_milli > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Demandes existantes : toutes les lignes de la RFQ, aux quantités de la RFQ ; numérotation par année d'envoi.
INSERT INTO supplier_quote_requested_lines (supplier_quote_id, rfq_line_id, quantity_milli)
SELECT q.id, l.id, l.quantity_milli FROM supplier_quotes q JOIN rfq_lines l ON l.rfq_id = q.rfq_id;

UPDATE supplier_quotes q
  JOIN (SELECT id, YEAR(requested_on) AS y, ROW_NUMBER() OVER (PARTITION BY YEAR(requested_on) ORDER BY id) AS rn FROM supplier_quotes) x ON x.id = q.id
   SET q.reference = CONCAT('DP-', x.y, '-', LPAD(x.rn, 4, '0'));

INSERT INTO sequences (name, year, value)
SELECT 'supplier_rfq', YEAR(requested_on), COUNT(*) FROM supplier_quotes GROUP BY YEAR(requested_on)
ON DUPLICATE KEY UPDATE value = VALUES(value);
