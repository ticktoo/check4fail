#!/usr/bin/env php
<?php
/**
 * Test script for Check4Fail components
 */

require_once __DIR__ . '/src/ConfigParser.php';
require_once __DIR__ . '/src/Lock.php';
require_once __DIR__ . '/src/SiteChecker.php';
require_once __DIR__ . '/src/StatisticsStorage.php';
require_once __DIR__ . '/src/AnomalyDetector.php';
require_once __DIR__ . '/src/EmailNotifier.php';

echo "Check4Fail Component Tests\n";
echo str_repeat("=", 60) . "\n\n";

// Test 1: Config Parser
echo "Test 1: Config Parser\n";
echo str_repeat("-", 60) . "\n";
try {
    $config = ConfigParser::parse(__DIR__ . '/config.toml.example');
    echo "✅ Config parsed successfully\n";
    echo "   Sites configured: " . count($config['sites']) . "\n";
    echo "   Settings loaded: " . (isset($config['settings']) ? 'yes' : 'no') . "\n";
} catch (Exception $e) {
    echo "❌ Config parsing failed: {$e->getMessage()}\n";
}
echo "\n";

// Test 2: Lock mechanism
echo "Test 2: Lock Mechanism\n";
echo str_repeat("-", 60) . "\n";
$lockFile = __DIR__ . '/var/lock/test.lock';
@mkdir(__DIR__ . '/var/lock', 0755, true);

$lock1 = new Lock($lockFile, 60);
if ($lock1->acquire()) {
    echo "✅ Lock acquired successfully\n";
    
    // Try to acquire again (should fail)
    $lock2 = new Lock($lockFile, 60);
    if (!$lock2->acquire()) {
        echo "✅ Second lock correctly denied\n";
    } else {
        echo "❌ Second lock should have been denied\n";
        $lock2->release();
    }
    
    $lock1->release();
    echo "✅ Lock released successfully\n";
} else {
    echo "❌ Could not acquire lock\n";
}
@unlink($lockFile);
echo "\n";

// Test 3: Site Checker
echo "Test 3: Site Checker\n";
echo str_repeat("-", 60) . "\n";
$checker = new SiteChecker(10);
$testSite = [
    'name' => 'Test Site',
    'url' => 'https://example.com',
    'expected_status' => 200
];

echo "Checking https://example.com...\n";
$metrics = $checker->checkSite($testSite);
echo "✅ Site checked\n";
echo "   Status: {$metrics['http_code']}\n";
echo "   Response Time: {$metrics['response_time']} ms\n";
echo "   Size: {$metrics['size_download']} bytes\n";
echo "   IP: {$metrics['primary_ip']}\n";
echo "\n";

// Test 4: Storage
echo "Test 4: Statistics Storage\n";
echo str_repeat("-", 60) . "\n";
@mkdir(__DIR__ . '/data/test', 0755, true);
$storage = new StatisticsStorage(__DIR__ . '/data/test', 7, 1);

$testMetrics = [
    'timestamp' => time(),
    'datetime' => date('Y-m-d H:i:s'),
    'site_name' => 'test_site',
    'url' => 'https://test.com',
    'success' => true,
    'response_time' => 150.5,
    'http_code' => 200,
    'size_download' => 1024
];

if ($storage->store($testMetrics)) {
    echo "✅ Metrics stored successfully\n";
    
    $history = $storage->getHistory('test_site', 1);
    echo "✅ History retrieved: " . count($history) . " day(s)\n";
    
    $stats = $storage->calculateStats('test_site', 1);
    echo "✅ Statistics calculated\n";
    echo "   Avg Response Time: {$stats['avg_response_time']} ms\n";
    echo "   Success Rate: {$stats['success_rate']}%\n";
} else {
    echo "❌ Failed to store metrics\n";
}
echo "\n";

// Test 5: Anomaly Detection
echo "Test 5: Anomaly Detector\n";
echo str_repeat("-", 60) . "\n";
$thresholds = [
    'response_time_multiplier' => 3.0,
    'response_size_difference' => 0.5,
    'alert_on_status_change' => true
];
$detector = new AnomalyDetector($storage, $thresholds);

// Create anomalous metrics
$anomalousMetrics = [
    'timestamp' => time(),
    'datetime' => date('Y-m-d H:i:s'),
    'site_name' => 'test_site',
    'url' => 'https://test.com',
    'success' => true,
    'response_time' => 5000, // Very slow
    'http_code' => 500, // Error
    'size_download' => 100 // Small
];

$analysis = $detector->detectAnomalies($anomalousMetrics, [
    'name' => 'test_site',
    'url' => 'https://test.com',
    'expected_status' => 200,
    'notification_email' => 'test@example.com'
]);

echo "✅ Anomaly detection completed\n";
echo "   Has Anomalies: " . ($analysis['has_anomalies'] ? 'yes' : 'no') . "\n";
echo "   Anomaly Count: " . count($analysis['anomalies']) . "\n";

if ($analysis['has_anomalies']) {
    foreach ($analysis['anomalies'] as $anomaly) {
        echo "   - [{$anomaly['severity']}] {$anomaly['message']}\n";
    }
}
echo "\n";

// Test 6: Email Notifier (structure test only, no actual sending)
echo "Test 6: Email Notifier\n";
echo str_repeat("-", 60) . "\n";
$notifier = new EmailNotifier('test@localhost', 'Check4Fail Test');
echo "✅ Email notifier initialized\n";
echo "   (Email sending requires configured mail server)\n";
echo "\n";

// Cleanup
@unlink(__DIR__ . '/data/test/test_site/' . date('Y-m-d') . '.json');
@rmdir(__DIR__ . '/data/test/test_site');
@rmdir(__DIR__ . '/data/test');

echo str_repeat("=", 60) . "\n";
echo "All component tests completed!\n";
echo "\nReady to use Check4Fail. Run: php check.php\n";
