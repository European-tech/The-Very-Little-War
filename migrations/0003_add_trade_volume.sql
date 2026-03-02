-- Add trade volume tracking for market points
ALTER TABLE `autre` ADD COLUMN `tradeVolume` DOUBLE NOT NULL DEFAULT 0 AFTER `ressourcesPillees`;
