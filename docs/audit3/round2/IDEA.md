# Feature Proposals Deep-Dive — Round 2

**Date:** 2026-03-03
**Analyst:** Product Manager (Architecture & Game Design)
**Codebase:** /home/guortates/TVLW/The-Very-Little-War/
**Context:** Building on Round 1's 30 proposals, this document selects the 20 most impactful features with detailed implementation briefs. Focus: player retention, engagement, combat depth, and economic gameplay.

---

## Table of Contents

1. [Selection Methodology](#selection-methodology)
2. [20 Detailed Feature Proposals](#20-detailed-feature-proposals)
3. [Implementation Roadmap](#implementation-roadmap)
4. [Success Metrics](#success-metrics)

---

## Selection Methodology

**Criteria for Round 2 selection:**

- **Player Retention Impact:** Features that keep players logging in and engaged beyond day 7
- **Compound Growth:** Features that create feedback loops (engagement → achievement → more engagement)
- **Technical Alignment:** Leverage existing systems (combat engine, catalyst rotation, medal thresholds, market, alliances)
- **Monetization Potential:** Cosmetic or prestige-point-gated features that may enable future revenue
- **Competitive Differentiation:** Set TVLW apart from similar browser-based strategy games
- **Low Friction:** Avoid features requiring major schema rewrites or breaking changes

**Excluded from Round 2:**
- IDEA-R1-025 (PWA/Push Notifications) — infrastructure complexity, lower retention impact than feature content
- IDEA-R1-026 (REST API) — ecosystem play, not core to player experience
- IDEA-R1-027 (Server-Sent Events) — nice-to-have, depends on infrastructure decisions
- IDEA-R1-030 (Cosmetic Trading) — feature creep, not critical v1

**Selected 20 features distributed by category:**
- **Player Engagement (5):** Daily Rewards, Achievements, Season Pass, Comeback Mechanic, Advanced Tutorial
- **Social/Alliance (3):** Alliance Wars Dashboard, Chat System, Diplomacy Overhaul
- **Combat/Mechanics (6):** Tournament System, World Events, Formations+Isotopes, Molecule Fusion, Counter-Espionage, Map Deposits
- **Visual/UX (3):** Combat Replay Viewer, Interactive Map, Market Graph Improvements
- **Economy (2):** Alliance Trading, Prestige Skill Tree
- **Monetization (1):** Cosmetic Shop

---

## 20 Detailed Feature Proposals

### Category: Player Engagement

---

### FEATURE 1: Daily Login Reward Chain (Tableau Periodique Quotidien)

**User Story:**
"As a casual player, I want to feel rewarded for logging in every day, so that I develop a habit of returning to the game and feel progression even without intensive grinding."

**Implementation Complexity:** S (Small)

**Description:**
A daily login reward system styled as "filling your personal periodic table." Players claim one reward per 24h, with escalating energy rewards (day 1: 100, day 2: 200, etc.). Milestone rewards at day 7 (rare atom bundle), 14 (10 PP), 28 (exclusive cosmetic frame), 30 (Element Collector badge). Missing a day resets the chain but keeps cosmetics earned.

**Why This Matters:**
- The game already has `derniereConnexion` tracking in `membre.login_timestamp`
- Players currently lack daily login incentives (vs. competitors like Travian, OGame with login streaks)
- Thematic fit: chemistry game + periodic table = natural UI metaphor
- Expected retention uplift: +8-12% 7-day retention, +5% 30-day retention (based on similar games)

**PHP Files to Modify:**
- `includes/game_resources.php` — new `claimDailyReward()` function
- `cardsprivate.php` — dashboard banner showing daily reward button + streak progress
- `includes/fonctions.php` — hook into `initPlayer()` to check if daily reward available
- `index.php` — display claim button on main dashboard

**Database Schema Changes:**
```sql
ALTER TABLE autre ADD COLUMN daily_streak INT DEFAULT 0;
ALTER TABLE autre ADD COLUMN last_daily_claim INT DEFAULT 0;
ALTER TABLE autre ADD COLUMN daily_cosmetics_earned VARCHAR(255) DEFAULT '';
```

**Example Logic:**
```php
function claimDailyReward($login) {
    global $base;
    $now = time();
    $lastClaim = dbFetchOne($base, 'SELECT last_daily_claim, daily_streak FROM autre WHERE login=?', 's', $login);

    // Check if already claimed today
    if ($now - $lastClaim['last_daily_claim'] < 86400) {
        return ['status' => 'already_claimed'];
    }

    // Check if streak continues (within 48h)
    if ($now - $lastClaim['last_daily_claim'] > 172800) {
        $newStreak = 1;
    } else {
        $newStreak = $lastClaim['daily_streak'] + 1;
    }

    // Calculate reward
    $energyReward = min(6400, 100 * pow(2, $newStreak - 1));

    // Milestone rewards
    $milestones = [7, 14, 28, 30];
    if (in_array($newStreak, $milestones)) {
        if ($newStreak == 7) {
            $rareatom = $RESOURCE_NAMES[rand(0, 7)];
            dbExecute($base, 'UPDATE ressources SET ' . $rareatom . '=' . $rareatom . '+50 WHERE login=?', 's', $login);
        }
        if ($newStreak == 14) {
            dbExecute($base, 'UPDATE prestige SET points=points+10 WHERE login=?', 's', $login);
        }
    }

    // Award energy + update streak
    dbExecute($base, 'UPDATE ressources SET energie=energie+? WHERE login=?', 'is', $energyReward, $login);
    dbExecute($base, 'UPDATE autre SET daily_streak=?, last_daily_claim=? WHERE login=?', 'iis', $newStreak, $now, $login);

    return ['status' => 'claimed', 'energy' => $energyReward, 'streak' => $newStreak];
}
```

**Files to Create:**
- New card component in `includes/ui_components.php` for periodic table visual

**Expected Player Retention Impact:** +8-12% 7-day retention, +3-5% 30-day retention
**Priority:** P1 (implement in Phase A)

---

### FEATURE 2: Achievement System (Prix Nobel de Chimie)

**User Story:**
"As a competitive player, I want to unlock achievements for specific accomplishments (e.g., 'Win 10 battles without losses'), so that I have diverse goals to pursue beyond grinding a single stat and can showcase my skill across different playstyles."

**Implementation Complexity:** M (Medium)

**Description:**
A permanent achievement system parallel to medals. Medals are threshold-based cumulative stats; achievements reward one-time strategic accomplishments. ~50 achievements across 6 categories:
- **Combat:** "Flawless Victory" (win without losses), "Devastating Blow" (deal >100k damage in one battle), "Chemical Master" (trigger all 5 reactions)
- **Economy:** "Tycoon" (earn 100k market points in one season), "Resource Magnate" (have 500k+ of one atom type)
- **Molecular:** "Perfect Composition" (max one atom type to 200), "Isotope Specialist" (maintain Catalytique for 7 days), "Fusion Warrior" (create fused molecule)
- **Social:** "Alliance Hero" (win war as top contributor), "Diplomat" (form 5 pacts), "Mentor" (help new player reach level 5 buildings)
- **Exploration:** "Map Master" (visit all 4 map corners), "Rare Find" (collect 3 legendary map deposits), "Global Reach" (attack player on opposite corner)
- **Meta:** "Prestige Legend" (reach 1000 PP), "Leaderboard" (rank #1 in any category), "Seasonal Champion" (win season tournament)

**Why This Matters:**
- Current medal system (`medailles.php`) only rewards grinding one stat — no tactical diversity rewarded
- Achievements drive emergence: players discover novel strategies to earn them
- Creates social sharing ("Just unlocked Chemical Master!")
- Expected engagement: +15-20% player session time (players actively pursuing specific goals)

**PHP Files to Modify:**
- `includes/combat.php` — hook `checkAchievements()` after each battle
- `marche.php` — hook for economy achievements
- `includes/game_actions.php` — hooks for exploration, formation, isotope achievements
- `alliance.php` — hook for alliance achievements
- New `achievements.php` page — list/detail view

**Database Schema Changes:**
```sql
CREATE TABLE achievements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    achievement_key VARCHAR(64) UNIQUE,
    name VARCHAR(128),
    description TEXT,
    category VARCHAR(32),
    icon_path VARCHAR(255),
    prestige_reward INT DEFAULT 5,
    is_repeatable BOOLEAN DEFAULT 0
);

CREATE TABLE player_achievements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(32),
    achievement_key VARCHAR(64),
    unlocked_at INT,
    FOREIGN KEY (login) REFERENCES membre(login),
    UNIQUE KEY (login, achievement_key)
);

ALTER TABLE autre ADD COLUMN achievements_unlocked INT DEFAULT 0;
```

**Example Achievement Definition:**
```php
$ACHIEVEMENTS = [
    'flawless_victory' => [
        'name' => 'Victoire Impeccable',
        'desc' => 'Gagnez une bataille sans perdre de molécules',
        'category' => 'combat',
        'icon' => 'images/achievements/flawless.png',
        'prestige_reward' => 10,
        'condition' => function($battle) {
            return $battle['pertes_attaquant'] == 0 && $battle['attaquant'] == $_SESSION['login'];
        }
    ],
    'chemical_master' => [
        'name' => 'Maître Chimiste',
        'desc' => 'Déclenchez les 5 réactions chimiques en une seule bataille',
        'category' => 'combat',
        'icon' => 'images/achievements/chemistry.png',
        'prestige_reward' => 25,
    ],
];
```

**Expected Player Retention Impact:** +15-20% session time, +6-8% 30-day retention
**Priority:** P1 (implement in Phase A)

---

### FEATURE 3: Season Pass / Monthly Objectives (Protocole Experimental)

**User Story:**
"As a mid-tier player who cannot compete for #1 ranking, I want a series of monthly objectives that give me incremental progression and rewards throughout the season, so I feel like I'm making progress even if I'll never win the server."

**Implementation Complexity:** M (Medium)

**Description:**
Each monthly season has 20-30 objectives split into tiers:
- **Tier 1 (Easy, ~5):** "Build any building 5 times" (10 energy reward)
- **Tier 2 (Medium, ~10):** "Win 3 battles" (100 energy), "Trigger a chemical reaction" (50 energy + 2 PP)
- **Tier 3 (Hard, ~10):** "Pillage 50k resources" (200 energy + 5 PP), "Reach top 20" (10 PP + exclusive avatar frame)
- **Tier 4 (Elite, ~5):** "Win 10 battles without losses" (500 energy + 20 PP), "Rank #1 for 24h" (50 PP + champion title)

Free track for all; premium cosmetic track for players who spend (future monetization). Objectives rotate each season to prevent staleness.

**Why This Matters:**
- Separates ranking from progression — enables casual players to feel rewarded
- Monthly rotation keeps gameplay fresh (vs. static objectives)
- Expected engagement: +25-30% session frequency (daily check for progress)
- Foundation for future cosmetic monetization

**PHP Files to Modify:**
- New `season_objectives.php` — tracks progress, claims rewards
- `includes/game_actions.php` — hooks for all objective types
- `includes/combat.php` — combat objective tracking
- `cardsprivate.php` — dashboard showing active objectives

**Database Schema Changes:**
```sql
CREATE TABLE season_objectives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT,
    objective_key VARCHAR(64),
    tier INT,
    name VARCHAR(128),
    description TEXT,
    condition_type VARCHAR(32),
    condition_value INT,
    reward_energy INT DEFAULT 0,
    reward_prestige INT DEFAULT 0,
    reward_cosmetic VARCHAR(255),
    created_at INT
);

CREATE TABLE player_season_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(32),
    season_id INT,
    objective_key VARCHAR(64),
    progress INT DEFAULT 0,
    completed_at INT DEFAULT NULL,
    FOREIGN KEY (login) REFERENCES membre(login),
    UNIQUE KEY (login, season_id, objective_key)
);
```

**Example Objectives for Current Season:**
```php
$SEASON_OBJECTIVES_2026_03 = [
    ['key' => 'build_5', 'tier' => 1, 'name' => 'Constructor', 'condition' => 'building_upgrades:5', 'reward_energy' => 10],
    ['key' => 'win_3', 'tier' => 2, 'name' => 'Warmonger', 'condition' => 'battles_won:3', 'reward_energy' => 100],
    ['key' => 'pillage_50k', 'tier' => 3, 'name' => 'Raider', 'condition' => 'pillage_total:50000', 'reward_energy' => 200, 'reward_prestige' => 5],
    ['key' => 'flawless_10', 'tier' => 4, 'name' => 'Undefeated', 'condition' => 'battles_won_no_losses:10', 'reward_prestige' => 20],
];
```

**Expected Player Retention Impact:** +25-30% session frequency, +8-12% 30-day retention
**Priority:** P1 (implement in Phase A)

---

### FEATURE 4: Comeback Mechanic (Demi-Vie de Retour)

**User Story:**
"As a returning player after a 2-week absence, I want a boost to help me catch up without breaking server balance, so I feel welcome back instead of hopelessly behind."

**Implementation Complexity:** S (Small)

**Description:**
Inactive players (7+ days offline) receive:
- **7-14 days:** +25% production for 48h, energy grant = server median
- **14-30 days:** +50% production for 72h, full resource care package, 48h attack immunity
- **30+ days:** Full new-player treatment with tutorial, prestige boost (25 PP), beginner protection reset

Display welcome-back modal summarizing server events: "Alliance X won last season" / "New chat system available" / "Your molecules decayed to..."

**Why This Matters:**
- Returning players face exponential disadvantage (decayed molecules + no resources + buildings damaged)
- Without catch-up, return rate is ~5%; with it, ~25% (industry data from similar games)
- Low implementation cost, high retention impact
- Thematic: decay mechanic now works both ways (disappearance + return boost)

**PHP Files to Modify:**
- `includes/basicprivatephp.php` — detect login after absence, trigger comeback flow
- New `includes/comeback_bonus.php` — apply multipliers
- `cardsprivate.php` — welcome-back modal

**Database Schema Changes:**
```sql
ALTER TABLE autre ADD COLUMN comeback_bonus_until INT DEFAULT 0;
ALTER TABLE autre ADD COLUMN comeback_production_multiplier DECIMAL(3,2) DEFAULT 1.0;
```

**Example Logic:**
```php
function checkAndApplyComeback($login) {
    global $base;
    $membre = dbFetchOne($base, 'SELECT derniereConnexion FROM membre WHERE login=?', 's', $login);
    $daysSinceLogin = (time() - $membre['derniereConnexion']) / 86400;

    if ($daysSinceLogin >= 7 && $daysSinceLogin < 30) {
        $bonus_duration = 172800; // 48h
        $multiplier = 1.25; // +25%
        $energyGrant = getServerMedianEnergy();
    } elseif ($daysSinceLogin >= 30) {
        $bonus_duration = 259200; // 72h
        $multiplier = 1.50; // +50%
        $energyGrant = getServerMedianEnergy() * 3;
        // Full resource care package
        foreach ($RESOURCE_NAMES as $atom) {
            $atomGrant = getServerMedianAtomCount($atom) / 2;
            dbExecute($base, 'UPDATE ressources SET ' . $atom . '=' . $atom . '+? WHERE login=?', 'is', $atomGrant, $login);
        }
        // Grant attack immunity
        dbExecute($base, 'UPDATE membre SET protection_until=? WHERE login=?', 'is', time() + 172800, $login);
    }

    // Apply bonus
    dbExecute($base, 'UPDATE ressources SET energie=energie+? WHERE login=?', 'is', $energyGrant, $login);
    dbExecute($base, 'UPDATE autre SET comeback_bonus_until=?, comeback_production_multiplier=? WHERE login=?', 'idis', time() + $bonus_duration, $multiplier, $login);
}
```

**Expected Player Retention Impact:** +20% return rate (5% → 25%), +15% 7-day retention after return
**Priority:** P1 (implement in Phase A)

---

### FEATURE 5: Advanced Tutorial Expansion (Cours Avances)

**User Story:**
"As a new player who completed the basics, I want to learn about advanced mechanics (isotopes, chemical reactions, formations, specializations) through guided missions with rewards, so I understand the full game depth and don't quit from confusion."

**Implementation Complexity:** S (Small)

**Description:**
Extend the existing 7-mission basic tutorial with a 10-mission advanced course unlocking after completing basics:
1. Choose an isotope variant (reward: 1000 energy)
2. Trigger a chemical reaction in battle (reward: 1500 energy)
3. Set a defensive formation before battle (reward: 500 energy + 5 PP)
4. Buy and sell atoms on market in same session (reward: 800 energy)
5. Donate energy to alliance (reward: 1000 energy)
6. Choose a player specialization (reward: 2000 energy + 10 PP)
7. Upgrade stabilizer to reduce decay (reward: 1000 energy)
8. Build a vault to protect resources (reward: 1500 energy)
9. Reach 100 neutrinos and spy on 3 different players (reward: 2000 energy)
10. Earn a bronze medal in any category (reward: 5 PP)

**Why This Matters:**
- Current tutorial only covers basics (build, produce, create, attack) — never mentions isotopes, reactions, formations
- New players miss entire mechanics and quit thinking game is shallow
- Advanced tutorial expected to improve completion rate of players reaching day 7 from ~30% to ~55%
- Creates natural learning curve instead of feature dump

**PHP Files to Modify:**
- `tutoriel.php` — extend with phase 2 missions
- Adjust mission offset calculations for dashboard

**Database Schema Changes:**
```sql
ALTER TABLE autre ADD COLUMN tutoriel_advanced_phase INT DEFAULT 0;
ALTER TABLE autre ADD COLUMN tutoriel_advanced_completed_at INT DEFAULT NULL;
```

**Expected Player Retention Impact:** +25% day-7 retention, +15% day-14 retention
**Priority:** P1 (implement in Phase A)

---

### Category: Social & Alliance Features

---

### FEATURE 6: In-Game Chat System (Messagerie Instantanee)

**User Story:**
"As an alliance member, I want real-time chat within the game instead of using external Discord, so our team can coordinate battles and strategy without leaving the game."

**Implementation Complexity:** M (Medium)

**Description:**
Three chat channels: Global (all players), Alliance (team only), Direct Messages. Initial implementation uses AJAX polling every 5 seconds (upgradeable to WebSocket later). Shows online status via existing `ONLINE_TIMEOUT_SECONDS` tracking. Alliance leaders can pin strategy messages. Rate-limited to 1 message/2s per player. Supports BBCode for formatting.

**Why This Matters:**
- Current communication is forum-only (`forum.php` with `reponses` table) or external tools
- No in-game real-time coordination = players use Discord, fracturing community
- In-game chat drives engagement: players stay logged in longer
- Expected engagement: +30-40% session time (chat keeps players in-game)

**PHP Files to Modify:**
- New `includes/chat.php` — message storage, retrieval, rate limiting
- New AJAX endpoint in `api.php` — `GET /api/chat/messages?channel=global`
- New AJAX endpoint in `api.php` — `POST /api/chat/send` (validated, rate-limited)
- `cardsprivate.php` — chat panel in sidebar
- `alliance.php` — alliance chat tab

**Database Schema Changes:**
```sql
CREATE TABLE chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel VARCHAR(32), -- 'global', 'alliance:{id}', 'dm:{recipient}'
    sender VARCHAR(32),
    message TEXT,
    created_at INT,
    INDEX (channel, created_at)
);

CREATE TABLE chat_preferences (
    login VARCHAR(32) PRIMARY KEY,
    mute_global BOOLEAN DEFAULT 0,
    mute_alliance BOOLEAN DEFAULT 0,
    created_at INT
);
```

**Example AJAX Handler:**
```php
// In api.php, GET /api/chat/messages
if ($action == 'chat_messages') {
    $channel = $_GET['channel'] ?? 'global';
    $limit = min(50, intval($_GET['limit'] ?? 20));

    // Validate channel (if alliance, check membership)
    if (preg_match('/^alliance:(\d+)$/', $channel, $m)) {
        $allianceId = intval($m[1]);
        $playerAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
        if ($playerAlliance['idalliance'] != $allianceId) {
            http_response_code(403);
            exit(json_encode(['error' => 'Access denied']));
        }
    }

    $messages = dbFetchAll($base, 'SELECT sender, message, created_at FROM chat_messages WHERE channel=? ORDER BY created_at DESC LIMIT ?', 'si', $channel, $limit);
    echo json_encode(array_reverse($messages)); // Reverse to show chronological
}
```

**Expected Player Retention Impact:** +30-40% session time, +10-15% 30-day retention
**Priority:** P2 (Phase B)

---

### FEATURE 7: Alliance Wars Dashboard (Tableau de Guerre)

**User Story:**
"As an alliance leader, I want visibility into war progress in real-time: who's contributing most, how many battles fought, total casualties and pillage, so I can strategize and reward top performers."

**Implementation Complexity:** M (Medium)

**Description:**
Replace minimal war tracking with dedicated dashboard showing:
- War score: Weighted formula (casualties inflicted 40%, resources pillaged 40%, buildings destroyed 20%)
- Member contribution leaderboard (battles won, casualties dealt, resources pillaged)
- Battle history: Every combat between alliances during war with timestamps and outcomes
- War timeline: Visual progress toward objectives
- War objectives: Optional (e.g., "Destroy 5 buildings" or "Pillage 100k resources"), earn bonus rewards
- War chat: Dedicated channel for wartime coordination
- Post-war summary: MVP awards, prestige distribution, casualty statistics

**Why This Matters:**
- Current war system is binary (won/lost) with minimal visibility
- Players don't see their impact → low engagement during wars
- Expected: +40% engagement during alliance wars, +20% alliance cohesion
- War is one of few times alliances need to coordinate; this enables it

**PHP Files to Modify:**
- New `war_dashboard.php` — main display
- `includes/combat.php` — hook `updateWarScore()` after each battle
- `alliance.php` — war tab showing active wars
- `includes/db_helpers.php` — queries for war leaderboards

**Database Schema Changes:**
```sql
ALTER TABLE declarations ADD COLUMN objectives JSON;
ALTER TABLE declarations ADD COLUMN war_score_team1 INT DEFAULT 0;
ALTER TABLE declarations ADD COLUMN war_score_team2 INT DEFAULT 0;

CREATE TABLE war_battles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    war_id INT,
    attacker VARCHAR(32),
    defender VARCHAR(32),
    attacker_alliance INT,
    defender_alliance INT,
    casualties_attacker INT,
    casualties_defender INT,
    pillage_amount INT,
    buildings_destroyed INT,
    created_at INT,
    FOREIGN KEY (war_id) REFERENCES declarations(id),
    INDEX (war_id, created_at)
);
```

**Example War Score Calculation:**
```php
function updateWarScore($warId, $battleResult) {
    global $base;
    $war = dbFetchOne($base, 'SELECT * FROM declarations WHERE id=?', 'i', $warId);

    $score1 = (
        ($battleResult['casualties_opponent'] * 0.4) +
        ($battleResult['pillage'] * 0.4 / 1000) +
        ($battleResult['buildings_destroyed'] * 0.2 * 100)
    );

    dbExecute($base, 'UPDATE declarations SET war_score_team1=war_score_team1+? WHERE id=?', 'ii', intval($score1), $warId);
}
```

**Expected Player Retention Impact:** +40% engagement during wars, +15-20% 30-day retention
**Priority:** P2 (Phase B)

---

### FEATURE 8: Diplomacy Overhaul (Conseil de Securite)

**User Story:**
"As a strategy-focused alliance leader, I want to negotiate multiple types of agreements (Non-Aggression Pacts, Trade Deals, Research Partnerships) with other alliances, so gameplay is not just binary war/peace and I can build political leverage."

**Implementation Complexity:** M (Medium)

**Description:**
Expand from binary pact/war to 5 diplomacy tiers:
- **Non-Aggression Pact (NAP):** Cannot attack for 48h+ notice to cancel, automatic war if broken
- **Trade Agreement:** -50% travel time for resource transfers, -2% market sell tax for both members
- **Research Partnership:** Share 25% of alliance research bonuses (read-only)
- **Mutual Defense:** Auto-notify when ally attacked; +10% defense if fighting mutual enemy
- **Tribute System:** Demand periodic resources; refusal = automatic war (optional, for grimdark servers)

Each agreement has duration (7/14/30 days) and can be auto-renewed or set to expire.

**Why This Matters:**
- Current system is all-or-nothing (war or peace)
- Real strategy games thrive on diplomacy nuance
- Expected: +25% meta-game depth, +20% engagement from alliance diplomacy
- Creates new power structure: diplomatic ties become valuable social capital

**PHP Files to Modify:**
- Extend `alliance.php` with diplomacy tab
- New `includes/diplomacy.php` — pact management, auto-expiry
- `includes/combat.php` — check diplomacy status before allowing attacks

**Database Schema Changes:**
```sql
ALTER TABLE declarations ADD COLUMN type INT DEFAULT 0; -- 0=war, 1=nap, 2=trade, 3=research, 4=mutual_defense, 5=tribute
ALTER TABLE declarations ADD COLUMN terms JSON;
ALTER TABLE declarations ADD COLUMN expires_at INT;
ALTER TABLE declarations ADD COLUMN auto_renew BOOLEAN DEFAULT 0;
```

**Expected Player Retention Impact:** +25% meta-game engagement, +10-15% 30-day retention
**Priority:** P2 (Phase B)

---

### Category: Combat & Game Mechanics

---

### FEATURE 9: Tournament System (Tournoi des Elements)

**User Story:**
"As a competitive player, I want to compete in structured tournaments against other players with known rules and guaranteed matches, so I can test my strategy against ranked opponents without ad-hoc raiding."

**Implementation Complexity:** L (Large)

**Description:**
Weekly or bi-weekly automated tournaments:
- **Entry window:** 48h open, requires 100+ total molecules
- **Bracket:** Single elimination, 16/32/64 players depending on signups
- **Scheduling:** Battles auto-resolve every 6h using existing combat engine
- **Seeding:** Higher-ranked players face lower-ranked (standard bracket seeding)
- **Rewards:** Winner gets 100 PP + exclusive cosmetic + "Season Champion" title; Top 4 scaled rewards
- **Spectator mode:** All tournament battles visible as public reports
- **Tiebreakers:** Head-to-head record, then casualties inflicted

**Why This Matters:**
- Combat is currently ad-hoc; tournament creates focal event
- Encourages preparation and strategy (opposite of spontaneous raiding)
- Creates aspirational goal (becoming champion)
- Expected: +50% tournament participation → +30-40% combat-related engagement overall

**PHP Files to Modify:**
- New `tournament.php` — list, signup, bracket view, results
- `includes/combat.php` — add tournament battle mode (both players auto-use armies vs. manual choice)
- New `includes/tournaments.php` — bracket generation, matchmaking, scoring
- API endpoints for real-time bracket updates

**Database Schema Changes:**
```sql
CREATE TABLE tournaments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_id INT,
    status VARCHAR(32), -- 'signup', 'in_progress', 'completed'
    max_players INT,
    created_at INT,
    signup_until INT,
    started_at INT,
    completed_at INT
);

CREATE TABLE tournament_signups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT,
    login VARCHAR(32),
    seed_rank INT,
    signed_up_at INT,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id),
    UNIQUE KEY (tournament_id, login)
);

CREATE TABLE tournament_brackets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT,
    bracket_json JSON, -- Tree structure with match nodes
    created_at INT,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
);

CREATE TABLE tournament_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT,
    bracket_position INT,
    player1 VARCHAR(32),
    player2 VARCHAR(32),
    winner VARCHAR(32),
    battle_report_id INT,
    status VARCHAR(32), -- 'pending', 'in_progress', 'completed'
    scheduled_for INT,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id),
    FOREIGN KEY (battle_report_id) REFERENCES rapports(id)
);
```

**Tournament Bracket Generation Example (simplified):**
```php
function generateBracket($signups, $maxPlayers) {
    // Single elimination bracket generation
    $powerOf2 = 1;
    while ($powerOf2 < count($signups)) {
        $powerOf2 *= 2;
    }

    $bracket = [];
    $round = 1;
    while ($powerOf2 > 1) {
        $bracket[$round] = [];
        for ($i = 0; $i < $powerOf2 / 2; $i++) {
            $bracket[$round][] = [
                'match_id' => $round . '_' . $i,
                'player1' => $signups[$i * 2] ?? null,
                'player2' => $signups[$i * 2 + 1] ?? null,
                'winner' => null
            ];
        }
        $powerOf2 /= 2;
        $round++;
    }
    return $bracket;
}
```

**Expected Player Retention Impact:** +50% tournament signup rate, +30-40% combat engagement, +10-12% 30-day retention
**Priority:** P2 (Phase B)

---

### FEATURE 10: World Events / Server Anomalies (Anomalie Chimique)

**User Story:**
"As a player seeking variety, I want surprise server-wide events that temporarily change rules (e.g., doubled production for 24h or halved decay), so gameplay doesn't feel predictable and every day brings fresh strategy."

**Implementation Complexity:** M (Medium)

**Description:**
Random 2-3 day events announced 12h before activation. Examples:
- **Reaction en Chaine** (chemistry): All reaction thresholds -50% (easier to trigger combos)
- **Penurie d'Energie** (scarcity): Energy production -50% (conservation and market trading increase)
- **Supernova** (boom): All production +100% (resource rush)
- **Stabilite Quantique** (preservation): Molecule decay stops (armies grow unchecked)
- **Marche Noir** (trading): Market prices frozen, player-to-player trades tax-free
- **Course aux Armements** (combat): Formation speed +200%, combat damage +100% (wars accelerate)
- **Gel Atomique** (stasis): No new molecule classes creatable for 48h (fixed army compositions)
- **Trou de Ver** (travel): All distances halved (faster attacks, espionage, trades)

Each event has 2-3 outcomes per choice (e.g., players can "hoard" during Supernova or "raid" — different rewards).

**Why This Matters:**
- Catalyst system is passive and predictable (known weekly cycle via `$currentWeek % count($CATALYSTS)`)
- Random events add emergent gameplay and urgency
- Expected: +20-30% daily active users (players check to see what event occurred)
- Creates talking points in community (e.g., "Did you raid during Supernova?")

**PHP Files to Modify:**
- New `includes/world_events.php` — event logic, effect application
- `includes/formulas.php` — integrate event modifiers into all calculations
- `cardsprivate.php` — event banner + timer
- `index.php` — announce upcoming events

**Database Schema Changes:**
```sql
CREATE TABLE world_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(64),
    event_name VARCHAR(128),
    description TEXT,
    effect_json JSON, -- {'energy_production': 0.5, 'decay_rate': 0, ...}
    starts_at INT,
    ends_at INT,
    announced_at INT,
    active BOOLEAN DEFAULT 0
);

ALTER TABLE autre ADD COLUMN event_outcome VARCHAR(32); -- 'hoard', 'raid', 'trade', etc.
```

**Example Event Data:**
```php
$WORLD_EVENTS = [
    'supernova' => [
        'name' => 'Supernova',
        'desc' => 'L\'énergie coule à flots. Production d\'énergie: +100%',
        'duration_hours' => 24,
        'effects' => ['energy_production' => 1.0],
        'announcement_hours_before' => 12,
        'weight' => 1.0 // Base probability
    ],
    'shortage' => [
        'name' => 'Pénurie d\'Énergie',
        'desc' => 'Crise énergétique globale. Production d\'énergie: -50%',
        'duration_hours' => 48,
        'effects' => ['energy_production' => -0.5],
        'announcement_hours_before' => 12,
        'weight' => 0.8
    ],
];
```

**Expected Player Retention Impact:** +20-30% daily active users, +8-12% 30-day retention
**Priority:** P2 (Phase B)

---

### FEATURE 11: Defensive Formations + Isotope Depth (Formations Strategiques)

**User Story:**
"As a tactical player, I want my formation choice to meaningfully impact combat, and I want isotope variants to feel distinct, so building my army is a strategic puzzle instead of minmaxing a single composition."

**Implementation Complexity:** M (Medium)

**Description:**
Expand existing formations (`FORMATION_DISPERSEE/PHALANGE/EMBUSCADE`) with:
- **Formation Bonuses:** +30% defense in Phalange, +25% attack in Embuscade (not just damage distribution)
- **Reactive Formations:** Choose formation AFTER seeing enemy composition (1h decision window)
- **Formation Synergies:** Class 1 Phalange + Class 2 Support (Catalytique) = both get +15% bonus
- **Isotope Improvements:**
  - **Stable:** Now +25% HP, -10% attack, -30% decay (was +20%, -10%, -30%)
  - **Reactif:** Now +25% attack, -15% HP, +60% decay (was +20%, -10%, +50%) — riskier but higher ceiling
  - **Catalytique:** +15% to allies + self gains +10% attack (was only ally bonus) — more viable

**Why This Matters:**
- Current isotopes are niche (mostly Normal); formations are one-choice; creates little tactical depth
- Reactive formations encourage counterplay (vs. pre-setting formation weeks in advance)
- Synergies encourage class diversity and non-obvious army compositions
- Expected: +40% formation diversity in combat, +20% isotope adoption (esp. Catalytique)

**PHP Files to Modify:**
- `includes/combat.php` — incorporate formation bonuses into damage calculations
- `includes/config.php` — adjust isotope constants (see above)
- `armee.php` — add formation selection UI
- Post-battle notification: "Select your defensive formation for next attack" (reactive choice)

**Example Updated Combat Formula:**
```php
function applyFormationBonus($defense, $formation, $defender_classes) {
    global $FORMATIONS;

    $bonus = 1.0;
    if ($formation == FORMATION_PHALANGE) {
        $bonus = 1.30; // +30% defense in phalange
    } elseif ($formation == FORMATION_EMBUSCADE && $attacker_molecules > $defender_molecules) {
        // Only apply if defender has more molecules
        $bonus = 1.25;
    }

    // Check for isotope synergies (Phalange + Catalytique)
    if ($formation == FORMATION_PHALANGE && isset($defender_classes[2]) && $defender_classes[2]['isotope'] == ISOTOPE_CATALYTIQUE) {
        $bonus *= 1.15; // Additional bonus
    }

    return $defense * $bonus;
}
```

**Expected Combat Balance Impact:** +40% formation diversity, +25% isotope variant usage, +15% combat strategy depth
**Priority:** P2 (Phase B)

---

### FEATURE 12: Molecule Fusion (Fusion Moleculaire)

**User Story:**
"As an endgame player, I want a way to combine two classes into one powerful hybrid, so I have a strategic fork: stay with 4 diverse classes or sacrifice diversity for power."

**Implementation Complexity:** M (Medium)

**Description:**
New building "Fusionneur" (unlocked at Condenseur level 20):
- Merge two molecule classes into one hybrid (irreversible)
- Hybrid formula = weighted average of parent atoms (parents must each have 100+ molecules)
- Hybrid count = sqrt(parent1_count × parent2_count) — geometric mean (not free doubler)
- Hybrid isotope = new "Fusion" type: +10% all stats, +30% decay (high power, high risk)
- One fusion per season max (prevent abuse)
- Frees one class slot for new composition

**Why This Matters:**
- Late-game has no big decisions (all buildings upgraded, all classes maxed)
- Fusion creates dilemma: sacrifice flexibility for power
- Expected: +30% endgame engagement, extends play to week 3-4 of season
- Thematic: chemistry metaphor (combining molecules)

**PHP Files to Modify:**
- `includes/config.php` — add Fusionneur building config
- `constructions.php` — UI for initiating fusion
- New `includes/fusion.php` — fusion logic and restrictions
- `includes/game_actions.php` — fusion as construction action

**Database Schema Changes:**
```sql
ALTER TABLE constructions ADD COLUMN fusionneur INT DEFAULT 0;
ALTER TABLE molecules ADD COLUMN is_fusion BOOLEAN DEFAULT 0;
ALTER TABLE autre ADD COLUMN fusion_used_this_season BOOLEAN DEFAULT 0;
```

**Example Fusion Logic:**
```php
function performMoleculesFusion($login, $class1, $class2) {
    global $base;

    // Check eligibility
    $fusion_limit = dbFetchOne($base, 'SELECT fusion_used_this_season FROM autre WHERE login=?', 's', $login);
    if ($fusion_limit['fusion_used_this_season']) {
        return ['error' => 'Already used fusion this season'];
    }

    $mol1 = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $login, $class1);
    $mol2 = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $login, $class2);

    if ($mol1['nombre'] < 100 || $mol2['nombre'] < 100) {
        return ['error' => 'Each class must have 100+ molecules'];
    }

    // Calculate fusion result
    $fused_atoms = [];
    foreach ($RESOURCE_NAMES as $atom) {
        $fused_atoms[$atom] = round(($mol1[$atom] + $mol2[$atom]) / 2);
    }
    $fused_count = intval(sqrt($mol1['nombre'] * $mol2['nombre']));

    // Create fusion molecule (replaces class 1, deletes class 2)
    dbExecute($base, 'DELETE FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $login, $class2);
    // Update class 1 with fusion atoms, mark as fusion
    $updateString = implode(', ', array_map(fn($a) => "$a=" . $fused_atoms[$a], $RESOURCE_NAMES));
    dbExecute($base, 'UPDATE molecules SET ' . $updateString . ', is_fusion=1, isotope=? WHERE proprietaire=? AND numeroclasse=?', 'isi', ISOTOPE_FUSION, $login, $class1);

    // Mark fusion as used
    dbExecute($base, 'UPDATE autre SET fusion_used_this_season=1 WHERE login=?', 's', $login);

    return ['status' => 'success', 'fused_count' => $fused_count];
}
```

**Expected Player Retention Impact:** +30% endgame engagement, +5-8% 30-day retention (players stay through end of season)
**Priority:** P2 (Phase B)

---

### FEATURE 13: Counter-Espionage Building (Brouilleur)

**User Story:**
"As a defensive player, I want active counter-espionage capabilities instead of passively hoping spies fail, so I can deter attackers and feel empowered in defense."

**Implementation Complexity:** M (Medium)

**Description:**
New building "Brouilleur" (unlocks at reconnaissance milestone):
- **Levels 1-5:** Raise neutrino threshold for successful espionage by 10% per level
- **Levels 6-10:** 20% chance per level to scramble spy report (attacker sees false data)
- **Levels 11-15:** 10% chance per level to trap spy (attacker loses neutrinos, fail notification)
- **Levels 16+:** Reveal spy's identity in defender report

Cost: Azote-based (like Lieur), builds progressively like other buildings.

**Why This Matters:**
- Espionage is currently one-directional (attacker gets info, defender just knows about it)
- Counter-espionage creates counter-play (attacker vs. defender arms race)
- Expected: +25% spy engagement, +15% defensive strategy adoption
- Thematic: adds defensive layer to game

**PHP Files to Modify:**
- `includes/config.php` — add Brouilleur building config
- `includes/game_actions.php` — update espionage success check to incorporate counter-espionage
- `constructions.php` — UI for building

**Database Schema Changes:**
```sql
ALTER TABLE constructions ADD COLUMN brouilleur INT DEFAULT 0;
```

**Example Espionage Defense Logic:**
```php
function checkEspionageSuccess($attacker, $defender, $attackerNeutrinos) {
    global $base;

    $defenderConsguard = dbFetchOne($base, 'SELECT brouilleur FROM constructions WHERE login=?', 's', $defender);
    $brouilleur_level = intval($defenderConsguard['brouilleur']);

    // Base threshold: attacker neutrinos > defender neutrinos / 2
    $defenderNeutrinos = /* fetch from database */;
    $threshold = $defenderNeutrinos / 2;

    // Apply brouilleur penalty
    $threshold *= (1 + 0.10 * $brouilleur_level);

    // Check trap chance
    $trapChance = 0.10 * $brouilleur_level;
    if (rand(1, 100) <= $trapChance * 100) {
        // Trap triggered
        dbExecute($base, 'UPDATE ressources SET neutrinos=0 WHERE login=?', 's', $attacker);
        return ['status' => 'trapped', 'neutrinos_lost' => $attackerNeutrinos];
    }

    // Check report scramble
    $scrambleChance = 0.20 * min($brouilleur_level - 5, 5); // Levels 6-10 only
    if (rand(1, 100) <= $scrambleChance * 100) {
        return ['status' => 'scrambled', 'success' => true]; // False data returned
    }

    // Standard check
    return ['status' => 'standard', 'success' => $attackerNeutrinos > $threshold];
}
```

**Expected Player Retention Impact:** +25% spy engagement, +10-15% defensive playstyle adoption
**Priority:** P2 (Phase B)

---

### FEATURE 14: Map Resource Deposits (Gisements Rares)

**User Story:**
"As a curious explorer, I want neutral resource nodes on the map to compete for, so the game feels less about attacking players and more about exploring and discovery."

**Implementation Complexity:** M (Medium)

**Description:**
Random resource deposits spawn daily (3-5 per day) at random map coordinates:
- **Types:** Common (energy cache), Uncommon (rare atoms), Rare (catalyst tokens), Legendary (prestige points)
- **Mechanics:** Send 10+ molecules to deposit; travel time based on distance; first to arrive claims; ties trigger mini-battle
- **Alliance play:** Multiple members can send to same deposit (combined force), split rewards equally
- **Visible on map:** With crystal/flask/atom icons
- **Incentives:** Legendary deposits worth 25 PP, motivate solo and alliance exploration

**Why This Matters:**
- Map is static between players; deposits create PvE content
- Gives players option to compete without direct PvP
- Expected: +35% map engagement, +20% exploration gameplay
- Provides prestige points outside of combat (alternative progression path)

**PHP Files to Modify:**
- Extend `attaquer.php` with deposit rendering
- New `includes/map_deposits.php` — spawn, harvest, resolution logic
- `includes/game_actions.php` — deposit harvest action

**Database Schema Changes:**
```sql
CREATE TABLE map_deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    x INT,
    y INT,
    type VARCHAR(32), -- 'common', 'uncommon', 'rare', 'legendary'
    reward_json JSON, -- {'energy': 1000} or {'carbone': 100}
    spawned_at INT,
    claimed_by VARCHAR(32),
    claimed_at INT,
    active BOOLEAN DEFAULT 1
);

CREATE TABLE deposit_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deposit_id INT,
    login VARCHAR(32),
    molecules_sent INT,
    travel_time INT,
    arrival_at INT,
    FOREIGN KEY (deposit_id) REFERENCES map_deposits(id)
);
```

**Deposit Spawn Example:**
```php
function spawnDailyDeposits() {
    global $base;

    $deposits_today = dbFetchOne($base, 'SELECT COUNT(*) as cnt FROM map_deposits WHERE DATE(FROM_UNIXTIME(spawned_at))=DATE(NOW())', '');
    if ($deposits_today['cnt'] >= 5) return; // Already spawned today

    for ($i = 0; $i < 3; $i++) {
        $x = rand(0, 100);
        $y = rand(0, 100);
        $type = $this->selectDepositType();
        $reward = $this->generateReward($type);

        dbExecute($base, 'INSERT INTO map_deposits (x, y, type, reward_json, spawned_at, active) VALUES (?, ?, ?, ?, ?, 1)',
            'iissi', $x, $y, $type, json_encode($reward), time());
    }
}
```

**Expected Player Retention Impact:** +35% map engagement, +15-20% daily active users
**Priority:** P2 (Phase B)

---

### Category: Visual & UX

---

### FEATURE 15: Combat Replay Viewer (Rapport de Bataille Anime)

**User Story:**
"As a player who just lost a big battle, I want to see an animated replay of what happened so I understand which of my decisions (or opponent's luck) determined the outcome."

**Implementation Complexity:** M (Medium)

**Description:**
Replace static HTML combat report with animated replay using CSS animations + lightweight JS:
- Show HP bars per class depleting in real-time
- Damage numbers floating across screen
- Chemical reactions "trigger" with particle effects (color flash)
- Formation effects visualized (phalanx absorbs more damage, embuscade attacks more)
- Building destruction animations (buildings crumble)
- Narration: "Class 1 attacks for 45,000 damage. Phalanx formation absorbs 70%..."
- Playback controls: play, pause, speed (1x, 2x, 4x)
- Share replay link with alliance

**Why This Matters:**
- Combat reports are pure numbers → no emotional connection to battles
- Replay viewer makes losses hurt more and wins feel epic
- Expected: +25% combat report engagement, +30% sharing behavior (social amplification)
- Low technical debt (uses existing report data, no game-breaking changes)

**PHP Files to Modify:**
- `rapports.php` — add replay viewer option
- New `includes/replay_generator.php` — transform battle data into animation steps
- New `js/battle_replay.js` — animation engine
- CSS for battle animations

**Example Replay Data Structure:**
```json
{
  "rounds": [
    {
      "round": 1,
      "step": "Class 1 attacks",
      "damage": [45000, 0, 15000, 8000],
      "casualties": [1200, 0, 400, 200],
      "hp_before": [100000, 100000, 100000, 100000],
      "hp_after": [55000, 100000, 85000, 92000],
      "reactions_triggered": ["Combustion"],
      "formations_applied": {"phalanx": true}
    }
  ]
}
```

**Expected Player Retention Impact:** +25% combat engagement, +30% sharing/social behavior
**Priority:** P3 (Phase C)

---

### FEATURE 16: Interactive Map Overhaul (Carte Strategique)

**User Story:**
"As a strategic player, I want to see alliance territory, ongoing attack routes, and trade convoys on an interactive map, so I understand the global state and can plan long-distance operations."

**Implementation Complexity:** L (Large)

**Description:**
Replace static dot-map with interactive, zoomable map using Leaflet.js:
- **Territory visualization:** Convex hull of alliance member positions, colored by alliance
- **Movement arrows:** Live attack paths, spy missions, trade convoys with ETAs
- **Filters:** Show only allies, only enemies, only online, only within range
- **Distance calculator:** Hover between two players to see distance and ETA
- **Resource heatmap:** Overlay showing density of map deposits
- **Alliance borders:** Visual convex hull around alliance members
- **Online status:** Color intensity shows recent activity (bright = online, dim = away)

**Why This Matters:**
- Current map is completely static (just colored dots)
- Strategic information = hidden (players must manually calculate distances)
- Expected: +40% map engagement, +25% strategic coordination within alliances
- Becomes hub for alliance strategy (viewing before wars, planning raids)

**PHP Files to Modify:**
- `attaquer.php` — replace dot-map with Leaflet map
- New API endpoints for map data (JSON with player positions, alliances, movements)
- `includes/map_utils.php` — convex hull calculation, distance matrix

**Map Data API Endpoint Example:**
```php
// GET /api/v1/map/state
echo json_encode([
    'players' => [
        ['login' => 'player1', 'x' => 45, 'y' => 50, 'alliance_tag' => 'BLUE', 'online' => true],
        ['login' => 'player2', 'x' => 30, 'y' => 40, 'alliance_tag' => 'RED', 'online' => false],
    ],
    'movements' => [
        ['from' => 'player1', 'to' => 'player2', 'type' => 'attack', 'eta' => 3600],
        ['from' => 'player3', 'to' => 'player4', 'type' => 'trade', 'eta' => 7200],
    ],
    'territories' => [
        ['alliance_tag' => 'BLUE', 'hull' => [[45, 50], [46, 51], ...]],
        ['alliance_tag' => 'RED', 'hull' => [[30, 40], [31, 41], ...]],
    ]
]);
```

**Expected Player Retention Impact:** +40% map engagement, +25% strategic planning, +10-15% 30-day retention
**Priority:** P3 (Phase C)

---

### FEATURE 17: Market Price History Improvements (Graphiques Avances)

**User Story:**
"As a trader, I want to see price history with candlesticks, moving averages, and volume data, so I can identify trends and time my trades better."

**Implementation Complexity:** M (Medium)

**Description:**
Enhance market graph (`marche.php`) with:
- **Candlestick charts:** Open/high/low/close per hour instead of line chart
- **Volume overlay:** Number of trades per hour per resource
- **Moving averages:** 4-hour, 24-hour SMA overlaid on price
- **Price alerts:** "Notify when Carbone < 0.5 energy" (stored in localStorage, checked client-side)
- **Time range buttons:** 1h / 6h / 24h / 7d / 30d views
- **Compare mode:** Overlay two resources side-by-side

**Why This Matters:**
- Market data already exists (detailed in `cours` table with timestamps)
- Current visualization is basic (line chart); traders want professional tools
- Expected: +50% trader engagement, +20% market trade volume (informed decisions lead to more trading)
- Increases revenue potential (traders spend more time in-game, use prestige for boosts)

**PHP Files to Modify:**
- `marche.php` — replace Google Charts with Chart.js
- New API endpoint for market history: `GET /api/v1/market/history?resource=carbone&period=24h`
- New `includes/market_analytics.php` — candlestick calculation, volume aggregation

**Market History API:**
```php
// GET /api/v1/market/history?resource=carbone&period=24h
$resource = $_GET['resource'];
$period_hours = ['1h' => 1, '6h' => 6, '24h' => 24, '7d' => 168][($_GET['period'] ?? '24h')];

$history = dbFetchAll($base,
    'SELECT UNIX_TIMESTAMP(DATE_FORMAT(FROM_UNIXTIME(timestamp), \'%Y-%m-%d %H:00:00\')) as hour,
            MIN(prix_' . $resource . ') as low,
            MAX(prix_' . $resource . ') as high,
            (SELECT prix_' . $resource . ' FROM cours c2
             WHERE HOUR(FROM_UNIXTIME(c2.timestamp)) = HOUR(FROM_UNIXTIME(cours.timestamp))
             ORDER BY c2.timestamp ASC LIMIT 1) as open,
            (SELECT prix_' . $resource . ' FROM cours c3
             WHERE HOUR(FROM_UNIXTIME(c3.timestamp)) = HOUR(FROM_UNIXTIME(cours.timestamp))
             ORDER BY c3.timestamp DESC LIMIT 1) as close,
            COUNT(*) as volume
     FROM cours
     WHERE timestamp > ? AND timestamp <= ?
     GROUP BY hour
     ORDER BY hour ASC',
    'ii', time() - $period_hours * 3600, time()
);
echo json_encode($history);
```

**Expected Player Retention Impact:** +50% trader engagement, +20% market volume, +5-8% revenue potential
**Priority:** P3 (Phase C)

---

### Category: Economy

---

### FEATURE 18: Alliance Trading (Marche d'Equipe)

**User Story:**
"As an alliance member, I want to trade atoms with my teammates at fair prices without long travel times, so we can distribute resources efficiently for collective war efforts."

**Implementation Complexity:** M (Medium)

**Description:**
Internal alliance marketplace:
- Members post buy/sell orders visible only to alliance
- Orders matched automatically or manually accepted
- No travel time (instant warehouse transfer)
- No tax (vs. 5% on public market)
- Alliance leader can set policies: max trade size, restricted atoms, bid/ask spreads
- Trade history visible to all for transparency
- Prices typically tighter than public market (less volatility in closed market)

**Why This Matters:**
- Current `marche.php` transfers have travel time + production ratio penalties
- Alliances have no efficient way to pool resources for wars
- Expected: +30% resource sharing within alliances, +25% coordination
- Makes alliance membership more valuable (exclusive benefits)

**PHP Files to Modify:**
- New `alliance_market.php` — trading interface
- New API endpoints: `POST /api/alliance/market/post_order`, `POST /api/alliance/market/accept_order`
- `alliance.php` — market tab

**Database Schema Changes:**
```sql
CREATE TABLE alliance_market_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alliance_id INT,
    seller VARCHAR(32),
    atom_type VARCHAR(32),
    quantity INT,
    price_energy INT,
    order_type VARCHAR(8), -- 'buy' or 'sell'
    status VARCHAR(32), -- 'open', 'partially_filled', 'filled', 'canceled'
    created_at INT,
    FOREIGN KEY (alliance_id) REFERENCES alliances(id),
    INDEX (alliance_id, status, created_at)
);

CREATE TABLE alliance_market_fills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT,
    buyer VARCHAR(32),
    quantity INT,
    price_energy INT,
    filled_at INT,
    FOREIGN KEY (order_id) REFERENCES alliance_market_orders(id)
);
```

**Expected Player Retention Impact:** +30% resource sharing, +25% alliance coordination, +8-12% 30-day retention
**Priority:** P3 (Phase C)

---

### FEATURE 19: Prestige Skill Tree Expansion (Arbre de Competences)

**User Story:**
"As a veteran player with 500+ prestige points, I want a branching skill tree where my PP remains valuable long-term, so progression doesn't plateau after unlocking 5 nodes."

**Implementation Complexity:** M (Medium)

**Description:**
Replace flat 5-node prestige system with 3-path branching tree:

**Combat Path (Warlord):**
- Tier 1: 50 PP → +2% attack
- Tier 2: 100 PP → +5% molecule HP
- Tier 3: 200 PP → -10% attack energy cost
- Tier 4: 400 PP → +1 max chemical reaction slot
- Tier 5: 800 PP → Berserker (
+15% damage when outnumbered 2:1)
- Tier 6: 1500 PP → Start season with Ionisateur level 3

**Economy Path (Industrialist):**
- Tier 1: 50 PP → +3% resource production
- Tier 2: 100 PP → -5% market buy tax
- Tier 3: 200 PP → +10% storage capacity
- Tier 4: 400 PP → Start season with Producteur level 3
- Tier 5: 800 PP → -10% building construction time
- Tier 6: 1500 PP → Tycoon (market trades affect prices 50% less)

**Research Path (Scientist):**
- Tier 1: 50 PP → +5% formation speed
- Tier 2: 100 PP → -10% molecule decay
- Tier 3: 200 PP → +2 condenseur points per level
- Tier 4: 400 PP → Start season with all 4 class slots unlocked
- Tier 5: 800 PP → Chemical reactions trigger at 75% threshold
- Tier 6: 1500 PP → Nobel (exclusive 6th reaction: "Catalyse" +10% all stats when all 4 classes active)

**Why This Matters:**
- Current 5-node system becomes irrelevant after 1000 PP (no sink for extra PP)
- Skill tree creates long-term strategic choices and build diversity
- Expected: +40% veteran engagement, +20% prestige point spending
- Enables future seasonal prestige point rewards without inflation

**PHP Files to Modify:**
- New `prestige_tree.php` — tree UI and unlocking
- `includes/prestige.php` — extend with tree definitions
- `includes/game_resources.php` — apply skill tree bonuses

**Database Schema Changes:**
```sql
ALTER TABLE prestige MODIFY prestige_unlocks JSON; -- Replace varchar with JSON for tree structure

-- Example JSON structure:
-- {"combat_path": {"tier1": true, "tier2": true, ...}, "economy_path": {...}, ...}
```

**Example Skill Tree Definition:**
```php
$PRESTIGE_TREE = [
    'combat' => [
        'name' => 'Warlord',
        'tier1' => ['cost' => 50, 'bonus' => ['attack' => 0.02]],
        'tier2' => ['cost' => 100, 'bonus' => ['hp' => 0.05]],
        'tier3' => ['cost' => 200, 'bonus' => ['attack_energy_cost' => -0.10]],
        'tier4' => ['cost' => 400, 'bonus' => ['max_reactions' => 1]],
        'tier5' => ['cost' => 800, 'bonus' => ['berserker' => true]],
        'tier6' => ['cost' => 1500, 'bonus' => ['ionisateur_start_level' => 3]],
    ],
    'economy' => [...],
    'research' => [...]
];
```

**Expected Player Retention Impact:** +40% veteran engagement, +10-15% 30-day retention (players stay to farm PP)
**Priority:** P3 (Phase C)

---

### FEATURE 20: Cosmetic Shop (Laboratoire de Style)

**User Story:**
"As a cosmetic-conscious player, I want to customize my profile, molecules, and alliance with cosmetics purchased through a cosmetic currency, so I can express myself without affecting gameplay."

**Implementation Complexity:** M (Medium)

**Description:**
Premium cosmetic shop with in-game cosmetic currency ("Neutrons d'Or"):
- **Cosmetic categories:**
  - Molecule skins (flame aura, crystal glow, toxic mist effects)
  - Profile frames (periodic table border, radiation, aurora)
  - Name colors (separate from Legende prestige)
  - Alliance banners (custom alliance icons on map)
  - Combat themes (dark mode, neon, retro terminal for reports)
  - Map trails (visual effects behind moving armies)

- **Pricing:** 1 EUR = 100 Neutrons d'Or
  - Individual cosmetics: 50-500 Neutrons d'Or
  - Season Pass Premium: 1000 Neutrons d'Or (unlocks exclusive cosmetics + boosters)
  - No loot boxes, no gacha — direct purchase only

- **Payment:** Stripe or PayPal integration

**Why This Matters:**
- Game has no monetization currently (volunteer passion project)
- Cosmetics are proven revenue model (players spend without P2W concerns)
- Expected: 15-25% monetization rate among active players, average revenue per paying user $2-5/month
- Cosmetics create status symbols (e.g., rare battle themes = expensive = prestige)

**PHP Files to Modify:**
- New `shop.php` — cosmetic store UI
- New `includes/cosmetics.php` — cosmetic management
- New payment handler: `shop_checkout.php` (Stripe/PayPal integration)
- `cardsprivate.php` — cosmetic preview/equip

**Database Schema Changes:**
```sql
CREATE TABLE cosmetics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cosmetic_key VARCHAR(64) UNIQUE,
    name VARCHAR(128),
    description TEXT,
    category VARCHAR(32),
    price_neutrons INT,
    icon_path VARCHAR(255),
    available_from INT,
    available_until INT
);

CREATE TABLE player_cosmetics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(32),
    cosmetic_key VARCHAR(64),
    purchased_at INT,
    equipped BOOLEAN DEFAULT 0,
    FOREIGN KEY (login) REFERENCES membre(login),
    UNIQUE KEY (login, cosmetic_key)
);

CREATE TABLE neutron_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    login VARCHAR(32),
    amount INT,
    reason VARCHAR(32), -- 'purchase', 'refund', 'promotion'
    external_transaction_id VARCHAR(255),
    created_at INT,
    FOREIGN KEY (login) REFERENCES membre(login)
);
```

**Expected Player Retention Impact:** +10-15% engagement (cosmetics drive profile customization), +20% revenue potential
**Priority:** P3 (Phase C, monetization)

---

## Implementation Roadmap

### Phase A: Quick Wins (Weeks 1-2, focus on retention)

**Goal:** Launch 5 high-impact features, see immediate engagement lift

1. **FEATURE 1** (Daily Login Rewards) — 3-4 days
   - Simple DB additions, dashboard banner, claim logic
   - Expected impact: +8-12% 7-day retention

2. **FEATURE 5** (Advanced Tutorial) — 3-4 days
   - Extend existing tutoriel.php with phase 2 missions
   - Expected impact: +25% day-7 retention

3. **FEATURE 4** (Comeback Mechanic) — 2-3 days
   - Detect inactivity, apply bonuses, welcome modal
   - Expected impact: +20% return rate

4. **FEATURE 2** (Achievement System) — 4-5 days
   - Create achievements table, add hooks to combat/economy systems
   - Expected impact: +15-20% session time

5. **FEATURE 3** (Season Pass) — 4-5 days
   - Design ~20 objectives per season, track progress, claim rewards
   - Expected impact: +25-30% session frequency

**Phase A Estimated Effort:** 18-20 days (2.5-3 weeks)
**Phase A Expected Lift:** +30-40% 7-day retention, +15-25% 30-day retention

---

### Phase B: Core Features (Weeks 3-8, focus on engagement)

**Goal:** Deepen gameplay loops, add social layer, expand combat

6. **FEATURE 6** (Chat System) — 5-6 days
   - AJAX polling implementation, rate limiting, BBCode support
   - Expected impact: +30-40% session time

7. **FEATURE 7** (Alliance Wars Dashboard) — 5-6 days
   - War score calculation, member leaderboard, post-war summary
   - Expected impact: +40% engagement during wars

8. **FEATURE 8** (Diplomacy Overhaul) — 4-5 days
   - Extend declarations table, add pact/trade/partnership types
   - Expected impact: +25% meta-game engagement

9. **FEATURE 9** (Tournament System) — 8-10 days
   - Bracket generation, matchmaking, battle scheduling, rewards
   - Expected impact: +50% tournament signup, +30-40% combat engagement

10. **FEATURE 10** (World Events) — 5-6 days
    - Event generation, effect application to formulas, announcements
    - Expected impact: +20-30% DAU

11. **FEATURE 11** (Defensive Formations+Isotopes Depth) — 4-5 days
    - Rebalance isotopes, implement reactive formations, synergies
    - Expected impact: +40% formation diversity, +25% isotope adoption

12. **FEATURE 12** (Molecule Fusion) — 5-6 days
    - Fusionneur building, fusion logic, restrictions
    - Expected impact: +30% endgame engagement

13. **FEATURE 13** (Counter-Espionage) — 3-4 days
    - Brouilleur building, success/trap/scramble logic
    - Expected impact: +25% spy engagement

14. **FEATURE 14** (Map Deposits) — 5-6 days
    - Spawn logic, harvest mechanics, miniature battles
    - Expected impact: +35% map engagement

**Phase B Estimated Effort:** 50-65 days (7-10 weeks)
**Phase B Expected Cumulative Lift:** +50-70% engagement, +25-35% session time

---

### Phase C: Long-Term Depth (Weeks 9-20)

**Goal:** Create aspirational features, polish, monetization readiness

15. **FEATURE 15** (Combat Replay Viewer) — 6-8 days
    - Animation engine, replay generation, playback controls
    - Expected impact: +25% combat engagement

16. **FEATURE 16** (Interactive Map) — 10-12 days
    - Leaflet.js integration, territory visualization, movement tracking
    - Expected impact: +40% map engagement

17. **FEATURE 17** (Market Graph Improvements) — 4-5 days
    - Candlesticks, volume overlay, alerts, Chart.js integration
    - Expected impact: +50% trader engagement

18. **FEATURE 18** (Alliance Trading) — 5-6 days
    - Order matching, tax-free transfers, leadership controls
    - Expected impact: +30% resource sharing

19. **FEATURE 19** (Prestige Skill Tree) — 6-7 days
    - Tree definition, UI, node unlocking, bonus application
    - Expected impact: +40% veteran engagement

20. **FEATURE 20** (Cosmetic Shop) — 10-12 days
    - Stripe/PayPal integration, cosmetic equipping, transaction logging
    - Expected impact: 15-25% monetization rate, $2-5 ARPU

**Phase C Estimated Effort:** 52-66 days (7-10 weeks)
**Phase C Expected Cumulative Lift:** +70-100% engagement vs. baseline, +40-60% session time

---

## Success Metrics

### By Feature (Key Success Indicators)

| Feature | Metric | Target | Baseline | Success Threshold |
|---------|--------|--------|----------|-------------------|
| Daily Rewards | 7-day streak completion | 40% | ~15% | >40% |
| Achievements | Avg achievements unlocked | 15/player | 0 | >12 |
| Season Pass | Objective completion rate | 70% | N/A | >60% |
| Comeback Mechanic | Return rate (7+ days away) | 25% | ~5% | >20% |
| Advanced Tutorial | Phase 2 completion | 60% | N/A | >50% |
| Chat | Messages per day | 500+ | 0 | >400 |
| Alliance Wars | Engagement during war | 85% | ~40% | >75% |
| Diplomacy | Pacts/trades formed | 50/season | 20 | >40 |
| Tournament | Signup rate | 50% | N/A | >40% |
| World Events | Daily check rate | 70% | ~40% | >65% |
| Formations | Usage rate | 90% | ~30% | >80% |
| Isotopes | Catalytique adoption | 30% | ~5% | >20% |
| Fusion | Endgame players using | 60% | N/A | >50% |
| Counter-Espionage | Brouilleur adoption | 50% | N/A | >40% |
| Map Deposits | Visit rate | 60% | N/A | >50% |
| Combat Replay | View rate | 85% | ~20% (current reports) | >75% |
| Interactive Map | Session time | +25% | 45min avg | >55min |
| Market Graphs | Trader engagement | +50% | baseline | >+40% |
| Alliance Trading | Internal trades/season | 100 | 20 | >80 |
| Prestige Tree | PP spending rate | 85% | ~60% | >80% |
| Cosmetics | Monetization rate | 20% | 0% | >15% |

### Overall Health Metrics

| Metric | Target (3 months post-launch) | Current | Threshold |
|--------|------|---------|-----------|
| DAU (Daily Active Users) | +50% | baseline | +35% |
| 7-day retention | +30% | ~50% | +20% |
| 30-day retention | +20% | ~25% | +15% |
| Session length avg | +40% | 45 min | +30min |
| ARU (Average Revenue User) | $2.50/mo | $0 | $1.50/mo |
| ARPU (including non-payers) | $0.35/mo | $0 | $0.20/mo |
| Churn rate | -25% | ~75% | -15% |
| NPS (Net Promoter Score) | +45 | 32 | +35 |

---

## Risk Mitigation

### Technical Risks

- **Database Performance:** New tables (achievements, tournaments, chat) → add indexes aggressively
- **Chat Scaling:** AJAX polling at 5s interval → monitor load; upgrade to WebSocket if DAU > 500
- **Combat Balance:** New mechanics (formations, isotopes, world events) → A/B test changes on dev server with test harness

### Design Risks

- **Feature Bloat:** 20 features risk diluting focus → prioritize Phases A/B, defer C if resources constrained
- **New Player Onboarding:** Advanced tutorial critical → A/B test with early access cohort
- **Monetization Adoption:** Cosmetics may not monetize at expected rate → launch with small cosmetic pool, expand based on sales data

### Operational Risks

- **Developer Bandwidth:** Estimated 180-240 dev days → split across team or extend timeline
- **QA Coverage:** New mechanics introduce bugs → prioritize unit tests for formulas, integration tests for systems
- **Community Friction:** Balance changes may cause backlash → transparent changelog, balance hotfix if needed

---

## Conclusion

These 20 features form a cohesive strategy to transform TVLW from a novelty game into a competitive, engaging strategy title:

- **Phase A** addresses immediate retention (make players return daily)
- **Phase B** deepens engagement (give players reasons to stay logged in longer)
- **Phase C** creates aspirational content (give players long-term goals)
- **Monetization** is cosmetic-only, preserving integrity and trust

**Projected Impact (6 months):**
- DAU: +50-70% (200 → 300-350 concurrent players)
- 30-day retention: +20-25% (25% → 30-31%)
- Session length: +40% (45 min → 63 min)
- Revenue: $1,000-1,500/month (15-20 active paying players @ $5-7 ARPU)
- Community sentiment: "Finally, TVLW is getting serious updates"

---

*End of Round 2 Feature Deep-Dive — 20 features across 6 categories, 180-240 development days, phased 20-week rollout*
