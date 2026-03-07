-- Migration 0039: Add FK from season_recap.login to membre.login
-- season_recap was created in 0029 without a foreign key constraint.
-- ON DELETE CASCADE ensures orphaned recap rows are removed when a player
-- account is deleted (supprimerJoueur() also issues an explicit DELETE for
-- cases where the FK cannot be applied on older MariaDB setups).

ALTER TABLE season_recap
    ADD CONSTRAINT IF NOT EXISTS fk_season_recap_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;
