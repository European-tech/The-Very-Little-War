-- Migration 0042: Add UNIQUE constraints on membre.email and membre.login
-- Prevents TOCTOU race conditions during concurrent registrations.
-- membre.login is already the PK-equivalent via FK references, but lacks a
-- formal UNIQUE index (the PK column may be 'id'). membre.email has no unique
-- constraint at all. Both are added here idempotently.

-- UNIQUE on email (case-insensitive in latin1 collation — duplicate emails
-- using different casing are rejected at the DB level).
-- MIG-M-003: Use DROP-then-ADD pattern for idempotency (IF NOT EXISTS not supported
-- for ADD CONSTRAINT UNIQUE in MariaDB 10.11; CREATE UNIQUE INDEX IF NOT EXISTS works)
CREATE UNIQUE INDEX IF NOT EXISTS uq_membre_email ON membre (email);

-- UNIQUE on login (belt-and-suspenders: login is referenced by FKs on other
-- tables but the column itself may not have a UNIQUE index separate from PK).
-- Use IF NOT EXISTS workaround via DROP IGNORE + ADD to stay idempotent.
-- In MariaDB we can use CREATE INDEX IF NOT EXISTS.
-- LOW-043: Note: membre.login was previously only a non-unique index. This migration
-- makes it UNIQUE (uq_membre_login). The sequence is: prior migrations add a plain
-- index, this migration (0042) upgrades it to UNIQUE. Final state verified on VPS.
CREATE UNIQUE INDEX IF NOT EXISTS uq_membre_login ON membre (login);
