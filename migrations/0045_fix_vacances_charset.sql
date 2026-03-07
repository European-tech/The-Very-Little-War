-- Migration 0045: Fix vacances table charset to match membre (latin1)
-- MED-047: vacances was created with utf8 charset while membre uses latin1_swedish_ci.
-- This mismatch can cause implicit charset conversion on JOIN/FK lookups and
-- prevents adding a proper FK from vacances.login → membre.login.
-- Converting to latin1 aligns the table with all other game tables.

ALTER TABLE vacances
    CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
