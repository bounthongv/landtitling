# LandTax Cron Jobs

## `cron_generate_obligations.php`

**Purpose:** Auto-generate fee obligations for active fee years.

### What it does
- Finds active fee years (`fee_years.is_active = TRUE`) where `due_date >= today` OR year >= current year
- For each year, loops all **confirmed** parcels
- Creates obligation via `fee_upsert_obligation()` (skips if already exists)
- Logs assessed count, skipped (already had obligation), total due

### Schedule (recommended)
Daily at 02:00 (after nightly bank import, before officers start):
```
0 2 * * * /usr/bin/php /var/www/html/landtax/cron/cron_generate_obligations.php >> /var/log/landtax/obligations.log 2>&1
```

### Options
| Flag | Description |
|------|-------------|
| `--year=2026` | Process only a specific fee year (default: all active years) |
| `--district=1` | Process only one district's parcels (default: all districts) |
| `--dry-run` | Simulate only, no DB writes |

### Examples
```bash
# Full run (all active years, all districts)
php cron_generate_obligations.php

# Only 2026, district 1 (Kaysone)
php cron_generate_obligations.php --year=2026 --district=1

# Test run (no DB writes)
php cron_generate_obligations.php --dry-run
```

### Output
```
=== Obligation Generation Cron ===
Date: 2026-10-27 02:00:01
Mode: LIVE
District filter: 0

--- Fee Year 2026 (due: 2026-12-31) ---
  Assessed: 142, Skipped (existing): 8, Total due: 1,245,600,000.00 kip

=== SUMMARY ===
Total assessed:  142
Total skipped:   8
Grand total due: 1,245,600,000.00 kip
Completed at: 2026-10-27 02:00:03
```

### Log Rotation
Add to `/etc/logrotate.d/landtax`:
```
/var/log/landtax/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
```

### Deploy
1. Copy `cron/` folder to server: `/var/www/html/landtax/cron/`
2. Ensure PHP CLI is installed: `apt-get install php-cli`
3. Add crontab entry (as `www-data` or root):
   ```
   crontab -e
   # Add: 0 2 * * * /usr/bin/php /var/www/html/landtax/cron/cron_generate_obligations.php >> /var/log/landtax/obligations.log 2>&1
   ```
4. Create log directory: `mkdir -p /var/log/landtax && chown www-data:www-data /var/log/landtax`