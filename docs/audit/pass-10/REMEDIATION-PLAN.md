# Pass 10 Remediation Plan

> Date: 2026-03-08 | Based on MASTER-TRACEABILITY-MATRIX.md
> Findings: 1 HIGH · 6 MEDIUM · 4 LOW

---

## Batch 1 — HIGH + MEDIUM fixes (parallel)

### B1-A: admin/supprimercompte.php — Auth gate + CSRF ordering + audit log
**Fixes:** P10-HIGH-001, P10-MED-002, P10-MED-004
- Replace `include("admin/redirectionmotdepasse.php")` with full session guard + ADMIN_LOGIN check
- Move csrfCheck() to top of POST block (before supprimerJoueur())
- Add logInfo('ADMIN', 'Player deleted', ['target' => $loginTarget]) inside deletion loop

### B1-B: moderationForum.php — Rate limit on sanctions
**Fixes:** P10-MED-001
- After moderator check, before INSERT:
  ```php
  rateLimitCheck($_SESSION['login'], 'sanction_create', 20, 3600);
  ```

### B1-C: joueur.php — Profile enumeration rate limit
**Fixes:** P10-MED-003
- At top of file after auth check:
  ```php
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  rateLimitCheck($ip, 'profile_view', 60, 60);
  ```

### B1-D: admin/tableau.php — Remove broken orphaned queries
**Fixes:** P10-MED-005, P10-MED-006
- Find and remove/comment the SELECT * FROM signalement and SELECT * FROM lieux queries
- These reference non-existent tables causing DB errors in admin panel

---

## Batch 2 — LOW fixes (parallel)

### B2-A: editer.php — Move ban check earlier
**Fixes:** P10-LOW-001
- Move the ban check for moderators to before the POST['contenu'] handler

### B2-B: Add nightly cron for expired sanctions
**Fixes:** P10-LOW-002
- Add to scripts/cleanup_old_data.php or existing cron:
  ```sql
  DELETE FROM sanctions WHERE dateFin < CURDATE();
  ```

### B2-C: api.php — Remove unused nbTotalAtomes param
**Fixes:** P10-LOW-003
- Remove or document the nbTotalAtomes parameter from the dispatch table

### B2-D: constructions.php — Building queue uniqueness check
**Fixes:** P10-LOW-004
- Before INSERT INTO actionsconstruction, check if (login, batiment) already has a pending build
  ```php
  $existing = dbFetchOne($base, 'SELECT id FROM actionsconstruction WHERE login = ? AND batiment = ?', 'ss', $login, $batiment);
  if ($existing) { $erreur = "Ce bâtiment est déjà en construction."; }
  ```

---

## Deploy
After all fixes: run tests, commit, push to GitHub, deploy to VPS.
