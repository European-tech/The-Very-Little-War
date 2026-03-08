# The Very Little War -- Guide Complet du Joueur

> **Version:** 2.0 | **Derniere mise a jour:** 2026-03-03
> Ce guide couvre **tous** les systemes du jeu et les strategies pour les optimiser.

---

## Table des matieres

1. [Premiers Pas](#1-premiers-pas)
2. [Ressources et Production](#2-ressources-et-production)
3. [Batiments](#3-batiments)
4. [Molecules et Armee](#4-molecules-et-armee)
5. [Isotopes](#5-isotopes)
6. [Combat](#6-combat)
7. [Formations Defensives](#7-formations-defensives)
8. [Reactions Chimiques](#8-reactions-chimiques)
9. [Economie et Marche](#9-economie-et-marche)
10. [Alliances](#10-alliances)
11. [Recherche d'Alliance](#11-recherche-dalliance)
12. [Points et Classement](#12-points-et-classement)
13. [Medailles](#13-medailles)
14. [Specialisations Atomiques](#14-specialisations-atomiques)
15. [Systeme de Prestige](#15-systeme-de-prestige)
16. [Catalyseurs Hebdomadaires](#16-catalyseurs-hebdomadaires)
17. [Strategies de Saison](#17-strategies-de-saison)

---

## 1. Premiers Pas

### Inscription et Demarrage

A l'inscription, vous recevez :
- Un **Generateur** niveau 1 (produit l'energie)
- Un **Producteur** niveau 1 (produit les atomes)
- Un **Depot** niveau 1 (stocke vos ressources)
- Un element atomique attribue aleatoirement selon ces probabilites :

| Element | Symbole | Probabilite |
|---------|---------|------------|
| Carbone | C | 50% |
| Azote | N | 25% |
| Hydrogene | H | 12.5% |
| Oxygene | O | 6% |
| Chlore | Cl | 3% |
| Soufre | S | 2% |
| Brome | Br | 1% |
| Iode | I | 0.5% |

Votre nom de joueur doit faire entre **3 et 20 caracteres**. Le mot de passe doit contenir au moins **8 caracteres**.

### Protection Debutant

Vous beneficiez de **5 jours de protection** pendant lesquels personne ne peut vous attaquer. Utilisez ce temps pour :
1. Monter votre Generateur a niveau 3-4
2. Monter votre Producteur a niveau 2-3
3. Commencer a former vos premieres molecules
4. Rejoindre une alliance

> **Astuce Prestige :** Si vous avez le bonus "Veteran" (250 PP), vous gagnez +1 jour de protection supplementaire.

### Tutoriel et Missions

Le jeu propose des missions de tutoriel qui vous guident a travers les bases. Completez-les pour recevoir des recompenses en energie et en atomes, dont une molecule de depart de 1000 atomes totaux.

### Mode Vacances

Vous pouvez activer le mode vacances pour proteger votre compte pendant une absence. Il faut le programmer **3 jours a l'avance** minimum. Pendant les vacances, votre compte est inattaquable mais vous ne produisez rien.

### Image de Profil

Vous pouvez uploader une image de profil (maximum **2 Mo**, **150x150 pixels**).

---

## 2. Ressources et Production

### Energie

L'energie est la devise universelle du jeu. Elle sert a :
- Construire et ameliorer les batiments
- Acheter des atomes au marche
- Former des molecules (cout d'attaque)
- Acheter des neutrinos (espionnage)

**Production d'energie** = 75 x niveau du Generateur

Chaque niveau de Producteur consomme 8 d'energie par cycle. Gerez l'equilibre entre production d'atomes et drain d'energie.

### Les 8 Atomes

Chaque atome a un role specifique dans la composition des molecules :

| Atome | Symbole | Role | Stat affectee |
|-------|---------|------|---------------|
| **Oxygene** | O | Offensif | Attaque |
| **Carbone** | C | Defensif | Defense |
| **Brome** | Br | Resistance | Points de Vie |
| **Hydrogene** | H | Destruction | Degats aux batiments |
| **Soufre** | S | Pillage | Capacite de pillage |
| **Chlore** | Cl | Vitesse | Vitesse de deplacement |
| **Azote** | N | Formation | Reduit le temps de formation |
| **Iode** | I | Energie | Produit de l'energie supplementaire |

### Production d'Atomes

**Production par atome** = bonus Duplicateur x 60 x niveau d'allocation

Vous repartissez les "points producteur" (8 par niveau de Producteur) entre les 8 types d'atomes. Concentrez vos points sur les atomes necessaires a votre strategie.

### Stockage

**Capacite de stockage** = 500 x niveau du Depot (par type d'atome)

Si vos reserves atteignent la capacite maximale, la production excedentaire est perdue. Montez votre Depot en priorite si vous manquez de place.

### Iode : La Strategie Energetique

L'iode est unique : chaque molecule contenant de l'iode **produit de l'energie** en continu.

**Energie de l'iode** = (0.003 x iode^2 + 0.04 x iode) x (1 + niveau / 50)

A 100 atomes d'iode par molecule avec un Condenseur niveau 10, une seule molecule peut produire ~40 d'energie. C'est une alternative viable au Generateur pour les joueurs avances.

---

## 3. Batiments

### Vue d'Ensemble

| Batiment | Cout principal | Effet |
|----------|---------------|-------|
| **Generateur** | Energie + Atomes | Produit 75 energie/niveau |
| **Producteur** | Energie + Atomes | 8 points de repartition/niveau, draine 8 energie/niveau |
| **Depot** | Energie | +500 stockage/niveau (par type d'atome) |
| **Coffre-fort** | Energie | Protege 100 ressources/niveau du pillage |
| **Champ de Force** | Carbone | +2% defense/niveau, absorbe les degats en premier |
| **Ionisateur** | Oxygene | +2% attaque/niveau |
| **Condenseur** | Energie + Atomes | +5 points de condenseur/niveau (ameliore les stats des molecules) |
| **Lieur** | Azote | Reduit le temps de formation des molecules |
| **Stabilisateur** | Atomes divers | -1.5% disparition des molecules/niveau |

### Formules de Cout et Temps

Chaque batiment a ses propres bases de cout et exposants. Les couts sont reduits par le bonus de la medaille **Constructeur**.

#### Couts de Construction

| Batiment | Cout Energie | Cout Atomes | Type d'atome |
|----------|-------------|-------------|--------------|
| Generateur | 50 x niv^0.7 | 75 x niv^0.7 (chaque type) | Tous |
| Producteur | 75 x niv^0.7 | 50 x niv^0.7 (chaque type) | Tous |
| Depot | 100 x niv^0.7 | -- | -- |
| Coffre-fort | 150 x niv^0.7 | -- | -- |
| Champ de Force | -- | 100 x niv^0.7 | Carbone |
| Ionisateur | -- | 100 x niv^0.7 | Oxygene |
| Condenseur | 25 x niv^0.8 | 100 x niv^0.8 (chaque type) | Tous |
| Lieur | -- | 100 x niv^0.8 | Azote |
| Stabilisateur | -- | 75 x niv^0.9 (chaque type) | Tous |

#### Temps de Construction (en secondes)

| Batiment | Formule | Niv.1 special |
|----------|---------|---------------|
| Generateur | 60 x niv^1.5 | 10s |
| Producteur | 40 x niv^1.5 | 10s |
| Depot | 80 x niv^1.5 | -- |
| Coffre-fort | 90 x (niv+1)^1.2 | -- |
| Champ de Force | 20 x (niv+2)^1.7 | -- |
| Ionisateur | 20 x (niv+2)^1.7 | -- |
| Condenseur | 120 x (niv+1)^1.6 | -- |
| Lieur | 100 x (niv+1)^1.5 | -- |
| Stabilisateur | 120 x (niv+1)^1.5 | -- |

> **Note :** Le Generateur et le Producteur ont un cas special au niveau 1 (10 secondes seulement) pour accelerer le tout debut de partie.

Vous pouvez avoir **2 constructions simultanees** maximum.

### Priorites de Construction

**Debut de saison :**
1. Generateur 3-4 (base economique)
2. Producteur 2-3 (production d'atomes)
3. Depot 2-3 (stockage suffisant)
4. Coffre-fort 2-3 (protection contre le pillage)

**Mi-saison :**
5. Ionisateur / Champ de Force (bonus combat)
6. Condenseur (ameliore les stats globales)
7. Lieur (formation plus rapide)

**Fin de saison :**
8. Stabilisateur (preservation de l'armee)

### Le Coffre-fort

Le Coffre-fort est un batiment defensif souvent neglige. Il protege **100 ressources par niveau** de chaque type d'atome contre le pillage. A niveau 10, 1000 unites de chaque atome sont intouchables.

### Points de Construction

Chaque niveau de batiment rapporte des points de construction :
- Points = base x (1 + 0.1 x niveau_atteint)
- Le Condenseur et le Stabilisateur donnent plus de points par niveau

---

## 4. Molecules et Armee

### Le Systeme de Classes

Vous pouvez avoir jusqu'a **4 classes de molecules**. Chaque classe est un modele avec une composition d'atomes differente.

- **Classe 1** : gratuite
- **Classe 2** : coute (2+1)^4 = 81 energie
- **Classe 3** : coute (3+1)^4 = 256 energie
- **Classe 4** : coute (4+1)^4 = 625 energie

### Composition et Stats

Chaque molecule contient jusqu'a **200 atomes maximum** de chaque type. Les stats sont calculees ainsi :

**Attaque** = (1 + (0.1 x O)^2 + O) x (1 + niveau/50)
**Defense** = (1 + (0.1 x C)^2 + C) x (1 + niveau/50)
**Points de Vie** = (1 + (0.1 x Br)^2 + Br) x (1 + niveau/50)
**Destruction** = ((0.075 x H)^2 + H) x (1 + niveau/50)
**Pillage** = ((0.1 x S)^2 + S/2) x (1 + niveau/50)
**Vitesse** = (1 + 0.5 x Cl) x (1 + niveau/50)
**Formation** = total_atomes / (1 + (0.09 x N)^1.09) / (1 + niveau/20)

Le **niveau** de la molecule est determine par le Condenseur (5 points de condenseur par niveau de batiment).

> **Astuce :** Les formules sont quadratiques — concentrer 200 atomes dans un seul type donne **beaucoup** plus de stats que repartir 100+100 dans deux types.

### La Disparition (Demi-vie)

Les molecules se degradent avec le temps. Le coefficient de disparition est :

**coefDisparition** = 0.99 ^ ( ((1 + nbAtomes / 150)^2) / 25000 )

Plus la molecule contient d'atomes au total, plus elle disparait vite. C'est un compromis fondamental : des molecules puissantes (beaucoup d'atomes) sont aussi les plus ephemeres.

Modificateurs de disparition :
- **Stabilisateur** : -1.5% par niveau (un Stabilisateur niv.10 reduit la disparition de 15%)
- **Isotope Stable** : -30% de vitesse de disparition
- **Isotope Reactif** : +20% de vitesse de disparition
- **Catalyseur "Volatilite"** : +30% de vitesse de disparition (semaine active)

### Archetypes de Molecules

| Archetype | Atomes principaux | Utilite |
|-----------|------------------|---------|
| **Tank** | C + Br | Haute defense et PV, absorbe les degats |
| **Attaquant** | O + H | Fort en attaque et destruction de batiments |
| **Pilleur** | S + Cl | Vole des ressources rapidement |
| **Producteur** | I + N | Produit de l'energie, se forme vite |
| **Polyvalent** | O + C + Br | Equilibre attaque/defense/PV |

---

## 5. Isotopes

A la creation d'une molecule, vous choisissez un **variant isotopique** :

### Normal
Pas de modification. Le choix par defaut, equilibre.

### Stable (Tank/Defenseur)
- -5% attaque
- **+40% points de vie**
- **-30% vitesse de disparition**

Ideal pour les classes defensives (C+Br). La reduction de disparition en fait le meilleur choix pour les armees long terme.

### Reactif (Canon de Verre)
- **+20% attaque**
- -10% points de vie
- +20% vitesse de disparition

Ideal pour les classes offensives (O+H). Puissant mais ephemere — formez-les juste avant d'attaquer.

### Catalytique (Support)
- -10% attaque
- -10% points de vie
- **+15% a toutes les stats des AUTRES classes**

Le Catalytique est un multiplicateur d'equipe. Une seule classe catalytique boost les 3 autres de 15%. C'est souvent le meilleur choix pour la classe 4 si vous avez deja 3 classes combattantes.

> **Strategie optimale :** 1 classe Stable (tank), 1-2 classes Reactif (attaque), 1 classe Catalytique (support).

---

## 6. Combat

### Lancer une Attaque

1. Choisissez un defenseur sur la carte ou dans le classement
2. Selectionnez les molecules a envoyer (par classe)
3. Payez le cout en energie : **0.15 x (1 - bonus_medaille_terreur / 100) x nombre_atomes** par molecule envoyee
4. Attendez le deplacement (depend de la vitesse et de la distance)

> La medaille **Terreur** reduit ce cout : a tier Or (6%), le cout tombe a 0.15 x 0.94 x atomes = **-6% de reduction**. Plus vous attaquez, moins ca coute en energie.

### Resolution du Combat

Le combat se deroule en **rounds** jusqu'a ce qu'un camp soit elimine :

1. **Calcul des degats** : somme des attaques de toutes les molecules de chaque camp
2. **Application des bonus** : Ionisateur (+2%/niveau), Champ de Force (+2%/niveau), Duplicateur (+1%/niveau), medailles, isotopes, reactions chimiques
3. **Repartition des degats** : selon la formation defensive du defenseur
4. **Destruction des molecules** : les molecules sont detruites en commencant par les plus faibles (moins de PV)
5. **Repetition** jusqu'a victoire

### Resultat du Combat

| Resultat | Effet |
|----------|-------|
| **Victoire attaquant** | Pillage des ressources, degats aux batiments, points d'attaque |
| **Victoire defenseur** | +30% bonus energie (30% de la capacite de pillage de l'attaquant), 1.5x points de defense, cooldown de 4h pour l'attaquant |
| **Match nul** | Pas de pillage, cooldown de 4h pour l'attaquant |

### Rapport d'Absence

Si vous etes hors-ligne pendant plus de **6 heures** lors d'une attaque, vous recevrez un rapport de pertes detaillant les degats subis pendant votre absence.

### Cooldowns

- **4 heures** de cooldown sur un meme defenseur apres defaite ou match nul
- **1 heure** de cooldown sur un meme defenseur apres victoire (empeche le harcelement en serie)

### Espionnage

Avant d'attaquer, envoyez des **neutrinos** pour espionner :
- Cout : 50 energie par neutrino
- Vitesse : 20 cases/heure
- Pour reussir, il faut envoyer **plus de la moitie** des neutrinos du defenseur
- Revele : armee, batiments, ressources du defenseur

### Pillage

En cas de victoire, vos molecules pillent des ressources proportionnellement a leur stat de pillage (soufre). Le Coffre-fort du defenseur protege une partie de ses reserves.

### Degats aux Batiments

La stat de destruction (hydrogene) inflige des degats aux batiments du defenseur. Chaque round, un batiment aleatoire parmi 4 (Generateur, Champ de Force, Producteur, Depot) est cible. Les batiments ne peuvent pas descendre en dessous du **niveau 1**.

Le **Champ de Force** absorbe les degats en priorite s'il est le batiment de plus haut niveau.

---

## 7. Formations Defensives

Avant d'etre attaque, vous pouvez choisir une **formation defensive** qui modifie la repartition des degats :

### Dispersee (par defaut)
Les degats sont repartis **a 25% sur chaque classe**. Equilibre, efficace contre les attaques concentrees.

### Phalange
Votre **classe 1 absorbe 50% de tous les degats** et gagne **+20% defense**. Ideal si votre classe 1 est un tank ultra-resistant (Carbone+Brome en isotope Stable).

> **Attention :** Si votre classe 1 est faible ou vide, la Phalange est desastreuse car 50% des degats sont "absorbes" par une classe qui ne peut rien encaisser.

### Embuscade
Si vous avez **plus de molecules en nombre total** que l'attaquant, toutes vos molecules gagnent **+40% d'attaque**. Ideal pour les joueurs avec beaucoup de petites molecules rapides.

### Quand utiliser quelle formation ?

| Situation | Formation recommandee |
|-----------|----------------------|
| Classe 1 tank ultra-forte | Phalange |
| Armee nombreuse et variee | Embuscade |
| Pas sur de l'attaquant | Dispersee |
| Classe 1 vide ou faible | **JAMAIS** Phalange |

---

## 8. Reactions Chimiques

Les reactions chimiques sont des **bonus passifs** qui s'activent quand vous deployez des molecules contenant certaines combinaisons d'atomes **entre differentes classes**.

### Les 5 Reactions

| Reaction | Condition | Bonus |
|----------|-----------|-------|
| **Combustion** | Classe A: O >= 100, Classe B: C >= 100 | +15% attaque aux deux classes |
| **Hydrogenation** | Classe A: H >= 100, Classe B: Br >= 100 | +15% PV aux deux classes |
| **Halogenation** | Classe A: Cl >= 80, Classe B: I >= 80 | +20% vitesse |
| **Sulfuration** | Classe A: S >= 100, Classe B: N >= 50 | +20% pillage |
| **Neutralisation** | Classe A: O >= 80, Classe B: H >= 80 + C >= 80 | +15% defense aux deux classes |

### Comment les activer

Les conditions doivent etre remplies **entre deux classes differentes**. Par exemple, pour la Combustion :
- Classe 1 : 100+ Oxygene
- Classe 2 : 100+ Carbone

Les bonus s'appliquent aux deux classes concernees.

> **Strategie :** Concevez vos 4 classes pour declencher 2-3 reactions simultanement. Combustion + Neutralisation se combinent bien (O dans les deux conditions).

---

## 9. Economie et Marche

### Le Marche Dynamique

Chaque atome a un prix fluctuant base sur l'offre et la demande des joueurs.

**Achat** : le prix augmente lineairement avec la quantite achetee
**Vente** : vous recevez 95% de la valeur (5% de taxe), prix diminue harmoniquement

### Prix

- **Plancher** : 0.1 (un atome ne peut jamais couter moins)
- **Plafond** : 10.0 (un atome ne peut jamais couter plus)
- **Reversion** : les prix convergent de 1% vers le prix de base a chaque transaction

### Volatilite

La volatilite du marche = 0.3 / nombre de joueurs actifs

Plus il y a de joueurs, plus les prix sont stables. En debut de saison avec peu de joueurs, les prix bougent beaucoup — c'est une opportunite de speculation.

### Transferts de Ressources

Vous pouvez envoyer des atomes a un autre joueur via la page "Don". Les transferts entre joueurs de meme IP sont penalises pour eviter les multi-comptes.

### Points de Commerce

Chaque transaction au marche contribue aux points de commerce :
- Points = 0.08 x racine(volume total echange)
- Maximum : 80 points de commerce

---

## 10. Alliances

### Creer ou Rejoindre

- Maximum **20 membres** par alliance
- Le tag d'alliance fait entre 3 et 16 caracteres
- Les grades permettent de deleguer les droits (invitations, guerres, gestion)

### Le Duplicateur

Le Duplicateur est le batiment collectif de l'alliance. Il donne a **tous les membres** :
- +1% production d'atomes par niveau
- +1% bonus combat par niveau

**Cout** = 10 x 2.0^(niveau+1)

Investir dans le Duplicateur est l'une des meilleures actions d'alliance. Niveau 10-12 est atteignable en une saison de 31 jours avec une alliance active.

### Guerres et Pactes

Les alliances peuvent :
- **Declarer la guerre** a une autre alliance (les combats entre membres ne coutent pas de cooldown)
- **Signer des pactes** de non-agression
- **Envoyer de l'energie** au Duplicateur

### Points de Victoire Alliance

| Rang | Points de Victoire |
|------|-------------------|
| 1er | 15 PV |
| 2eme | 10 PV |
| 3eme | 7 PV |
| 4-9 | 10 - rang |
| 10+ | 0 PV |

---

## 11. Recherche d'Alliance

Au-dela du Duplicateur, votre alliance peut investir dans **5 technologies de recherche** :

### Technologies Disponibles

| Technologie | Effet par niveau | Cout de base | Croissance |
|-------------|-----------------|--------------|------------|
| **Catalyseur** | -2% temps de formation | 15 | x2.0/niv |
| **Fortification** | +1% PV des batiments | 15 | x2.0/niv |
| **Reseau** | +5% points de commerce | 12 | x1.8/niv |
| **Radar** | -2% cout des neutrinos | 20 | x2.5/niv |
| **Bouclier** | -1% pertes de pillage en defense | 15 | x2.0/niv |

Niveau maximum : **25** pour chaque technologie.

### Priorites de Recherche

1. **Catalyseur** (debut) — former des molecules plus vite est crucial en debut de saison
2. **Duplicateur** (toujours) — le bonus a tous les membres est imbattable
3. **Fortification** (mi-saison) — protege les batiments de tous les membres
4. **Reseau** (commerce actif) — boost les points de commerce de toute l'alliance
5. **Bouclier** (defensif) — reduit les pertes de tous les membres
6. **Radar** (espionnage actif) — utile si votre alliance espionne beaucoup

---

## 12. Points et Classement

### Calcul des Points Totaux

Vos **points totaux** (totalPoints) determinent votre rang au classement. Ils sont la somme de 5 sources :

**totalPoints = construction + attaque + defense + pillage + commerce**

### 1. Points de Construction

Chaque amelioration de batiment rapporte des points immediatement. C'est generalement la plus grande source de points.

| Batiment | Points par niveau | Formule |
|----------|-------------------|---------|
| Generateur | ~1.1 - 6 | 1 x (1 + 0.1 x niveau) |
| Producteur | ~1.1 - 6 | 1 x (1 + 0.1 x niveau) |
| Depot | ~1.1 - 6 | 1 x (1 + 0.1 x niveau) |
| Coffre-fort | ~1.1 - 6 | 1 x (1 + 0.1 x niveau) |
| Champ de Force | ~1.08 - 4.75 | 1 x (1 + 0.075 x niveau) |
| Ionisateur | ~1.08 - 4.75 | 1 x (1 + 0.075 x niveau) |
| Condenseur | ~2.2 - 12 | 2 x (1 + 0.1 x niveau) |
| Lieur | ~2.2 - 12 | 2 x (1 + 0.1 x niveau) |
| Stabilisateur | ~3.3 - 18 | 3 x (1 + 0.1 x niveau) |

Les points de construction sont **cumulatifs** : chaque niveau construit ajoute ses points au total. Perdre un niveau de batiment en combat retire les points correspondants.

> **Exemple :** Monter le Generateur du niveau 9 au 10 donne 1 x (1 + 0.1 x 10) = **2 points**. Monter le Stabilisateur du 9 au 10 donne 3 x (1 + 0.1 x 10) = **6 points**. Le Stabilisateur et le Condenseur sont les batiments les plus rentables en points.

### 2. Points d'Attaque

Gagnes en **remportant des combats offensifs**. Chaque combat donne des points bruts d'attaque (pointsAttaque) base sur les pertes totales :

- Points bruts par combat = min(20, 1 + 0.5 x racine(pertes_totales))
- **Contribution au classement** = 5.0 x racine(points_bruts_cumules)
- En cas de defaite, les points bruts sont **retires** (le perdant perd des points)

> **Exemple :** Un combat avec 100 molecules perdues au total donne min(20, 1 + 0.5 x 10) = **6 points bruts**. Apres 50 combats gagnes (~300 points bruts cumules) : 5.0 x racine(300) = **~87 points** au classement.

### 3. Points de Defense

Gagnes en **repoussant des attaques**. Meme formule que l'attaque, mais avec un **bonus x1.5** pour les victoires defensives :

- Points bruts par victoire defensive = min(20, 1 + 0.5 x racine(pertes_totales)) **x 1.5**
- **Contribution au classement** = 5.0 x racine(points_bruts_cumules)
- En cas de defaite en defense, vous **perdez** des points bruts

> **Defendre est rentable** : 1.5x les points par rapport a l'attaque, et vous gagnez +30% de la capacite de pillage ennemie en energie bonus en repoussant un attaquant.

### 4. Points de Pillage

Gagnes en **pillant des ressources** lors de victoires offensives :

- **Contribution au classement** = tanh(ressources_pillees / 50000) x 80
- Maximum theorique : **~80 points** (atteint autour de 150k ressources pillees)
- Fonction tanh = rendements decroissants, les premiers pillages comptent le plus

> **Exemple :** 10 000 ressources pillees = tanh(0.2) x 80 = **~16 points**. 50 000 pillees = tanh(1) x 80 = **~61 points**. 100 000 pillees = tanh(2) x 80 = **~77 points**.

### 5. Points de Commerce

Gagnes en **achetant et vendant au marche** :

- **Contribution au classement** = min(80, 0.08 x racine(volume_echange))
- Maximum : **80 points** (atteint a un volume de 1 000 000)
- Le volume inclut les achats ET les ventes
- Le bonus alliance **Reseau** augmente le volume comptabilise de +5%/niveau

> **Exemple :** 10 000 d'energie echangee = 0.08 x 100 = **8 points**. 100 000 = 0.08 x 316 = **~25 points**. 500 000 = 0.08 x 707 = **~57 points**.

### Recapitulatif : Comment Maximiser ses Points

| Source | Action | Points max typiques |
|--------|--------|---------------------|
| Construction | Monter tous les batiments | ~200-400+ (pas de cap) |
| Attaque | Gagner beaucoup de gros combats | ~100-200 |
| Defense | Repousser des attaques | ~80-150 |
| Pillage | Piller des joueurs riches | ~80 (cap via tanh) |
| Commerce | Trader activement au marche | ~80 (cap) |

### Points de Victoire

A la fin de chaque manche, des **points de victoire** (PV) sont attribues selon le classement :

| Rang | PV |
|------|-----|
| 1er | 100 |
| 2eme | 80 |
| 3eme | 70 |
| 4-10 | 70 - (rang-3) x 5 |
| 11-20 | 35 - (rang-10) x 2 |
| 21-50 | 12 - (rang-20) x 0.23 |
| 51-100 | 6 - (rang-50) x 0.08 |
| 101+ | 0 |

La saison se termine quand un joueur ou une alliance atteint **1000 points de victoire** cumules sur plusieurs manches.

### 4 Classements

1. **General** (totalPoints)
2. **Attaque** (points d'attaque bruts)
3. **Defense** (points de defense bruts)
4. **Pillage** (ressources pillees)

---

## 13. Medailles

### Systeme de Tiers

Chaque medaille a 8 tiers progressifs :

| Tier | Nom | Bonus |
|------|-----|-------|
| 1 | Bronze | +1% |
| 2 | Argent | +3% |
| 3 | Or | +6% |
| 4 | Emeraude | +10% |
| 5 | Saphir | +15% |
| 6 | Rubis | +20% |
| 7 | Diamant | +30% |
| 8 | Diamant Rouge | +50% |

### Les 10 Medailles et Seuils Complets

#### Medailles avec bonus en jeu

Ces medailles offrent un **bonus direct** qui modifie vos stats de combat ou d'economie :

| Medaille | Stat suivie | Bonus | Bronze | Argent | Or | Emeraude | Saphir | Rubis | Diamant | D.Rouge |
|----------|------------ |-------|--------|--------|----|----------|--------|-------|---------|---------|
| **Terreur** | Attaques lancees | Reduit cout d'attaque | 5 | 15 | 30 | 60 | 120 | 250 | 500 | 1000 |
| **Attaque** | Points d'attaque bruts | Augmente stat d'attaque | 100 | 1k | 5k | 20k | 100k | 500k | 2M | 10M |
| **Defense** | Points de defense bruts | Augmente stat de defense | 100 | 1k | 5k | 20k | 100k | 500k | 2M | 10M |
| **Pillage** | Ressources pillees | Augmente stat de pillage | 1k | 10k | 50k | 200k | 1M | 5M | 20M | 100M |
| **Constructeur** | Plus haut niv. batiment | Reduit couts de construction | 5 | 10 | 15 | 25 | 35 | 50 | 70 | 100 |

> **Important :** Les bonus des medailles Attaque, Defense et Pillage sont des **multiplicateurs** appliques directement aux formules de stat des molecules. Par exemple, avec la medaille Attaque tier Or (+6%), votre stat d'attaque est multipliee par 1.06.

#### Medailles de progression

Ces medailles comptent pour les points de **prestige** mais n'ont pas de bonus direct en jeu :

| Medaille | Stat suivie | Bronze | Argent | Or | Emeraude | Saphir | Rubis | Diamant | D.Rouge |
|----------|------------ |--------|--------|----|----------|--------|-------|---------|---------|
| **Pipelette** | Messages forum | 10 | 25 | 50 | 100 | 200 | 500 | 1000 | 5000 |
| **Pertes** | Molecules perdues | 10 | 100 | 500 | 2k | 10k | 50k | 200k | 1M |
| **Energivore** | Energie depensee | 100 | 500 | 3k | 20k | 100k | 2M | 10M | 1B |
| **Bombe** | (Sanctions moderation) | 1 | 2 | 3 | 4 | 5 | 6 | 8 | 12 |
| **Troll** | (Sanctions moderation) | 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 |

### Plafonnement Cross-Saison

Pour eviter que les veterans ecrasent les nouveaux joueurs, les bonus de medailles sont plafonnes :

- Pendant les **14 premiers jours** d'une nouvelle saison : bonus plafonnes au tier **Or (6%)**
- Apres 14 jours : bonus plafonnes a **10%** (tier Emeraude)

Cela signifie que vos medailles Diamant ou Diamant Rouge ne donnent pas 30-50% de bonus — elles sont plafonnees a 10% maximum en jeu. Les tiers superieurs restent utiles pour le prestige et l'affichage.

---

## 14. Specialisations Atomiques

A certains niveaux de batiments, vous debloquez des **specialisations irreversibles**. Choisissez soigneusement !

### Combat (Ionisateur niveau 15)

| Option | Effet |
|--------|-------|
| **Oxydant** | +10% attaque, -5% defense |
| **Reducteur** | +10% defense, -5% attaque |

### Economie (Producteur niveau 20)

| Option | Effet |
|--------|-------|
| **Industriel** | +20% production d'atomes, -10% production d'energie |
| **Energetique** | +20% production d'energie, -10% production d'atomes |

### Recherche (Condenseur niveau 15)

| Option | Effet |
|--------|-------|
| **Theorique** | +2 points condenseur/niveau, -20% vitesse de formation |
| **Applique** | +20% vitesse de formation, -1 point condenseur/niveau |

### Recommandations

- **Joueur offensif** : Oxydant + Industriel + Applique (attaque rapide et puissante)
- **Joueur defensif** : Reducteur + Energetique + Theorique (molecules tres fortes, production stable)
- **Joueur economique** : Reducteur + Industriel + Applique (production maximale d'atomes, formation rapide)

> **Attention :** Ces choix sont **irreversibles**. Ne vous precipitez pas — attendez d'avoir defini votre strategie de saison.

---

## 15. Systeme de Prestige

Le prestige est un systeme de **progression cross-saison**. Vos points de prestige (PP) s'accumulent entre les saisons et debloquent des bonus permanents.

### Gagner des PP

| Source | PP gagnes |
|--------|----------|
| Connecte dans la derniere semaine de la saison | +5 PP |
| Par tier de medaille atteint (6 categories : Terreur, Attaque, Defense, Pillage, Pertes, Energivore) | +1 PP par tier atteint |
| 10+ attaques dans la saison | +5 PP |
| Volume d'echange >= 20 | +3 PP |
| Don d'energie effectue | +2 PP |

> **Exemple :** Si vous avez Terreur Bronze (1 tier), Attaque Argent (2 tiers), Defense Bronze (1 tier), Pillage Or (3 tiers), Pertes Argent (2 tiers), Energivore Bronze (1 tier) = **10 PP** des medailles. Ajoutez la connexion finale semaine (+5), 10+ attaques (+5) = **20 PP minimum**.

### Bonus de Rang

| Rang final | PP supplementaires |
|------------|-------------------|
| Top 5 | +50 PP |
| Top 10 | +30 PP |
| Top 25 | +20 PP |
| Top 50 | +10 PP |

### Ameliorations Prestige

| Amelioration | Cout | Effet |
|--------------|------|-------|
| **Debutant Rapide** | 50 PP | Commencez avec le Generateur niveau 2 |
| **Experimente** | 100 PP | +5% production de ressources en permanence |
| **Veteran** | 250 PP | +1 jour de protection debutant |
| **Maitre Chimiste** | 500 PP | +5% aux stats de combat |
| **Legende** | 1000 PP | Badge unique + nom colore |

### Strategie Prestige

- **Premiere saison** : Visez les medailles (beaucoup de tiers faciles a atteindre) et restez connecte la derniere semaine = ~15-25 PP
- **Saisons suivantes** : Avec "Experimente" debloque, la production +5% s'accumule et vous donne un avantage subtil
- **Priorite d'achat** : Debutant Rapide (50) -> Experimente (100) -> Veteran (250) -> Maitre Chimiste (500)
- La "Legende" (1000 PP) est purement cosmetique — priorite basse

---

## 16. Catalyseurs Hebdomadaires

Chaque semaine, un **catalyseur** global est actif et modifie le gameplay pour tous les joueurs.

### Les 6 Catalyseurs

| Catalyseur | Effet | Strategie |
|------------|-------|-----------|
| **Combustion** | +10% degats d'attaque | Lancez vos offensives cette semaine |
| **Synthese** | +20% vitesse de formation | Formez un maximum de molecules |
| **Equilibre** | +50% convergence des prix marche | Les prix se stabilisent plus vite — moment pour specular |
| **Fusion** | -25% cout du Duplicateur | Investissez massivement dans le Duplicateur d'alliance |
| **Cristallisation** | -15% temps de construction | Ameliorez vos batiments |
| **Volatilite** | +30% disparition, +25% pillage | Attaquez pour piller, mais vos propres molecules disparaissent plus vite |

### Rotation

Les catalyseurs tournent automatiquement chaque **lundi**. Planifiez vos actions en fonction du catalyseur de la semaine.

> **Astuce :** Pendant la semaine "Fusion", coordonnez toute votre alliance pour investir massivement dans le Duplicateur — l'economie de 25% est enorme.

---

## 17. Strategies de Saison

### Phase 1 : Debut (Jours 1-7)

**Objectif :** Batir l'economie

1. Montez Generateur a 4-5 (revenu d'energie stable)
2. Montez Producteur a 3-4 (production d'atomes)
3. Montez Depot a 3 (stockage suffisant)
4. Rejoignez une alliance immediatement
5. Investissez dans le Duplicateur d'alliance
6. Commencez a former votre Classe 1 (tank : Carbone + Brome, isotope Stable)

### Phase 2 : Croissance (Jours 7-14)

**Objectif :** Militarisation

1. Formez les Classes 2 et 3 (attaquants : Oxygene + Hydrogene, isotope Reactif)
2. Montez Ionisateur et Champ de Force a 5+
3. Montez Condenseur a 5-8 (ameliore les stats de toutes vos molecules)
4. Commencez a espionner et attaquer les cibles faibles
5. Construisez le Coffre-fort a 3-5 (protegez vos reserves)
6. Concevez vos molecules pour activer des Reactions Chimiques

### Phase 3 : Domination (Jours 14-25)

**Objectif :** Accumuler des points

1. Attaquez regulierement (points d'attaque et pillage)
2. Commercez activement au marche (points de commerce)
3. Debloquez les Specialisations si vous atteignez les niveaux requis
4. Formez la Classe 4 (support Catalytique si vous avez 3 classes combattantes)
5. Montez le Stabilisateur pour preserver votre armee
6. Coordonnez les guerres d'alliance pour les PV d'alliance

### Phase 4 : Fin de Saison (Derniere semaine)

**Objectif :** Maximiser le rang final

1. Connectez-vous (5 PP de prestige)
2. Assurez-vous d'avoir lance 10+ attaques (5 PP)
3. Faites un don d'energie (2 PP)
4. Verifiez vos tiers de medailles — un dernier effort peut debloquer un tier superieur
5. Points de commerce : achetez/vendez au marche pour approcher le cap de 80 points

### Combinaisons Avancees

**Le Chimiste :** Concevez vos 4 classes pour activer Combustion + Neutralisation + Halogenation simultanement. Exige une planification precise de la repartition des atomes entre les classes.

**Le Banquier :** Focalisez sur Iode + marche. Des molecules riches en Iode produisent de l'energie passive, que vous reinvestissez au marche pendant les semaines "Equilibre". Points de commerce maximaux.

**Le General :** Phalange avec Classe 1 tank (C200 + Br200, Stable), Classes 2-3 offensives (O200 + H200, Reactif), Classe 4 support (Catalytique). Active Combustion + Hydrogenation pour +15% attaque et +15% PV.

**L'Opportuniste :** Adaptez votre strategie au catalyseur de la semaine. Formez pendant Synthese, construisez pendant Cristallisation, attaquez pendant Combustion, investissez en Duplicateur pendant Fusion.

---

## Annexe A : La Carte

La taille de l'icone d'un joueur sur la carte depend de ses points de victoire. Les joueurs proches de la victoire (1000 PV) apparaissent avec les plus grandes icones. Les seuils de taille sont a 62, 125, 250, et 500 PV.

---

## Annexe B : Aide-Memoire des Formules

### Production et Economie

| Formule | Expression |
|---------|------------|
| Energie produite | 75 x niveau_generateur |
| Drain producteur | 8 x niveau_producteur |
| Atomes produits | (1 + duplicateur%) x 60 x points_allocation |
| Stockage | 500 x niveau_depot |
| Protection coffre | min(50%, niveau_coffre × 3%) × placeDepot(niveau_depot) par type |
| Cout duplicateur | 10 x 2.0^(niveau+1) |
| Cout recherche alliance | base x facteur^(niveau+1) |
| Volatilite marche | 0.3 / joueurs_actifs |
| Taxe vente marche | 5% (vous recevez 95%) |

### Stats de Molecule

| Stat | Formule |
|------|---------|
| Attaque (O) | (1 + (0.1 x O)^2 + O) x (1 + niv/50) x (1 + medaille_attaque%) |
| Defense (C) | (1 + (0.1 x C)^2 + C) x (1 + niv/50) x (1 + medaille_defense%) |
| Points de Vie (Br) | (1 + (0.1 x Br)^2 + Br) x (1 + niv/50) |
| Destruction (H) | ((0.075 x H)^2 + H) x (1 + niv/50) |
| Pillage (S) | ((0.1 x S)^2 + S/2) x (1 + niv/50) x (1 + medaille_pillage%) |
| Vitesse (Cl) | (1 + 0.5 x Cl) x (1 + niv/50) |
| Formation (N) | total_atomes / (1 + (0.09 x N)^1.09) / (1 + niv/20) / bonus_lieur |
| Energie (I) | (0.003 x I^2 + 0.04 x I) x (1 + niv/50) |
| Disparition | 0.99 ^ ( ((1 + nbAtomes/150)^2) / 25000 ) |

### Batiments

| Formule | Expression |
|---------|------------|
| PV batiment | 20 x (1.2^niveau + niveau^1.2) |
| PV champ de force | 50 x (1.2^niveau + niveau^1.2) |
| Bonus ionisateur | +2% attaque par niveau |
| Bonus champ de force | +2% defense par niveau |
| Bonus duplicateur combat | +1% par niveau |
| Bonus lieur | floor(100 x 1.07^niveau) / 100 |
| Bonus stabilisateur | -1.5% disparition par niveau |
| Points condenseur | 5 par niveau de condenseur |

### Combat

| Formule | Expression |
|---------|------------|
| Cout attaque | 0.15 x (1 - medaille_terreur% / 100) x nombre_atomes |
| Neutrino cout | 50 energie chacun |
| Vitesse espionnage | 20 cases/heure |
| Vitesse marchands | 20 cases/heure |
| Points combat bruts | min(20, 1 + 0.5 x racine(pertes_totales)) |
| Points defense | bruts x 1.5 (victoire defensive) |
| Cooldown defaite | 4 heures |
| Cooldown victoire | 1 heure |

### Points au Classement

| Source | Contribution a totalPoints |
|--------|---------------------------|
| Construction | Somme(base x (1 + facteur x niveau)) par batiment |
| Attaque | 5.0 x racine(points_attaque_bruts_cumules) |
| Defense | 5.0 x racine(points_defense_bruts_cumules) |
| Pillage | tanh(ressources_pillees / 50000) x 80 (max ~80) |
| Commerce | min(80, 0.08 x racine(volume_echange)) |
