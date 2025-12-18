# Check4Fail Lite

A lightweight, PHP-based website monitoring and anomaly detection system that runs via cron to check site availability, performance, and detect issues.

## Features

- ✅ **Parallel Site Checking** - Check multiple sites simultaneously using curl_multi
- ✅ **TOML Configuration** - Easy-to-read configuration format
- ✅ **Historical Analysis** - Compares current metrics against 7-day historical data
- ✅ **Anomaly Detection** - Detects response time spikes, size changes, status code errors
- ✅ **Email Notifications** - Multipart text/HTML emails compatible with Thunderbird & Outlook
- ✅ **Performance Reports** - Generate detailed reports for customers (CLI or email)
- ✅ **Professional Status Page** - Generate beautiful static HTML status pages with charts
- ✅ **Race Condition Prevention** - File-based locking prevents overlapping runs
- ✅ **Override IP Support** - Test standby clusters directly by bypassing DNS
- ✅ **Data Compression** - Automatically compresses old statistics to save space
- ✅ **Timeout Protection** - 30-second per-site timeout prevents hung checks

## Requirements

- PHP 8.0+ (or PHP 7.4+)
- PHP cURL extension
- PHP JSON extension
- Cron access
- Mail server (postfix/sendmail) configured for PHP mail()

## Installation

### 1. Clone or Copy Files

```bash
cd /path/to/check4fail
```

### 2. Configure Sites to Monitor

```bash
cp config.toml.example config.toml
nano config.toml
```

Or use the quick add helper to add sites:

```bash
# Add single site with auto-generated name
php add-site.php https://example.com

# Add with custom email
php add-site.php https://example.com admin@example.com

# Add with custom settings
php add-site.php https://api.example.com/health admin@example.com --max-time=5000

# Bulk add from file
cat urls.txt | while read url; do php add-site.php "$url"; done
```

Edit the configuration file to add your sites:

```toml
[settings]
retention_days = 7
compression_threshold_days = 1
timeout_per_site = 30
max_execution_time = 300

[anomaly_thresholds]
response_time_multiplier = 3.0
response_size_difference = 0.5
alert_on_status_change = true
error_keywords = ["error", "exception", "fatal", "database error", "500 Internal Server"]

[[sites]]
name = "Production Website"
url = "https://example.com"
expected_status = 200
expected_max_response_time = 2000
notification_email = "admin@example.com"

[[sites]]
name = "API Endpoint"
url = "https://api.example.com/health"
expected_status = 200
expected_max_response_time = 1000
# Multiple notification emails (array format)
notification_email = ["devops@example.com", "oncall@example.com"]
check_content_contains = "\"status\":\"ok\""
basic_auth_user = "api_monitor"
basic_auth_pass = "secret123"

[[sites]]
name = "Standby Cluster"
url = "https://standby.example.com"
expected_status = 200
expected_max_response_time = 3000
notification_email = "admin@example.com"
override_ip = "10.0.0.50"  # Direct IP check
```

### 3. Make Script Executable

```bash
chmod +x check.php
```

### 4. Test Manual Run

```bash
php check.php
```

Or with custom config:

```bash
php check.php --config=/path/to/custom/config.toml
```

### 5. Set Up Cron Job

Add to crontab (`crontab -e`):

```cron
# Run Check4Fail every minute
* * * * * cd /path/to/check4fail && php check.php >> var/log/cron.log 2>&1

# Or every 5 minutes:
*/5 * * * * cd /path/to/check4fail && php check.php >> var/log/cron.log 2>&1
```

### 6. Generate Reports (Optional)

View available sites:
```bash
php report.php --list
```

Generate CLI report:
```bash
php report.php <site_name>
php report.php ophirum_de
php report.php ophirum_de --days=30  # 30-day report
```

Send report via email:
```bash
php report.php <site_name> --email
```

### 7. Generate Status Page (Optional)

Create a professional public status page:
```bash
php generate-status-page.php
php generate-status-page.php --title="Production Status" --org="Your Company"
php generate-status-page.php --output=/var/www/status --days=30
```

The status page includes:
- Real-time uptime percentages with color indicators
- Interactive charts (Chart.js) showing uptime and response time trends
- Expandable detailed statistics tables
- Auto-refresh every 5 minutes
- Mobile responsive design

Serve via web server (see `nginx.conf.example` or `apache.conf.example`).

### 8. Repair Statistics (Optional)

Remove false positive failures caused by monitoring bugs or network issues:
```bash
# List all monitored sites
php repair.php --list-sites

# Show recent errors for a site
php repair.php --site="example.com" --list-errors

# Remove the most recent failed check
php repair.php --site="example.com" --silence-last-error

# Remove the last 5 failed checks
php repair.php --site="example.com" --silence-last-error --count=5

# Remove a specific check by exact date and time
php repair.php --site="example.com" --remove-date="2025-12-16" --remove-time="14:30:00"
```

**Note:** The repair tool physically removes failed checks from statistics. This is useful when:
- Monitoring bugs cause false positives
- Network issues unrelated to the destination site
- Testing/debugging caused erroneous failures
- You need to clean up statistics for accurate reporting

The tool can be called consecutively to remove errors one at a time.

```cron
# Run Check4Fail every minute
* * * * * cd /path/to/check4fail && php check.php >> var/log/cron.log 2>&1

# Or every 5 minutes:
*/5 * * * * cd /path/to/check4fail && php check.php >> var/log/cron.log 2>&1
```

## Directory Structure

```
check4fail-lite/
├── check.php                  # Main cron script
├── config.toml               # Your configuration (create from .example)
├── config.toml.example       # Example configuration
├── src/
│   ├── AnomalyDetector.php   # Anomaly detection logic
│   ├── ConfigParser.php      # TOML configuration parser
│   ├── EmailNotifier.php     # Email notification system
│   ├── Lock.php              # File-based locking mechanism
│   ├── SiteChecker.php       # cURL-based site checking
│   └── StatisticsStorage.php # JSON storage and compression
├── data/                     # Statistics storage (auto-created)
│   └── {site_name}/
│       ├── 2025-12-15.json   # Daily metrics
│       └── 2025-12-08.json.gz # Compressed old data
├── var/
│   ├── lock/                 # Lock files (auto-created)
│   └── log/                  # Log files (auto-created)
└── README.md
```

## Configuration Options

### Settings Section

| Option | Default | Description |
|--------|---------|-------------|
| `retention_days` | 7 | Days to keep historical data |
| `compression_threshold_days` | 1 | Compress files older than N days |
| `timeout_per_site` | 30 | Max seconds per site check |
| `max_execution_time` | 300 | Max total execution time |

### Anomaly Thresholds

| Option | Default | Description |
|--------|---------|-------------|
| `response_time_multiplier` | 3.0 | Alert if N times slower than average |
| `response_size_difference` | 0.5 | Alert if size differs by N% |
| `alert_on_status_change` | true | Alert on HTTP status code changes |
| `error_keywords` | array | Keywords that trigger alerts |

### Site Configuration

| Field | Required | Description |
|-------|----------|-------------|
| `name` | Yes | Friendly name for the site |
| `url` | Yes | Full URL to check |
| `expected_status` | No | Expected HTTP status (default: 200) |
| `expected_max_response_time` | No | Max allowed response time (ms) |
| `notification_email` | Yes | Email address (string) or multiple addresses (array) for alerts |
| `override_ip` | No | Bypass DNS, use specific IP |
| `check_content_contains` | No | Verify response contains text |
| `basic_auth_user` | No | HTTP Basic Auth username |
| `basic_auth_pass` | No | HTTP Basic Auth password |

## Anomaly Detection

Check4Fail automatically detects:

1. **Site Down** - Failed requests, timeouts
2. **HTTP Status Changes** - Unexpected status codes (500, 503, etc.)
3. **Slow Response Times** - 3x slower than historical average
4. **Response Time Limits** - Exceeds configured max time
5. **Size Anomalies** - Content size differs by >50%
6. **Content Validation** - Expected content missing

## Email Notifications

Emails are sent as multipart text/HTML with:
- ✅ Inline CSS for compatibility
- ✅ No external resources
- ✅ Severity-based color coding
- ✅ Current metrics table
- ✅ Historical comparison
- ✅ Detailed anomaly descriptions

Compatible with:
- Thunderbird
- Microsoft Outlook
- Gmail
- Most email clients

## Lock Mechanism

The system uses file-based locks to prevent race conditions:

- **Automatic Lock Cleanup** - Stale locks (>1 hour) are removed
- **PID Tracking** - Tracks which process holds the lock
- **Graceful Exit** - Locks are always released

If a check is already running, the new instance exits gracefully.

## Data Storage

### Daily JSON Files

```json
[
  {
    "timestamp": 1734278400,
    "datetime": "2025-12-15 14:30:00",
    "url": "https://example.com",
    "site_name": "Production Website",
    "success": true,
    "response_time": 245.67,
    "http_code": 200,
    "size_download": 45678,
    "primary_ip": "93.184.216.34",
    "content_type": "text/html; charset=UTF-8"
  }
]
```

### Automatic Compression

Files older than 1 day are automatically gzipped to save space.

### Data Retention

Files older than 7 days are automatically deleted (configurable).

## Logging

Logs are written to:
- `var/log/check4fail_YYYY-MM-DD.log` - Daily log files
- `var/log/cron.log` - Cron execution log

Log levels:
- **INFO** - Normal operations
- **WARNING** - Anomalies detected, lock conflicts
- **ERROR** - Critical errors, failed notifications

## Troubleshooting

### No emails received

1. Check PHP mail configuration: `php -i | grep mail`
2. Test mail: `echo "test" | mail -s "test" your@email.com`
3. Check mail logs: `tail -f /var/log/mail.log`

### Sites not being checked

1. Verify cron is running: `service cron status`
2. Check cron logs: `grep CRON /var/log/syslog`
3. Run manually with full path: `php /full/path/to/check.php`

### Lock file issues

1. Check lock directory: `ls -la var/lock/`
2. Remove stale lock: `rm var/lock/check4fail.lock`
3. Check permissions: `chmod 755 var/lock`

### High memory usage

1. Reduce number of sites checked
2. Increase compression threshold
3. Reduce retention days
4. Check for large response bodies

## Extending Check4Fail

### Future Enhancements

The codebase is designed for easy extension:

- **Screenshot Support** - Add wkhtmltoimage integration
- **LLM Integration** - Use AI for content analysis (see `AnomalyDetector::analyzeTrends()`)
- **Database Storage** - Replace JSON with SQLite/MySQL
- **Web Dashboard** - Build a monitoring UI
- **Webhook Notifications** - Add Slack, Discord, etc.
- **Response Body Storage** - Store full responses for analysis
- **Keyword Detection** - Scan for error messages in content

## License

This project is provided as-is for monitoring purposes.

## Support

For issues or questions, check:
- Configuration file syntax
- PHP error logs
- Cron logs
- Mail server status

## License

Check4Fail is released under the MIT License. See [LICENSE](LICENSE) file for details.

### Third-Party Dependencies

This project uses Chart.js (MIT License) for status page visualizations. See [THIRD_PARTY_LICENSES.md](THIRD_PARTY_LICENSES.md) for details.

## Author

Created for efficient, lightweight website monitoring without external dependencies.

