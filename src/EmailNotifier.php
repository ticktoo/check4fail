<?php
/**
 * Email notification system
 * Multipart text/HTML emails compatible with Thunderbird and Outlook
 */
class EmailNotifier {
    private $fromEmail;
    private $fromName;
    
    public function __construct(string $fromEmail = 'downdetector@localhost', string $fromName = 'DownDetector') {
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
    }
    
    /**
     * Send anomaly notification email
     */
    public function sendAnomalyNotification(array $analysis, array $siteConfig): bool {
        $to = $siteConfig['notification_email'];
        $siteName = $siteConfig['name'] ?? $siteConfig['url'];
        
        // Determine severity for subject line
        $severity = $this->getHighestSeverity($analysis['anomalies']);
        $subjectPrefix = match($severity) {
            'critical' => '🔴 CRITICAL',
            'error' => '🔴 ERROR',
            'warning' => '⚠️ WARNING',
            default => 'ℹ️ INFO',
        };
        
        $subject = "{$subjectPrefix}: {$siteName} - Anomaly Detected";
        
        // Create email body
        $textBody = $this->createTextBody($analysis, $siteConfig);
        $htmlBody = $this->createHtmlBody($analysis, $siteConfig);
        
        return $this->sendMultipartEmail($to, $subject, $textBody, $htmlBody);
    }
    
    /**
     * Send multipart email (text + HTML)
     */
    private function sendMultipartEmail(string $to, string $subject, string $textBody, string $htmlBody): bool {
        $boundary = md5(uniqid(time()));
        
        // Headers
        $headers = [
            "From: {$this->fromName} <{$this->fromEmail}>",
            "Reply-To: {$this->fromEmail}",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            "X-Mailer: PHP/" . phpversion(),
            "X-Priority: 1", // High priority
            "Importance: High"
        ];
        
        // Build message
        $message = "This is a multi-part message in MIME format.\n\n";
        
        // Text part
        $message .= "--{$boundary}\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\n";
        $message .= "Content-Transfer-Encoding: 8bit\n\n";
        $message .= $textBody . "\n\n";
        
        // HTML part
        $message .= "--{$boundary}\n";
        $message .= "Content-Type: text/html; charset=UTF-8\n";
        $message .= "Content-Transfer-Encoding: 8bit\n\n";
        $message .= $htmlBody . "\n\n";
        
        // End boundary
        $message .= "--{$boundary}--";
        
        // Send email
        return mail($to, $subject, $message, implode("\r\n", $headers));
    }
    
    /**
     * Create plain text email body
     */
    private function createTextBody(array $analysis, array $siteConfig): string {
        $siteName = $siteConfig['name'] ?? $siteConfig['url'];
        $url = $siteConfig['url'];
        $metrics = $analysis['current_metrics'];
        $anomalies = $analysis['anomalies'];
        $stats = $analysis['historical_stats'];
        
        $text = "DOWNDETECTOR ALERT\n";
        $text .= str_repeat("=", 60) . "\n\n";
        
        $text .= "Site: {$siteName}\n";
        $text .= "URL: {$url}\n";
        $text .= "Time: {$metrics['datetime']}\n\n";
        
        $text .= "DETECTED ANOMALIES:\n";
        $text .= str_repeat("-", 60) . "\n";
        
        foreach ($anomalies as $i => $anomaly) {
            $text .= ($i + 1) . ". [{$anomaly['severity']}] {$anomaly['message']}\n";
            if (!empty($anomaly['details'])) {
                foreach ($anomaly['details'] as $key => $value) {
                    $text .= "   - {$key}: {$value}\n";
                }
            }
            $text .= "\n";
        }
        
        $text .= "\nCURRENT METRICS:\n";
        $text .= str_repeat("-", 60) . "\n";
        $text .= "HTTP Status: {$metrics['http_code']}\n";
        $text .= "Response Time: {$metrics['response_time']} ms\n";
        $text .= "Download Size: " . $this->formatBytes($metrics['size_download']) . "\n";
        $text .= "Primary IP: {$metrics['primary_ip']}\n";
        $text .= "Content Type: {$metrics['content_type']}\n";
        
        if ($stats['count'] > 0) {
            $text .= "\nHISTORICAL AVERAGE (7 days):\n";
            $text .= str_repeat("-", 60) . "\n";
            $text .= "Avg Response Time: {$stats['avg_response_time']} ms\n";
            $text .= "Avg Download Size: " . $this->formatBytes($stats['avg_size']) . "\n";
            $text .= "Success Rate: {$stats['success_rate']}%\n";
            $text .= "Total Checks: {$stats['count']}\n";
        }
        
        $text .= "\n" . str_repeat("=", 60) . "\n";
        $text .= "This is an automated message from DownDetector.\n";
        
        return $text;
    }
    
    /**
     * Create HTML email body with inline CSS
     */
    private function createHtmlBody(array $analysis, array $siteConfig): string {
        $siteName = htmlspecialchars($siteConfig['name'] ?? $siteConfig['url']);
        $url = htmlspecialchars($siteConfig['url']);
        $metrics = $analysis['current_metrics'];
        $anomalies = $analysis['anomalies'];
        $stats = $analysis['historical_stats'];
        
        // Inline CSS for maximum compatibility
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DownDetector Alert</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f4; padding: 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #d32f2f; padding: 20px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: bold;">⚠️ DownDetector Alert</h1>
                        </td>
                    </tr>
                    
                    <!-- Site Info -->
                    <tr>
                        <td style="padding: 20px; border-bottom: 1px solid #e0e0e0;">
                            <h2 style="margin: 0 0 10px 0; color: #333; font-size: 20px;">' . $siteName . '</h2>
                            <p style="margin: 0; color: #666; font-size: 14px;">
                                <strong>URL:</strong> <a href="' . $url . '" style="color: #1976d2; text-decoration: none;">' . $url . '</a><br>
                                <strong>Time:</strong> ' . htmlspecialchars($metrics['datetime']) . '
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Anomalies -->';
        
        foreach ($anomalies as $anomaly) {
            $severityColor = $this->getSeverityColor($anomaly['severity']);
            $severityBg = $this->getSeverityBackground($anomaly['severity']);
            
            $html .= '
                    <tr>
                        <td style="padding: 20px; border-bottom: 1px solid #e0e0e0;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background-color: ' . $severityBg . '; padding: 10px; border-left: 4px solid ' . $severityColor . ';">
                                        <p style="margin: 0 0 5px 0; color: #333; font-weight: bold; font-size: 16px;">
                                            <span style="color: ' . $severityColor . '; text-transform: uppercase; font-size: 12px;">[' . htmlspecialchars($anomaly['severity']) . ']</span><br>
                                            ' . htmlspecialchars($anomaly['message']) . '
                                        </p>';
            
            if (!empty($anomaly['details'])) {
                $html .= '<table style="margin-top: 10px; width: 100%;">';
                foreach ($anomaly['details'] as $key => $value) {
                    $html .= '<tr>
                                <td style="padding: 3px 0; color: #666; font-size: 13px;"><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars($value) . '</td>
                              </tr>';
                }
                $html .= '</table>';
            }
            
            $html .= '
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>';
        }
        
        // Current Metrics
        $html .= '
                    <tr>
                        <td style="padding: 20px; background-color: #f9f9f9;">
                            <h3 style="margin: 0 0 15px 0; color: #333; font-size: 16px;">Current Metrics</h3>
                            <table width="100%" cellpadding="8" cellspacing="0" style="border: 1px solid #e0e0e0; background-color: #ffffff;">
                                <tr style="background-color: #f5f5f5;">
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; font-weight: bold; color: #666; font-size: 13px;">Metric</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; font-weight: bold; color: #666; font-size: 13px;">Value</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">HTTP Status</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px; font-weight: bold;">' . htmlspecialchars($metrics['http_code']) . '</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">Response Time</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">' . htmlspecialchars($metrics['response_time']) . ' ms</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">Download Size</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">' . htmlspecialchars($this->formatBytes($metrics['size_download'])) . '</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">Primary IP</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">' . htmlspecialchars($metrics['primary_ip']) . '</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">Content Type</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">' . htmlspecialchars($metrics['content_type']) . '</td>
                                </tr>
                            </table>
                        </td>
                    </tr>';
        
        // Historical stats
        if ($stats['count'] > 0) {
            $html .= '
                    <tr>
                        <td style="padding: 20px;">
                            <h3 style="margin: 0 0 15px 0; color: #333; font-size: 16px;">Historical Average (7 days)</h3>
                            <table width="100%" cellpadding="8" cellspacing="0" style="border: 1px solid #e0e0e0; background-color: #ffffff;">
                                <tr style="background-color: #f5f5f5;">
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; font-weight: bold; color: #666; font-size: 13px;">Metric</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; font-weight: bold; color: #666; font-size: 13px;">Value</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">Avg Response Time</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">' . htmlspecialchars($stats['avg_response_time']) . ' ms</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">Avg Download Size</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">' . htmlspecialchars($this->formatBytes($stats['avg_size'])) . '</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">Success Rate</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">' . htmlspecialchars($stats['success_rate']) . '%</td>
                                </tr>
                                <tr>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">Total Checks</td>
                                    <td style="border: 1px solid #e0e0e0; padding: 8px; color: #333; font-size: 13px;">' . htmlspecialchars($stats['count']) . '</td>
                                </tr>
                            </table>
                        </td>
                    </tr>';
        }
        
        // Footer
        $html .= '
                    <tr>
                        <td style="background-color: #f5f5f5; padding: 15px; text-align: center; border-top: 1px solid #e0e0e0;">
                            <p style="margin: 0; color: #999; font-size: 12px;">
                                This is an automated message from DownDetector.<br>
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
     * Get highest severity from anomalies
     */
    private function getHighestSeverity(array $anomalies): string {
        $severityRank = ['critical' => 4, 'error' => 3, 'warning' => 2, 'info' => 1];
        $highest = 'info';
        $highestRank = 0;
        
        foreach ($anomalies as $anomaly) {
            $rank = $severityRank[$anomaly['severity']] ?? 0;
            if ($rank > $highestRank) {
                $highestRank = $rank;
                $highest = $anomaly['severity'];
            }
        }
        
        return $highest;
    }
    
    /**
     * Get color for severity level
     */
    private function getSeverityColor(string $severity): string {
        return match($severity) {
            'critical' => '#d32f2f',
            'error' => '#f44336',
            'warning' => '#ff9800',
            'info' => '#2196f3',
            default => '#757575'
        };
    }
    
    /**
     * Get background color for severity level
     */
    private function getSeverityBackground(string $severity): string {
        return match($severity) {
            'critical' => '#ffebee',
            'error' => '#ffcdd2',
            'warning' => '#fff3e0',
            'info' => '#e3f2fd',
            default => '#f5f5f5'
        };
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes, $precision = 2): string {
        if ($bytes == 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
    }
}
