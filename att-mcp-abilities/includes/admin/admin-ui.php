<?php
/**
 * Shared admin UI — enqueues the design-system stylesheet and admin script on
 * MCP screens, renders the branded top bar + nav tabs used by every MCP admin
 * page, the MCP Adapter dependency notice, and the Plugins-screen action link.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** True on this plugin's own admin screens. */
function att_mcp_is_own_screen() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    return 0 === strpos( $page, 'att-mcp' );
}

function att_mcp_enqueue_admin_assets( $hook ) {
    if ( ! att_mcp_is_own_screen() ) {
        return;
    }
    wp_enqueue_style( 'att-mcp-admin', ATT_MCP_URL . 'assets/admin.css', array(), ATT_MCP_VERSION );
    wp_enqueue_script( 'att-mcp-admin', ATT_MCP_URL . 'assets/admin.js', array( 'wp-api-fetch' ), ATT_MCP_VERSION, array( 'in_footer' => true ) );
    wp_localize_script( 'att-mcp-admin', 'attMcpAdmin', array(
        'placeholder' => 'replace-with-your-application-password',
        'i18n'        => array(
            'confirmWrite'  => __( 'This ability can MODIFY live site content. Are you sure you want to enable it?', 'att-mcp-abilities' ),
            'confirmPhp'    => __( 'Allowing PHP lets AI agents run server code (PHP Code Snippets, GeneratePress PHP hooks). A mistake can break your site. Enable only if you fully trust the connected agents. Continue?', 'att-mcp-abilities' ),
            'confirmRevoke' => __( 'Revoke this Application Password? Any AI client using it will lose access immediately.', 'att-mcp-abilities' ),
            'copied'        => __( 'Copied!', 'att-mcp-abilities' ),
            'copyFailed'    => __( 'Copy failed — please select the text and copy it manually.', 'att-mcp-abilities' ),
            'generating'    => __( 'Generating…', 'att-mcp-abilities' ),
            'generateError' => __( 'Could not create the Application Password:', 'att-mcp-abilities' ),
            'revoked'       => __( 'Revoked', 'att-mcp-abilities' ),
            'nameRequired'  => __( 'Please enter a name for this password.', 'att-mcp-abilities' ),
        ),
    ) );
}

/** Branded top bar + nav tabs. $current = settings | config | activity. */
function att_mcp_admin_header( $current = 'settings' ) {
    $tabs = array(
        'settings' => array( 'label' => __( 'Settings', 'att-mcp-abilities' ),     'slug' => 'att-mcp-abilities' ),
        'config'   => array( 'label' => __( 'Connect', 'att-mcp-abilities' ),      'slug' => 'att-mcp-config' ),
        'activity' => array( 'label' => __( 'Activity', 'att-mcp-abilities' ),     'slug' => 'att-mcp-activity' ),
    );
    ?>
    <div class="att-topbar">
        <div class="att-brand">
            <img class="att-logo" src="<?php echo esc_url( ATT_MCP_URL . 'assets/logo.svg' ); ?>" width="42" height="42" alt="">

            <div>
                <div class="att-brand-title"><?php esc_html_e( 'MCP Abilities', 'att-mcp-abilities' ); ?> <span class="att-ver">v<?php echo esc_html( ATT_MCP_VERSION ); ?></span></div>
                <div class="att-brand-sub"><?php esc_html_e( 'Control what AI agents can read & write on your site', 'att-mcp-abilities' ); ?></div>
            </div>
        </div>
        <nav class="att-nav" aria-label="<?php esc_attr_e( 'MCP sections', 'att-mcp-abilities' ); ?>">
            <?php foreach ( $tabs as $key => $tab ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $tab['slug'] ) ); ?>"
                   class="<?php echo esc_attr( 'att-nav-link' . ( $current === $key ? ' is-active' : '' ) ); ?>"
                   <?php if ( $current === $key ) : ?>aria-current="page"<?php endif; ?>><?php echo esc_html( $tab['label'] ); ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php
}

/** Footer credit line shown on MCP screens. */
function att_mcp_admin_credit() {
    $link = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">ATT</a>', esc_url( 'https://anuptechtips.com' ) );
    echo '<p class="att-credit">' . wp_kses(
        sprintf(
            /* translators: 1: plugin version, 2: author link */
            esc_html__( 'ATT MCP Abilities v%1$s — by %2$s', 'att-mcp-abilities' ),
            esc_html( ATT_MCP_VERSION ),
            $link
        ),
        array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
    ) . '</p>';
}

/** True when MCP Adapter (bundled in vendor/, or the separate plugin) is loaded: it exposes abilities as MCP tools. */
function att_mcp_adapter_active() {
    return defined( 'WP_MCP_VERSION' ) || class_exists( 'WP\\MCP\\Plugin' ) || class_exists( 'WP\\MCP\\Core\\McpAdapter' );
}

/**
 * Is the separate MCP Adapter plugin active in a version older than this plugin is
 * tested with (0.6)? Such a copy loads its own classes instead of the bundled ones.
 */
function att_mcp_adapter_plugin_outdated( $version = null ) {
    if ( null === $version ) {
        $version = defined( 'WP_MCP_VERSION' ) ? WP_MCP_VERSION : '';
    }
    return '' !== (string) $version && version_compare( (string) $version, '0.6.0', '<' );
}

/** Warn admins (on the Plugins screen and MCP screens only) when MCP Adapter is missing (an incomplete copy of this plugin). */
function att_mcp_dependency_notice() {
    if ( att_mcp_adapter_active() || ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! att_mcp_is_own_screen() && ( ! $screen || 'plugins' !== $screen->id ) ) {
        return;
    }
    $msg = sprintf(
        /* translators: 1: this plugin's name, 2: required plugin's name */
        __( '%1$s could not load %2$s, which it includes to connect AI clients: this copy of the plugin is incomplete (its "vendor" folder is missing). Install the plugin again from its zip, or install the MCP Adapter plugin.', 'att-mcp-abilities' ),
        '<strong>ATT MCP Abilities</strong>',
        '<strong>MCP Adapter</strong>'
    );
    printf(
        '<div class="notice notice-warning"><p>%s &nbsp;<a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>',
        wp_kses_post( $msg ),
        esc_url( 'https://github.com/WordPress/mcp-adapter' ),
        esc_html__( 'Get MCP Adapter →', 'att-mcp-abilities' )
    );
}

/** "Settings" link on the Plugins screen. */
function att_mcp_plugin_action_links( $links ) {
    array_unshift(
        $links,
        sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=att-mcp-abilities' ) ), esc_html__( 'Settings', 'att-mcp-abilities' ) )
    );
    return $links;
}
