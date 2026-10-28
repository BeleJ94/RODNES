-- ETHAN — suivi commercial : délais par client, motif de perte d'un devis, historique des relances.
-- Les délais par défaut (mode jours ouvrés / calendaires, réponse RFQ, validité, rythmes de relance) sont dans settings.

ALTER TABLE clients
    ADD COLUMN rfq_response_days SMALLINT UNSIGNED NULL AFTER payment_terms_days,
    ADD COLUMN quote_validity_days SMALLINT UNSIGNED NULL AFTER rfq_response_days;

-- Devis refusé ou sans suite : motif obligatoire (liste fermée), commentaire obligatoire pour « autre ».
ALTER TABLE quotations
    ADD COLUMN lost_reason VARCHAR(20) NULL AFTER decided_at,
    ADD COLUMN lost_comment VARCHAR(255) NULL AFTER lost_reason;

-- Relance faite : devis envoyé sans réponse (quote), devis accepté sans PO client (client_po),
-- PO fournisseur non confirmé (supplier_confirm), date de mise à disposition à vérifier (supplier_ready).
CREATE TABLE followups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(20) NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    channel VARCHAR(10) NOT NULL,
    contacted_on DATE NOT NULL,
    comment VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_followups_subject (kind, subject_id),
    CONSTRAINT fk_followups_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_followups_kind CHECK (kind IN ('quote', 'client_po', 'supplier_confirm', 'supplier_ready')),
    CONSTRAINT chk_followups_channel CHECK (channel IN ('phone', 'email', 'visit', 'whatsapp', 'other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
