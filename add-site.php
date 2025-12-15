#!/usr/bin/env php
<?php
/**
 * DownDetector - Add Site Helper
 * 
 * Quick tool to add sites to config.toml with auto-generated settings.
 * 
 * Usage:
 *   php add-site.php <url> [notification_email]
 *   php add-site.php https://example.com
 *   php add-site.php https://example.com admin@example.com
 *   php add-site.php https://example.com admin@example.com --expected-status=301
 *   php add-site.php https://example.com admin@example.com --max-time=5000
 * 
 * Bulk add:
 *   cat urls.txt | while read url; do php add-site.php "$url"; done
 */

define('BASE_DIR', __DIR__);
define('CONFIG_FILE', BASE_DIR . '/config.toml');

/**
 * Display usage information
 */
function showUsage(): void {
    echo "DownDetector - Add Site Helper\n\n";
    echo "Usage:\n";
    echo "  php add-site.php <url> [notification_email] [options]\n\n";
    echo "Arguments:\n";
    echo "  url                    Full URL to monitor (required)\n";
    echo "  notification_email     Email for alerts (optional, uses default)\n\n";
    echo "Options:\n";
    echo "  --expected-status=N    Expected HTTP status code (default: 200)\n";
    echo "  --max-time=N           Max response time in ms (default: 3000)\n";
    echo "  --name=\"Custom Name\"   Custom site name (auto-generated if not set)\n\n";
    echo "Examples:\n";
    echo "  php add-site.php https://example.com\n";
    echo "  php add-site.php https://example.com admin@example.com\n";
    echo "  php add-site.php https://api.example.com/health --expected-status=200\n";
    echo "  php add-site.php https://slow-site.com --max-time=10000\n\n";
    echo "Bulk add from file:\n";
    echo "  cat urls.txt | while read url; do php add-site.php \"\$url\"; done\n\n";
}

/**
 * Generate site name from URL
 */
function generateSiteName(string $url): string {
    $parsed = parse_url($url);
    
    if (!isset($parsed['host'])) {
        return 'Unknown Site';
    }
    
    $host = $parsed['host'];
    
    // Remove www. prefix
    $host = preg_replace('/^www\./', '', $host);
    
    // Convert to title case and replace dots/dashes with spaces
    $name = str_replace(['.', '-', '_'], ' ', $host);
    $name = ucwords($name);
    
    // Add path if present and meaningful
    if (isset($parsed['path']) && $parsed['path'] !== '/' && $parsed['path'] !== '') {
        $pathPart = trim($parsed['path'], '/');
        $pathPart = str_replace(['/', '-', '_'], ' ', $pathPart);
        $pathPart = ucwords($pathPart);
        $name .= ' ' . $pathPart;
    }
    
    return $name;
}

/**
 * Get default notification email from existing config
 */
function getDefaultEmail(): string {
    if (!file_exists(CONFIG_FILE)) {
        return 'admin@localhost';
    }
    
    $content = file_get_contents(CONFIG_FILE);
    
    // Try to find an existing notification_email
    if (preg_match('/notification_email\s*=\s*"([^"]+)"/', $content, $matches)) {
        return $matches[1];
    }
    
    return 'admin@localhost';
}

/**
 * Check if URL is already in config
 */
function isUrlInConfig(string $url): bool {
    if (!file_exists(CONFIG_FILE)) {
        return false;
    }
    
    $content = file_get_contents(CONFIG_FILE);
    $escapedUrl = preg_quote($url, '/');
    
    return preg_match('/url\s*=\s*"' . $escapedUrl . '"/', $content) === 1;
}

/**
 * Validate URL
 */
function validateUrl(string $url): bool {
    $parsed = parse_url($url);
    
    if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
        return false;
    }
    
    if (!in_array($parsed['scheme'], ['http', 'https'])) {
        return false;
    }
    
    return true;
}

/**
 * Add site to config
 */
function addSiteToConfig(array $siteData): bool {
    // Check if config exists
    if (!file_exists(CONFIG_FILE)) {
        echo "❌ Config file not found: " . CONFIG_FILE . "\n";
        echo "Please create config.toml first (copy from config.toml.example)\n";
        return false;
    }
    
    // Read current config
    $content = file_get_contents(CONFIG_FILE);
    
    // Build site entry
    $entry = "\n[[sites]]\n";
    $entry .= "name = \"" . $siteData['name'] . "\"\n";
    $entry .= "url = \"" . $siteData['url'] . "\"\n";
    $entry .= "expected_status = " . $siteData['expected_status'] . "\n";
    $entry .= "expected_max_response_time = " . $siteData['expected_max_response_time'] . "\n";
    $entry .= "notification_email = \"" . $siteData['notification_email'] . "\"\n";
    
    // Append to file
    if (file_put_contents(CONFIG_FILE, $content . $entry, LOCK_EX) === false) {
        echo "❌ Failed to write to config file\n";
        return false;
    }
    
    return true;
}

/**
 * Main execution
 */
function main(array $argv): int {
    // Parse arguments
    $url = null;
    $notificationEmail = null;
    $expectedStatus = 200;
    $maxResponseTime = 3000;
    $customName = null;
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--help' || $arg === '-h') {
            showUsage();
            return 0;
        } elseif (strpos($arg, '--expected-status=') === 0) {
            $expectedStatus = (int)substr($arg, 18);
        } elseif (strpos($arg, '--max-time=') === 0) {
            $maxResponseTime = (int)substr($arg, 11);
        } elseif (strpos($arg, '--name=') === 0) {
            $customName = trim(substr($arg, 7), '"\'');
        } elseif ($url === null && strpos($arg, '--') !== 0) {
            $url = $arg;
        } elseif ($notificationEmail === null && strpos($arg, '--') !== 0 && filter_var($arg, FILTER_VALIDATE_EMAIL)) {
            $notificationEmail = $arg;
        }
    }
    
    // Validate URL
    if ($url === null) {
        echo "❌ Error: URL is required\n\n";
        showUsage();
        return 1;
    }
    
    if (!validateUrl($url)) {
        echo "❌ Error: Invalid URL format\n";
        echo "URL must start with http:// or https:// and have a valid domain\n";
        return 1;
    }
    
    // Check if URL already exists
    if (isUrlInConfig($url)) {
        echo "⚠️  Warning: URL already exists in config: {$url}\n";
        echo "Skipping...\n";
        return 0;
    }
    
    // Generate or use provided name
    $siteName = $customName ?? generateSiteName($url);
    
    // Get notification email
    if ($notificationEmail === null) {
        $notificationEmail = getDefaultEmail();
    }
    
    // Prepare site data
    $siteData = [
        'name' => $siteName,
        'url' => $url,
        'expected_status' => $expectedStatus,
        'expected_max_response_time' => $maxResponseTime,
        'notification_email' => $notificationEmail
    ];
    
    // Display what will be added
    echo "Adding site to config:\n";
    echo "  Name: {$siteName}\n";
    echo "  URL: {$url}\n";
    echo "  Expected Status: {$expectedStatus}\n";
    echo "  Max Response Time: {$maxResponseTime} ms\n";
    echo "  Notification Email: {$notificationEmail}\n";
    echo "\n";
    
    // Add to config
    if (addSiteToConfig($siteData)) {
        echo "✅ Site added successfully!\n";
        echo "\nNext steps:\n";
        echo "  1. Review config: nano config.toml\n";
        echo "  2. Test check: php check.php\n";
        echo "  3. View status: php status.php\n";
        return 0;
    } else {
        return 1;
    }
}

// Execute
exit(main($argv));
