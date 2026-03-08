-- Migration 0064: Convert remaining utf8 tables to latin1 (project charset standard)
-- Tables: messages, cours, parties, reponses, sujets, tutoriel
ALTER TABLE `messages` CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
ALTER TABLE `cours` CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
ALTER TABLE `parties` CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
ALTER TABLE `reponses` CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
ALTER TABLE `sujets` CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
ALTER TABLE `tutoriel` CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci;
