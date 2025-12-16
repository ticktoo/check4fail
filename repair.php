#!/usr/bin/env php
<?php
/**
 * Repair Tool - Remove false positive failures from statistics
 * 
 * This tool helps clean up monitoring statistics by removing checks that failed
 * due to monitoring bugs, network issues, or other problems unrelated to the
 * actual destination site.
 * 
 * Usage:
 *   php repair.php --site="Site Name" --silence-last-error
 *   php repair.php --site="Site Name" --silence-last-error --count=3
 *   php repair.php --site="Site Name" --list-errors
 *   php repair.php --site="Site Name" --remove-date="2025-12-16" --remove-time="14:30:00"
 *   php repair.php --list-sites
 */

require_once __DIR__ . '/src/ConfigParser.php';
require_once __DIR__ . '/src/StatisticsStorage.php';

class RepairTool {
    private $storage;
    private $dataDir;
    
    public function __construct(string $dataDir) {
        $this->dataDir = $dataDir;
        $this->storage = new StatisticsStorage($dataDir);
    }
    
    /**
     * List all monitored sites
     */
    public function listSites(): void {
        echo "📊 Monitored Sites:\n";
        echo str_repeat("=", 60) . "\n";
        
        $sites = $this->storage->getMonitoredSites();
        
        if (empty($sites)) {
            echo "No monitored sites found.\n";
            return;
        }
        
        foreach ($sites as $site) {
            echo "  • {$site}\n";
        }
        
        echo "\nTotal: " . count($sites) . " sites\n";
    }
    
    /**
     * List recent errors for a site
     */
    public function listErrors(string $siteName, int $days = 7): void {
        $sanitizedName = $this->sanitizeSiteName($siteName);
        $history = $this->storage->getHistory($sanitizedName, $days);
        
        if (empty($history)) {
            echo "❌ No data found for site: {$siteName}\n";
            echo "   (sanitized as: {$sanitizedName})\n";
            echo "\nTip: Use --list-sites to see available site names.\n";
            return;
        }
        
        echo "🔍 Recent Errors for: {$siteName}\n";
        echo "   (directory: {$sanitizedName})\n";
        echo str_repeat("=", 80) . "\n";
        
        $errorCount = 0;
        
        foreach ($history as $date => $checks) {
            $dailyErrors = array_filter($checks, fn($check) => !$check['success']);
            
            if (empty($dailyErrors)) {
                continue;
            }
            
            echo "\n📅 Date: {$date}\n";
            
            foreach ($dailyErrors as $check) {
                $errorCount++;
                $time = $check['datetime'] ?? date('Y-m-d H:i:s', $check['timestamp']);
                $error = $check['error'] ?? 'Unknown error';
                $httpCode = $check['http_code'] ?? 0;
                
                echo "  [{$time}] HTTP {$httpCode} - {$error}\n";
            }
        }
        
        if ($errorCount === 0) {
            echo "✅ No errors found in the last {$days} days.\n";
        } else {
            echo "\n" . str_repeat("=", 80) . "\n";
            echo "Total errors found: {$errorCount}\n";
        }
    }
    
    /**
     * Remove the last N failed checks for a site
     */
    public function silenceLastError(string $siteName, int $count = 1): void {
        $sanitizedName = $this->sanitizeSiteName($siteName);
        $history = $this->storage->getHistory($sanitizedName, 30); // Search last 30 days
        
        if (empty($history)) {
            echo "❌ No data found for site: {$siteName}\n";
            echo "   (sanitized as: {$sanitizedName})\n";
            echo "\nTip: Use --list-sites to see available site names.\n";
            return;
        }
        
        // Collect all errors with their locations
        $errors = [];
        foreach ($history as $date => $checks) {
            foreach ($checks as $index => $check) {
                if (!$check['success']) {
                    $errors[] = [
                        'date' => $date,
                        'index' => $index,
                        'check' => $check,
                        'timestamp' => $check['timestamp']
                    ];
                }
            }
        }
        
        if (empty($errors)) {
            echo "✅ No errors found for site: {$siteName}\n";
            return;
        }
        
        // Sort by timestamp descending (most recent first)
        usort($errors, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
        
        // Limit to requested count
        $toRemove = array_slice($errors, 0, $count);
        
        echo "🔧 Removing {$count} most recent error(s) for: {$siteName}\n";
        echo "   (directory: {$sanitizedName})\n";
        echo str_repeat("=", 80) . "\n";
        
        $removed = 0;
        foreach ($toRemove as $error) {
            $datetime = $error['check']['datetime'] ?? date('Y-m-d H:i:s', $error['timestamp']);
            $errorMsg = $error['check']['error'] ?? 'Unknown error';
            $httpCode = $error['check']['http_code'] ?? 0;
            
            echo "  Removing: [{$datetime}] HTTP {$httpCode} - {$errorMsg}\n";
            
            if ($this->removeCheck($sanitizedName, $error['date'], $error['index'])) {
                $removed++;
            } else {
                echo "    ⚠️  Failed to remove this entry\n";
            }
        }
        
        echo "\n✅ Successfully removed {$removed} of {$count} error(s).\n";
    }
    
    /**
     * Remove a specific check by date and time
     */
    public function removeByDateTime(string $siteName, string $date, string $time): void {
        $sanitizedName = $this->sanitizeSiteName($siteName);
        $data = $this->loadDailyData($sanitizedName, $date);
        
        if (empty($data)) {
            echo "❌ No data found for site '{$siteName}' on date: {$date}\n";
            echo "   (directory: {$sanitizedName})\n";
            return;
        }
        
        $targetDatetime = "{$date} {$time}";
        $found = false;
        
        foreach ($data as $index => $check) {
            $checkDatetime = $check['datetime'] ?? date('Y-m-d H:i:s', $check['timestamp']);
            
            if (strpos($checkDatetime, $targetDatetime) === 0) {
                $httpCode = $check['http_code'] ?? 0;
                $status = $check['success'] ? '✅ SUCCESS' : '❌ FAILED';
                $error = $check['error'] ?? '';
                
                echo "🔧 Removing check: [{$checkDatetime}] HTTP {$httpCode} {$status}\n";
                if ($error) {
                    echo "   Error was: {$error}\n";
                }
                
                if ($this->removeCheck($sanitizedName, $date, $index)) {
                    echo "✅ Successfully removed.\n";
                    $found = true;
                } else {
                    echo "❌ Failed to remove.\n";
                }
                break;
            }
        }
        
        if (!$found) {
            echo "❌ No check found at: {$targetDatetime}\n";
        }
    }
    
    /**
     * Remove a specific check from daily data
     */
    private function removeCheck(string $siteName, string $date, int $index): bool {
        $data = $this->loadDailyData($siteName, $date);
        
        if (!isset($data[$index])) {
            return false;
        }
        
        // Remove the check
        array_splice($data, $index, 1);
        
        // Save back
        return $this->saveDailyData($siteName, $date, $data);
    }
    
    /**
     * Load daily data (handles compressed and uncompressed)
     */
    private function loadDailyData(string $siteName, string $date): array {
        $filePath = $this->getFilePath($siteName, $date);
        
        // Try uncompressed
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            return json_decode($content, true) ?? [];
        }
        
        // Try compressed
        $compressedPath = $filePath . '.gz';
        if (file_exists($compressedPath)) {
            $content = gzdecode(file_get_contents($compressedPath));
            return json_decode($content, true) ?? [];
        }
        
        return [];
    }
    
    /**
     * Save daily data
     */
    private function saveDailyData(string $siteName, string $date, array $data): bool {
        $filePath = $this->getFilePath($siteName, $date);
        $compressedPath = $filePath . '.gz';
        
        // If compressed version exists, remove it (we'll write uncompressed)
        if (file_exists($compressedPath)) {
            unlink($compressedPath);
        }
        
        $json = json_encode($data, JSON_PRETTY_PRINT);
        return file_put_contents($filePath, $json, LOCK_EX) !== false;
    }
    
    /**
     * Get file path for site data
     */
    private function getFilePath(string $siteName, string $date): string {
        return $this->dataDir . '/' . $siteName . '/' . $date . '.json';
    }
    
    /**
     * Sanitize site name
     */
    private function sanitizeSiteName(string $name): string {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($name));
    }
}

// Parse command line arguments
$options = getopt('', [
    'site:',
    'silence-last-error',
    'count:',
    'list-errors',
    'list-sites',
    'remove-date:',
    'remove-time:',
    'help'
]);

// Help text
if (isset($options['help']) || empty($options)) {
    echo <<<HELP

Check4Fail Repair Tool
======================

Remove false positive failures from monitoring statistics.

Usage:
  php repair.php --list-sites
  php repair.php --site="Site Name" --list-errors
  php repair.php --site="Site Name" --silence-last-error
  php repair.php --site="Site Name" --silence-last-error --count=3
  php repair.php --site="Site Name" --remove-date="2025-12-16" --remove-time="14:30:00"

Options:
  --list-sites                    List all monitored sites
  --site="Site Name"              Specify the site to work with
  --list-errors                   Show recent errors for the site
  --silence-last-error            Remove the most recent error(s)
  --count=N                       Number of recent errors to remove (default: 1)
  --remove-date="YYYY-MM-DD"      Specific date to remove check from
  --remove-time="HH:MM:SS"        Specific time to remove check at
  --help                          Show this help message

Examples:
  # List all monitored sites
  php repair.php --list-sites

  # Show recent errors for a site
  php repair.php --site="example.com" --list-errors

  # Remove the last failed check
  php repair.php --site="example.com" --silence-last-error

  # Remove the last 5 failed checks
  php repair.php --site="example.com" --silence-last-error --count=5

  # Remove a specific check by date and time
  php repair.php --site="example.com" --remove-date="2025-12-16" --remove-time="14:30:00"

Note: The script can be called consecutively to remove multiple errors one at a time.


HELP;
    exit(0);
}

// Load configuration
$configFile = __DIR__ . '/config.toml';
if (!file_exists($configFile)) {
    echo "❌ Error: config.toml not found. Please copy config.toml.example and configure it.\n";
    exit(1);
}

$parser = new ConfigParser();
$config = $parser->parse($configFile);
$dataDir = $config['data_dir'] ?? __DIR__ . '/data';

// Initialize repair tool
$repair = new RepairTool($dataDir);

// Execute requested action
if (isset($options['list-sites'])) {
    $repair->listSites();
    exit(0);
}

if (!isset($options['site'])) {
    echo "❌ Error: --site option is required.\n";
    echo "Run with --help for usage information.\n";
    exit(1);
}

$siteName = $options['site'];

if (isset($options['list-errors'])) {
    $repair->listErrors($siteName);
} elseif (isset($options['silence-last-error'])) {
    $count = isset($options['count']) ? (int)$options['count'] : 1;
    $repair->silenceLastError($siteName, $count);
} elseif (isset($options['remove-date']) && isset($options['remove-time'])) {
    $date = $options['remove-date'];
    $time = $options['remove-time'];
    $repair->removeByDateTime($siteName, $date, $time);
} else {
    echo "❌ Error: No action specified.\n";
    echo "Use --list-errors, --silence-last-error, or --remove-date with --remove-time.\n";
    echo "Run with --help for usage information.\n";
    exit(1);
}

exit(0);
