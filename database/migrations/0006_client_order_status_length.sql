-- « partially_delivered » (19 caractères) ne tenait pas dans VARCHAR(12). Vérifié désormais par MigratorTest.
ALTER TABLE client_orders MODIFY status VARCHAR(20) NOT NULL DEFAULT 'received';
