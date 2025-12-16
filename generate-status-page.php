#!/usr/bin/env php
<?php
/**
 * DownDetector - Static HTML Status Page Generator
 * 
 * Generates a professional status page from collected statistics.
 * Creates a static HTML page suitable for hosting as a public status page.
 * 
 * Usage:
 *   php generate-status-page.php [--output=path] [--days=7] [--title="Status Page"]
 *   php generate-status-page.php
 *   php generate-status-page.php --output=/var/www/status
 *   php generate-status-page.php --days=30 --title="Production Systems Status"
 */

define('BASE_DIR', __DIR__);
define('DATA_DIR', BASE_DIR . '/data');
define('DEFAULT_OUTPUT_DIR', BASE_DIR . '/public_html');

require_once BASE_DIR . '/src/ConfigParser.php';
require_once BASE_DIR . '/src/StatisticsStorage.php';

/**
 * Display usage information
 */
function showUsage(): void {
    echo "DownDetector - Status Page Generator\n\n";
    echo "Usage:\n";
    echo "  php generate-status-page.php [options]\n\n";
    echo "Options:\n";
    echo "  --output=PATH    Output directory (default: ./public_html)\n";
    echo "  --days=N         Days of history to show (default: 7)\n";
    echo "  --title=TEXT     Page title (default: 'System Status')\n";
    echo "  --org=TEXT       Organization name (default: '')\n";
    echo "  --refresh=N      Auto-refresh interval in seconds (default: 300)\n\n";
    echo "Examples:\n";
    echo "  php generate-status-page.php\n";
    echo "  php generate-status-page.php --output=/var/www/status\n";
    echo "  php generate-status-page.php --days=30 --title=\"Production Status\"\n";
    echo "  php generate-status-page.php --org=\"ACME Corp\" --refresh=60\n\n";
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
 * Calculate uptime percentage
 * Considers both complete failures AND degraded performance
 */
function calculateUptime(array $checks, array $historicalStats): float {
    if (empty($checks)) return 0;
    
    $healthyCount = 0;
    $avgResponseTime = $historicalStats['avg_response_time'] ?? 0;
    $avgSize = $historicalStats['avg_size'] ?? 0;
    
    foreach ($checks as $check) {
        // Check 1: Must be successful
        if (!$check['success']) {
            continue;
        }
        
        // Check 2: Response time not excessively slow (>3x average)
        if ($avgResponseTime > 0 && $check['response_time'] > ($avgResponseTime * 3)) {
            continue;
        }
        
        // Check 3: Size not drastically different (>50%)
        if ($avgSize > 0) {
            $sizeDiff = abs($check['size_download'] - $avgSize) / $avgSize;
            if ($sizeDiff > 0.5) {
                continue;
            }
        }
        
        $healthyCount++;
    }
    
    return ($healthyCount / count($checks)) * 100;
}

/**
 * Extract incidents from checks that caused down-rating
 */
function extractIncidents(array $checks, array $historicalStats): array {
    if (empty($checks)) return [];
    
    $incidents = [];
    $avgResponseTime = $historicalStats['avg_response_time'] ?? 0;
    $avgSize = $historicalStats['avg_size'] ?? 0;
    
    foreach ($checks as $check) {
        $reasons = [];
        
        // Check 1: Complete failure
        if (!$check['success']) {
            $reasons[] = "Site down (HTTP {$check['http_code']})";
        }
        
        // Check 2: Extremely slow response
        if ($avgResponseTime > 0 && $check['response_time'] > ($avgResponseTime * 3)) {
            $multiplier = round($check['response_time'] / $avgResponseTime, 1);
            $reasons[] = "Slow response ({$multiplier}× average, {$check['response_time']}ms vs {$avgResponseTime}ms avg)";
        }
        
        // Check 3: Size anomaly
        if ($avgSize > 0) {
            $sizeDiff = abs($check['size_download'] - $avgSize) / $avgSize;
            if ($sizeDiff > 0.5) {
                $percentDiff = round($sizeDiff * 100, 1);
                $reasons[] = "Content size anomaly ({$percentDiff}% difference, " . formatBytes($check['size_download']) . " vs " . formatBytes($avgSize) . " avg)";
            }
        }
        
        // If any issues found, record the incident
        if (!empty($reasons)) {
            $incidents[] = [
                'datetime' => $check['datetime'],
                'timestamp' => $check['timestamp'],
                'reasons' => $reasons,
                'http_code' => $check['http_code'],
                'response_time' => $check['response_time'],
                'size' => $check['size_download']
            ];
        }
    }
    
    // Sort by timestamp descending (newest first)
    usort($incidents, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    
    return $incidents;
}

/**
 * Get status color based on uptime
 */
function getStatusColor(float $uptime): string {
    if ($uptime >= 99.9) return '#10b981'; // green
    if ($uptime >= 99.0) return '#22c55e'; // light green
    if ($uptime >= 95.0) return '#eab308'; // yellow
    if ($uptime >= 90.0) return '#f97316'; // orange
    return '#ef4444'; // red
}

/**
 * Get status text based on uptime
 */
function getStatusText(float $uptime): string {
    if ($uptime >= 99.9) return 'Operational';
    if ($uptime >= 99.0) return 'Healthy';
    if ($uptime >= 95.0) return 'Degraded';
    if ($uptime >= 90.0) return 'Issues';
    return 'Down';
}

/**
 * Generate chart data for a site
 */
function generateChartData(array $history, int $days, array $historicalStats): array {
    $labels = [];
    $uptimeData = [];
    $responseTimeData = [];
    
    // Get dates in order
    $dates = array_keys($history);
    sort($dates);
    
    // Fill in missing days with 0
    $startDate = strtotime("-{$days} days");
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} days", $startDate));
        $labels[] = date('M j', strtotime($date));
        
        if (isset($history[$date]) && !empty($history[$date])) {
            $checks = $history[$date];
            $uptime = calculateUptime($checks, $historicalStats);
            $uptimeData[] = round($uptime, 2);
            
            $responseTimes = array_filter(array_column($checks, 'response_time'), fn($t) => $t > 0);
            $avgResponseTime = !empty($responseTimes) ? array_sum($responseTimes) / count($responseTimes) : 0;
            $responseTimeData[] = round($avgResponseTime, 2);
        } else {
            $uptimeData[] = 0;
            $responseTimeData[] = 0;
        }
    }
    
    return [
        'labels' => $labels,
        'uptime' => $uptimeData,
        'responseTime' => $responseTimeData
    ];
}

/**
 * Generate the HTML status page
 */
function generateHtmlPage(array $sitesData, array $config, int $days): string {
    $title = $config['title'] ?? 'System Status';
    $org = $config['org'] ?? '';
    $refresh = $config['refresh'] ?? 300;
    $generatedAt = date('Y-m-d H:i:s');
    
    // Calculate overall status
    $overallUptime = 0;
    $activeCount = 0;
    foreach ($sitesData as $site) {
        if ($site['stats']['count'] > 0) {
            // Calculate uptime considering degraded performance
            $allChecks = [];
            foreach ($site['history'] as $dayData) {
                $allChecks = array_merge($allChecks, $dayData);
            }
            $siteUptime = calculateUptime($allChecks, $site['stats']);
            $overallUptime += $siteUptime;
            $activeCount++;
        }
    }
    $overallUptime = $activeCount > 0 ? $overallUptime / $activeCount : 0;
    $overallUptimeFormatted = number_format($overallUptime, 2, '.', '');
    $overallColor = getStatusColor($overallUptime);
    $overallStatus = getStatusText($overallUptime);
    
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="{$refresh}">
    <title>{$title}</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5rem;
            color: #1f2937;
            margin-bottom: 10px;
        }
        
        .header .org-name {
            font-size: 1.2rem;
            color: #6b7280;
            margin-bottom: 20px;
        }
        
        .overall-status {
            display: inline-block;
            padding: 12px 30px;
            border-radius: 30px;
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            background-color: {$overallColor};
            margin-top: 10px;
        }
        
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .stat-card .label {
            font-size: 0.9rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .site-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }
        
        .site-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        
        .site-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            cursor: pointer;
        }
        
        .site-info {
            flex: 1;
        }
        
        .site-name {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .site-url {
            font-size: 0.9rem;
            color: #6b7280;
            word-break: break-all;
        }
        
        .site-status {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .status-badge {
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            color: white;
            font-size: 0.9rem;
        }
        
        .uptime-display {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1f2937;
        }
        
        .site-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .metric {
            text-align: center;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }
        
        .metric .value {
            font-size: 1.3rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 3px;
        }
        
        .metric .label {
            font-size: 0.8rem;
            color: #6b7280;
            text-transform: uppercase;
        }
        
        .chart-container {
            margin: 20px 0;
            height: 200px;
            display: none;
        }
        
        .chart-container.active {
            display: block;
        }
        
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 0.9rem;
            display: none;
        }
        
        .details-table.active {
            display: table;
        }
        
        .details-table th {
            background: #f3f4f6;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .details-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .details-table tr:hover {
            background: #f9fafb;
        }
        
        .incidents-section {
            margin-top: 30px;
            display: none;
        }
        
        .incidents-section.active {
            display: block;
        }
        
        .incidents-header {
            font-size: 1.1rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .incident-item {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 15px;
            margin-bottom: 12px;
            border-radius: 6px;
        }
        
        .incident-item.warning {
            background: #fef9c3;
            border-left-color: #eab308;
        }
        
        .incident-time {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
        }
        
        .incident-reason {
            color: #4b5563;
            padding: 4px 0;
            font-size: 0.9rem;
        }
        
        .incident-reason::before {
            content: "• ";
            color: #ef4444;
            font-weight: bold;
        }
        
        .incident-details {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid rgba(0,0,0,0.1);
            font-size: 0.85rem;
            color: #6b7280;
        }
        
        .expand-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 15px;
            transition: background 0.2s;
        }
        
        .expand-btn:hover {
            background: #5568d3;
        }
        
        .footer {
            text-align: center;
            color: white;
            margin-top: 40px;
            padding: 20px;
            font-size: 0.9rem;
        }
        
        .footer a {
            color: white;
            text-decoration: none;
            font-weight: 600;
        }
        
        .uptime-bar {
            display: flex;
            height: 40px;
            border-radius: 6px;
            overflow: hidden;
            margin: 15px 0;
            background: #e5e7eb;
        }
        
        .uptime-segment {
            transition: width 0.3s;
        }
        
        .uptime-segment.up {
            background: #10b981;
        }
        
        .uptime-segment.down {
            background: #ef4444;
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 1.8rem;
            }
            
            .site-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .site-metrics {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
HTML;

    if ($org) {
        $html .= "            <div class=\"org-name\">{$org}</div>\n";
    }
    
    $html .= <<<HTML
            <h1>{$title}</h1>
            <div class="overall-status">{$overallStatus} - {$overallUptimeFormatted}% Uptime</div>
        </div>
        
        <div class="stats-overview">
            <div class="stat-card">
                <div class="value">{$activeCount}</div>
                <div class="label">Monitored Systems</div>
            </div>
            <div class="stat-card">
                <div class="value">{$overallUptime}%</div>
                <div class="label">Overall Uptime</div>
            </div>
            <div class="stat-card">
                <div class="value">{$days}</div>
                <div class="label">Days Tracked</div>
            </div>
        </div>

HTML;

    // Generate site cards
    foreach ($sitesData as $siteData) {
        $siteName = htmlspecialchars($siteData['name']);
        $siteUrl = htmlspecialchars($siteData['url']);
        $stats = $siteData['stats'];
        
        // Calculate uptime considering degraded performance
        $allChecks = [];
        foreach ($siteData['history'] as $dayData) {
            $allChecks = array_merge($allChecks, $dayData);
        }
        $uptime = calculateUptime($allChecks, $stats);
        
        $statusColor = getStatusColor($uptime);
        $statusText = getStatusText($uptime);
        $chartId = 'chart_' . md5($siteName);
        $detailsId = 'details_' . md5($siteName);
        
        $uptimePercent = round($uptime, 2);
        $downPercent = 100 - $uptimePercent;
        
        $html .= <<<HTML
        <div class="site-card">
            <div class="site-header" onclick="toggleDetails('{$chartId}', '{$detailsId}')">
                <div class="site-info">
                    <div class="site-name">{$siteName}</div>
                    <div class="site-url">{$siteUrl}</div>
                </div>
                <div class="site-status">
                    <div class="uptime-display">{$uptimePercent}%</div>
                    <div class="status-badge" style="background-color: {$statusColor}">{$statusText}</div>
                </div>
            </div>
            
            <div class="uptime-bar">
                <div class="uptime-segment up" style="width: {$uptimePercent}%"></div>
                <div class="uptime-segment down" style="width: {$downPercent}%"></div>
            </div>
            
            <div class="site-metrics">
                <div class="metric">
                    <div class="value">{$stats['count']}</div>
                    <div class="label">Total Checks</div>
                </div>
                <div class="metric">
                    <div class="value">{$stats['avg_response_time']} ms</div>
                    <div class="label">Avg Response</div>
                </div>
                <div class="metric">
                    <div class="value">{$stats['min_response_time']} ms</div>
                    <div class="label">Min Response</div>
                </div>
                <div class="metric">
                    <div class="value">{$stats['max_response_time']} ms</div>
                    <div class="label">Max Response</div>
                </div>
            </div>
            
            <div class="chart-container" id="{$chartId}">
                <canvas id="{$chartId}_canvas"></canvas>
            </div>
            
            <button class="expand-btn" onclick="toggleDetails('{$chartId}', '{$detailsId}')">
                Show Details & Charts
            </button>
            
            <table class="details-table" id="{$detailsId}">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Checks</th>
                        <th>Uptime</th>
                        <th>Avg Response</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>

HTML;

        // Add daily details
        $history = $siteData['history'];
        $dates = array_keys($history);
        rsort($dates);
        
        foreach ($dates as $date) {
            $checks = $history[$date];
            if (empty($checks)) continue;
            
            $dailyUptime = calculateUptime($checks, $stats);
            $responseTimes = array_filter(array_column($checks, 'response_time'), fn($t) => $t > 0);
            $avgResponse = !empty($responseTimes) ? round(array_sum($responseTimes) / count($responseTimes), 2) : 0;
            $dailyStatus = getStatusText($dailyUptime);
            $dailyColor = getStatusColor($dailyUptime);
            
            $html .= <<<HTML
                    <tr>
                        <td>{$date}</td>
                        <td>{$stats['count']}</td>
                        <td>{$dailyUptime}%</td>
                        <td>{$avgResponse} ms</td>
                        <td><span style="color: {$dailyColor}; font-weight: 600;">●</span> {$dailyStatus}</td>
                    </tr>

HTML;
        }
        
        $html .= <<<HTML
                </tbody>
            </table>
            
            <div class="incidents-section" id="incidents_{$detailsId}">
                <div class="incidents-header">
                    Incidents & Performance Issues
                </div>

HTML;

        // Extract and display incidents
        $allChecks = [];
        foreach ($siteData['history'] as $dayData) {
            $allChecks = array_merge($allChecks, $dayData);
        }
        $incidents = extractIncidents($allChecks, $stats);
        
        if (empty($incidents)) {
            $html .= <<<HTML
                <div style="padding: 20px; text-align: center; color: #10b981; font-weight: 600;">
                    ✓ No incidents detected in the last {$days} days
                </div>

HTML;
        } else {
            $incidentCount = count($incidents);
            $displayLimit = 20; // Show last 20 incidents
            $displayIncidents = array_slice($incidents, 0, $displayLimit);
            
            foreach ($displayIncidents as $incident) {
                $incidentClass = $incident['http_code'] == 0 || !isset($incident['http_code']) ? 'incident-item' : 'incident-item warning';
                $datetime = htmlspecialchars($incident['datetime']);
                $httpCode = $incident['http_code'];
                $responseTime = round($incident['response_time'], 2);
                $size = formatBytes($incident['size']);
                
                $html .= <<<HTML
                <div class="{$incidentClass}">
                    <div class="incident-time">{$datetime}</div>

HTML;
                
                foreach ($incident['reasons'] as $reason) {
                    $reason = htmlspecialchars($reason);
                    $html .= <<<HTML
                    <div class="incident-reason">{$reason}</div>

HTML;
                }
                
                $html .= <<<HTML
                    <div class="incident-details">
                        HTTP {$httpCode} | Response: {$responseTime}ms | Size: {$size}
                    </div>
                </div>

HTML;
            }
            
            if ($incidentCount > $displayLimit) {
                $remaining = $incidentCount - $displayLimit;
                $html .= <<<HTML
                <div style="padding: 15px; text-align: center; color: #6b7280; font-style: italic;">
                    ... and {$remaining} more incident(s) in the last {$days} days
                </div>

HTML;
            }
        }
        
        $html .= <<<HTML
            </div>
        </div>

HTML;
    }
    
    $html .= <<<HTML
        
        <div class="footer">
            <p>Last updated: {$generatedAt} | Auto-refreshes every {$refresh} seconds</p>
            <p>Powered by <a href="https://github.com/yourusername/downdetector-lite" target="_blank">DownDetector Lite</a></p>
        </div>
    </div>
    
    <script>
        const chartData = {

HTML;

    // Add chart data for each site
    foreach ($sitesData as $siteData) {
        $siteName = $siteData['name'];
        $chartId = 'chart_' . md5($siteName);
        $chartData = generateChartData($siteData['history'], $days, $siteData['stats']);
        
        $html .= "            '{$chartId}': " . json_encode($chartData) . ",\n";
    }
    
    $html .= <<<HTML
        };
        
        function toggleDetails(chartId, detailsId) {
            const chartContainer = document.getElementById(chartId);
            const detailsTable = document.getElementById(detailsId);
            const incidentsSection = document.getElementById('incidents_' + detailsId);
            
            const isActive = chartContainer.classList.contains('active');
            
            if (isActive) {
                chartContainer.classList.remove('active');
                detailsTable.classList.remove('active');
                if (incidentsSection) incidentsSection.classList.remove('active');
            } else {
                chartContainer.classList.add('active');
                detailsTable.classList.add('active');
                if (incidentsSection) incidentsSection.classList.add('active');
                
                // Initialize chart if not already done
                if (!chartContainer.dataset.initialized) {
                    initChart(chartId);
                    chartContainer.dataset.initialized = 'true';
                }
            }
        }
        
        function getUptimeColor(uptime) {
            if (uptime >= 99.9) return '#10b981'; // green
            if (uptime >= 99.0) return '#22c55e'; // light green
            if (uptime >= 95.0) return '#eab308'; // yellow
            if (uptime >= 90.0) return '#f97316'; // orange
            return '#ef4444'; // red
        }
        
        function initChart(chartId) {
            const data = chartData[chartId];
            const canvas = document.getElementById(chartId + '_canvas');
            const ctx = canvas.getContext('2d');
            
            // Generate colors for each uptime data point
            const uptimeColors = data.uptime.map(uptime => getUptimeColor(uptime));
            const uptimeBackgroundColors = data.uptime.map(uptime => {
                const color = getUptimeColor(uptime);
                // Convert hex to rgba with transparency
                const r = parseInt(color.slice(1, 3), 16);
                const g = parseInt(color.slice(3, 5), 16);
                const b = parseInt(color.slice(5, 7), 16);
                return 'rgba(' + r + ', ' + g + ', ' + b + ', 0.1)';
            });
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Uptime %',
                            data: data.uptime,
                            borderColor: uptimeColors,
                            backgroundColor: uptimeBackgroundColors,
                            segment: {
                                borderColor: ctx => {
                                    const uptime = ctx.p1.parsed.y;
                                    return getUptimeColor(uptime);
                                }
                            },
                            tension: 0.4,
                            yAxisID: 'y',
                            pointBackgroundColor: uptimeColors,
                            pointBorderColor: uptimeColors,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Response Time (ms)',
                            data: data.responseTime,
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            tension: 0.4,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            min: 0,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Uptime %'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Response Time (ms)'
                            },
                            grid: {
                                drawOnChartArea: false,
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
HTML;

    return $html;
}

/**
 * Main execution
 */
function main(array $argv): int {
    // Parse arguments
    $outputDir = DEFAULT_OUTPUT_DIR;
    $days = 7;
    $title = 'System Status';
    $org = '';
    $refresh = 300;
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--help' || $arg === '-h') {
            showUsage();
            return 0;
        } elseif (strpos($arg, '--output=') === 0) {
            $outputDir = substr($arg, 9);
        } elseif (strpos($arg, '--days=') === 0) {
            $days = (int)substr($arg, 7);
        } elseif (strpos($arg, '--title=') === 0) {
            $title = trim(substr($arg, 8), '"\'');
        } elseif (strpos($arg, '--org=') === 0) {
            $org = trim(substr($arg, 6), '"\'');
        } elseif (strpos($arg, '--refresh=') === 0) {
            $refresh = (int)substr($arg, 10);
        }
    }
    
    echo "DownDetector Status Page Generator\n";
    echo str_repeat("=", 60) . "\n\n";
    
    // Create output directory
    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0755, true)) {
            echo "❌ Failed to create output directory: {$outputDir}\n";
            return 1;
        }
        echo "✅ Created output directory: {$outputDir}\n";
    }
    
    // Load configuration
    $configFile = BASE_DIR . '/config.toml';
    $sites = [];
    
    if (file_exists($configFile)) {
        $config = ConfigParser::parse($configFile);
        $sites = $config['sites'] ?? [];
    }
    
    if (empty($sites)) {
        echo "⚠️  No sites configured in config.toml\n";
        echo "Please add sites first.\n";
        return 1;
    }
    
    echo "📊 Processing {$days} days of data for " . count($sites) . " site(s)...\n\n";
    
    // Collect data for all sites
    $storage = new StatisticsStorage(DATA_DIR);
    $sitesData = [];
    
    foreach ($sites as $site) {
        $siteName = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($site['name']));
        
        echo "  • Processing: {$site['name']}... ";
        
        $history = $storage->getHistory($siteName, $days);
        $stats = $storage->calculateStats($siteName, $days);
        
        if ($stats['count'] > 0) {
            $sitesData[] = [
                'name' => $site['name'],
                'url' => $site['url'],
                'history' => $history,
                'stats' => $stats
            ];
            echo "✓ ({$stats['count']} checks)\n";
        } else {
            echo "⚠️  (no data)\n";
        }
    }
    
    if (empty($sitesData)) {
        echo "\n❌ No data available to generate status page\n";
        echo "Run 'php check.php' first to collect data.\n";
        return 1;
    }
    
    echo "\n📝 Generating HTML...\n";
    
    // Generate HTML
    $html = generateHtmlPage($sitesData, [
        'title' => $title,
        'org' => $org,
        'refresh' => $refresh
    ], $days);
    
    // Write to file
    $outputFile = $outputDir . '/index.html';
    if (file_put_contents($outputFile, $html) === false) {
        echo "❌ Failed to write output file: {$outputFile}\n";
        return 1;
    }
    
    echo "✅ Status page generated: {$outputFile}\n\n";
    
    // Generate a simple CSS file for customization
    $cssFile = $outputDir . '/custom.css';
    if (!file_exists($cssFile)) {
        file_put_contents($cssFile, "/* Add your custom CSS here */\n");
        echo "✅ Created custom CSS file: {$cssFile}\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "✨ Status page ready!\n\n";
    echo "Next steps:\n";
    echo "  1. View locally: file://{$outputFile}\n";
    echo "  2. Serve via web server:\n";
    echo "     - Nginx: Point document root to {$outputDir}\n";
    echo "     - Apache: Set DocumentRoot to {$outputDir}\n";
    echo "  3. Add to cron for auto-updates:\n";
    echo "     */5 * * * * php " . BASE_DIR . "/generate-status-page.php\n";
    echo "\n";
    
    return 0;
}

// Execute
exit(main($argv));
