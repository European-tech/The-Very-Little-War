# Deployment Guide - The Very Little War

## Prerequisites

- PHP 7.4+ (8.x recommended)
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite enabled
- Composer (for running tests)

## Server Setup

### 1. Upload Files

Upload the entire project to your web root. On Ionos VPS:

```bash
scp -r The-Very-Little-War/ user@your-server:/var/www/html/
```

### 2. Database Configuration

Edit `includes/connexion.php` with your database credentials:

```php
$base = mysqli_connect('localhost', 'your_db_user', 'your_db_password');
mysqli_select_db($base, 'your_database_name');
```

### 3. Run Database Migrations

Migrations add indexes and fix column types. Run from the project root:

```bash
cd /var/www/html/The-Very-Little-War
php migrations/migrate.php
```

This will:
- Create a `migrations` tracking table
- Add 25 indexes across 14 tables (dramatically improves query performance)
- Fix column types (BIGINT display widths, VARCHAR for indexed columns, etc.)

### 4. Set Admin Password

Edit `includes/constantesBase.php` to set your admin password:

```bash
# Generate a new hash
php -r "echo password_hash('your-secure-password', PASSWORD_DEFAULT);"

# Copy the output and replace the hash in constantesBase.php
```

### 5. Directory Permissions

```bash
# Writable directories
chmod 755 logs/
chmod 755 images/profil/

# Sensitive directories (should not be web-accessible)
chmod 700 migrations/
chmod 700 tests/
chmod 700 vendor/
```

### 6. Apache Configuration

The `.htaccess` file handles most security headers. Ensure these Apache modules are enabled:

```bash
a2enmod rewrite headers
systemctl restart apache2
```

### 7. HTTPS (Recommended)

Once HTTPS is enabled via Let's Encrypt:

1. Edit `includes/basicprivatephp.php` line 8: change `session.cookie_secure` to `1`
2. Add HSTS header to `.htaccess`:
   ```
   Header always set Strict-Transport-Security "max-age=31536000"
   ```

## File Structure

```
The-Very-Little-War/
├── includes/           # Core PHP modules
│   ├── connexion.php   # DB connection (edit credentials here)
│   ├── database.php    # Prepared statement helpers
│   ├── config.php      # All game constants
│   ├── fonctions.php   # Shim that loads all modules
│   ├── formulas.php    # Game formulas (pure math)
│   ├── game_resources.php  # Resource production
│   ├── game_actions.php    # Action processing
│   ├── player.php      # Player management
│   ├── ui_components.php   # UI rendering
│   ├── display.php     # Formatting helpers
│   ├── db_helpers.php  # Legacy DB wrappers
│   ├── combat.php      # Combat resolution
│   ├── csrf.php        # CSRF protection
│   ├── validation.php  # Input validation
│   ├── logger.php      # Event logging
│   └── rate_limiter.php # Rate limiting
├── admin/              # Admin panel (password-protected)
├── moderation/         # Moderator tools
├── migrations/         # Database migrations
├── tests/              # PHPUnit tests
├── docs/               # Documentation
├── images/             # Game images
├── logs/               # Application logs (auto-created)
└── .htaccess           # Security headers + rules
```

## Monitoring

### Logs

Application logs are written to `logs/` with daily rotation:
- `logs/tvlw-YYYY-MM-DD.log`

Categories: AUTH, REGISTER, ATTACK, MARKET, ALLIANCE, ADMIN, ACCOUNT, COMBAT

### Rate Limiting

Rate limit state is stored in `/tmp/tvlw_rates/` (auto-cleaned).
- Login: 10 attempts per 5 minutes per IP
- Registration: 3 attempts per hour per IP

## Game Configuration

All game constants are in `includes/config.php`. Key settings:

| Constant | Default | Description |
|----------|---------|-------------|
| BEGINNER_PROTECTION_SECONDS | 259200 (3 days) | New player attack immunity |
| MAX_CONCURRENT_CONSTRUCTIONS | 2 | Simultaneous building upgrades |
| MAX_MOLECULE_CLASSES | 4 | Molecule army slots |
| MAX_ATOMS_PER_ELEMENT | 200 | Cap per atom type |
| MARKET_PRICE_FLOOR | 0.1 | Minimum market price |
| MARKET_PRICE_CEILING | 10.0 | Maximum market price |
| VICTORY_POINTS_TOTAL | 1000 | Points distributed monthly |

## Running Tests

```bash
composer install
vendor/bin/phpunit
```

## Troubleshooting

### "Access denied" on game pages
Check that `includes/connexion.php` has correct DB credentials.

### Missing indexes warning
Run `php migrations/migrate.php` to apply database migrations.

### Session issues
Clear `/tmp/sess_*` files. Check that `session.save_path` is writable.

### Rate limiting too aggressive
Adjust in `includes/basicpublicphp.php` (login) and `inscription.php` (registration).
Delete files in `/tmp/tvlw_rates/` to reset all limits.
