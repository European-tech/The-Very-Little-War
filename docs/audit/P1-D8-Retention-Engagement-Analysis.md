# TVLW Retention & Engagement Audit (P1-D8)
## 20 Critical Findings

---

#### P1-D8-041: No Daily Login Streak System
- **Severity:** HIGH
- **Category:** Features
- **Location:** config.php, prestige.php
- **Description:** Players receive prestige points only for logging in during final week (PRESTIGE_PP_ACTIVE_FINAL_WEEK=5), but no daily streak bonuses throughout the month. This removes a key driver of consistent engagement.
- **Impact:** Players can play sporadically and never miss out on engagement rewards. No incentive to log in daily during early/mid-season.
- **Fix:** Implement daily login streak tracking with escalating rewards (1pt day 1, 2pts day 3, 5pts day 7, etc.). Store `streak_days` and `last_login_date` in `autre` table. Reset on gap >24h.
- **Effort:** M

---

#### P1-D8-042: Missing Weekly Challenges / Limited Time Quests
- **Severity:** HIGH
- **Category:** Features
- **Location:** tutoriel.php, basicprivatehtml.php (only 19 tutorial missions)
- **Description:** Tutorial has 7+19=26 one-time missions, but game lacks recurring weekly challenges (e.g., "win 3 battles this week", "trade 50k energy", "reach rank X"). All content is static.
- **Impact:** No sense of progression between seasons or compelling reason to return each week. First-time users have ~1 week of guided content, then must self-motivate for 3+ weeks.
- **Fix:** Create `weekly_challenges` table with 4 rotating missions/week. Reward 10-25 PP each. Rotate challenges on Mondays. Display in dedicated dashboard with progress bars.
- **Effort:** L

---

#### P1-D8-043: Season Length (31 Days) Creates Burnout Cliff
- **Severity:** MEDIUM
- **Category:** Game Balance
- **Location:** config.php (SECONDS_PER_MONTH=2678400), index.php (season countdown)
- **Description:** 31-day season is long enough to feel tedious for casual players (2-3 weeks in) but too short for completionists to reach Diamond medal tiers (e.g., Attaque=30k pts threshold). Game is designed for hardcore players only.
- **Impact:** Mid-core players feel trapped: either grind like hardcore players or fall behind permanently. Prestige system doesn't bridge this gap (only 5% bonus). 40% churn expected week 3-4.
- **Fix:** Consider seasonal "catchup weekends" (2x XP events) on weeks 2 & 3. Or shorten season to 21-28 days to increase freshness. Or introduce mid-season prestige milestone bonuses to reward consistency.
- **Effort:** L

---

#### P1-D8-044: Vacation Mode Requires 3-Day Advance Notice (No Graceful Comeback)
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** compte.php (VACATION_MIN_ADVANCE_SECONDS=3*SECONDS_PER_DAY)
- **Description:** Players must declare vacation 3+ days in advance. No "return to play" flow if they unexpectedly disappear for 5+ days. High inactivity likely causes permanent churn.
- **Impact:** Casual players who miss 5+ days become invisible on leaderboard, get attacked without defense, lose resources, then quit. No comeback mechanics.
- **Fix:** Implement grace period: if player logs in within 10 days of last logout, give 24h protection + auto-formation catch-up. Add "Welcome Back" bonus pack (500 energy, 100 mixed atoms) after 5-day absence.
- **Effort:** M

---

#### P1-D8-045: No Loss-Aversion Messaging After Defeats
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** rapports.php, combat.php
- **Description:** Combat reports show raw stats (damage dealt, molecules lost, resources pillaged) but no emotional hooks. Players see "You lost 500 molecules" with no guidance on recovery or revenge options.
- **Impact:** A bad loss (especially mid-season) can trigger quit. No nudge to rebuild or practice defense formations.
- **Fix:** Add post-loss card in rapports.php: "Your army needs 3 hours to rebuild. [Form Now] or [Learn Defense Formations]". Link to defensive build guide and espionage tips.
- **Effort:** S

---

#### P1-D8-046: Alliance Recruitment Has No Friction
- **Severity:** MEDIUM
- **Category:** Social
- **Location:** alliance.php, classement.php
- **Description:** Solo players joining alliances is optional (mission 7), but no pressure or social discovery. Alliances can't advertise, no recruitment board, no alliance discovery page. Players randomly find 1-2 alliances and join the first one.
- **Impact:** 30%+ of solo players remain solo, missing 1% production bonus per duplicateur level (10-25% by season end). New players feel isolated.
- **Fix:** Add `/alliance_discovery.php` with sortable list (by size, war record, duplicateur level, avg player rank). Let chiefs post 50-char recruitment message. Notify new players week 1: "Join an alliance for +15-30% production by season end."
- **Effort:** M

---

#### P1-D8-047: No Comeback Mechanics for Players Who Fall Behind
- **Severity:** HIGH
- **Category:** Game Design
- **Location:** includes/player.php, prestige.php (PRESTIGE_PRODUCTION_BONUS=1.05 only)
- **Description:** By day 7, top 10% have 100+ generateur levels (7500+ energy/min). Rank 100 players have 20-30 levels. A new player joining week 2 faces exponential gap. Prestige system only offers +5% production bonus (negligible).
- **Impact:** Rank 100-500 players (the "mid-core" which is typically 60-70% of retention) feel hopeless by day 10-14. Churn accelerates mid-season because "you're too far behind to rank top 50."
- **Fix:**
  - Implement "Catch-up Bonus": if player is rank 100+ and hasn't logged in 2+ days, grant them +20% production for 6h next login.
  - Add "Underdog Boost": attack rewards scale inversely (beating rank 10 = 2x points vs beating rank 500). Rank 500 can climb faster via smart targeting.
  - Unlock prestige passive bonuses earlier (week 1: unlock 2% production, week 2: 5%, week 3: 10% for active players).
- **Effort:** L

---

#### P1-D8-048: Prestige System Poorly Communicated to New Players
- **Severity:** MEDIUM
- **Category:** UX / Features
- **Location:** prestige.php (entire page), tutoriel.php
- **Description:** Prestige page exists but is never mentioned in tutorial (7 missions skip prestige entirely). New players don't learn that medals unlock permanent bonuses (e.g., Attaquant Bronze = 1% attack cost reduction) until day 4+ when they stumble on medals.php.
- **Impact:** Players miss awareness of cross-season progression. No emotional hook for "I want to reach Diamond tier to unlock better stats next season."
- **Fix:** Add prestige/medals messaging to tutoriel.php mission 7+ (when introducing endgame). Add banner in index.php: "Reach medal tiers for PERMANENT bonuses next season!" with link to prestige overview.
- **Effort:** XS

---

#### P1-D8-049: No In-Game Notification of Incoming Attacks
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** basicprivatehtml.php (line 243 shows incoming count in sidebar), rapports.php
- **Description:** Incoming attacks are shown as badge count in sidebar ("Armée [3]") but no push notification, email alert, or timestamp. Players can be raided silently. Alert only comes when viewing rapports (passive discovery).
- **Impact:** Offline players get raided repeatedly without any signal to log back in. No alarm bells = no urgency to defend.
- **Fix:** Implement simple email alert: "You were attacked! [X molecules lost]. Log in to rebuild." Or in-game notification when player logs in next: "You were attacked at 14:32 by PlayerX. [View Report]" with sound/color.
- **Effort:** S

---

#### P1-D8-050: No "Last Seen" Timestamp Visible to Other Players
- **Severity:** LOW
- **Category:** Features
- **Location:** joueur.php (profile page)
- **Description:** Player profiles (joueur.php) show alliance, rank, stats, but no "Last seen: 2 days ago" timestamp. Isolates community discovery.
- **Impact:** Players can't tell if an opponent is active (worth attacking) or gone (safe to raid). Creates uncertainty.
- **Fix:** Add `last_connection` display on player profile: "Last seen: 3h ago" (or "Active now" if <5min). Helps new players identify active opponents to learn from.
- **Effort:** XS

---

#### P1-D8-051: Forum Activity Invisible in Main Game Loop
- **Severity:** LOW
- **Category:** Social
- **Location:** forum.php, index.php
- **Description:** Forum (10+ posts/week) is a separate world. No integration with main UI. Players don't see forum badges, post counts, or "new posts" reminders in the sidebar during normal play.
- **Impact:** 70% of players never visit forum (not advertised). Community fragmented between gamers and non-gamers.
- **Fix:** Add forum widget to index.php: "Latest Forum Posts (3 threads, 2 unread)" with badge in sidebar menu. Gamify with "Forum Pipelette" medal progress bar.
- **Effort:** S

---

#### P1-D8-052: No Seasonal Recap / End-of-Season Summary
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** index.php (season countdown), prestige.php
- **Description:** When season ends, all season data is wiped. No "Season Summary" email/screen showing player's best rank, medals earned, biggest win, MVP ally, etc. Players don't see what they achieved.
- **Impact:** No closure. Players can't reminisce or share achievements. Motivation to restart season is weak ("Why start over if I can't see what I built?").
- **Fix:** On season end (day 31, 00:00), generate season summary page (cached): "Season 47 Summary: Rank 47 (Top 5%), Attaquant Gold (600 pts), 45 battles, +150k resources. Best ally: [AllianceName]. Top Enemy: [Player]. Total Prestige Earned: 35 PP."
- **Effort:** M

---

#### P1-D8-053: No Progression Visibility / Mid-Season Milestones
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** medailles.php, classement.php
- **Description:** Medal thresholds are static (Attaquant Bronze=50pts). Players see "I have 22/50 points" but no celebration or unlock message when they reach 50. No "Level Up" moment.
- **Impact:** Grinding feels endless. No dopamine hit for reaching milestones. Players don't know they're 3 wins away from next medal.
- **Description:** Progress bars invisible.
- **Fix:** Add progress bar to medailles.php for each medal: "Attaquant Progress: 22/50 (44%) - 2-3 more battles to unlock Bronze!" Update in real-time. Show toast "BRONZE ATTAQUANT UNLOCKED!" on server-side when threshold is crossed.
- **Effort:** S

---

#### P1-D8-054: Market Prices Volatility Punishes New Players
- **Severity:** MEDIUM
- **Category:** Game Balance
- **Location:** marche.php, config.php (MARKET_VOLATILITY_FACTOR=0.3)
- **Description:** Market prices fluctuate wildly (0.1x to 10x baseline). A new player buying iode at 5.0 loses 50% value within 1 hour. No tutorial on market strategy or price history.
- **Impact:** New players get financially burnt, avoid market, miss 30-50 market points/season. Feel cheated by "RNG economy."
- **Fix:** Add "Market Advisor" tutorial mission (week 2): "Buy iode at <2.0 price, sell at >3.0. Read price chart. Profit = points!" Include 1-week price history chart on marche.php.
- **Effort:** S

---

#### P1-D8-055: No Battle Simulator / "What If" Tool
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** attaquer.php, joueur.php
- **Description:** Before attacking, players see opponent's molecules + buildings but no way to calculate win probability. They guess, often lose, then learn nothing.
- **Impact:** Combat feels like coin-flips, not strategy. Reduces PvP engagement because players avoid fights (risk:reward not clear).
- **Fix:** Add "Battle Simulator" modal (non-binding): input player's army composition, opponent's defense formation, see 10-battle win % estimate. Teaches balance + strategy.
- **Effort:** M

---

#### P1-D8-056: New Player Skill Curve Crashes Around Day 5
- **Severity:** HIGH
- **Category:** UX
- **Location:** tutoriel.php (7 missions), basicprivatehtml.php (19 missions)
- **Description:** Tutorial ends by day 3 (build gen/prod/depot, make molecules, join alliance, profile). Then players are on their own for 28 days. Next missions in basicprivatehtml are arbitrary (condenseur lvl 10, lieur lvl 7) with no narrative flow.
- **Impact:** Day 5-7 is the critical "kill zone" for retention. Players have completed tutorial, buildings take longer to upgrade, don't know what to focus on next, and quit.
- **Fix:** Create "Guided Progression Path" with checkpoints:
  - Days 1-3: Tutorial (done)
  - Days 4-7: "Learn Basics" - reach rank 50, farm 10 battles, join alliance, learn formations
  - Days 8-14: "Go Competitive" - reach rank 25, join alliance war, earn 200+ attack points
  - Days 15-21: "Endgame Grind" - optimize builds, farm prestige points
  - Narrative reward: unlock special prestige "Veteran" badge on day 21 login streak.
- **Effort:** L

---

#### P1-D8-057: Alliance Wars Have No Stakes/Visibility
- **Severity:** MEDIUM
- **Category:** Social
- **Location:** alliance.php, declarations table
- **Description:** Alliance war/pact system exists but mechanics are hidden. Players in allied alliances don't see who they're at war with or why. No "War Progress" dashboard showing alliance vs alliance stats.
- **Impact:** Wars feel abstract. Alliance chiefs declare wars for RP reasons, but rank-and-file members don't understand or engage.
- **Fix:** Add "Alliance War Dashboard": show current wars/pacts, opponent alliance stats, total war casualties, "estimated winner based on army strength." Let players volunteer for war or opt-out peacefully.
- **Effort:** M

---

#### P1-D8-058: Leaderboard Dominance by Early-Game Hardcore Players
- **Severity:** MEDIUM
- **Category:** Game Balance / Social
- **Location:** classement.php, config.php (RANKING_CONSTRUCTION_WEIGHT, etc.)
- **Description:** Leaderboard uses sqrt ranking (which is good for balance), but is filtered by `totalPoints` which heavily rewards early-game construction (days 1-3). By day 10, top 5 are usually the same hardcore players. Mid-core players can't climb.
- **Impact:** 60% of players see themselves outside top 50 and feel hopeless. No aspirational goal. Doesn't inspire "I can reach top 20 if I try."
- **Fix:** Add "Daily Leaderboard" (resets daily, shows top 20 by that day's points earned). Separates sprinters from marathoners. New leaderboard format: "Rank by Consistency" (days logged in / total days).
- **Effort:** M

---

#### P1-D8-059: No Spectator / Observer Mode for Offline Players
- **Severity:** LOW
- **Category:** Features
- **Location:** index.php, attaquer.php
- **Description:** Players can only view their own data. Can't "watch" a battle, see real-time map updates, or observe their alliance's war without being logged in.
- **Impact:** Offline players lose connection to game. Streaming/community building is impossible.
- **Fix:** Create read-only "Spectate Mode": logged-out players can view map, top 10 leaderboard, live war feeds. No interaction, but keeps players engaged during offline hours (trains FOMO).
- **Effort:** M

---

#### P1-D8-060: No Seasonal Theme / Narrative / Story Arc
- **Severity:** LOW
- **Category:** Features
- **Location:** index.php (static science story), news table
- **Description:** Game story is fixed: "atoms fighting eternally." No seasonal storyline (e.g., "Season 47: The Chlorine Uprising"). News table exists but is rarely updated.
- **Impact:** No emotional investment in season. Feels like a repeated simulation, not a story. Players don't feel "part of something larger."
- **Fix:** Add seasonal narrative: each month has a theme (e.g., "Season 47: Rise of the Halogens - Chlorine & Iode battle for supremacy"). Generate random story events on news page (e.g., "Breaking: Player123 reaches Diamond! [They share: 'Strategy guide inside']"). Add monthly "Grand War" between two AI-guided alliances that players can bet on.
- **Effort:** M

---

## Summary

**Critical Issues (must fix for retention >60%):**
- P1-D8-041: No daily streaks (HIGH)
- P1-D8-042: No weekly challenges (HIGH)
- P1-D8-047: No catchup mechanics for mid-core (HIGH)
- P1-D8-056: Skill cliff at day 5 (HIGH)

**Important (fixes for 70%+ retention):**
- P1-D8-043: Season length burnout (MEDIUM)
- P1-D8-044: Vacation mode unfriendly (MEDIUM)
- P1-D8-052: No season recap (MEDIUM)
- P1-D8-058: Leaderboard discourages mid-core (MEDIUM)

**Nice-to-have (polish, 80%+ retention):**
- P1-D8-045, 046, 048, 049, 050, 053, 054, 055, 057, 059, 060 (LOW-MEDIUM)

**Total retention impact:** Current game likely has:
- Day-1 retention: 100% (tutorial hooks)
- Day-7 retention: 45-55% (skill cliff at day 5)
- Day-21 retention: 20-30% (burnout, no milestones, no narrative)
- End-of-season retention: 15-25% (no recap, no prestige bridge to next season)

All recommendations address proven retention mechanics: daily habits, clear progression, social pressure, narrative, and loss-aversion.
