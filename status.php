#!/usr/bin/env php
<?php
/**
 * Generate a status report for DownDetector
 */

define('BASE_DIR', __DIR__);
define('DATA_DIR', BASE_DIR . '/data');
define('LOG_DIR', BASE_DIR . '/var/log');

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║         DownDetector Lite - Status Report               ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// Check configuration
echo "📋 Configuration\n";
echo str_repeat("─", 60) . "\n";

$configFile = BASE_DIR . '/config.toml';
if (file_exists($configFile)) {
    echo "✅ Config file: config.toml\n";
    
    require_once BASE_DIR . '/src/ConfigParser.php';
    try {
        $config = ConfigParser::parse($configFile);
        $siteCount = count($config['sites'] ?? []);
        echo "✅ Sites configured: {$siteCount}\n";
        
        foreach ($config['sites'] ?? [] as $site) {
            echo "   • " . ($site['name'] ?? 'Unnamed') . " - " . ($site['url'] ?? 'No URL') . "\n";
        }
    } catch (Exception $e) {
        echo "❌ Config parse error: {$e->getMessage()}\n";
    }
} else {
    echo "⚠️  No config.toml found (use config.toml.example as template)\n";
}
echo "\n";

// Check data directory
echo "💾 Data Storage\n";
echo str_repeat("─", 60) . "\n";

if (is_dir(DATA_DIR)) {
    $sites = array_filter(scandir(DATA_DIR), function($item) {
        return $item !== '.' && $item !== '..' && is_dir(DATA_DIR . '/' . $item);
    });
    
    echo "✅ Data directory: " . DATA_DIR . "\n";
    echo "✅ Monitored sites: " . count($sites) . "\n";
    
    foreach ($sites as $site) {
        $siteDir = DATA_DIR . '/' . $site;
        $files = glob($siteDir . '/*.{json,gz}', GLOB_BRACE);
        $totalSize = array_sum(array_map('filesize', $files));
        
        echo "   • {$site}: " . count($files) . " file(s) - " . formatBytes($totalSize) . "\n";
        
        // Show latest check
        $jsonFiles = glob($siteDir . '/*.json');
        if (!empty($jsonFiles)) {
            rsort($jsonFiles);
            $latest = json_decode(file_get_contents($jsonFiles[0]), true);
            if (!empty($latest)) {
                $lastCheck = end($latest);
                $status = $lastCheck['success'] ? '✅' : '❌';
                echo "      Last check: {$lastCheck['datetime']} {$status}\n";
            }
        }
    }
} else {
    echo "⚠️  No data directory (will be created on first run)\n";
}
echo "\n";

// Check logs
echo "📝 Logs\n";
echo str_repeat("─", 60) . "\n";

if (is_dir(LOG_DIR)) {
    $logFiles = glob(LOG_DIR . '/*.log');
    echo "✅ Log directory: " . LOG_DIR . "\n";
    echo "✅ Log files: " . count($logFiles) . "\n";
    
    if (!empty($logFiles)) {
        // Get today's log
        $todayLog = LOG_DIR . '/downdetector_' . date('Y-m-d') . '.log';
        if (file_exists($todayLog)) {
            $lines = file($todayLog);
            $recentLines = array_slice($lines, -5);
            
            echo "\nRecent log entries:\n";
            foreach ($recentLines as $line) {
                echo "   " . trim($line) . "\n";
            }
        }
    }
} else {
    echo "⚠️  No log directory (will be created on first run)\n";
}
echo "\n";

// System info
echo "🖥️  System Information\n";
echo str_repeat("─", 60) . "\n";
echo "✅ PHP Version: " . PHP_VERSION . "\n";
echo "✅ cURL: " . (extension_loaded('curl') ? 'installed' : 'NOT INSTALLED') . "\n";
echo "✅ JSON: " . (extension_loaded('json') ? 'installed' : 'NOT INSTALLED') . "\n";
echo "✅ Timezone: " . date_default_timezone_get() . "\n";
echo "✅ Current time: " . date('Y-m-d H:i:s') . "\n";
echo "\n";

// Quick stats
echo "📊 Quick Statistics\n";
echo str_repeat("─", 60) . "\n";

$totalChecks = 0;
$successfulChecks = 0;
$failedChecks = 0;

if (is_dir(DATA_DIR)) {
    foreach ($sites as $site) {
        $siteDir = DATA_DIR . '/' . $site;
        $jsonFiles = glob($siteDir . '/*.json');
        
        foreach ($jsonFiles as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                foreach ($data as $check) {
                    $totalChecks++;
                    if ($check['success']) {
                        $successfulChecks++;
                    } else {
                        $failedChecks++;
                    }
                }
            }
        }
    }
    
    $successRate = $totalChecks > 0 ? round(($successfulChecks / $totalChecks) * 100, 2) : 0;
    
    echo "Total checks recorded: {$totalChecks}\n";
    echo "✅ Successful: {$successfulChecks}\n";
    echo "❌ Failed: {$failedChecks}\n";
    echo "Success rate: {$successRate}%\n";
}
echo "\n";

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║  Ready to monitor! Run: php check.php                   ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";

function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}
