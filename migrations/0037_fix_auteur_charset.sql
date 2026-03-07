-- Fix auteur columns to match membre.login charset (latin1) for FK compatibility.
-- sujets.auteur and reponses.auteur reference membre.login which is latin1_swedish_ci;
-- mismatched charsets prevent index usage and can silently break comparisons.
ALTER TABLE sujets MODIFY auteur VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL;
ALTER TABLE reponses MODIFY auteur VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL;
