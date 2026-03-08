-- PASS7-HIGH-008: Add ON UPDATE CASCADE to account_flags FK
-- Recreate FK with ON UPDATE CASCADE so login renames propagate correctly
ALTER TABLE account_flags DROP FOREIGN KEY IF EXISTS account_flags_ibfk_1;
ALTER TABLE account_flags DROP FOREIGN KEY IF EXISTS fk_account_flags_login;
ALTER TABLE account_flags
    ADD CONSTRAINT fk_account_flags_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE ON UPDATE CASCADE;
