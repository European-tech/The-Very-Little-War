-- Migration 0017: Add CHECK constraints to prevent negative values
-- MariaDB 10.11 supports enforced CHECK constraints (since 10.2.1)
--
-- These constraints guard against application bugs that could set resource
-- amounts, building levels, or molecule counts below zero.

-- ============================================================
-- ressources: prevent negative resource amounts
-- ============================================================
ALTER TABLE `ressources`
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
    ADD CONSTRAINT chk_mol_hydrogene_nonneg CHECK (`hydrogene` >= 0),
    ADD CONSTRAINT chk_mol_carbone_nonneg CHECK (`carbone` >= 0),
    ADD CONSTRAINT chk_mol_oxygene_nonneg CHECK (`oxygene` >= 0),
    ADD CONSTRAINT chk_mol_azote_nonneg CHECK (`azote` >= 0),
    ADD CONSTRAINT chk_mol_iode_nonneg CHECK (`iode` >= 0),
    ADD CONSTRAINT chk_mol_brome_nonneg CHECK (`brome` >= 0),
    ADD CONSTRAINT chk_mol_chlore_nonneg CHECK (`chlore` >= 0),
    ADD CONSTRAINT chk_mol_soufre_nonneg CHECK (`soufre` >= 0),
    ADD CONSTRAINT chk_mol_nombre_nonneg CHECK (`nombre` >= 0);
