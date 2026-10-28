-- ETHAN — livraison en deux temps : déclaration de la livraison (le bon de livraison / POD est alors généré et
-- numéroté pour signature), puis, une fois signé par le transporteur et le client, dépôt du POD signé.

ALTER TABLE shipments
    ADD COLUMN delivery_note_number VARCHAR(20) NULL AFTER delivered_at,
    ADD COLUMN delivery_note_issued_at DATETIME NULL AFTER delivery_note_number,
    ADD COLUMN delivery_note_issued_by INT UNSIGNED NULL AFTER delivery_note_issued_at,
    ADD CONSTRAINT fk_shipments_dn_user FOREIGN KEY (delivery_note_issued_by) REFERENCES users (id);

-- Expéditions déjà livrées : bon de livraison réputé émis à la livraison.
UPDATE shipments SET delivery_note_number = REPLACE(reference, 'EXP-', 'BL-'), delivery_note_issued_at = delivered_at
 WHERE status = 'delivered' AND delivered_at IS NOT NULL;

-- Journal scellé : nouvel événement « delivered » (déclaration de livraison, POD généré).
ALTER TABLE shipment_events DROP CONSTRAINT chk_she_type;
ALTER TABLE shipment_events ADD CONSTRAINT chk_she_type CHECK (type IN ('created', 'milestone', 'delivered', 'pod', 'cancelled'));
