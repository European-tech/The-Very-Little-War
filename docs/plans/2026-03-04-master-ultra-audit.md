# TVLW Master Ultra Audit — 5-Pass Multi-Domain Orchestration

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Execute the ultra audit (see `docs/plans/2026-03-04-ultra-audit.md`) 5 times with progressive depth, consolidate all findings into a deduplicated master list, verify completeness, produce a prioritized remediation roadmap, and execute ALL fixes autonomously until CI is green.

**Architecture:** Master Orchestrator → 5 sequential passes → each pass runs 9 domains in parallel → findings consolidated after each pass → verification agents validate consolidation → master remediation plan → autonomous implementation with parallel subagents.

**Mode:** FULLY AUTONOMOUS — no user interaction required.

**References:** This plan extends `docs/plans/2026-03-04-ultra-audit.md` (Domains 1-8 definitions). Domain 9 (UI) is defined below.

---

## Master Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                     MASTER ORCHESTRATOR                             │
│                    (Main Session — YOU)                              │
└────────────────────────────┬────────────────────────────────────────┘
                             │
     ┌───────────┬───────────┼───────────┬───────────┐
     ▼           ▼           ▼           ▼           ▼
 ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐
 │ PASS 1 │ │ PASS 2 │ │ PASS 3 │ │ PASS 4 │ │ PASS 5 │
 │ Broad  │ │ Deep   │ │ Cross  │ │ Edge   │ │ Verify │
 │ Scan   │ │ Dive   │ │ Domain │ │ Cases  │ │ & Fill │
 └───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘
     │           │           │           │           │
  9 domains   9 domains   9 domains   9 domains   9 domains
  parallel    parallel    parallel    parallel    parallel
     │           │           │           │           │
     ▼           ▼           ▼           ▼           ▼
 ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐
 │Consoli-│ │Merge + │ │Merge + │ │Merge + │ │Final   │
 │date    │ │Dedup   │ │Dedup   │ │Dedup   │ │Verify  │
 └───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘
     │           │           │           │           │
     └───────────┴───────────┴───────────┴───────────┘
                             │
                    ┌────────▼────────┐
                    │  CONSOLIDATED   │
                    │  MASTER LIST    │
                    │ (deduplicated)  │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │  VERIFICATION   │
                    │  SUBAGENTS (3)  │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │  REMEDIATION    │
                    │  ROADMAP        │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │  AUTONOMOUS     │
                    │  IMPLEMENTATION │
                    │  (parallel)     │
                    └────────┬────────┘
                             │
                    ┌────────▼────────┐
                    │   GREEN CI      │
                    │   ✓ DONE        │
                    └─────────────────┘
```

---

## DOMAIN 9: UI Visual Design & Asset Audit (NEW)

**Coordinator Agent:** `ui-ux-pro-max:ui-ux-pro-max`
**Goal:** Improve visual design while preserving nostalgia feel. Fix missing images, enhance CSS, modernize UI components subtly. Use NanoBanana 2 (Gemini) for image generation where needed.

### NanoBanana 2 MCP Configuration
- **Server:** https://github.com/zhongweili/nanobanana-mcp-server
- **API Key:** AIzaSyAp39EnoCEAhb7S36DotW_4lwHnFCppgkM
- **Use for:** Generating missing game assets, icons, sprites, backgrounds

### Subagent 9.1: Visual Audit & Missing Assets
**Agent:** `voltagent-core-dev:ui-designer`
**Scope:** images/ directory, all PHP files referencing images
**Focus:**
- [ ] Inventory ALL image references in PHP/CSS/JS
- [ ] Cross-reference with actual files in images/ directory
- [ ] Identify missing images (broken `<img>` tags)
- [ ] Identify placeholder or low-quality images
- [ ] Atom type icons: do all 8 exist? Quality consistent?
- [ ] Building icons: do all 9 exist? Visual hierarchy clear?
- [ ] Medal tier images: all 8 tiers × 10 categories?
- [ ] Map sprites: complete set? Consistent style?
- [ ] UI icons: buttons, navigation, status indicators
- [ ] Favicon quality (mentioned in meta.php)

### Subagent 9.2: CSS Enhancement & Theme Consistency
**Agent:** `ui-ux-pro-max:ui-ux-pro-max`
**Scope:** CSS files, includes/style.php, includes/layout.php
**Focus:**
- [ ] Color palette audit: extract all colors, check consistency
- [ ] Framework7 Material theme: customize vs default?
- [ ] Dark mode potential (chemistry/lab aesthetic)
- [ ] Typography: font stack, sizes, weights, line heights
- [ ] Spacing system: consistent margins/padding?
- [ ] Card components: visual quality, shadows, borders
- [ ] Table styling: readability, zebra striping, hover states
- [ ] Form styling: inputs, buttons, selectors
- [ ] Status indicators: online/offline, attack/defense, resources
- [ ] Animation: subtle transitions for state changes
- [ ] Nostalgia elements to preserve: identify and protect

### Subagent 9.3: Page-by-Page UI Polish
**Agent:** `frontend-design:frontend-design`
**Scope:** All 45+ page PHP files
**Focus:**
- [ ] Homepage (index.php): hero section, CTA, news layout
- [ ] Army page (armee.php): molecule designer, stat display
- [ ] Combat pages (attaquer.php, rapports.php): battle UI, reports
- [ ] Market (marche.php): trading interface, charts
- [ ] Buildings (constructions.php): upgrade cards, progress bars
- [ ] Rankings (classement.php): leaderboard design
- [ ] Alliance (alliance.php): member list, research display
- [ ] Lab (laboratoire.php): compound synthesis UI
- [ ] Profile (compte.php): account settings, avatar
- [ ] Forum: thread list, post layout, BBCode rendering
- [ ] Prestige (prestige.php): PP shop, unlock display
- [ ] Bilan page: bonus breakdown layout
- [ ] Map view: tile rendering, player markers, resource nodes
- [ ] Navigation: sidebar, bottom tabs, breadcrumbs
- [ ] Loading states: spinners, skeleton screens

### Subagent 9.4: Image Generation with NanoBanana 2
**Agent:** `voltagent-core-dev:ui-designer`
**Scope:** Missing or low-quality images identified by Subagent 9.1
**Focus:**
- [ ] Generate missing atom type icons (chemistry-themed, consistent style)
- [ ] Generate missing building icons (lab/chemistry aesthetic)
- [ ] Generate missing medal tier images
- [ ] Generate background textures/patterns (subtle, chemistry-themed)
- [ ] Generate UI decoration elements (borders, dividers, icons)
- [ ] All generated images must match the nostalgic/retro-web-game feel
- [ ] Output format: PNG, appropriate sizes, optimized for web
- [ ] Save to images/ directory with descriptive names

### Subagent 9.5: Responsive Design Fixes
**Agent:** `voltagent-core-dev:frontend-developer`
**Scope:** All PHP pages, CSS
**Focus:**
- [ ] Mobile-first layout fixes (Framework7 is mobile-first)
- [ ] Breakpoint consistency across pages
- [ ] Touch-friendly interactive elements
- [ ] Table overflow handling on small screens
- [ ] Map view mobile adaptation
- [ ] Form layout on mobile (registration, login, settings)
- [ ] Navigation: hamburger menu, bottom tabs behavior
- [ ] Modal/popover behavior on mobile

---

## 5-Pass Execution Strategy

### Pass 1: Broad Scan (Surface-Level Discovery)
**Focus:** Quick sweep of every file, identify obvious issues
**Depth:** Read file headers, function signatures, obvious patterns
**Agent instruction prefix:** "Perform a broad, surface-level scan. Identify obvious issues, missing patterns, and red flags. Don't deep-dive into complex logic."
**Expected findings:** 100-150 (many LOW/MEDIUM)

### Pass 2: Deep Dive (Line-by-Line Analysis)
**Focus:** Thorough analysis of high-risk files
**Depth:** Read every line, trace data flows, analyze formula math
**Agent instruction prefix:** "Perform a deep, line-by-line analysis. Trace every data flow from input to output. Verify every formula mathematically. Look for subtle bugs."
**Priority files:** combat.php, player.php, config.php, game_actions.php, formulas.php
**Expected new findings:** 50-80 (more HIGH/CRITICAL)

### Pass 3: Cross-Domain Analysis
**Focus:** Interactions between systems, emergent behaviors
**Depth:** How does system A affect system B? What happens when X and Y occur simultaneously?
**Agent instruction prefix:** "Focus on cross-system interactions. How do combat, market, alliance, and prestige systems interact? What emergent behaviors exist? What exploit chains are possible?"
**Expected new findings:** 30-50 (cross-domain patterns)

### Pass 4: Edge Cases & Adversarial Thinking
**Focus:** What would a malicious or creative player do?
**Depth:** Think like a player trying to exploit, grief, or break the game
**Agent instruction prefix:** "Think adversarially. You are a skilled player trying to exploit every mechanic, find every loophole, and break every formula. What would you do?"
**Expected new findings:** 20-40 (exploit vectors, edge cases)

### Pass 5: Verification & Gap-Fill
**Focus:** Verify all previous findings, fill gaps, validate fixes
**Depth:** Re-read every finding, verify it's real, check nothing was missed
**Agent instruction prefix:** "Verify every finding from passes 1-4. Are they real? Are the proposed fixes correct? What was missed? Fill any coverage gaps."
**Expected new findings:** 10-20 (missed items, false positive removal)

---

## Consolidated Findings Management

### Finding ID Format
```
P{pass}-D{domain}-{NNN}
Example: P1-D1-001 = Pass 1, Domain 1 (Security), Finding #1
```

### Consolidation Process (After Each Pass)

1. **Collect:** Gather all domain findings from the pass
2. **Normalize:** Ensure every finding follows the standard format
3. **Deduplicate:** Compare against existing consolidated list
   - Same file + same line + same issue = DUPLICATE → skip
   - Same issue, different location = VARIANT → merge as sub-items
   - Related but different issues = CROSS-REF → link with IDs
4. **Merge:** Add unique findings to `docs/audit/consolidated-findings.md`
5. **Stats:** Update finding counts by severity, domain, pass

### Consolidated File Structure
```markdown
# TVLW Ultra Audit — Consolidated Findings

## Statistics
| Metric | Pass 1 | Pass 2 | Pass 3 | Pass 4 | Pass 5 | Total |
|--------|--------|--------|--------|--------|--------|-------|
| CRITICAL | | | | | | |
| HIGH | | | | | | |
| MEDIUM | | | | | | |
| LOW | | | | | | |
| Total | | | | | | |

## Findings by Domain

### Domain 1: Security
#### P1-D1-001: [Title]
...

### Domain 2: Code Quality
...
(through Domain 9)
```

---

## Verification Process (Post-Pass 5)

### Verification Subagent A: Completeness Check
**Agent:** `comprehensive-review:code-reviewer`
**Task:**
1. Read `docs/audit/consolidated-findings.md`
2. Read every PHP file in the project
3. Verify that EVERY file was analyzed
4. Verify that EVERY function was considered
5. Report any files/functions with zero findings (suspicious)
6. Report confidence level per domain

### Verification Subagent B: Duplicate Check
**Agent:** `voltagent-qa-sec:code-reviewer`
**Task:**
1. Read `docs/audit/consolidated-findings.md`
2. Compare every finding against every other finding
3. Report any duplicates or near-duplicates
4. Report any findings that should be merged
5. Report any findings that should be split
6. Verify cross-references are bidirectional

### Verification Subagent C: Fix Validation
**Agent:** `voltagent-qa-sec:architect-reviewer`
**Task:**
1. Read `docs/audit/consolidated-findings.md`
2. For each finding with a proposed fix:
   - Is the fix technically correct?
   - Does the fix introduce new issues?
   - Is the effort estimate accurate?
   - Are dependencies correctly identified?
3. Report any fixes that need revision

---

## Remediation Execution Plan

### Phase A: Pre-Implementation
1. **Create audit directory:** `docs/audit/`
2. **Run all 5 passes** (see execution tasks below)
3. **Consolidate and verify** findings
4. **Produce remediation roadmap**

### Phase B: Implementation (Fully Autonomous)
1. **Sort findings** by priority score: `Severity × (1 + cross_domain_count) / Effort`
2. **Group into batches** of 5-10 related fixes
3. **For each batch:**
   a. Create implementation subagent (parallel where independent)
   b. Subagent implements fixes
   c. Run tests after each fix
   d. Commit after each successful fix
4. **After all batches:** Full test suite run
5. **CI validation:** Ensure `.github/workflows/ci.yml` passes

### Phase C: Post-Implementation
1. **Final verification pass:** Re-scan for regressions
2. **Update documentation** affected by fixes
3. **Update test suite** to cover new fixes
4. **Final CI run** — must be GREEN

---

## Execution Tasks

### Task 1: Create audit directory structure

**Step 1: Create directory**

```bash
mkdir -p /home/guortates/TVLW/The-Very-Little-War/docs/audit
```

**Step 2: Commit**

```bash
git add docs/audit
git commit -m "audit: create audit directory for ultra audit findings"
```

---

### Task 2: Execute Pass 1 — Broad Scan

**Step 1: Launch 9 domain coordinators in parallel**

Each coordinator dispatches its subagents per the ultra audit plan. Pass 1 instruction: "Broad surface scan — identify obvious issues quickly."

Domains to launch simultaneously:
- Domain 1: Security (5 subagents)
- Domain 2: Code Quality (5 subagents)
- Domain 3: Technology (5 subagents)
- Domain 4: Game Balance (5 subagents)
- Domain 5: Game Mechanics (5 subagents)
- Domain 6: UX & Performance (4 subagents)
- Domain 7: Database (3 subagents)
- Domain 8: Features (3 subagents)
- Domain 9: UI Visual Design (5 subagents)

**Step 2: Collect all 9 domain reports**

Merge into `docs/audit/pass-1-findings.md`

**Step 3: Create initial consolidated list**

Copy pass 1 findings to `docs/audit/consolidated-findings.md`

**Step 4: Commit**

```bash
git add docs/audit/pass-1-findings.md docs/audit/consolidated-findings.md
git commit -m "audit: Pass 1 complete — broad scan findings"
```

---

### Task 3: Execute Pass 2 — Deep Dive

**Step 1: Launch 9 domain coordinators in parallel**

Pass 2 instruction: "Deep line-by-line analysis. Trace data flows. Verify formulas mathematically. Focus on high-risk files."

**Step 2: Collect all 9 domain reports into `docs/audit/pass-2-findings.md`**

**Step 3: Merge into consolidated list (deduplicate)**

Read pass-2-findings.md and consolidated-findings.md. For each pass-2 finding:
- If duplicate → skip, note in pass-2 as "DUP of P1-Dx-NNN"
- If new → add to consolidated with P2- prefix

**Step 4: Commit**

```bash
git add docs/audit/pass-2-findings.md docs/audit/consolidated-findings.md
git commit -m "audit: Pass 2 complete — deep dive findings merged"
```

---

### Task 4: Execute Pass 3 — Cross-Domain Analysis

**Step 1: Launch 9 domain coordinators in parallel**

Pass 3 instruction: "Focus on cross-system interactions, emergent behaviors, and exploit chains across domains."

**Step 2: Collect into `docs/audit/pass-3-findings.md`**

**Step 3: Merge + deduplicate into consolidated list**

**Step 4: Commit**

```bash
git add docs/audit/pass-3-findings.md docs/audit/consolidated-findings.md
git commit -m "audit: Pass 3 complete — cross-domain analysis merged"
```

---

### Task 5: Execute Pass 4 — Edge Cases & Adversarial

**Step 1: Launch 9 domain coordinators in parallel**

Pass 4 instruction: "Think adversarially. You are a skilled player trying to exploit every mechanic. What would you do?"

**Step 2: Collect into `docs/audit/pass-4-findings.md`**

**Step 3: Merge + deduplicate into consolidated list**

**Step 4: Commit**

```bash
git add docs/audit/pass-4-findings.md docs/audit/consolidated-findings.md
git commit -m "audit: Pass 4 complete — adversarial analysis merged"
```

---

### Task 6: Execute Pass 5 — Verification & Gap-Fill

**Step 1: Launch 9 domain coordinators in parallel**

Pass 5 instruction: "Verify all findings from passes 1-4. Remove false positives. Fill coverage gaps. Validate proposed fixes."

**Step 2: Collect into `docs/audit/pass-5-findings.md`**

**Step 3: Final merge + deduplicate**

**Step 4: Commit**

```bash
git add docs/audit/pass-5-findings.md docs/audit/consolidated-findings.md
git commit -m "audit: Pass 5 complete — verification and gap-fill merged"
```

---

### Task 7: Run Verification Subagents

**Step 1: Launch 3 verification subagents in parallel**

- Verification A: Completeness check
- Verification B: Duplicate check
- Verification C: Fix validation

**Step 2: Apply corrections to consolidated list**

Remove confirmed duplicates. Add missed findings. Fix invalid fix proposals.

**Step 3: Commit**

```bash
git add docs/audit/consolidated-findings.md
git commit -m "audit: Verification complete — consolidated list validated"
```

---

### Task 8: Generate Master Remediation Roadmap

**Step 1: Score all findings**

```
priority_score = severity_weight × (1 + cross_domain_refs) / effort_weight

severity_weight: CRITICAL=4, HIGH=3, MEDIUM=2, LOW=1
effort_weight: XS=0.5, S=1, M=2, L=3, XL=5
```

**Step 2: Group into implementation sprints**

- Sprint ALPHA: CRITICAL findings (security + bugs)
- Sprint BETA: HIGH findings (balance + mechanics + security)
- Sprint GAMMA: UI improvements (Domain 9 fixes)
- Sprint DELTA: MEDIUM code quality + architecture
- Sprint EPSILON: Technology upgrades
- Sprint ZETA: Feature additions
- Sprint ETA: LOW polish items

**Step 3: Write roadmap**

Produce `docs/audit/master-remediation-roadmap.md`

**Step 4: Commit**

```bash
git add docs/audit/master-remediation-roadmap.md
git commit -m "audit: Master remediation roadmap — prioritized sprints"
```

---

### Task 9: Execute Remediation — Sprint ALPHA (Critical)

**Step 1: Identify all CRITICAL findings from consolidated list**

**Step 2: For each fix, dispatch parallel subagent**

Each subagent:
1. Reads the finding and proposed fix
2. Implements the fix
3. Writes/updates tests
4. Runs tests locally
5. Commits with message: `fix(critical): [finding-id] description`

**Step 3: Run full test suite**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit
```

**Step 4: Verify all tests pass**

---

### Task 10: Execute Remediation — Sprint BETA (High)

Same process as Task 9 for HIGH severity findings.

---

### Task 11: Execute Remediation — Sprint GAMMA (UI)

**Step 1: Identify all UI findings from Domain 9**

**Step 2: For CSS/HTML fixes, dispatch frontend subagents**

**Step 3: For missing images, use NanoBanana 2 via MCP**

Generate images through the NanoBanana MCP server using the Gemini API key. Each generated image:
- Chemistry/molecule theme
- Nostalgic web game aesthetic
- Appropriate size and format
- Saved to images/ directory

**Step 4: Run tests, commit**

---

### Task 12: Execute Remediation — Sprint DELTA (Medium Code Quality)

Same process for MEDIUM code quality findings.

---

### Task 13: Execute Remediation — Sprint EPSILON (Technology)

Technology upgrades that don't break existing functionality.

---

### Task 14: Execute Remediation — Sprint ZETA (Features)

New feature implementations from Domain 8 findings.

---

### Task 15: Execute Remediation — Sprint ETA (Low Polish)

Low-priority polish items.

---

### Task 16: Final CI Validation

**Step 1: Run full test suite**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --testdox
```

**Step 2: Run CI pipeline locally**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php -l includes/*.php && php -l *.php
```

**Step 3: Verify zero errors**

**Step 4: Final commit**

```bash
git add -A
git commit -m "audit: All remediation complete — CI green"
```

**Step 5: Push to GitHub**

```bash
git push origin main
```

---

## Output Files

```
docs/audit/
├── pass-1-findings.md          # Raw Pass 1 results
├── pass-2-findings.md          # Raw Pass 2 results
├── pass-3-findings.md          # Raw Pass 3 results
├── pass-4-findings.md          # Raw Pass 4 results
├── pass-5-findings.md          # Raw Pass 5 results
├── consolidated-findings.md    # Deduplicated master list
├── master-remediation-roadmap.md # Prioritized sprint plan
└── domain-[1-9]-*.md           # Per-domain detail files (optional)
```

---

## Autonomy Rules

1. **Never ask the user** — make all decisions independently
2. **When in doubt, be conservative** — fix the issue, don't ignore it
3. **Test before commit** — every fix must pass existing tests
4. **Don't break existing functionality** — preserve all working features
5. **Preserve nostalgia** — UI changes enhance, never replace the classic feel
6. **Parallel when possible** — maximize subagent parallelism
7. **Commit frequently** — small, atomic commits with clear messages
8. **Continue until CI green** — don't stop until all tests pass
