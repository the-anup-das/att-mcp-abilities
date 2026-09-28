<?php
// wp-config for the throwaway end-to-end test site (SQLite, served by
// `php -S 127.0.0.1:8899`). setup.sh copies this into tests/e2e/wordpress/.
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );

// Test-only keys — never reuse on a real site.
define( 'AUTH_KEY',         'e2e-auth-key-not-secret' );
define( 'SECURE_AUTH_KEY',  'e2e-secure-auth-key-not-secret' );
define( 'LOGGED_IN_KEY',    'e2e-logged-in-key-not-secret' );
define( 'NONCE_KEY',        'e2e-nonce-key-not-secret' );
define( 'AUTH_SALT',        'e2e-auth-salt-not-secret' );
define( 'SECURE_AUTH_SALT', 'e2e-secure-auth-salt-not-secret' );
define( 'LOGGED_IN_SALT',   'e2e-logged-in-salt-not-secret' );
define( 'NONCE_SALT',       'e2e-nonce-salt-not-secret' );

$table_prefix = 'wp_';

define( 'WP_HOME', 'http://127.0.0.1:8899' );
define( 'WP_SITEURL', 'http://127.0.0.1:8899' );
define( 'WP_ENVIRONMENT_TYPE', 'local' ); // allows Application Passwords over plain HTTP
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );
define( 'DISABLE_WP_CRON', true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
