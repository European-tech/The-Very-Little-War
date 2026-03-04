-- TVLW Test Database Seed Data
-- 5 archetype players for integration testing

-- Initialize game state
INSERT INTO parties (id, manche, dateFin, tailleCarte, prix, maintenance)
VALUES (1, 1, DATE_ADD(CURDATE(), INTERVAL 25 DAY), 50, '1,1,1,1,1,1,1,1', 0)
ON DUPLICATE KEY UPDATE manche = 1;

-- Raider: high attack (O, H)
INSERT INTO membre (login, pass_md5, email, timestamp, derniereConnexion, ip, x, y)
VALUES ('test_raider', '$2y$10$dummyhash.raider.bcrypt.placeholder.pad', 'raider@test.com', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), '10.0.0.1', 10, 10);
INSERT INTO autre (login, totalPoints) VALUES ('test_raider', 500);
INSERT INTO constructions (login, generateur, producteur, depot, champdeforce, ionisateur, condenseur, lieur, stabilisateur)
VALUES ('test_raider', 15, 12, 10, 5, 12, 8, 6, 3);
INSERT INTO ressources (login, energie, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode)
VALUES ('test_raider', 50000, 5000, 2000, 8000, 10000, 1000, 3000, 2000, 1000);

-- Turtle: high defense (C, Br)
INSERT INTO membre (login, pass_md5, email, timestamp, derniereConnexion, ip, x, y)
VALUES ('test_turtle', '$2y$10$dummyhash.turtle.bcrypt.placeholder.pad', 'turtle@test.com', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), '10.0.0.2', 20, 20);
INSERT INTO autre (login, totalPoints) VALUES ('test_turtle', 500);
INSERT INTO constructions (login, generateur, producteur, depot, champdeforce, ionisateur, condenseur, lieur, stabilisateur)
VALUES ('test_turtle', 18, 15, 14, 15, 5, 10, 4, 8);
INSERT INTO ressources (login, energie, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode)
VALUES ('test_turtle', 80000, 12000, 3000, 3000, 3000, 1000, 1000, 10000, 5000);

-- Pillager: high pillage (S), fast (Cl)
INSERT INTO membre (login, pass_md5, email, timestamp, derniereConnexion, ip, x, y)
VALUES ('test_pillager', '$2y$10$dummyhash.pillager.bcrypt.placeholder.', 'pillager@test.com', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), '10.0.0.3', 30, 30);
INSERT INTO autre (login, totalPoints) VALUES ('test_pillager', 500);
INSERT INTO constructions (login, generateur, producteur, depot, champdeforce, ionisateur, condenseur, lieur, stabilisateur)
VALUES ('test_pillager', 14, 12, 8, 3, 8, 8, 10, 3);
INSERT INTO ressources (login, energie, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode)
VALUES ('test_pillager', 40000, 3000, 4000, 2000, 5000, 8000, 10000, 3000, 1000);

-- Trader: economy focused
INSERT INTO membre (login, pass_md5, email, timestamp, derniereConnexion, ip, x, y)
VALUES ('test_trader', '$2y$10$dummyhash.trader.bcrypt.placeholder.pad', 'trader@test.com', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), '10.0.0.4', 25, 15);
INSERT INTO autre (login, totalPoints) VALUES ('test_trader', 500);
INSERT INTO constructions (login, generateur, producteur, depot, champdeforce, ionisateur, condenseur, lieur, stabilisateur)
VALUES ('test_trader', 20, 18, 20, 3, 3, 12, 8, 5);
INSERT INTO ressources (login, energie, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode)
VALUES ('test_trader', 100000, 8000, 8000, 8000, 8000, 3000, 3000, 3000, 3000);

-- Balanced: moderate everything
INSERT INTO membre (login, pass_md5, email, timestamp, derniereConnexion, ip, x, y)
VALUES ('test_balanced', '$2y$10$dummyhash.balanced.bcrypt.placeholder.', 'balanced@test.com', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), '10.0.0.5', 15, 25);
INSERT INTO autre (login, totalPoints) VALUES ('test_balanced', 500);
INSERT INTO constructions (login, generateur, producteur, depot, champdeforce, ionisateur, condenseur, lieur, stabilisateur)
VALUES ('test_balanced', 15, 14, 12, 8, 8, 10, 8, 5);
INSERT INTO ressources (login, energie, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode)
VALUES ('test_balanced', 60000, 6000, 5000, 5000, 6000, 3000, 4000, 5000, 3000);

-- Molecule classes for combat testing
INSERT INTO molecules (proprietaire, numeroclasse, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode, nombre)
VALUES ('test_raider', 1, 10, 20, 30, 80, 10, 5, 30, 0, 200);

INSERT INTO molecules (proprietaire, numeroclasse, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode, nombre)
VALUES ('test_turtle', 1, 80, 15, 5, 10, 5, 5, 60, 0, 150);

INSERT INTO molecules (proprietaire, numeroclasse, carbone, azote, hydrogene, oxygene, chlore, soufre, brome, iode, nombre)
VALUES ('test_pillager', 1, 10, 30, 5, 30, 50, 50, 15, 0, 250);

-- Alliance for alliance tests
INSERT INTO alliances (nom, tag, description, chef)
VALUES ('Test Alliance', 'TST', 'Test alliance', 'test_raider');
