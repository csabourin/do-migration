#!/bin/bash
# Fix for "Call to undefined method" errors

echo "=== Fixing Cache and Autoloader Issues ==="
echo ""

# Step 1: Verify we're on the correct branch
echo "1. Checking current branch..."
git branch --show-current
echo ""

# Step 2: Pull latest changes
echo "2. Pulling latest changes..."
git pull origin $(git branch --show-current)
echo ""

# Step 3: Clear Composer autoloader cache
echo "3. Regenerating Composer autoloader..."
if [ -f "composer.json" ]; then
    composer dump-autoload
    echo "Composer autoloader regenerated"
else
    echo "No composer.json found - skipping"
fi
echo ""

# Step 4: Verify the method exists
echo "4. Verifying getDoEnvVarBaseUrl() method exists..."
if grep -q "function getDoEnvVarBaseUrl" modules/helpers/MigrationConfig.php; then
    echo "✓ Method found in MigrationConfig.php"
    grep -n "function getDoEnvVarBaseUrl" modules/helpers/MigrationConfig.php
else
    echo "✗ Method NOT found - this is a problem!"
fi
echo ""

# Step 5: Check PHP opcache
echo "5. Checking PHP opcache status..."
php -r "echo opcache_get_status() ? 'OPcache is ENABLED - may need restart' : 'OPcache is disabled';" 2>/dev/null || echo "Unable to check opcache status"
echo ""
echo ""

echo "=== Recommendations ==="
echo "If the method exists but you still get errors:"
echo "1. Restart PHP-FPM: sudo systemctl restart php-fpm"
echo "2. OR clear opcache: php -r 'opcache_reset();'"
echo "3. OR restart your web server"
echo ""

