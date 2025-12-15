<?php
/**
 * Anomaly detection engine - compares current vs historical data
 */
class AnomalyDetector {
    private $storage;
    private $thresholds;
    
    public function __construct(StatisticsStorage $storage, array $thresholds) {
        $this->storage = $storage;
        $this->thresholds = $thresholds;
    }
    
    /**
     * Analyze a site check for anomalies
     * @param array $currentMetrics Current check metrics
     * @param array $siteConfig Site configuration
     * @return array Detected anomalies
     */
    public function detectAnomalies(array $currentMetrics, array $siteConfig): array {
        $anomalies = [];
        $siteName = $currentMetrics['site_name'];
        
        // Get historical statistics
        $historicalStats = $this->storage->calculateStats($siteName, 7);
        
        // Check 1: Site is down (failed request)
        if (!$currentMetrics['success']) {
            $anomalies[] = [
                'type' => 'site_down',
                'severity' => 'critical',
                'message' => 'Site is unreachable',
                'details' => [
                    'error' => $currentMetrics['error'] ?? 'Unknown error',
                    'error_code' => $currentMetrics['error_code'] ?? 0
                ]
            ];
        }
        
        // Check 2: HTTP status code anomaly
        $expectedStatus = $siteConfig['expected_status'] ?? 200;
        if ($currentMetrics['http_code'] != $expectedStatus) {
            $severity = $this->getStatusCodeSeverity($currentMetrics['http_code']);
            $anomalies[] = [
                'type' => 'status_code_mismatch',
                'severity' => $severity,
                'message' => "Unexpected HTTP status code: {$currentMetrics['http_code']} (expected: {$expectedStatus})",
                'details' => [
                    'actual_code' => $currentMetrics['http_code'],
                    'expected_code' => $expectedStatus
                ]
            ];
        }
        
        // Check 3: Response time anomaly
        if ($historicalStats['count'] >= 10) { // Need sufficient history
            $avgResponseTime = $historicalStats['avg_response_time'];
            $multiplier = $this->thresholds['response_time_multiplier'] ?? 3.0;
            
            if ($currentMetrics['response_time'] > ($avgResponseTime * $multiplier)) {
                $anomalies[] = [
                    'type' => 'slow_response',
                    'severity' => 'warning',
                    'message' => "Response time is {$multiplier}x slower than average",
                    'details' => [
                        'current_time' => $currentMetrics['response_time'],
                        'average_time' => $avgResponseTime,
                        'threshold' => $avgResponseTime * $multiplier
                    ]
                ];
            }
        }
        
        // Check 4: Expected max response time (from config)
        if (!empty($siteConfig['expected_max_response_time'])) {
            if ($currentMetrics['response_time'] > $siteConfig['expected_max_response_time']) {
                $anomalies[] = [
                    'type' => 'response_time_exceeded',
                    'severity' => 'warning',
                    'message' => "Response time exceeded configured maximum",
                    'details' => [
                        'current_time' => $currentMetrics['response_time'],
                        'max_allowed' => $siteConfig['expected_max_response_time']
                    ]
                ];
            }
        }
        
        // Check 5: Response size anomaly
        if ($historicalStats['count'] >= 10 && $historicalStats['avg_size'] > 0) {
            $avgSize = $historicalStats['avg_size'];
            $currentSize = $currentMetrics['size_download'];
            $sizeDiff = abs($currentSize - $avgSize) / $avgSize;
            
            $threshold = $this->thresholds['response_size_difference'] ?? 0.5;
            
            if ($sizeDiff > $threshold) {
                $percentDiff = round($sizeDiff * 100, 1);
                $anomalies[] = [
                    'type' => 'size_anomaly',
                    'severity' => 'warning',
                    'message' => "Response size differs by {$percentDiff}% from average",
                    'details' => [
                        'current_size' => $currentSize,
                        'average_size' => $avgSize,
                        'difference_percent' => $percentDiff
                    ]
                ];
            }
        }
        
        // Check 6: Content validation failed
        if (isset($currentMetrics['content_check_passed']) && !$currentMetrics['content_check_passed']) {
            $anomalies[] = [
                'type' => 'content_check_failed',
                'severity' => 'warning',
                'message' => "Expected content not found in response",
                'details' => [
                    'expected_content' => $siteConfig['check_content_contains']
                ]
            ];
        }
        
        // Check 7: Error keywords detection (would require body storage - placeholder for now)
        // This would be implemented when we store response bodies
        
        return [
            'has_anomalies' => !empty($anomalies),
            'anomalies' => $anomalies,
            'current_metrics' => $currentMetrics,
            'historical_stats' => $historicalStats
        ];
    }
    
    /**
     * Determine severity based on HTTP status code
     */
    private function getStatusCodeSeverity(int $code): string {
        if ($code >= 500) {
            return 'critical'; // Server errors
        } elseif ($code >= 400) {
            return 'error'; // Client errors
        } elseif ($code >= 300 && $code < 400) {
            return 'info'; // Redirects might be OK
        } else {
            return 'warning';
        }
    }
    
    /**
     * Analyze trends over time (for future LLM integration)
     */
    public function analyzeTrends(string $siteName, int $days = 7): array {
        $history = $this->storage->getHistory($siteName, $days);
        
        if (empty($history)) {
            return ['trend' => 'no_data'];
        }
        
        // Calculate daily averages
        $dailyStats = [];
        foreach ($history as $date => $dayData) {
            if (empty($dayData)) continue;
            
            $responseTimes = array_column($dayData, 'response_time');
            $successfulChecks = array_filter($dayData, fn($m) => $m['success']);
            
            $dailyStats[$date] = [
                'avg_response_time' => !empty($responseTimes) ? array_sum($responseTimes) / count($responseTimes) : 0,
                'check_count' => count($dayData),
                'success_count' => count($successfulChecks),
                'success_rate' => count($dayData) > 0 ? (count($successfulChecks) / count($dayData)) * 100 : 0
            ];
        }
        
        // Detect trends
        $responseTimes = array_column($dailyStats, 'avg_response_time');
        $successRates = array_column($dailyStats, 'success_rate');
        
        return [
            'trend' => $this->calculateTrend($responseTimes),
            'success_trend' => $this->calculateTrend($successRates),
            'daily_stats' => $dailyStats,
            'overall_health' => $this->calculateOverallHealth($dailyStats)
        ];
    }
    
    /**
     * Calculate simple trend (improving, stable, degrading)
     */
    private function calculateTrend(array $values): string {
        if (count($values) < 3) {
            return 'insufficient_data';
        }
        
        // Simple linear trend detection
        $first_half = array_slice($values, 0, ceil(count($values) / 2));
        $second_half = array_slice($values, floor(count($values) / 2));
        
        $avg_first = array_sum($first_half) / count($first_half);
        $avg_second = array_sum($second_half) / count($second_half);
        
        $change = (($avg_second - $avg_first) / $avg_first) * 100;
        
        if ($change > 20) {
            return 'degrading';
        } elseif ($change < -20) {
            return 'improving';
        } else {
            return 'stable';
        }
    }
    
    /**
     * Calculate overall health score (0-100)
     */
    private function calculateOverallHealth(array $dailyStats): int {
        if (empty($dailyStats)) {
            return 0;
        }
        
        $avgSuccessRate = array_sum(array_column($dailyStats, 'success_rate')) / count($dailyStats);
        
        // Simple health score based on success rate
        // Could be enhanced with response time, error patterns, etc.
        return (int) round($avgSuccessRate);
    }
}
