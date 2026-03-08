# Pass 9 Fix Batch 12: Prestige & Ranking

Date: 2026-03-08

## Summary

4 fixes applied across 5 files. All files were read before editing.

---

## P9-LOW-024: PRES — Non-milestone 1 PP/day baseline undocumented

**Status: APPLIED**

Files edited:
- `/home/guortates/TVLW/The-Very-Little-War/regles.php` (line 333)
- `/home/guortates/TVLW/The-Very-Little-War/docs/game/10-PLAYER-GUIDE.md` (line 710)

Both files were read before editing. The sentence was inserted in the prestige/connexion section of each document, immediately before the existing streak-reset tip in regles.php and immediately after the PP earnings table in the player guide.

Sentence added in both:
> "Chaque connexion journalière rapporte également +1 PP (en plus des jalons de série), soit jusqu'à ~25 PP supplémentaires par saison pour les joueurs très actifs."

---

## P9-LOW-025: Migration 0075 idempotency check wrong column

**Status: APPLIED**

File edited: `/home/guortates/TVLW/The-Very-Little-War/migrations/0075_prestige_total_pp_unsigned.sql`

The file was read before editing. The original guard used a dynamic `SET @sql = IF(...)` pattern that checked `DATA_TYPE = 'int'` (which would remain `'int'` even after the column becomes UNSIGNED, so the ALTER would re-run every time). Replaced with the correct pattern using `COLUMN_TYPE` (`'int(10) unsigned'`) in a stored-procedure-style `IF` block, which correctly skips the ALTER once the column has been modified.

Before: checked `DATA_TYPE` via dynamic SQL `PREPARE/EXECUTE`.
After: checks `COLUMN_TYPE = 'int(10) unsigned'` via `SELECT ... INTO @col_type` with a plain `IF` block.

---

## P9-LOW-026: classement.php — recalculerStatsAlliances triggered by unauthenticated GET

**Status: APPLIED**

File edited: `/home/guortates/TVLW/The-Very-Little-War/classement.php` (line 375)

The file was read before editing. The bare call `recalculerStatsAlliances()` was wrapped in an `isset($_SESSION['login'])` guard so that unauthenticated visitors browsing the leaderboard page do not trigger an alliance stats recalculation (which involves writes). Authenticated users retain unchanged behavior.

---

## P9-LOW-027: Missing indexes on leaderboard sort columns

**Status: APPLIED**

File created: `/home/guortates/TVLW/The-Very-Little-War/migrations/0078_leaderboard_indexes.sql`

Confirmed 0078 did not already exist (directory listing showed migrations through 0077). Created a new idempotent migration using `CREATE INDEX IF NOT EXISTS` (supported by MariaDB 10.11) for the 7 sort columns used by classement.php non-default ranking views:

- `idx_autre_pointsAttaque`
- `idx_autre_pointsDefense`
- `idx_autre_ressourcesPillees`
- `idx_autre_tradeVolume`
- `idx_autre_victoires`
- `idx_autre_points`
- `idx_autre_batmax`

---

## Files Modified

| File | Fix | Action |
|------|-----|--------|
| `regles.php` | P9-LOW-024 | Inserted 1 PP/day baseline sentence in prestige section |
| `docs/game/10-PLAYER-GUIDE.md` | P9-LOW-024 | Inserted 1 PP/day baseline sentence after PP earnings table |
| `migrations/0075_prestige_total_pp_unsigned.sql` | P9-LOW-025 | Rewrote guard to use `COLUMN_TYPE = 'int(10) unsigned'` |
| `classement.php` | P9-LOW-026 | Wrapped `recalculerStatsAlliances()` in `isset($_SESSION['login'])` |
| `migrations/0078_leaderboard_indexes.sql` | P9-LOW-027 | Created new idempotent migration with 7 leaderboard indexes |
