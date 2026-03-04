# Bonus Summary Page (bilan.php) — Design Document

**Goal:** Give players a single page showing ALL active bonuses, effects, and their calculation breakdowns.

**Architecture:** Standard authenticated PHP page using existing formula functions for verified calculations. Displays step-by-step chains with color-coded bonuses (green = positive, red = negative).

## Page Structure (14 Sections)

### A. Production d'Énergie
Full chain: Base (75 × gen level) → Iode catalyst → Energievore medal → Duplicateur → Prestige → Producteur drain = Net E/h

### B. Production d'Atomes
Table of 8 atom types: allocated points × base (60) × duplicateur × prestige = total/h

### C. Combat — Attaque
Ionisateur bonus, attack medal, prestige combat, catalyst, combat specialization

### D. Combat — Défense
Champ de force, defense medal, prestige combat, formation type, combat specialization

### E. Pillage
Pillage medal, catalyst bonus (Volatilité), vault protection, alliance Bouclier

### F. Stockage
Depot capacity, coffre-fort protection amount and %, fill levels

### G. Vitesse de Formation
Lieur bonus, alliance Catalyseur, catalyst (Synthèse), research specialization

### H. Déclin des Molécules
Stabilisateur effect, pertes medal, catalyst decay, per-class half-life

### I. Recherche Alliance
All 5 tech trees with levels and % bonuses (duplicateur + 5 techs)

### J. Médailles
6 medal types: current tier, progress to next threshold, active bonus %

### K. Prestige
PP balance, 5 unlocks with cost/effect/status

### L. Catalyseur de la Semaine
Current catalyst name, effects, countdown to rotation

### M. Niveaux du Condenseur
Per-atom condenseur levels with multiplier values

### N. Spécialisations
3 specialization types: unlock status, current choice, effects

## Implementation
- File: `bilan.php` (791 lines)
- Menu: Added to sidebar after "Prestige" link
- Uses all existing formula functions (revenuEnergie, revenuAtome, bonusLieur, demiVie, etc.)
- Verified totals cross-checked against canonical functions
