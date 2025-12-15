<?php
/**
 * Statistics storage - manages daily JSON files and compression
 */
class StatisticsStorage {
    private $dataDir;
    private $retentionDays;
    private $compressionThreshold;
    
    public function __construct(string $dataDir, int $retentionDays = 7, int $compressionThreshold = 1) {
        $this->dataDir = rtrim($dataDir, '/');
        $this->retentionDays = $retentionDays;
        $this->compressionThreshold = $compressionThreshold;
        
        // Ensure data directory exists
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }
    
    /**
     * Store metrics for a site check
     */
    public function store(array $metrics): bool {
        $date = date('Y-m-d');
        $siteName = $this->sanitizeSiteName($metrics['site_name']);
        $filePath = $this->getFilePath($siteName, $date);
        
        // Ensure site directory exists
        $siteDir = dirname($filePath);
        if (!is_dir($siteDir)) {
            mkdir($siteDir, 0755, true);
        }
        
        // Load existing data
        $data = [];
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $data = json_decode($content, true) ?? [];
        }
        
        // Append new metrics
        $data[] = $metrics;
        
        // Write back
        $json = json_encode($data, JSON_PRETTY_PRINT);
        return file_put_contents($filePath, $json, LOCK_EX) !== false;
    }
    
    /**
     * Get historical data for a site
     * @param string $siteName Site name
     * @param int $days Number of days to retrieve (default: 7)
     * @return array Historical metrics
     */
    public function getHistory(string $siteName, int $days = 7): array {
        $siteName = $this->sanitizeSiteName($siteName);
        $history = [];
        
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $data = $this->loadDailyData($siteName, $date);
            
            if (!empty($data)) {
                $history[$date] = $data;
            }
        }
        
        return $history;
    }
    
    /**
     * Load daily data for a site
     */
    private function loadDailyData(string $siteName, string $date): array {
        // Try uncompressed first
        $filePath = $this->getFilePath($siteName, $date);
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
     * Calculate statistics from historical data
     */
    public function calculateStats(string $siteName, int $days = 7): array {
        $history = $this->getHistory($siteName, $days);
        
        if (empty($history)) {
            return [
                'count' => 0,
                'avg_response_time' => 0,
                'max_response_time' => 0,
                'min_response_time' => 0,
                'avg_size' => 0,
                'success_rate' => 0,
                'status_codes' => []
            ];
        }
        
        $allMetrics = [];
        foreach ($history as $dayData) {
            $allMetrics = array_merge($allMetrics, $dayData);
        }
        
        $responseTimes = [];
        $sizes = [];
        $successCount = 0;
        $statusCodes = [];
        
        foreach ($allMetrics as $metric) {
            if ($metric['success']) {
                $responseTimes[] = $metric['response_time'];
                $sizes[] = $metric['size_download'];
                $successCount++;
            }
            
            $code = $metric['http_code'];
            $statusCodes[$code] = ($statusCodes[$code] ?? 0) + 1;
        }
        
        $count = count($allMetrics);
        
        return [
            'count' => $count,
            'avg_response_time' => !empty($responseTimes) ? round(array_sum($responseTimes) / count($responseTimes), 2) : 0,
            'max_response_time' => !empty($responseTimes) ? max($responseTimes) : 0,
            'min_response_time' => !empty($responseTimes) ? min($responseTimes) : 0,
            'avg_size' => !empty($sizes) ? round(array_sum($sizes) / count($sizes), 2) : 0,
            'success_rate' => $count > 0 ? round(($successCount / $count) * 100, 2) : 0,
            'status_codes' => $statusCodes,
            'period_days' => $days,
            'first_check' => !empty($allMetrics) ? $allMetrics[0]['datetime'] : null,
            'last_check' => !empty($allMetrics) ? end($allMetrics)['datetime'] : null
        ];
    }
    
    /**
     * Compress old data files
     */
    public function compressOldData(): int {
        $compressed = 0;
        $threshold = $this->compressionThreshold;
        
        // Find all JSON files older than threshold
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dataDir),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'json') {
                continue;
            }
            
            $age = (time() - $file->getMTime()) / 86400; // days
            
            if ($age > $threshold) {
                $filePath = $file->getPathname();
                $compressedPath = $filePath . '.gz';
                
                // Skip if already compressed
                if (file_exists($compressedPath)) {
                    continue;
                }
                
                // Compress
                $content = file_get_contents($filePath);
                $compressed_content = gzencode($content, 9);
                
                if (file_put_contents($compressedPath, $compressed_content) !== false) {
                    unlink($filePath);
                    $compressed++;
                }
            }
        }
        
        return $compressed;
    }
    
    /**
     * Clean up old data beyond retention period
     */
    public function cleanupOldData(): int {
        $deleted = 0;
        $cutoffTime = time() - ($this->retentionDays * 86400);
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dataDir),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            
            $ext = $file->getExtension();
            if ($ext !== 'json' && $ext !== 'gz') {
                continue;
            }
            
            if ($file->getMTime() < $cutoffTime) {
                unlink($file->getPathname());
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Get file path for a site's daily data
     */
    private function getFilePath(string $siteName, string $date): string {
        return "{$this->dataDir}/{$siteName}/{$date}.json";
    }
    
    /**
     * Sanitize site name for use in file paths
     */
    private function sanitizeSiteName(string $name): string {
        // Remove or replace unsafe characters
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
        return strtolower($name);
    }
    
    /**
     * Get list of monitored sites
     */
    public function getMonitoredSites(): array {
        if (!is_dir($this->dataDir)) {
            return [];
        }
        
        $sites = [];
        $dirs = scandir($this->dataDir);
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            $path = $this->dataDir . '/' . $dir;
            if (is_dir($path)) {
                $sites[] = $dir;
            }
        }
        
        return $sites;
    }
}
