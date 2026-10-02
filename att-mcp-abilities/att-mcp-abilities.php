<?php
/**
 * Plugin Name:       ATT MCP Abilities
 * Plugin URI:        https://github.com/the-anup-das/att-mcp-abilities
 * Description:      Lets AI agents (Claude, Cursor, Codex…) read and — only where you allow it — build, edit, speed up and optimise (SEO) your site through the WordPress Abilities API and MCP Adapter, with per-ability toggles, read-only mode, undo, and an audit log.
 * Version:           1.13.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            ATT
 * Author URI:        https://anuptechtips.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       att-mcp-abilities
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ATT_MCP_VERSION', '1.13.0' );
define( 'ATT_MCP_FILE', __FILE__ );
define( 'ATT_MCP_OPTION', 'att_mcp_abilities' );
define( 'ATT_MCP_ADDONS_OPTION', 'att_mcp_addons' );
define( 'ATT_MCP_CONTROLS_OPTION', 'att_mcp_controls' );
define( 'ATT_MCP_APP_ID', '7d4f2b1e-3c9a-4e8b-9f61-2a5c8d0e4b73' ); // app_id stamped on Application Passwords created from MCP > Connect
define( 'ATT_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATT_MCP_URL', plugin_dir_url( __FILE__ ) );

// MCP Adapter, the MCP server AI clients connect to, is bundled (vendor/). The Jetpack
// Autoloader loads the newest copy when another plugin, or the MCP Adapter plugin
// itself, ships one too.
if ( is_readable( ATT_MCP_DIR . 'vendor/autoload_packages.php' ) ) {
    require_once ATT_MCP_DIR . 'vendor/autoload_packages.php';
}

require_once ATT_MCP_DIR . 'includes/helpers.php';
require_once ATT_MCP_DIR . 'includes/analysis.php';
require_once ATT_MCP_DIR . 'includes/registry.php';
require_once ATT_MCP_DIR . 'includes/addons.php';
require_once ATT_MCP_DIR . 'includes/dispatch.php';
require_once ATT_MCP_DIR . 'includes/governance.php';
require_once ATT_MCP_DIR . 'includes/history.php';
require_once ATT_MCP_DIR . 'includes/admin/admin-ui.php';
require_once ATT_MCP_DIR . 'includes/admin/settings-page.php';
require_once ATT_MCP_DIR . 'includes/admin/config-page.php';
require_once ATT_MCP_DIR . 'includes/admin/activity-page.php';
require_once ATT_MCP_DIR . 'includes/abilities/content.php';
require_once ATT_MCP_DIR . 'includes/abilities/posts.php';
require_once ATT_MCP_DIR . 'includes/abilities/pages.php';
require_once ATT_MCP_DIR . 'includes/abilities/taxonomy.php';
require_once ATT_MCP_DIR . 'includes/abilities/comments.php';
require_once ATT_MCP_DIR . 'includes/abilities/media.php';
require_once ATT_MCP_DIR . 'includes/abilities/users.php';
require_once ATT_MCP_DIR . 'includes/abilities/search.php';
require_once ATT_MCP_DIR . 'includes/abilities/site.php';
require_once ATT_MCP_DIR . 'includes/abilities/menus.php';
require_once ATT_MCP_DIR . 'includes/abilities/history.php';
require_once ATT_MCP_DIR . 'includes/abilities/performance.php';
require_once ATT_MCP_DIR . 'includes/abilities/cache-config.php';
require_once ATT_MCP_DIR . 'includes/abilities/seo.php';
require_once ATT_MCP_DIR . 'includes/abilities/design.php';
require_once ATT_MCP_DIR . 'includes/abilities/site-editor.php';
require_once ATT_MCP_DIR . 'includes/abilities/snippets.php';
require_once ATT_MCP_DIR . 'includes/abilities/generatepress.php';
require_once ATT_MCP_DIR . 'includes/abilities/elementor.php';
require_once ATT_MCP_DIR . 'includes/abilities/quickcal.php';
require_once ATT_MCP_DIR . 'includes/abilities/rank-math.php';
require_once ATT_MCP_DIR . 'includes/abilities/advanced.php';

register_activation_hook( __FILE__, 'att_mcp_activate' );
register_deactivation_hook( __FILE__, 'att_mcp_deactivate' );

add_action( 'plugins_loaded',                   'att_mcp_boot_adapter', 20 ); // start the bundled MCP Adapter
add_filter( 'mcp_adapter_create_default_server', 'att_mcp_keep_default_server', PHP_INT_MAX ); // the server clients connect to
add_action( 'init',                             'att_mcp_maybe_upgrade' );
add_action( 'admin_menu',                       'att_mcp_add_menu' );
add_action( 'admin_enqueue_scripts',            'att_mcp_enqueue_admin_assets' );
add_action( 'admin_notices',                    'att_mcp_dependency_notice' );
add_action( 'admin_init',                       'att_mcp_register_settings' );
add_action( 'admin_post_att_mcp_clear_audit',   'att_mcp_handle_clear_audit' );
add_action( 'att_mcp_daily_digest',             'att_mcp_send_daily_digest' );
add_action( 'wp_abilities_api_categories_init', 'att_mcp_register_ability_category' );
add_action( 'wp_abilities_api_init',            'att_mcp_register_all_abilities' );
add_filter( 'wp_register_ability_args',         'att_mcp_govern_ability_args', 20, 2 ); // other plugins' MCP tools follow the MCP Controls
add_filter( 'mcp_adapter_pre_tool_call',        'att_mcp_on_mcp_tool_call', PHP_INT_MAX ); // an MCP client's call starts…
add_filter( 'mcp_adapter_tool_call_result',     'att_mcp_on_mcp_tool_result', 1 );         // …and ends
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'att_mcp_plugin_action_links' );

function att_mcp_register_all_abilities() {
    // Master kill switch — register nothing when MCP is turned off.
    if ( ! att_mcp_is_active() ) {
        return;
    }
    att_mcp_register_posts_abilities();
    att_mcp_register_pages_abilities();
    att_mcp_register_content_abilities();
    att_mcp_register_taxonomy_abilities();
    att_mcp_register_comments_abilities();
    att_mcp_register_media_abilities();
    att_mcp_register_users_abilities();
    att_mcp_register_search_abilities();
    att_mcp_register_site_abilities();
    att_mcp_register_menus_abilities();
    att_mcp_register_history_abilities();
    att_mcp_register_performance_abilities();
    att_mcp_register_cache_config_abilities();
    att_mcp_register_seo_abilities();
    att_mcp_register_design_abilities();
    att_mcp_register_site_editor_abilities();
    att_mcp_register_snippets_abilities();
    att_mcp_register_generatepress_abilities();
    att_mcp_register_elementor_abilities();
    att_mcp_register_quickcal_abilities();
    att_mcp_register_rank_math_abilities();
    att_mcp_register_advanced_abilities();
}
