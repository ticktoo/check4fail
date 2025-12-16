#!/usr/bin/env php
<?php
/**
 * DownDetector - Main cron script
 * 
 * This script checks configured sites for availability and performance issues.
 * It should be run via cron at regular intervals (e.g., every minute).
 * 
 * Usage: php check.php [--config=/path/to/config.toml]
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Base directory
define('BASE_DIR', __DIR__);
define('DATA_DIR', BASE_DIR . '/data');
define('LOCK_DIR', BASE_DIR . '/var/lock');
define('LOG_DIR', BASE_DIR . '/var/log');

// Ensure required directories exist
foreach ([DATA_DIR, LOCK_DIR, LOG_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Set up logging
$logFile = LOG_DIR . '/downdetector_' . date('Y-m-d') . '.log';
ini_set('error_log', $logFile);

// Load classes
require_once BASE_DIR . '/src/ConfigParser.php';
require_once BASE_DIR . '/src/Lock.php';
require_once BASE_DIR . '/src/SiteChecker.php';
require_once BASE_DIR . '/src/StatisticsStorage.php';
require_once BASE_DIR . '/src/AnomalyDetector.php';
require_once BASE_DIR . '/src/EmailNotifier.php';

/**
 * Log message to file and stdout
 */
function logMessage(string $level, string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$level}] {$message}\n";
    
    error_log("[{$level}] {$message}");
    echo $logLine;
}

/**
 * Main execution function
 */
function main(): int {
    $startTime = microtime(true);
    
    logMessage('INFO', 'DownDetector started');
    
    // Parse command line arguments
    $configFile = BASE_DIR . '/config.toml';
    $options = getopt('', ['config:']);
    if (isset($options['config'])) {
        $configFile = $options['config'];
    }
    
    // Check if config exists
    if (!file_exists($configFile)) {
        logMessage('ERROR', "Configuration file not found: {$configFile}");
        logMessage('INFO', 'Please copy config.toml.example to config.toml and configure your sites');
        return 1;
    }
    
    // Acquire lock to prevent race conditions
    $lock = new Lock(LOCK_DIR . '/downdetector.lock', 3600);
    
    if (!$lock->acquire()) {
        $lockInfo = $lock->getLockInfo();
        logMessage('WARNING', 'Another instance is already running');
        if ($lockInfo) {
            logMessage('WARNING', sprintf(
                'Lock held by PID %d since %s (age: %d seconds)',
                $lockInfo['pid'],
                $lockInfo['started_at'],
                $lockInfo['age_seconds']
            ));
        }
        return 2;
    }
    
    try {
        // Load configuration
        logMessage('INFO', "Loading configuration from: {$configFile}");
        $config = ConfigParser::parse($configFile);
        
        // Validate configuration
        if (empty($config['sites'])) {
            logMessage('ERROR', 'No sites configured in config.toml');
            return 1;
        }
        
        $settings = $config['settings'] ?? [];
        $anomalyThresholds = $config['anomaly_thresholds'] ?? [];
        $sites = $config['sites'];
        
        logMessage('INFO', sprintf('Found %d site(s) to monitor', count($sites)));
        
        // Initialize components
        $storage = new StatisticsStorage(
            DATA_DIR,
            $settings['retention_days'] ?? 7,
            $settings['compression_threshold_days'] ?? 1
        );
        
        $checker = new SiteChecker($settings['timeout_per_site'] ?? 30);
        $detector = new AnomalyDetector($storage, $anomalyThresholds);
        $notifier = new EmailNotifier();
        
        // Check all sites in parallel
        logMessage('INFO', 'Starting parallel site checks...');
        $allMetrics = $checker->checkSitesParallel($sites);
        
        // Process each result
        $anomalyCount = 0;
        foreach ($allMetrics as $index => $metrics) {
            $siteConfig = $sites[$index];
            $siteName = $siteConfig['name'] ?? $siteConfig['url'];
            
            logMessage('INFO', sprintf(
                'Site: %s | Status: %d | Time: %s ms | Size: %s bytes',
                $siteName,
                $metrics['http_code'],
                $metrics['response_time'],
                $metrics['size_download']
            ));
            
            // Store metrics
            $storage->store($metrics);
            
            // Detect anomalies
            $analysis = $detector->detectAnomalies($metrics, $siteConfig);
            
            if ($analysis['has_anomalies']) {
                $anomalyCount++;
                
                logMessage('WARNING', sprintf(
                    'Anomalies detected for %s: %d issue(s)',
                    $siteName,
                    count($analysis['anomalies'])
                ));
                
                // Check if there are any critical/error level anomalies (not just warnings)
                $hasHardError = false;
                foreach ($analysis['anomalies'] as $anomaly) {
                    logMessage('WARNING', sprintf(
                        '  - [%s] %s',
                        $anomaly['severity'],
                        $anomaly['message']
                    ));
                    
                    // Critical or error severity = hard error that needs notification
                    if (in_array($anomaly['severity'], ['critical', 'error'])) {
                        $hasHardError = true;
                    }
                }
                
                // Only send notification for hard errors (critical/error), not warnings
                if ($hasHardError) {
                    try {
                        $notificationSent = $notifier->sendAnomalyNotification($analysis, $siteConfig);
                        if ($notificationSent) {
                            logMessage('INFO', "Notification sent to {$siteConfig['notification_email']}");
                        } else {
                            logMessage('ERROR', "Failed to send notification to {$siteConfig['notification_email']}");
                        }
                    } catch (Exception $e) {
                        logMessage('ERROR', "Error sending notification: {$e->getMessage()}");
                    }
                } else {
                    logMessage('INFO', "Only warnings detected for {$siteName}, skipping email notification");
                }
            }
        }
        
        // Maintenance tasks
        logMessage('INFO', 'Running maintenance tasks...');
        
        // Compress old data
        $compressed = $storage->compressOldData();
        if ($compressed > 0) {
            logMessage('INFO', "Compressed {$compressed} old data file(s)");
        }
        
        // Cleanup old data beyond retention
        $deleted = $storage->cleanupOldData();
        if ($deleted > 0) {
            logMessage('INFO', "Deleted {$deleted} file(s) beyond retention period");
        }
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        logMessage('INFO', sprintf(
            'DownDetector completed in %s seconds | Sites: %d | Anomalies: %d',
            $duration,
            count($sites),
            $anomalyCount
        ));
        
        return 0;
        
    } catch (Exception $e) {
        logMessage('ERROR', 'Fatal error: ' . $e->getMessage());
        logMessage('ERROR', 'Stack trace: ' . $e->getTraceAsString());
        return 1;
    } finally {
        $lock->release();
    }
}

// Execute main function
exit(main());
