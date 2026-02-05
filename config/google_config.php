<?php
/**
 * Google OAuth Configuration
 * Loads credentials from .env file for security
 */

// Load environment variables from .env file (if not already loaded)
if (!isset($env)) {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $env = parse_ini_file($envFile);
    } else {
        // Fallback for backward compatibility (if .env doesn't exist)
        $env = [];
    }
}

// Helper function to get environment variable with fallback (only declare if not exists)
if (!function_exists('env')) {
    function env($key, $default = '') {
        global $env;
        return isset($env[$key]) ? $env[$key] : $default;
    }
}

// Google OAuth Client ID (from .env)
define('GOOGLE_CLIENT_ID', env('GOOGLE_CLIENT_ID', '1036942169198-5cd08d6doa4vb4r6442g9k9vdnjgfs7j.apps.googleusercontent.com'));

// Google OAuth Client Secret (from .env)
define('GOOGLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET', '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'));

// Redirect URI (from .env)
define('GOOGLE_REDIRECT_URI', env('GOOGLE_REDIRECT_URI', 'http://localhost/pcuRFID2/google_callback.php'));

// Application name (from .env)
define('GOOGLE_APP_NAME', env('GOOGLE_APP_NAME', 'GateWatchProject'));
?>
