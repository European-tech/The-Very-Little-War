-- Migration 0063: Make CHECK constraints idempotent (repair re-run safety for 0017)
-- Uses DROP CONSTRAINT IF EXISTS before re-adding each constraint so this migration
-- can be re-run safely without failing on "Duplicate constraint name" errors.
-- MariaDB 10.11 supports DROP CONSTRAINT IF EXISTS.

-- ============================================================
-- ressources: prevent negative resource amounts
-- ============================================================
ALTER TABLE `ressources`
    DROP CONSTRAINT IF EXISTS chk_energie_nonneg,
    DROP CONSTRAINT IF EXISTS chk_carbone_nonneg,
    DROP CONSTRAINT IF EXISTS chk_azote_nonneg,
    DROP CONSTRAINT IF EXISTS chk_hydrogene_nonneg,
    DROP CONSTRAINT IF EXISTS chk_oxygene_nonneg,
    DROP CONSTRAINT IF EXISTS chk_chlore_nonneg,
    DROP CONSTRAINT IF EXISTS chk_soufre_nonneg,
    DROP CONSTRAINT IF EXISTS chk_brome_nonneg,
    DROP CONSTRAINT IF EXISTS chk_iode_nonneg,
    ADD CONSTRAINT chk_energie_nonneg CHECK (`energie` >= 0),
    ADD CONSTRAINT chk_carbone_nonneg CHECK (`carbone` >= 0),
    ADD CONSTRAINT chk_azote_nonneg CHECK (`azote` >= 0),
    ADD CONSTRAINT chk_hydrogene_nonneg CHECK (`hydrogene` >= 0),
    ADD CONSTRAINT chk_oxygene_nonneg CHECK (`oxygene` >= 0),
    ADD CONSTRAINT chk_chlore_nonneg CHECK (`chlore` >= 0),
    ADD CONSTRAINT chk_soufre_nonneg CHECK (`soufre` >= 0),
    ADD CONSTRAINT chk_brome_nonneg CHECK (`brome` >= 0),
    ADD CONSTRAINT chk_iode_nonneg CHECK (`iode` >= 0);

-- ============================================================
-- constructions: prevent negative building levels
-- ============================================================
ALTER TABLE `constructions`
    DROP CONSTRAINT IF EXISTS chk_generateur_nonneg,
    DROP CONSTRAINT IF EXISTS chk_producteur_nonneg,
    DROP CONSTRAINT IF EXISTS chk_depot_nonneg,
    DROP CONSTRAINT IF EXISTS chk_champdeforce_nonneg,
    DROP CONSTRAINT IF EXISTS chk_ionisateur_nonneg,
    DROP CONSTRAINT IF EXISTS chk_condenseur_nonneg,
    DROP CONSTRAINT IF EXISTS chk_lieur_nonneg,
    DROP CONSTRAINT IF EXISTS chk_stabilisateur_nonneg,
    DROP CONSTRAINT IF EXISTS chk_coffrefort_nonneg,
    ADD CONSTRAINT chk_generateur_nonneg CHECK (`generateur` >= 0),
    ADD CONSTRAINT chk_producteur_nonneg CHECK (`producteur` >= 0),
    ADD CONSTRAINT chk_depot_nonneg CHECK (`depot` >= 0),
    ADD CONSTRAINT chk_champdeforce_nonneg CHECK (`champdeforce` >= 0),
    ADD CONSTRAINT chk_ionisateur_nonneg CHECK (`ionisateur` >= 0),
    ADD CONSTRAINT chk_condenseur_nonneg CHECK (`condenseur` >= 0),
    ADD CONSTRAINT chk_lieur_nonneg CHECK (`lieur` >= 0),
    ADD CONSTRAINT chk_stabilisateur_nonneg CHECK (`stabilisateur` >= 0),
    ADD CONSTRAINT chk_coffrefort_nonneg CHECK (`coffrefort` >= 0);

-- ============================================================
-- molecules: prevent negative atom counts and molecule quantities
-- ============================================================
ALTER TABLE `molecules`
    DROP CONSTRAINT IF EXISTS chk_mol_hydrogene_nonneg,
    DROP CONSTRAINT IF EXISTS chk_mol_carbone_nonneg,
    DROP CONSTRAINT IF EXISTS chk_mol_oxygene_nonneg,
    DROP CONSTRAINT IF EXISTS chk_mol_azote_nonneg,
    DROP CONSTRAINT IF EXISTS chk_mol_iode_nonneg,
    DROP CONSTRAINT IF EXISTS chk_mol_brome_nonneg,
    DROP CONSTRAINT IF EXISTS chk_mol_chlore_nonneg,
    DROP CONSTRAINT IF EXISTS chk_mol_soufre_nonneg,
    DROP CONSTRAINT IF EXISTS chk_mol_nombre_nonneg,
    ADD CONSTRAINT chk_mol_hydrogene_nonneg CHECK (`hydrogene` >= 0),
    ADD CONSTRAINT chk_mol_carbone_nonneg CHECK (`carbone` >= 0),
    ADD CONSTRAINT chk_mol_oxygene_nonneg CHECK (`oxygene` >= 0),
    ADD CONSTRAINT chk_mol_azote_nonneg CHECK (`azote` >= 0),
    ADD CONSTRAINT chk_mol_iode_nonneg CHECK (`iode` >= 0),
    ADD CONSTRAINT chk_mol_brome_nonneg CHECK (`brome` >= 0),
    ADD CONSTRAINT chk_mol_chlore_nonneg CHECK (`chlore` >= 0),
    ADD CONSTRAINT chk_mol_soufre_nonneg CHECK (`soufre` >= 0),
    ADD CONSTRAINT chk_mol_nombre_nonneg CHECK (`nombre` >= 0);
