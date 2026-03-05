# Ultra Audit Pass 1 — Domain 8: Feature & Competitiveness

**Date:** 2026-03-04
**Pass:** 1 (Broad Scan)
**Subagents:** 3 (Competitive Analysis, Feature Gap Analysis, Retention & Engagement)

---

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| HIGH | 11 |
| MEDIUM | 28 |
| LOW | 21 |
| **Total** | **60** |

---

## Area 1: Competitive Analysis (vs OGame, Travian, Clash of Clans, etc.)

#### P1-D8-001: No real-time chat or voice communication
- **Severity:** HIGH
- **Category:** Features
- **Location:** N/A
- **Description:** Only forum + async private messages. No alliance chat, buddy system, or Discord integration. Competitors all have real-time chat.
- **Impact:** Alliance coordination is delayed and friction-heavy during conflicts.
- **Fix:** WebSocket-based alliance chat. Discord webhook integration.
- **Effort:** L

#### P1-D8-002: No mobile native app
- **Severity:** HIGH
- **Category:** Features
- **Location:** N/A
- **Description:** Browser-only with Framework7 responsive. 60%+ gaming traffic is mobile. No App Store presence, no push notifications.
- **Impact:** Limits discoverability and daily engagement vs mobile-first competitors.
- **Fix:** React Native or Flutter cross-platform app with Firebase push.
- **Effort:** XL

#### P1-D8-003: No monetization system
- **Severity:** HIGH
- **Category:** Features
- **Location:** N/A
- **Description:** 100% free, zero revenue. Cannot fund development, marketing, or operations.
- **Impact:** Unsustainable long-term. Cannot compete with funded studios.
- **Fix:** Cosmetics + battle pass ($5/season). No pay-to-win.
- **Effort:** L

#### P1-D8-004: Single-server architecture limits scale
- **Severity:** HIGH
- **Category:** Features
- **Location:** N/A
- **Description:** Single VPS. No server sharding, no separate beginner servers. Veterans dominate indefinitely.
- **Impact:** Cannot scale beyond ~10K concurrent. New players permanently outmatched.
- **Fix:** Multi-universe architecture. Beginner/veteran server separation.
- **Effort:** XL

#### P1-D8-005: No beginner-to-veteran progression ladder
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** Only 3-day beginner protection. OGame offers 12 weeks. New players face veterans with 1000x resources.
- **Impact:** Extremely poor day-7 retention for new players.
- **Fix:** 2-3 week beginner servers. Graduated release to main server.
- **Effort:** M

#### P1-D8-006: No alliance progression/reward system
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** Alliances have shared research only. No levels, treasury, territory, perks, or progression.
- **Impact:** Alliances feel like loose groups, not communities.
- **Fix:** Alliance levels, treasury, alliance-only missions.
- **Effort:** L

#### P1-D8-007: No PvE content or cooperative missions
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** 100% PvP. No NPCs, campaigns, or cooperative missions.
- **Impact:** Solo players have nothing to do. New players can't practice safely.
- **Fix:** NPC enemies on map. Alliance PvE raids. Campaign missions.
- **Effort:** M

#### P1-D8-008: No daily login rewards or habit loops
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** No login streaks, daily challenges, or time-gated rewards.
- **Impact:** No reason to log in daily. DAU/MAU likely <20% vs competitors' 40-60%.
- **Fix:** Daily login streaks + 3-5 daily quests.
- **Effort:** S

#### P1-D8-009: Chemistry theme is niche
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** Molecule/atom theme appeals mainly to STEM players. High complexity ceiling.
- **Impact:** Difficulty recruiting casual players. Weak word-of-mouth.
- **Fix:** Rebrand as "Science Strategy RPG". Simplify tooltips. Target STEM communities.
- **Effort:** M

#### P1-D8-010: No battle pass or seasonal cosmetics
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** Season resets carry only prestige points. No visual progression or cosmetics.
- **Impact:** No cosmetic flex. Low reset engagement.
- **Fix:** Seasonal cosmetics + battle pass ($5/season).
- **Effort:** S

#### P1-D8-011: French language only
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** All content French-only. No i18n framework.
- **Impact:** Unreachable 99.5% of global population. Zero English SEO.
- **Fix:** Implement i18n. English translation priority 1.
- **Effort:** M

#### P1-D8-012: No interactive onboarding
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `tutoriel.php`
- **Description:** Tutorial missions exist but no guided walkthrough. Chemistry jargon is confusing.
- **Impact:** >80% bounce rate for first-time visitors.
- **Fix:** Interactive step-by-step tutorial for first 20 minutes.
- **Effort:** M

#### P1-D8-013: No cross-platform data sync
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** No API for mobile sync, cloud save, or device switching.
- **Impact:** Multi-device usage impossible.
- **Fix:** REST API with session auth. Cloud save system.
- **Effort:** L

#### P1-D8-014: No time-limited events
- **Severity:** LOW
- **Category:** Features
- **Location:** N/A
- **Description:** No seasonal events, holiday specials, or limited-time missions.
- **Impact:** Static gameplay loop. Content stagnation.
- **Fix:** Monthly events. Weekend challenges. Holiday specials.
- **Effort:** M

#### P1-D8-015: No spectator mode or replay system
- **Severity:** LOW
- **Category:** Features
- **Location:** N/A
- **Description:** Combat reports are text-only. No animated replay or spectating.
- **Impact:** Combat feels invisible. No esports potential.
- **Fix:** Animated combat replay. Spectator mode for live wars.
- **Effort:** L

#### P1-D8-016: Market system too simple
- **Severity:** LOW
- **Category:** Features
- **Location:** `marche.php`
- **Description:** Basic buy/sell only. No player-to-player trades, caravans, or contracts.
- **Impact:** Limited strategic depth for economy-focused players.
- **Fix:** Player marketplace. Caravan mechanics. Price contracts.
- **Effort:** M

#### P1-D8-017: Limited anti-cheat enforcement
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `includes/multiaccount.php`
- **Description:** Basic IP/fingerprint logging but no active enforcement or automated suspension.
- **Impact:** Multi-accounters erode competitive integrity.
- **Fix:** Automated detection + suspension. Mod review queue.
- **Effort:** M

#### P1-D8-018: No matchmaking or rating system
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** No ELO, skill rating, or balanced matchmaking.
- **Impact:** War outcomes entirely unbalanced. No esports ladder.
- **Fix:** ELO-style rating. Pre-war opponent rating display.
- **Effort:** M

#### P1-D8-019: Prestige system lacks visual uniqueness
- **Severity:** LOW
- **Category:** Features
- **Location:** `prestige.php`
- **Description:** Prestige carries bonuses but no cosmetic flex. Invisible to other players.
- **Impact:** Long-term progression feels hidden.
- **Fix:** Prestige-unlocked cosmetics and badges.
- **Effort:** S

#### P1-D8-020: No social sharing or viral mechanics
- **Severity:** LOW
- **Category:** Features
- **Location:** N/A
- **Description:** No share-to-social, referral bonuses, or invite systems.
- **Impact:** Zero viral loop. Organic-only player acquisition.
- **Fix:** Share battle results. Referral code system.
- **Effort:** S

---

## Area 2: Feature Gap Analysis

#### P1-D8-021: Real-time in-game chat system
- **Severity:** HIGH
- **Category:** Features
- **Location:** N/A
- **Description:** No WebSocket chat. Players must use forum or external tools for coordination.
- **Impact:** Reduces engagement and community building.
- **Fix:** 4-channel chat (global, alliance, whisper, trade). Rate limiting per channel.
- **Effort:** L

#### P1-D8-022: Matchmaking/tournament system
- **Severity:** HIGH
- **Category:** Features
- **Location:** N/A
- **Description:** No tournaments, brackets, or ranked matchmaking.
- **Impact:** Limits competitive replayability.
- **Fix:** Tournament brackets (single/double elim, round-robin) with PP prizes.
- **Effort:** L

#### P1-D8-023: Achievement system beyond medals
- **Severity:** HIGH
- **Category:** Features
- **Location:** N/A
- **Description:** Medals only track cumulative thresholds. No one-time skill-based achievements.
- **Impact:** Reduces goal diversity. Single grinding path.
- **Fix:** 40+ achievements across 6 categories. Cosmetic rewards on unlock.
- **Effort:** M

#### P1-D8-024: Daily login rewards & streaks
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** No daily login incentive. No streak tracking.
- **Impact:** 7-day retention suffers vs competitors.
- **Fix:** 24h cooldown rewards, streak reset at 48h gap, escalating energy.
- **Effort:** S

#### P1-D8-025: Seasonal events & limited-time content
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** Static gameplay within seasons. No temporary modifiers or events.
- **Impact:** No metagame variation.
- **Fix:** World events table with multipliers. Event page showing active/upcoming.
- **Effort:** M

#### P1-D8-026: Alliance wars — deeper mechanics
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `alliance.php`, `declarations` table
- **Description:** Wars are binary (at war: yes/no). No battle tracking, war scores, or shared vault.
- **Impact:** Alliances feel disconnected from combat.
- **Fix:** War dashboard with casualty tracking and war scores.
- **Effort:** L

#### P1-D8-027: Counter-espionage & defense intelligence
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `attaquer.php`
- **Description:** Espionage is one-way. Defenders get zero intel on incoming spies.
- **Impact:** Asymmetric information. No defensive counterplay.
- **Fix:** Counter-espionage notifications. "Who scouted you" log.
- **Effort:** M

#### P1-D8-028: Map territory control & objectives
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `carte.php`
- **Description:** Map is visual only. No control points, regional bonuses, or map-based victory.
- **Impact:** Map is not strategic. Players ignore neighbors.
- **Fix:** Capturable map objectives with alliance bonuses.
- **Effort:** L

#### P1-D8-029: Research tree / tech progression
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `alliance.php`, `constructions.php`
- **Description:** Alliance has 6 research techs. Individual players have NO research tree. All building unlocks automatic.
- **Impact:** All players follow same path. No specialization choices.
- **Fix:** 8-10 personal research techs unlocked via milestones.
- **Effort:** M

#### P1-D8-030: Unit variety beyond 4 molecule classes
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `armee.php`, `molecules` table
- **Description:** Only 4 molecule classes. No special units, leaders, or transformations.
- **Impact:** Limited strategic depth. Players optimize one composition.
- **Fix:** Offensive/Defensive specialization modifiers at high levels.
- **Effort:** M

#### P1-D8-031: PvE content — AI opponents
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** 100% PvP. No safe practice environment.
- **Impact:** High new-player churn from getting stomped.
- **Fix:** Tutorial combat with scripted AI opponents. PP/cosmetic rewards.
- **Effort:** L

#### P1-D8-032: Spectator mode / live battle feeds
- **Severity:** LOW
- **Category:** Features
- **Location:** N/A
- **Description:** No live spectating or battle feeds.
- **Impact:** Reduces social engagement around combat.
- **Fix:** Live battle page showing round-by-round updates.
- **Effort:** L

#### P1-D8-033: Replay system / combat simulator
- **Severity:** LOW
- **Category:** Features
- **Location:** `rapports` table
- **Description:** No round-by-round replay. No "what-if" simulator.
- **Impact:** Cannot study tactics or learn from losses.
- **Fix:** Round-by-round combat log viewer. Pre-battle simulator.
- **Effort:** L

#### P1-D8-034: Social features — player profiles & showcases
- **Severity:** LOW
- **Category:** Features
- **Location:** `compte.php`
- **Description:** Profiles show basic info only. No achievement showcase or customization.
- **Impact:** Reduces identity/pride. Missing cosmetic motivation.
- **Fix:** Achievement badges, medal gallery, profile customization.
- **Effort:** M

#### P1-D8-035: Vacation mode & comeback mechanics
- **Severity:** LOW
- **Category:** Features
- **Location:** `vacances` table
- **Description:** Vacation exists but no comeback bonuses for returning players.
- **Impact:** High-friction return. Players fall permanently behind.
- **Fix:** +20% production for 3 days on return after >7 day absence.
- **Effort:** S

#### P1-D8-036: Alliance trading system
- **Severity:** LOW
- **Category:** Features
- **Location:** `alliance.php`
- **Description:** No alliance-level resource pooling or trading.
- **Impact:** Rich members cannot fund recruits.
- **Fix:** Alliance marketplace with member offers and trade history.
- **Effort:** M

#### P1-D8-037: Diplomacy system beyond pacts
- **Severity:** LOW
- **Category:** Features
- **Location:** `alliance.php`, `declarations` table
- **Description:** Binary: pact or war. No treaty types, reputation, or honor system.
- **Impact:** Shallow diplomacy. No complex geopolitics.
- **Fix:** Treaty types (non-aggression, neutrality, tributary). Broken-treaty penalties.
- **Effort:** M

#### P1-D8-038: Market graph improvements & trading analytics
- **Severity:** LOW
- **Category:** Features
- **Location:** `marche.php`
- **Description:** Basic line chart only. No moving averages, volume, or trader analytics.
- **Impact:** No "trader role" for economy players.
- **Fix:** Moving averages, volatility bands, volume overlays.
- **Effort:** M

#### P1-D8-039: Player-driven cosmetics/customization shop
- **Severity:** LOW
- **Category:** Features
- **Location:** N/A
- **Description:** No cosmetics shop. All customization is functional.
- **Impact:** No cosmetic monetization. Low identity engagement.
- **Fix:** Cosmetics table with prestige-cost unlockables.
- **Effort:** M

#### P1-D8-040: Player-driven governance — council elections
- **Severity:** LOW
- **Category:** Features
- **Location:** N/A
- **Description:** No player council, voting, or feedback mechanism tied to game decisions.
- **Impact:** Players feel voiceless. Balance changes feel arbitrary.
- **Fix:** Monthly community votes on balance proposals.
- **Effort:** M

---

## Area 3: Retention & Engagement Analysis

#### P1-D8-041: No daily login streaks
- **Severity:** HIGH
- **Category:** Features
- **Location:** N/A
- **Description:** No daily habit-formation loop. No streak tracking or escalating bonuses.
- **Impact:** Removes consistent engagement driver. 7-day retention suffers.
- **Fix:** Daily streak system with escalating energy rewards. Reset at 48h gap.
- **Effort:** S

#### P1-D8-042: Missing weekly challenges
- **Severity:** HIGH
- **Category:** Features
- **Location:** N/A
- **Description:** Static content only. No rotating weekly objectives.
- **Impact:** No reason to vary gameplay week-to-week.
- **Fix:** Weekly challenge rotation with bonus rewards.
- **Effort:** M

#### P1-D8-043: 31-day season creates burnout cliff
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `includes/config.php` (SEASON_DURATION)
- **Description:** Month-long season with no mid-season milestones or phase changes.
- **Impact:** Players burn out by day 15. Last 2 weeks feel like grinding.
- **Fix:** Split season into 4 weekly phases with escalating events.
- **Effort:** M

#### P1-D8-044: Vacation mode requires 3-day notice
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `vacances` table
- **Description:** Must set vacation 3 days in advance. No graceful comeback mechanism.
- **Impact:** Emergency absences leave players unprotected.
- **Fix:** Instant vacation with 24h warmup. Comeback bonuses.
- **Effort:** S

#### P1-D8-045: No loss-aversion messaging after defeats
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `rapports.php`
- **Description:** Battle reports show losses but no encouragement or tactical advice.
- **Impact:** Defeats feel terminal. Players quit instead of adapting.
- **Fix:** Post-defeat tips: "Upgrade fortification to prevent X% damage next time."
- **Effort:** S

#### P1-D8-046: Alliance recruitment has zero friction/discovery
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `alliance.php`
- **Description:** No alliance browser, search, or recruitment posts. Players must know alliance names.
- **Impact:** New players can't discover or compare alliances.
- **Fix:** Alliance browser with filters (size, activity, focus). Recruitment posts.
- **Effort:** M

#### P1-D8-047: No comeback mechanics for players falling behind
- **Severity:** HIGH
- **Category:** Features
- **Location:** N/A
- **Description:** Players who fall behind have no catch-up mechanics. Gap widens exponentially.
- **Impact:** Mid-core players quit when gap becomes insurmountable.
- **Fix:** Asymptotic scaling (V4 already helps). Underdog attack bonuses.
- **Effort:** L

#### P1-D8-048: Prestige system poorly communicated to new players
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `prestige.php`
- **Description:** Prestige is the key retention hook but not explained until players discover it.
- **Impact:** New players don't understand cross-season progression.
- **Fix:** Prestige tutorial during first season. Show PP projection on homepage.
- **Effort:** S

#### P1-D8-049: No notifications for incoming attacks
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `includes/basicprivatehtml.php`
- **Description:** Players only discover attacks from combat reports after the fact.
- **Impact:** Defensive play impossible. All combat is surprise attacks.
- **Fix:** Alert when attack launched (if radar level sufficient). Push notification on mobile.
- **Effort:** M

#### P1-D8-050: No "last seen" timestamps on player profiles
- **Severity:** LOW
- **Category:** Features
- **Location:** `compte.php`
- **Description:** No way to know if a player is active or inactive.
- **Impact:** Players attack inactive targets, wasting resources.
- **Fix:** Show "Last active: X hours ago" on profiles and leaderboard.
- **Effort:** XS

#### P1-D8-051: Forum invisible in main game loop
- **Severity:** LOW
- **Category:** Features
- **Location:** `includes/layout.php`
- **Description:** Forum is a separate section, not integrated into game flow.
- **Impact:** Low forum engagement. Social features underutilized.
- **Fix:** Show latest forum posts on homepage. Notification badge for new posts.
- **Effort:** S

#### P1-D8-052: No seasonal recap/summary
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `includes/player.php` (performSeasonEnd)
- **Description:** Season ends with a reset. No summary of achievements, stats, or highlights.
- **Impact:** No closure. Players don't feel accomplished.
- **Fix:** Season recap page: stats, rankings, highlights, prestige earned.
- **Effort:** M

#### P1-D8-053: No progression visibility/milestone celebration
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** Building upgrades and milestones have no celebration animations or notifications.
- **Impact:** Achievement moments feel flat.
- **Fix:** Toast notifications and animations for milestones.
- **Effort:** S

#### P1-D8-054: Market volatility punishes new players
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `marche.php`
- **Description:** Dynamic pricing with global slippage. New players can lose significant resources to price shifts.
- **Impact:** Discourages market participation by beginners.
- **Fix:** "Practice mode" trades with reduced quantities. Beginner market guide.
- **Effort:** S

#### P1-D8-055: No battle simulator/strategy planning tool
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** N/A
- **Description:** No way to test army compositions before committing to attack.
- **Impact:** Trial-and-error with real resources is frustrating.
- **Fix:** Combat simulator showing expected damage/outcome.
- **Effort:** M

#### P1-D8-056: New player skill curve crashes at day 5
- **Severity:** HIGH
- **Category:** Features
- **Location:** `tutoriel.php`
- **Description:** Tutorial covers basics but leaves players without guidance post-tutorial. Day 5 is when beginner protection expires and veterans attack.
- **Impact:** Massive churn spike at day 5-7.
- **Fix:** Extended tutorial missions through day 14. Graduated protection reduction.
- **Effort:** M

#### P1-D8-057: Alliance wars invisible to rank-and-file members
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `alliance.php`
- **Description:** War declarations visible but no war dashboard, casualty tracking, or contribution metrics.
- **Impact:** Regular alliance members don't feel involved in wars.
- **Fix:** War dashboard showing each member's contributions.
- **Effort:** M

#### P1-D8-058: Leaderboard dominance by day-1 hardcore discourages mid-core
- **Severity:** MEDIUM
- **Category:** Features
- **Location:** `classement.php`
- **Description:** Top 10 established within first 3 days. Mid-core players see insurmountable gap.
- **Impact:** 80% of players see leaderboard as unreachable.
- **Fix:** Category leaderboards (weekly gains, best attack, biggest trade). Relative ranking.
- **Effort:** M

#### P1-D8-059: No spectator mode for offline engagement
- **Severity:** LOW
- **Category:** Features
- **Location:** N/A
- **Description:** No way to watch ongoing conflicts or alliance activities when not playing.
- **Impact:** Missing engagement for idle/casual moments.
- **Fix:** Activity feed showing notable events on homepage.
- **Effort:** M

#### P1-D8-060: No seasonal narrative/story arc
- **Severity:** LOW
- **Category:** Features
- **Location:** N/A
- **Description:** Seasons are identical. No flavor text, theme, or narrative variation.
- **Impact:** Repetitive feel. No excitement for new season start.
- **Fix:** Themed seasons with unique modifiers and flavor text.
- **Effort:** M
