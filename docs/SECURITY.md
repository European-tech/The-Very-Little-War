# Security Vulnerabilities

## VULN-1 (CRITICAL): SQL Injection in nearly every PHP file

Raw `$_POST` and `$_GET` values are interpolated directly into SQL queries throughout the entire codebase with no sanitization, escaping, or use of prepared statements. This allows trivial SQL injection attacks on virtually every endpoint.

---

## VULN-2 (CRITICAL): Plaintext passwords stored in localStorage

JavaScript code stores plaintext passwords in the browser's `localStorage`. This exposes credentials to any XSS attack and persists them beyond the session lifetime.

---

## VULN-3 (HIGH): MD5 password hashing (unsalted, cryptographically broken)

Passwords are hashed using unsalted MD5, which is cryptographically broken. Rainbow tables and brute force attacks can reverse MD5 hashes trivially with modern hardware.

---

## VULN-4 (CRITICAL): Hardcoded admin password in source code

The admin password `"Faux mot de passe"` is hardcoded directly in the source code, accessible to anyone who can read the files.

---

## VULN-5 (HIGH): Unauthenticated API endpoint reveals player molecule stats

The API endpoint exposes player molecule statistics without requiring authentication, leaking game state to unauthenticated users.

---

## VULN-6 (HIGH): No CSRF protection on any forms

No forms in the application include CSRF tokens. Any external site can craft requests that perform actions on behalf of an authenticated user.

---

## VULN-7 (HIGH): File upload only checks extension, not MIME type

File upload validation only checks the file extension, not the actual MIME type or file content. Additionally, the original filename is preserved, enabling path traversal or overwrite attacks.

---

## VULN-8 (MEDIUM): XSS vectors in BBCode parser and report content

The BBCode parser and report content output do not properly sanitize user input, allowing cross-site scripting attacks through crafted BBCode or report text.

---

## VULN-9 (CRITICAL): sql.php executes arbitrary SQL

The `sql.php` file executes arbitrary SQL queries and is accessible to any logged-in user. This provides full database read/write access to any authenticated player.

---

## VULN-10 (MEDIUM): SQL error information disclosure

`die()` statements throughout the codebase output raw SQL error messages to the browser, disclosing database structure, table names, and query details to attackers.

---

## VULN-11 (MEDIUM): No rate limiting on login, registration, or transactions

There is no rate limiting on login attempts, account registration, or in-game transactions, enabling brute force attacks, spam account creation, and transaction abuse.

---

## VULN-12 (MEDIUM): Password change without current password verification

Users can change their password without providing their current password, meaning a session hijacker can permanently take over an account.

---

## VULN-13 (MEDIUM): GET-based destructive actions

Message and report deletion is performed via GET requests, making them vulnerable to CSRF via image tags, link prefetching, and browser history exposure.

---

## VULN-14 (HIGH): Account deletion via CSRF

Account deletion requires no confirmation token or password verification, making it trivially exploitable via CSRF. An attacker can delete any logged-in user's account with a single forged request.

---

## VULN-15 (LOW): IP-based multi-account detection trivially bypassed

Multi-account detection relies solely on IP address matching, which is easily bypassed with VPNs, proxies, or mobile networks.

---

## VULN-16 (HIGH): Moderation panel can give unlimited resources

The moderation panel allows granting unlimited resources to any player with minimal logging or audit trail, enabling abuse by compromised or malicious moderators.

---

## VULN-17 (CRITICAL): Database credentials in source (root with no password)

The database connection uses the `root` user with no password, and these credentials are hardcoded in the source code.

---

## VULN-18 (MEDIUM): Hardcoded username check for broadcast messages

The broadcast message feature checks for the hardcoded username `"Guortates"` rather than using a proper role/permission system, making it brittle and easily spoofed if that username is available for registration.
