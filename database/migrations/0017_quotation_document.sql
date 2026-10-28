-- ETHAN — devis au format « Quotation » : livraison, expédition, disponibilité par ligne, identifiant client.

ALTER TABLE clients
    ADD COLUMN customer_number VARCHAR(40) NULL AFTER code;

ALTER TABLE quotations
    ADD COLUMN payment_terms_basis VARCHAR(10) NOT NULL DEFAULT 'delivery' AFTER payment_terms_days,
    ADD COLUMN ship_to_name VARCHAR(160) NULL AFTER delivery_terms,
    ADD COLUMN ship_to_address VARCHAR(500) NULL AFTER ship_to_name,
    ADD COLUMN ship_to_phone VARCHAR(40) NULL AFTER ship_to_address,
    ADD COLUMN country_of_origin VARCHAR(80) NULL AFTER ship_to_phone,
    ADD COLUMN estimated_ship_date DATE NULL AFTER country_of_origin,
    ADD COLUMN transport_mode VARCHAR(12) NULL AFTER estimated_ship_date,
    ADD COLUMN carrier VARCHAR(120) NULL AFTER transport_mode,
    ADD COLUMN packages_count SMALLINT UNSIGNED NULL AFTER carrier,
    ADD COLUMN gross_weight_milli BIGINT UNSIGNED NULL AFTER packages_count,
    ADD COLUMN net_weight_milli BIGINT UNSIGNED NULL AFTER gross_weight_milli,
    ADD CONSTRAINT chk_quotations_terms_basis CHECK (payment_terms_basis IN ('delivery', 'invoice')),
    ADD CONSTRAINT chk_quotations_transport CHECK (transport_mode IS NULL OR transport_mode IN ('road', 'air', 'sea', 'rail', 'multimodal', 'courier'));

-- Disponibilité annoncée au client pour chaque article (ex. « 2 semaines »).
ALTER TABLE quotation_lines
    ADD COLUMN availability VARCHAR(40) NULL AFTER unit;

-- Devis existants : livraison au site de la RFQ, sinon à l'adresse du client.
UPDATE quotations q
  JOIN clients c ON c.id = q.client_id
  JOIN rfqs r ON r.id = q.rfq_id
   SET q.ship_to_name = c.name,
       q.ship_to_address = COALESCE(NULLIF(r.delivery_site, ''), NULLIF(TRIM(CONCAT_WS(', ', c.address, c.city)), '')),
       q.ship_to_phone = c.contact_phone;
