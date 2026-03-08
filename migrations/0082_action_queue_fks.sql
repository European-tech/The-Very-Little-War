-- PASS7-HIGH-009: Add FK constraints on action-queue tables
-- latin1 charset for FK compatibility with membre.login (latin1)
-- Note: actionsattaques uses attaquant/defenseur columns (not login)
-- Note: actionsenvoi uses envoyeur/receveur columns (not login)

SET FOREIGN_KEY_CHECKS=0;

-- actionsconstruction.login → membre.login
ALTER TABLE actionsconstruction
    ADD CONSTRAINT fk_actionsconstruction_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;

-- actionsformation.login → membre.login
ALTER TABLE actionsformation
    ADD CONSTRAINT fk_actionsformation_login
    FOREIGN KEY (login) REFERENCES membre(login) ON DELETE CASCADE;

-- actionsattaques.attaquant → membre.login (attacker reference)
ALTER TABLE actionsattaques
    ADD CONSTRAINT fk_actionsattaques_attaquant
    FOREIGN KEY (attaquant) REFERENCES membre(login) ON DELETE CASCADE;

-- actionsattaques.defenseur → membre.login (defender reference)
ALTER TABLE actionsattaques
    ADD CONSTRAINT fk_actionsattaques_defenseur
    FOREIGN KEY (defenseur) REFERENCES membre(login) ON DELETE CASCADE;

-- actionsenvoi.envoyeur → membre.login (sender reference)
ALTER TABLE actionsenvoi
    ADD CONSTRAINT fk_actionsenvoi_envoyeur
    FOREIGN KEY (envoyeur) REFERENCES membre(login) ON DELETE CASCADE;

-- actionsenvoi.receveur → membre.login (receiver reference)
ALTER TABLE actionsenvoi
    ADD CONSTRAINT fk_actionsenvoi_receveur
    FOREIGN KEY (receveur) REFERENCES membre(login) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS=1;
