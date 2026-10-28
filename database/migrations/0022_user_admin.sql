-- Gestion des utilisateurs : mot de passe provisoire à changer à la première connexion, suivi de qui a créé le compte.
-- Journal d'activité : index par utilisateur et par action pour la consultation filtrée.

ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_changed_at,
    ADD COLUMN created_by INT UNSIGNED NULL AFTER must_change_password,
    ADD CONSTRAINT fk_users_creator FOREIGN KEY (created_by) REFERENCES users (id);

ALTER TABLE activity_log
    ADD KEY ix_activity_user (user_id, created_at),
    ADD KEY ix_activity_action (entity_type, action);
