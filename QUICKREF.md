# DownDetector Lite - Quick Reference

## 🚀 Common Commands

### Setup & Testing
```bash
./setup.sh              # Initial setup
php test.php            # Run component tests
php check.php           # Manual check run
php status.php          # View current status
php report.php --list   # List all monitored sites
```

### Add Sites Quickly
```bash
# Add single site
php add-site.php https://example.com
php add-site.php https://example.com admin@example.com
php add-site.php https://api.example.com/health --max-time=5000

# Bulk add from file
cat urls.txt | while read url; do php add-site.php "$url"; done
```

### Generate Reports
```bash
# CLI reports
php report.php <site_name>
php report.php ophirum_de
php report.php ophirum_de --days=30

# Email reports
php report.php <site_name> --email
php report.php ophirum_de --email --days=14
```

### Generate Status Page
```bash
# Generate static HTML status page
php generate-status-page.php
php generate-status-page.php --title="Production Status"
php generate-status-page.php --org="ACME Corp" --days=30
php generate-status-page.php --output=/var/www/status

# View in browser
xdg-open public_html/index.html
```

### Configuration
```bash
nano config.toml        # Edit configuration
cat config.toml.example # View example config
```

### Monitoring
```bash
# View logs
tail -f var/log/downdetector_$(date +%Y-%m-%d).log
tail -f var/log/cron.log

# View data
ls -lah data/
cat data/production_website/$(date +%Y-%m-%d).json | jq

# Check statistics
php status.php
```

### Cron Setup
```bash
crontab -e              # Edit crontab
# Add one of:
* * * * * cd /home/sebastian/Codebase/downdetector-lite && php check.php >> var/log/cron.log 2>&1
*/5 * * * * cd /home/sebastian/Codebase/downdetector-lite && php check.php >> var/log/cron.log 2>&1
```

## 📋 Configuration Template

```toml
[[sites]]
name = "My Website"
url = "https://example.com"
expected_status = 200
expected_max_response_time = 2000  # milliseconds
notification_email = "admin@example.com"
override_ip = "192.168.1.100"  # Optional: bypass DNS
check_content_contains = "success"  # Optional: content validation
```

## 🔍 Troubleshooting

### No emails
```bash
# Test mail
echo "test" | mail -s "test" your@email.com

# Check mail logs
tail -f /var/log/mail.log
```

### Lock issues
```bash
# Remove stale lock
rm var/lock/downdetector.lock

# Check lock status
ls -la var/lock/
```

### Permission issues
```bash
chmod +x check.php test.php setup.sh status.php
chmod 755 var/lock var/log data
```

### View detailed errors
```bash
php check.php 2>&1 | tee -a debug.log
```

## 📊 Data Locations

```
data/{site_name}/YYYY-MM-DD.json      # Daily metrics
data/{site_name}/YYYY-MM-DD.json.gz   # Compressed
var/log/downdetector_YYYY-MM-DD.log   # Daily logs
var/log/cron.log                      # Cron output
var/lock/downdetector.lock            # Lock file
```

## ⚙️ Key Settings

### Anomaly Thresholds
- `response_time_multiplier: 3.0` - Alert if 3x slower
- `response_size_difference: 0.5` - Alert if 50% size change
- `alert_on_status_change: true` - Alert on status changes

### Retention
- `retention_days: 7` - Keep data for 7 days
- `compression_threshold_days: 1` - Compress after 1 day
- `timeout_per_site: 30` - 30 second timeout per site

## 🎯 Exit Codes

- `0` - Success
- `1` - Error (config, check failed)
- `2` - Already running (lock held)

## 📧 Email Notification Triggers

1. Site unreachable (connection failed)
2. HTTP status code mismatch (e.g., 500 instead of 200)
3. Response time >3x average
4. Response time exceeds configured max
5. Response size differs by >50%
6. Expected content not found

## 🔧 Extending

### Add screenshot support
Edit `src/SiteChecker.php::checkSite()` - add wkhtmltoimage call

### Add webhook notifications
Edit `check.php` - add webhook call after anomaly detection

### Change storage backend
Replace `src/StatisticsStorage.php` with database implementation

### Add more checks
Edit `src/AnomalyDetector.php::detectAnomalies()` - add custom logic

## 📞 Quick Diagnostics

```bash
# Is PHP working?
php -v

# Are required extensions loaded?
php -m | grep -E "curl|json"

# Is cron running?
service cron status

# Are checks being executed?
grep "DownDetector started" var/log/downdetector_$(date +%Y-%m-%d).log

# What's the latest check result?
php status.php
```

## 🎓 Understanding the Flow

1. **Cron triggers** → `check.php`
2. **Lock acquired** → Prevents overlapping runs
3. **Config loaded** → `config.toml` parsed
4. **Sites checked** → Parallel cURL requests
5. **Metrics stored** → JSON files in `data/`
6. **Anomalies detected** → Compare with history
7. **Emails sent** → If anomalies found
8. **Maintenance** → Compress old files, cleanup
9. **Lock released** → Allow next run

## 📚 Files Overview

| File | Purpose |
|------|---------|
| `check.php` | Main cron script |
| `test.php` | Component tests |
| `status.php` | Status report |
| `setup.sh` | Quick setup |
| `config.toml` | Configuration |
| `src/ConfigParser.php` | TOML parser |
| `src/Lock.php` | Lock mechanism |
| `src/SiteChecker.php` | Site checking |
| `src/StatisticsStorage.php` | Data storage |
| `src/AnomalyDetector.php` | Anomaly detection |
| `src/EmailNotifier.php` | Email system |

---

**Quick Start**: `./setup.sh` → Edit `config.toml` → `crontab -e`
**Help**: `php status.php` or check `README.md`
