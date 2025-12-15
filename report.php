#!/usr/bin/env php
<?php
/**
 * DownDetector - Report Generator
 * 
 * Generates detailed reports for monitored sites showing statistics
 * for the last 7 days (or custom period).
 * 
 * Usage:
 *   php report.php <site_name> [--email] [--days=7]
 *   php report.php production_website
 *   php report.php production_website --email
 *   php report.php production_website --days=14
 *   php report.php --list  # List all monitored sites
 * 
 * Examples:
 *   php report.php production_website              # Display on CLI
 *   php report.php production_website --email      # Send via email
 *   php report.php production_website --days=30    # 30-day report
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Base directory
define('BASE_DIR', __DIR__);
define('DATA_DIR', BASE_DIR . '/data');

// Load classes
require_once BASE_DIR . '/src/ConfigParser.php';
require_once BASE_DIR . '/src/StatisticsStorage.php';
require_once BASE_DIR . '/src/EmailNotifier.php';

/**
 * Display usage information
 */
function showUsage(): void {
    echo "DownDetector Report Generator\n\n";
    echo "Usage:\n";
    echo "  php report.php <site_name> [options]\n";
    echo "  php report.php --list\n\n";
    echo "Options:\n";
    echo "  --email           Send report via email instead of displaying\n";
    echo "  --days=N          Report period in days (default: 7)\n";
    echo "  --list            List all monitored sites\n\n";
    echo "Examples:\n";
    echo "  php report.php production_website\n";
    echo "  php report.php production_website --email\n";
    echo "  php report.php production_website --days=30\n";
    echo "  php report.php --list\n\n";
}

/**
 * List all monitored sites
 */
function listSites(): void {
    $storage = new StatisticsStorage(DATA_DIR);
    $sites = $storage->getMonitoredSites();
    
    if (empty($sites)) {
        echo "No monitored sites found.\n";
        echo "Run 'php check.php' first to collect data.\n";
        return;
    }
    
    echo "Monitored Sites:\n";
    echo str_repeat("=", 60) . "\n";
    
    foreach ($sites as $site) {
        // Get latest data file
        $files = glob(DATA_DIR . '/' . $site . '/*.json');
        if (!empty($files)) {
            rsort($files);
            $data = json_decode(file_get_contents($files[0]), true);
            $count = count($data);
            $latest = !empty($data) ? end($data) : null;
            
            echo "• {$site}\n";
            if ($latest) {
                echo "  URL: {$latest['url']}\n";
                echo "  Last check: {$latest['datetime']}\n";
                echo "  Status: " . ($latest['success'] ? '✅ UP' : '❌ DOWN') . "\n";
            }
            echo "\n";
        }
    }
}

/**
 * Generate CLI report
 */
function generateCliReport(string $siteName, int $days): void {
    $storage = new StatisticsStorage(DATA_DIR);
    
    // Get historical data
    $history = $storage->getHistory($siteName, $days);
    
    if (empty($history)) {
        echo "❌ No data found for site: {$siteName}\n";
        echo "Available sites: " . implode(', ', $storage->getMonitoredSites()) . "\n";
        exit(1);
    }
    
    // Calculate statistics
    $stats = $storage->calculateStats($siteName, $days);
    
    // Flatten all checks
    $allChecks = [];
    foreach ($history as $date => $checks) {
        $allChecks = array_merge($allChecks, $checks);
    }
    
    // Get site info from latest check
    $latestCheck = end($allChecks);
    $siteUrl = $latestCheck['url'] ?? 'Unknown';
    
    // Display report
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║              DownDetector - Site Report                         ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    
    echo "Site: {$siteName}\n";
    echo "URL: {$siteUrl}\n";
    echo "Report Period: {$days} days\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "\n";
    
    echo str_repeat("═", 70) . "\n";
    echo "SUMMARY STATISTICS\n";
    echo str_repeat("═", 70) . "\n";
    echo "\n";
    
    printf("Total Checks:          %d\n", $stats['count']);
    printf("Success Rate:          %.2f%%\n", $stats['success_rate']);
    printf("Failed Checks:         %d\n", $stats['count'] - ($stats['count'] * $stats['success_rate'] / 100));
    echo "\n";
    
    printf("Avg Response Time:     %.2f ms\n", $stats['avg_response_time']);
    printf("Min Response Time:     %.2f ms\n", $stats['min_response_time']);
    printf("Max Response Time:     %.2f ms\n", $stats['max_response_time']);
    echo "\n";
    
    printf("Avg Download Size:     %s\n", formatBytes($stats['avg_size']));
    echo "\n";
    
    // Status code distribution
    echo "HTTP Status Codes:\n";
    arsort($stats['status_codes']);
    foreach ($stats['status_codes'] as $code => $count) {
        $percent = ($count / $stats['count']) * 100;
        printf("  %d: %d times (%.1f%%)\n", $code, $count, $percent);
    }
    echo "\n";
    
    // Daily breakdown
    echo str_repeat("═", 70) . "\n";
    echo "DAILY BREAKDOWN\n";
    echo str_repeat("═", 70) . "\n";
    echo "\n";
    
    printf("%-12s %8s %10s %10s %10s %8s\n", 
        "Date", "Checks", "Success%", "Avg Time", "Min Time", "Max Time");
    echo str_repeat("-", 70) . "\n";
    
    foreach ($history as $date => $checks) {
        if (empty($checks)) continue;
        
        $successCount = count(array_filter($checks, fn($c) => $c['success']));
        $successRate = ($successCount / count($checks)) * 100;
        
        $responseTimes = array_filter(array_column($checks, 'response_time'), fn($t) => $t > 0);
        $avgTime = !empty($responseTimes) ? array_sum($responseTimes) / count($responseTimes) : 0;
        $minTime = !empty($responseTimes) ? min($responseTimes) : 0;
        $maxTime = !empty($responseTimes) ? max($responseTimes) : 0;
        
        printf("%-12s %8d %9.1f%% %9.2f %9.2f %9.2f\n",
            $date, count($checks), $successRate, $avgTime, $minTime, $maxTime);
    }
    echo "\n";
    
    // Recent failures
    $failures = array_filter($allChecks, fn($c) => !$c['success']);
    if (!empty($failures)) {
        echo str_repeat("═", 70) . "\n";
        echo "RECENT FAILURES (" . count($failures) . " total)\n";
        echo str_repeat("═", 70) . "\n";
        echo "\n";
        
        // Show last 10 failures
        $recentFailures = array_slice($failures, -10);
        foreach ($recentFailures as $failure) {
            echo "• {$failure['datetime']} - ";
            echo "HTTP {$failure['http_code']} - ";
            echo ($failure['error'] ?? 'Unknown error') . "\n";
        }
        echo "\n";
    }
    
    // Performance trends
    echo str_repeat("═", 70) . "\n";
    echo "PERFORMANCE TRENDS\n";
    echo str_repeat("═", 70) . "\n";
    echo "\n";
    
    // Calculate trend
    $firstHalf = array_slice($allChecks, 0, ceil(count($allChecks) / 2));
    $secondHalf = array_slice($allChecks, floor(count($allChecks) / 2));
    
    $firstHalfTimes = array_filter(array_column($firstHalf, 'response_time'), fn($t) => $t > 0);
    $secondHalfTimes = array_filter(array_column($secondHalf, 'response_time'), fn($t) => $t > 0);
    
    if (!empty($firstHalfTimes) && !empty($secondHalfTimes)) {
        $firstAvg = array_sum($firstHalfTimes) / count($firstHalfTimes);
        $secondAvg = array_sum($secondHalfTimes) / count($secondHalfTimes);
        $change = (($secondAvg - $firstAvg) / $firstAvg) * 100;
        
        echo "Response Time Trend:\n";
        printf("  First half average:  %.2f ms\n", $firstAvg);
        printf("  Second half average: %.2f ms\n", $secondAvg);
        printf("  Change:              %+.1f%%", $change);
        
        if ($change > 20) {
            echo " ⚠️ DEGRADING\n";
        } elseif ($change < -20) {
            echo " ✅ IMPROVING\n";
        } else {
            echo " ✓ STABLE\n";
        }
        echo "\n";
    }
    
    // Uptime calculation
    $totalTime = count($allChecks); // Assuming 1-minute intervals
    $uptime = ($stats['success_rate'] / 100);
    
    echo "Availability:\n";
    printf("  Uptime:    %.3f%% (%.2f hours up of %.2f total)\n", 
        $stats['success_rate'],
        ($totalTime * $uptime) / 60,
        $totalTime / 60);
    printf("  Downtime:  %.3f%% (%.2f hours)\n",
        100 - $stats['success_rate'],
        ($totalTime * (1 - $uptime)) / 60);
    echo "\n";
    
    echo str_repeat("═", 70) . "\n";
    echo "Report complete.\n\n";
}

/**
 * Generate and send email report
 */
function generateEmailReport(string $siteName, int $days, array $siteConfig): void {
    $storage = new StatisticsStorage(DATA_DIR);
    
    // Get historical data
    $history = $storage->getHistory($siteName, $days);
    
    if (empty($history)) {
        echo "❌ No data found for site: {$siteName}\n";
        exit(1);
    }
    
    // Calculate statistics
    $stats = $storage->calculateStats($siteName, $days);
    
    // Flatten all checks
    $allChecks = [];
    foreach ($history as $date => $checks) {
        $allChecks = array_merge($allChecks, $checks);
    }
    
    $latestCheck = end($allChecks);
    
    // Build report
    $subject = "DownDetector Report: {$siteConfig['name']} ({$days} days)";
    
    $textBody = buildTextReport($siteName, $siteConfig, $stats, $history, $allChecks, $days);
    $htmlBody = buildHtmlReport($siteName, $siteConfig, $stats, $history, $allChecks, $days);
    
    // Send email
    $notifier = new EmailNotifier();
    $to = $siteConfig['notification_email'];
    
    if (sendMultipartEmail($to, $subject, $textBody, $htmlBody)) {
        echo "✅ Report sent to {$to}\n";
    } else {
        echo "❌ Failed to send report to {$to}\n";
        exit(1);
    }
}

/**
 * Build text report
 */
function buildTextReport(string $siteName, array $siteConfig, array $stats, array $history, array $allChecks, int $days): string {
    $text = "DOWNDETECTOR SITE REPORT\n";
    $text .= str_repeat("=", 70) . "\n\n";
    
    $text .= "Site: {$siteConfig['name']}\n";
    $text .= "URL: {$siteConfig['url']}\n";
    $text .= "Report Period: {$days} days\n";
    $text .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    $text .= "SUMMARY STATISTICS\n";
    $text .= str_repeat("-", 70) . "\n";
    $text .= sprintf("Total Checks:        %d\n", $stats['count']);
    $text .= sprintf("Success Rate:        %.2f%%\n", $stats['success_rate']);
    $text .= sprintf("Avg Response Time:   %.2f ms\n", $stats['avg_response_time']);
    $text .= sprintf("Min Response Time:   %.2f ms\n", $stats['min_response_time']);
    $text .= sprintf("Max Response Time:   %.2f ms\n", $stats['max_response_time']);
    $text .= sprintf("Avg Download Size:   %s\n\n", formatBytes($stats['avg_size']));
    
    $text .= "Status Codes:\n";
    arsort($stats['status_codes']);
    foreach ($stats['status_codes'] as $code => $count) {
        $text .= sprintf("  %d: %d times\n", $code, $count);
    }
    
    $text .= "\n" . str_repeat("=", 70) . "\n";
    $text .= "This is an automated report from DownDetector.\n";
    
    return $text;
}

/**
 * Build HTML report
 */
function buildHtmlReport(string $siteName, array $siteConfig, array $stats, array $history, array $allChecks, int $days): string {
    $siteName = htmlspecialchars($siteConfig['name']);
    $siteUrl = htmlspecialchars($siteConfig['url']);
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>DownDetector Report</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="700" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px;">📊 Site Performance Report</h1>
                            <p style="margin: 10px 0 0 0; color: #ffffff; font-size: 14px; opacity: 0.9;">' . $days . '-Day Analysis</p>
                        </td>
                    </tr>
                    
                    <!-- Site Info -->
                    <tr>
                        <td style="padding: 25px; border-bottom: 1px solid #e0e0e0;">
                            <h2 style="margin: 0 0 15px 0; color: #333; font-size: 22px;">' . $siteName . '</h2>
                            <p style="margin: 0; color: #666; font-size: 14px;">
                                <strong>URL:</strong> <a href="' . $siteUrl . '" style="color: #667eea; text-decoration: none;">' . $siteUrl . '</a><br>
                                <strong>Report Period:</strong> ' . $days . ' days<br>
                                <strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Summary Stats -->
                    <tr>
                        <td style="padding: 25px;">
                            <h3 style="margin: 0 0 15px 0; color: #333; font-size: 18px;">📈 Summary Statistics</h3>
                            
                            <table width="100%" cellpadding="12" cellspacing="0" style="border: 1px solid #e0e0e0; background-color: #fafafa;">
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; width: 50%; font-size: 14px; color: #666;"><strong>Total Checks</strong></td>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; width: 50%; font-size: 14px; color: #333;"><strong>' . number_format($stats['count']) . '</strong></td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; font-size: 14px; color: #666;"><strong>Success Rate</strong></td>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; font-size: 14px; color: ' . ($stats['success_rate'] >= 99 ? '#4caf50' : ($stats['success_rate'] >= 95 ? '#ff9800' : '#f44336')) . ';"><strong>' . number_format($stats['success_rate'], 2) . '%</strong></td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; font-size: 14px; color: #666;"><strong>Avg Response Time</strong></td>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; font-size: 14px; color: #333;">' . number_format($stats['avg_response_time'], 2) . ' ms</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; font-size: 14px; color: #666;"><strong>Min Response Time</strong></td>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; font-size: 14px; color: #333;">' . number_format($stats['min_response_time'], 2) . ' ms</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; font-size: 14px; color: #666;"><strong>Max Response Time</strong></td>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; font-size: 14px; color: #333;">' . number_format($stats['max_response_time'], 2) . ' ms</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; font-size: 14px; color: #666;"><strong>Avg Download Size</strong></td>
                                    <td style="border: 1px solid #e0e0e0; padding: 12px; font-size: 14px; color: #333;">' . formatBytes($stats['avg_size']) . '</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Daily Breakdown -->
                    <tr>
                        <td style="padding: 25px; background-color: #f9f9f9;">
                            <h3 style="margin: 0 0 15px 0; color: #333; font-size: 18px;">📅 Daily Breakdown</h3>
                            
                            <table width="100%" cellpadding="8" cellspacing="0" style="border: 1px solid #e0e0e0; background-color: #ffffff; font-size: 13px;">
                                <tr style="background-color: #667eea;">
                                    <th style="border: 1px solid #e0e0e0; padding: 10px; color: #ffffff; text-align: left;">Date</th>
                                    <th style="border: 1px solid #e0e0e0; padding: 10px; color: #ffffff; text-align: center;">Checks</th>
                                    <th style="border: 1px solid #e0e0e0; padding: 10px; color: #ffffff; text-align: center;">Success%</th>
                                    <th style="border: 1px solid #e0e0e0; padding: 10px; color: #ffffff; text-align: right;">Avg Time</th>
                                </tr>';
    
    foreach ($history as $date => $checks) {
        if (empty($checks)) continue;
        
        $successCount = count(array_filter($checks, fn($c) => $c['success']));
        $successRate = ($successCount / count($checks)) * 100;
        $responseTimes = array_filter(array_column($checks, 'response_time'), fn($t) => $t > 0);
        $avgTime = !empty($responseTimes) ? array_sum($responseTimes) / count($responseTimes) : 0;
        
        $successColor = $successRate >= 99 ? '#4caf50' : ($successRate >= 95 ? '#ff9800' : '#f44336');
        
        $html .= '
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333;">' . $date . '</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; text-align: center;">' . count($checks) . '</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; text-align: center; color: ' . $successColor . '; font-weight: bold;">' . number_format($successRate, 1) . '%</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; text-align: right;">' . number_format($avgTime, 2) . ' ms</td>
                                </tr>';
    }
    
    $html .= '
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Status Codes -->
                    <tr>
                        <td style="padding: 25px;">
                            <h3 style="margin: 0 0 15px 0; color: #333; font-size: 18px;">🔢 HTTP Status Codes</h3>
                            
                            <table width="100%" cellpadding="8" cellspacing="0" style="border: 1px solid #e0e0e0; background-color: #fafafa;">';
    
    arsort($stats['status_codes']);
    foreach ($stats['status_codes'] as $code => $count) {
        $percent = ($count / $stats['count']) * 100;
        $codeColor = $code == 200 ? '#4caf50' : ($code >= 400 ? '#f44336' : '#ff9800');
        
        $html .= '
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: ' . $codeColor . '; font-weight: bold; font-size: 14px;">HTTP ' . $code . '</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; text-align: right;">' . $count . ' times (' . number_format($percent, 1) . '%)</td>
                                </tr>';
    }
    
    $html .= '
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f5f5f5; padding: 20px; text-align: center; border-top: 1px solid #e0e0e0;">
                            <p style="margin: 0; color: #999; font-size: 12px;">
                                This is an automated report from DownDetector<br>
                                Generated on ' . date('Y-m-d H:i:s') . '
                            </p>
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    
    return $html;
}

/**
 * Send multipart email
 */
function sendMultipartEmail(string $to, string $subject, string $textBody, string $htmlBody): bool {
    $boundary = md5(uniqid(time()));
    
    $headers = [
        "From: DownDetector <downdetector@localhost>",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\""
    ];
    
    $message = "This is a multi-part message in MIME format.\n\n";
    $message .= "--{$boundary}\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\n";
    $message .= "Content-Transfer-Encoding: 8bit\n\n";
    $message .= $textBody . "\n\n";
    $message .= "--{$boundary}\n";
    $message .= "Content-Type: text/html; charset=UTF-8\n";
    $message .= "Content-Transfer-Encoding: 8bit\n\n";
    $message .= $htmlBody . "\n\n";
    $message .= "--{$boundary}--";
    
    return mail($to, $subject, $message, implode("\r\n", $headers));
}

/**
 * Format bytes to human readable
 */
function formatBytes($bytes, $precision = 2): string {
    if ($bytes == 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

/**
 * Main execution
 */
function main(array $argv): int {
    // Parse arguments
    $siteName = null;
    $sendEmail = false;
    $days = 7;
    $listSites = false;
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--list') {
            $listSites = true;
        } elseif ($arg === '--email') {
            $sendEmail = true;
        } elseif (strpos($arg, '--days=') === 0) {
            $days = (int)substr($arg, 7);
        } elseif ($arg === '--help' || $arg === '-h') {
            showUsage();
            return 0;
        } elseif ($siteName === null && strpos($arg, '--') !== 0) {
            $siteName = $arg;
        }
    }
    
    // List sites if requested
    if ($listSites) {
        listSites();
        return 0;
    }
    
    // Validate site name
    if ($siteName === null) {
        showUsage();
        return 1;
    }
    
    // Load config to get email address
    $configFile = BASE_DIR . '/config.toml';
    $siteConfig = null;
    
    if (file_exists($configFile)) {
        $config = ConfigParser::parse($configFile);
        
        foreach ($config['sites'] ?? [] as $site) {
            $sanitizedName = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($site['name']));
            if ($sanitizedName === $siteName) {
                $siteConfig = $site;
                break;
            }
        }
    }
    
    if ($sendEmail) {
        if ($siteConfig === null) {
            echo "❌ Site configuration not found in config.toml\n";
            echo "Cannot send email without configuration.\n";
            return 1;
        }
        
        generateEmailReport($siteName, $days, $siteConfig);
    } else {
        generateCliReport($siteName, $days);
    }
    
    return 0;
}

// Execute
exit(main($argv));
