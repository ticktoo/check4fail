#!/usr/bin/env php
<?php
/**
 * Test script to verify multiple notification email support
 */

require_once __DIR__ . '/src/EmailNotifier.php';

echo "Testing Multiple Email Notification Support\n";
echo str_repeat("=", 60) . "\n\n";

// Test 1: Single email as string
echo "Test 1: Single email (string format)\n";
$siteConfig1 = [
    'name' => 'Test Site 1',
    'url' => 'https://example.com',
    'notification_email' => 'admin@example.com'
];

$notifier = new EmailNotifier();
$reflection = new ReflectionClass($notifier);
$method = $reflection->getMethod('getRecipients');
$method->setAccessible(true);

$recipients1 = $method->invoke($notifier, $siteConfig1['notification_email']);
echo "  Input: " . var_export($siteConfig1['notification_email'], true) . "\n";
echo "  Output: " . var_export($recipients1, true) . "\n";
echo "  Expected: ['admin@example.com']\n";
echo "  Result: " . (($recipients1 === ['admin@example.com']) ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test 2: Multiple emails as array
echo "Test 2: Multiple emails (array format)\n";
$siteConfig2 = [
    'name' => 'Test Site 2',
    'url' => 'https://example.com',
    'notification_email' => ['admin@example.com', 'oncall@example.com', 'devops@example.com']
];

$recipients2 = $method->invoke($notifier, $siteConfig2['notification_email']);
echo "  Input: " . var_export($siteConfig2['notification_email'], true) . "\n";
echo "  Output: " . var_export($recipients2, true) . "\n";
echo "  Expected: ['admin@example.com', 'oncall@example.com', 'devops@example.com']\n";
echo "  Result: " . (($recipients2 === ['admin@example.com', 'oncall@example.com', 'devops@example.com']) ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test 3: Empty array
echo "Test 3: Empty array\n";
$recipients3 = $method->invoke($notifier, []);
echo "  Input: []\n";
echo "  Output: " . var_export($recipients3, true) . "\n";
echo "  Expected: []\n";
echo "  Result: " . (($recipients3 === []) ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test 4: Array with empty strings (should be filtered)
echo "Test 4: Array with empty strings (should filter)\n";
$recipients4 = $method->invoke($notifier, ['admin@example.com', '', '  ', 'test@example.com']);
echo "  Input: ['admin@example.com', '', '  ', 'test@example.com']\n";
echo "  Output: " . var_export($recipients4, true) . "\n";
echo "  Expected: ['admin@example.com', 'test@example.com']\n";
$expected4 = array_values(array_filter(['admin@example.com', '', '  ', 'test@example.com'], function($e) {
    return is_string($e) && !empty(trim($e));
}));
echo "  Result: " . (($recipients4 == $expected4) ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Test 5: Empty string
echo "Test 5: Empty string\n";
$recipients5 = $method->invoke($notifier, '');
echo "  Input: ''\n";
echo "  Output: " . var_export($recipients5, true) . "\n";
echo "  Expected: []\n";
echo "  Result: " . (($recipients5 === []) ? "✅ PASS" : "❌ FAIL") . "\n\n";

echo str_repeat("=", 60) . "\n";
echo "All tests completed!\n";
