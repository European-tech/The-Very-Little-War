# Ultra Audit Pass 9 — Fix Batch 2

Date: 2026-03-08
Agent: PHP fix agent
Files modified: `.htaccess`, `.github/workflows/ci.yml`
PHP files touched: none (no `php -l` required)

---

## P9-HIGH-002: composer.phar and phpunit.phar in web root

**Status: CONFIRMED ALREADY BLOCKED — no change needed**

The `.htaccess` FilesMatch pattern on line 15 already includes the `phar` extension:

```
<FilesMatch "\.(sql|psd|md|json|xml|lock|gitignore|bak|swp|swo|orig|env|phar|pem|key|crt|example)$|~$|^\.env">
    Require all denied
</FilesMatch>
```

Both `composer.phar` and `phpunit.phar` match this pattern and are denied by Apache before PHP can execute them. No change required.

---

## P9-MED-002: mod_php.c block skipped on PHP-FPM

**Status: FIXED**

**File:** `.htaccess` (lines 36–48 after fix)

**Problem:** The VPS (Debian 12, PHP 8.2) runs PHP-FPM whose Apache module is named `mod_php8.c`, not `mod_php.c`. The existing `<IfModule mod_php.c>` block was silently skipped, meaning `display_errors`, `log_errors`, `error_reporting`, and `expose_php` directives were never applied.

**Fix:** Added a second `<IfModule mod_php8.c>` block with identical directives immediately after the existing block. Both blocks are kept so the file works on both classic `mod_php` and PHP-FPM (`mod_php8`) deployments.

```apache
<IfModule mod_php.c>
    php_flag display_errors off
    php_flag log_errors on
    php_value error_reporting 32767
    php_flag expose_php off
</IfModule>
<IfModule mod_php8.c>
    php_flag display_errors off
    php_flag log_errors on
    php_value error_reporting 32767
    php_flag expose_php off
</IfModule>
```

---

## P9-MED-003: No composer audit in CI pipeline

**Status: FIXED**

**File:** `.github/workflows/ci.yml` (step added between "Install dependencies" and "PHP Syntax Check")

**Problem:** The CI pipeline had no dependency vulnerability scanning. A compromised or vulnerable Composer package could go undetected.

**Fix:** Added a `composer audit` step that runs after `composer install`. This queries the Packagist security advisories API and fails the pipeline if any installed package has a known CVE.

```yaml
      - name: Security audit
        run: composer audit
```

No `working-directory` override needed — the CI job runs from the repo root, matching where `composer install` runs.

---

## Summary

| Finding     | Severity | Status   | File changed              |
|-------------|----------|----------|---------------------------|
| P9-HIGH-002 | HIGH     | CONFIRMED BLOCKED | none               |
| P9-MED-002  | MEDIUM   | FIXED    | `.htaccess`               |
| P9-MED-003  | MEDIUM   | FIXED    | `.github/workflows/ci.yml` |
