-- ETHAN — frais logistiques (transport, dédouanement, frais de frontière…) : factures fournisseurs de type « logistics ».
-- Elles ne portent pas sur un PO mais sur des expéditions, ligne par ligne : une facture par camion ou une facture
-- mensuelle groupée. Mêmes paiements, dettes et relevés que les achats ; réparties sur la marge réelle.

ALTER TABLE supplier_invoices
    ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'goods' AFTER reference,
    MODIFY supplier_order_id INT UNSIGNED NULL,
    ADD CONSTRAINT chk_si_kind CHECK (kind IN ('goods', 'logistics')),
    ADD CONSTRAINT chk_si_kind_order CHECK ((kind = 'goods') = (supplier_order_id IS NOT NULL));

CREATE TABLE supplier_invoice_cost_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    shipment_id INT UNSIGNED NOT NULL,
    cost_type VARCHAR(12) NOT NULL,
    description VARCHAR(255) NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_sicl_no (invoice_id, line_no),
    KEY ix_sicl_shipment (shipment_id),
    CONSTRAINT fk_sicl_invoice FOREIGN KEY (invoice_id) REFERENCES supplier_invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_sicl_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id),
    CONSTRAINT chk_sicl_amount CHECK (amount_minor > 0),
    CONSTRAINT chk_sicl_type CHECK (cost_type IN ('transport', 'customs_duty', 'clearing', 'border_fees', 'insurance', 'other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
