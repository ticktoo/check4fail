<?php
/**
 * TOML configuration parser
 * Simple TOML parser for reading configuration
 */
class ConfigParser {
    /**
     * Parse TOML configuration file
     * @param string $filePath Path to TOML file
     * @return array Parsed configuration
     */
    public static function parse(string $filePath): array {
        if (!file_exists($filePath)) {
            throw new Exception("Configuration file not found: {$filePath}");
        }
        
        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);
        
        $config = [];
        $currentSection = null;
        $currentArraySection = null;
        $arrayIndex = -1;
        
        foreach ($lines as $line) {
            // Remove comments and trim
            $line = preg_replace('/#.*$/', '', $line);
            $line = trim($line);
            
            if (empty($line)) {
                continue;
            }
            
            // Array of tables: [[section]]
            if (preg_match('/^\[\[(.+)\]\]$/', $line, $matches)) {
                $currentArraySection = $matches[1];
                $arrayIndex++;
                
                if (!isset($config[$currentArraySection])) {
                    $config[$currentArraySection] = [];
                }
                $config[$currentArraySection][$arrayIndex] = [];
                $currentSection = &$config[$currentArraySection][$arrayIndex];
                continue;
            }
            
            // Regular section: [section]
            if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
                $sectionName = $matches[1];
                $currentArraySection = null;
                
                if (!isset($config[$sectionName])) {
                    $config[$sectionName] = [];
                }
                $currentSection = &$config[$sectionName];
                continue;
            }
            
            // Key-value pair
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2]);
                
                // Parse value
                $parsedValue = self::parseValue($value);
                
                if ($currentSection !== null) {
                    $currentSection[$key] = $parsedValue;
                } else {
                    $config[$key] = $parsedValue;
                }
            }
        }
        
        return $config;
    }
    
    /**
     * Parse TOML value
     */
    private static function parseValue(string $value) {
        // String (double quotes)
        if (preg_match('/^"(.*)"$/', $value, $matches)) {
            return $matches[1];
        }
        
        // String (single quotes)
        if (preg_match("/^'(.*)'$/", $value, $matches)) {
            return $matches[1];
        }
        
        // Boolean
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        
        // Integer
        if (preg_match('/^-?\d+$/', $value)) {
            return (int)$value;
        }
        
        // Float
        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float)$value;
        }
        
        // Array
        if (preg_match('/^\[(.*)\]$/', $value, $matches)) {
            $items = explode(',', $matches[1]);
            $array = [];
            foreach ($items as $item) {
                $array[] = self::parseValue(trim($item));
            }
            return $array;
        }
        
        // Return as-is if no pattern matches
        return $value;
    }
}
