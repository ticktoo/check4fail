#!/bin/bash
# Quick setup script for DownDetector

echo "================================================"
echo "DownDetector Lite - Setup"
echo "================================================"
echo ""

# Check PHP
if ! command -v php &> /dev/null; then
    echo "❌ PHP is not installed. Please install PHP 7.4+ with curl and json extensions."
    exit 1
fi

echo "✅ PHP found: $(php -v | head -n 1)"

# Check PHP extensions
php -m | grep -q curl || { echo "❌ PHP cURL extension not found"; exit 1; }
php -m | grep -q json || { echo "❌ PHP JSON extension not found"; exit 1; }

echo "✅ Required PHP extensions found"
echo ""

# Make check.php executable
chmod +x check.php
echo "✅ Made check.php executable"

# Create directories
mkdir -p var/lock var/log data
echo "✅ Created directories: var/lock, var/log, data"
echo ""

# Check if config exists
if [ ! -f "config.toml" ]; then
    echo "⚠️  config.toml not found"
    echo "   Creating from config.toml.example..."
    cp config.toml.example config.toml
    echo "✅ Created config.toml"
    echo ""
    echo "⚠️  IMPORTANT: Edit config.toml to configure your sites!"
    echo "   nano config.toml"
else
    echo "✅ config.toml already exists"
fi

echo ""
echo "================================================"
echo "Testing DownDetector..."
echo "================================================"
echo ""

# Test run
php check.php

echo ""
echo "================================================"
echo "Setup Complete!"
echo "================================================"
echo ""
echo "Next steps:"
echo "1. Edit config.toml to add your sites"
echo "2. Test manually: php check.php"
echo "3. Add to crontab: crontab -e"
echo "   * * * * * cd $(pwd) && php check.php >> var/log/cron.log 2>&1"
echo ""
echo "Check logs:"
echo "  - tail -f var/log/downdetector_$(date +%Y-%m-%d).log"
echo "  - tail -f var/log/cron.log"
echo ""
echo "View data:"
echo "  - ls -lah data/"
echo "  - cat data/*/$(date +%Y-%m-%d).json"
echo ""
