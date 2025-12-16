<?php
/**
 * Site checker - fetches URLs and collects metrics
 */
class SiteChecker {
    private $timeout;
    
    public function __construct(int $timeout = 30) {
        $this->timeout = $timeout;
    }
    
    /**
     * Check a single site
     * @param array $siteConfig Site configuration from TOML
     * @return array Metrics and response data
     */
    public function checkSite(array $siteConfig): array {
        $url = $siteConfig['url'];
        $startTime = microtime(true);
        
        $ch = curl_init();
        
        // Basic cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Check4Fail/1.0 (Health Monitor)',
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) >= 2) {
                    $responseHeaders[trim($header[0])] = trim($header[1]);
                }
                return $len;
            }
        ]);
        
        // Handle IP override (bypass DNS)
        if (!empty($siteConfig['override_ip'])) {
            $parsedUrl = parse_url($url);
            $host = $parsedUrl['host'];
            $port = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80);
            
            curl_setopt($ch, CURLOPT_RESOLVE, [
                "{$host}:{$port}:{$siteConfig['override_ip']}"
            ]);
        }
        
        $responseHeaders = [];
        $responseBody = curl_exec($ch);
        $endTime = microtime(true);
        
        // Collect metrics
        $metrics = [
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'url' => $url,
            'site_name' => $siteConfig['name'] ?? $url,
            'success' => $responseBody !== false,
            'response_time' => round(($endTime - $startTime) * 1000, 2), // milliseconds
            'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'size_download' => curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD),
            'header_size' => curl_getinfo($ch, CURLINFO_HEADER_SIZE),
            'namelookup_time' => round(curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME) * 1000, 2),
            'connect_time' => round(curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000, 2),
            'pretransfer_time' => round(curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) * 1000, 2),
            'starttransfer_time' => round(curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000, 2),
            'redirect_count' => curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
            'primary_ip' => curl_getinfo($ch, CURLINFO_PRIMARY_IP),
            'content_type' => curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'headers' => $responseHeaders,
        ];
        
        if ($responseBody === false) {
            $metrics['error'] = curl_error($ch);
            $metrics['error_code'] = curl_errno($ch);
        } else {
            $metrics['body_hash'] = md5($responseBody);
            $metrics['body_length'] = strlen($responseBody);
            
            // Optional content validation
            if (!empty($siteConfig['check_content_contains'])) {
                $metrics['content_check_passed'] = 
                    stripos($responseBody, $siteConfig['check_content_contains']) !== false;
            }
        }
        
        curl_close($ch);
        
        return $metrics;
    }
    
    /**
     * Check multiple sites in parallel using curl_multi
     * @param array $sites Array of site configurations
     * @return array Array of metrics for each site
     */
    public function checkSitesParallel(array $sites): array {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $responseHeaders = [];
        $results = [];
        
        // Initialize all cURL handles
        foreach ($sites as $index => $siteConfig) {
            $url = $siteConfig['url'];
            $ch = curl_init();
            
            // Store for later reference
            $curlHandles[$index] = $ch;
            $responseHeaders[$index] = [];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'Check4Fail/1.0 (Health Monitor)',
                CURLOPT_HEADER => false,
                CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders, $index) {
                    $len = strlen($header);
                    $header = explode(':', $header, 2);
                    if (count($header) >= 2) {
                        $responseHeaders[$index][trim($header[0])] = trim($header[1]);
                    }
                    return $len;
                }
            ]);
            
            // Handle IP override
            if (!empty($siteConfig['override_ip'])) {
                $parsedUrl = parse_url($url);
                $host = $parsedUrl['host'];
                $port = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? 443 : 80);
                
                curl_setopt($ch, CURLOPT_RESOLVE, [
                    "{$host}:{$port}:{$siteConfig['override_ip']}"
                ]);
            }
            
            curl_multi_add_handle($multiHandle, $ch);
        }
        
        // Execute all handles
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);
        
        // Collect results
        foreach ($sites as $index => $siteConfig) {
            $ch = $curlHandles[$index];
            $responseBody = curl_multi_getcontent($ch);
            
            // Use cURL's accurate individual timing
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $expectedStatus = $siteConfig['expected_status'] ?? 200;
            
            // Success means: response received AND HTTP status matches expected
            $responseReceived = $responseBody !== false && $responseBody !== null;
            $statusMatches = ($httpCode == $expectedStatus);
            $isSuccess = $responseReceived && $statusMatches;
            
            $metrics = [
                'timestamp' => time(),
                'datetime' => date('Y-m-d H:i:s'),
                'url' => $siteConfig['url'],
                'site_name' => $siteConfig['name'] ?? $siteConfig['url'],
                'success' => $isSuccess,
                'response_time' => round($totalTime * 1000, 2),
                'http_code' => $httpCode,
                'size_download' => curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD),
                'header_size' => curl_getinfo($ch, CURLINFO_HEADER_SIZE),
                'namelookup_time' => round(curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME) * 1000, 2),
                'connect_time' => round(curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000, 2),
                'pretransfer_time' => round(curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) * 1000, 2),
                'starttransfer_time' => round(curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000, 2),
                'redirect_count' => curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
                'primary_ip' => curl_getinfo($ch, CURLINFO_PRIMARY_IP),
                'content_type' => curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                'headers' => $responseHeaders[$index] ?? [],
            ];
            
            if (!$metrics['success']) {
                $metrics['error'] = curl_error($ch);
                $metrics['error_code'] = curl_errno($ch);
            } else {
                $metrics['body_hash'] = md5($responseBody);
                $metrics['body_length'] = strlen($responseBody);
                
                // Optional content validation
                if (!empty($siteConfig['check_content_contains'])) {
                    $metrics['content_check_passed'] = 
                        stripos($responseBody, $siteConfig['check_content_contains']) !== false;
                }
            }
            
            $results[] = $metrics;
            
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multiHandle);
        
        return $results;
    }
}
