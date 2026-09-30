<?php
/**
 * MCP > Settings — MCP Controls (kill switch, read-only, audit, PHP gate, rate
 * limit, digest) plus one card per addon with its per-ability toggles.
 * Behaviour (confirmations, Toggle All, dimming) lives in assets/admin.js.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin-menu icon as a base64 SVG data URI — the form WordPress recolours to
 * match the admin colour scheme (see add_menu_page()). Source: assets/menu-icon.svg.
 */
function att_mcp_menu_icon() {
    $file = plugin_dir_path( ATT_MCP_FILE ) . 'assets/menu-icon.svg';
    $svg  = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
    if ( ! $svg ) {
        return 'dashicons-admin-generic';
    }
    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodes the plugin's own SVG icon as the data URI add_menu_page() requires.
    return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

function att_mcp_add_menu() {
    add_menu_page(
        __( 'ATT MCP Abilities', 'att-mcp-abilities' ),
        __( 'MCP', 'att-mcp-abilities' ),
        'manage_options',
        'att-mcp-abilities',
        'att_mcp_settings_page',
        att_mcp_menu_icon(),
        3
    );
    add_submenu_page( 'att-mcp-abilities', __( 'Settings', 'att-mcp-abilities' ), __( 'Settings', 'att-mcp-abilities' ), 'manage_options', 'att-mcp-abilities' );
    add_submenu_page( 'att-mcp-abilities', __( 'Connect an AI client', 'att-mcp-abilities' ), __( 'Connect', 'att-mcp-abilities' ), 'manage_options', 'att-mcp-config', 'att_mcp_config_page' );
    add_submenu_page( 'att-mcp-abilities', __( 'Activity', 'att-mcp-abilities' ), __( 'Activity', 'att-mcp-abilities' ), 'manage_options', 'att-mcp-activity', 'att_mcp_activity_page' );
}

/** Translated display names for registry groups (group keys stay English). */
function att_mcp_group_label( $group ) {
    $labels = array(
        'Posts'         => __( 'Posts', 'att-mcp-abilities' ),
        'Pages'         => __( 'Pages', 'att-mcp-abilities' ),
        'Content'       => __( 'Any content type', 'att-mcp-abilities' ),
        'Taxonomy'      => __( 'Taxonomy', 'att-mcp-abilities' ),
        'Comments'      => __( 'Comments', 'att-mcp-abilities' ),
        'Media'         => __( 'Media', 'att-mcp-abilities' ),
        'Users'         => __( 'Users', 'att-mcp-abilities' ),
        'Search'        => __( 'Search', 'att-mcp-abilities' ),
        'Menus'         => __( 'Menus', 'att-mcp-abilities' ),
        'Site'          => __( 'Site', 'att-mcp-abilities' ),
        'Performance'   => __( 'Performance & caching', 'att-mcp-abilities' ),
        'SEO'           => __( 'SEO', 'att-mcp-abilities' ),
        'History'       => __( 'History & undo', 'att-mcp-abilities' ),
        'Design'        => __( 'Design', 'att-mcp-abilities' ),
        'Site Editor'   => __( 'Site Editor (block themes)', 'att-mcp-abilities' ),
        'GeneratePress' => __( 'GeneratePress', 'att-mcp-abilities' ),
        'Code Snippets' => __( 'Code Snippets', 'att-mcp-abilities' ),
        'Elementor'     => __( 'Elementor', 'att-mcp-abilities' ),
        'QuickCal'        => __( 'QuickCal appointment booking', 'att-mcp-abilities' ),
        'Rank Math'       => __( "Rank Math's own tools", 'att-mcp-abilities' ),
        'Rank Math Fixes' => __( 'Redirection & 404 fixes (added by this plugin)', 'att-mcp-abilities' ),
        'Other MCP Tools' => __( "Other plugins' and WordPress's tools", 'att-mcp-abilities' ),
        'Advanced'      => __( 'Advanced', 'att-mcp-abilities' ),
    );
    return isset( $labels[ $group ] ) ? $labels[ $group ] : $group;
}

/** One toggle row in the MCP Controls card. */
function att_mcp_control_row( $key, $label, $help, $checked, $badge = '', $extra_attr = array() ) {
    ?>
    <div class="att-row">
        <div>
            <div class="att-al">
                <?php echo esc_html( $label ); ?>
                <?php if ( $badge ) : ?><span class="att-ac att-ac--write"><?php echo esc_html( $badge ); ?></span><?php endif; ?>
            </div>
            <div class="att-ad"><?php echo wp_kses( $help, array( 'strong' => array(), 'code' => array() ) ); ?></div>
        </div>
        <label class="att-sw">
            <input type="checkbox" name="<?php echo esc_attr( ATT_MCP_CONTROLS_OPTION . '[' . $key . ']' ); ?>" value="1"
                <?php checked( $checked ); ?>
                <?php foreach ( $extra_attr as $attr => $value ) { echo ' ' . esc_attr( $attr ) . '="' . esc_attr( $value ) . '"'; } ?>>
            <span class="att-sl"></span>
            <span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
        </label>
    </div>
    <?php
}

function att_mcp_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $registry = att_mcp_ability_registry();
    foreach ( array_keys( $registry ) as $key ) {
        // Other plugins' tools are listed while they exist (their plugin is active).
        if ( 0 !== strpos( $key, 'att/' ) && ! wp_has_ability( $key ) ) {
            unset( $registry[ $key ] );
        }
    }
    $settings = att_mcp_get_settings();
    $groups   = array();
    foreach ( $registry as $key => $cfg ) {
        $groups[ $cfg['group'] ][ $key ] = $cfg;
    }

    $addons         = att_mcp_addons();
    $addon_settings = att_mcp_get_addon_settings();
    $controls       = att_mcp_get_controls();
    $php_locked     = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'ATT_MCP_DISALLOW_PHP' ) && ATT_MCP_DISALLOW_PHP );

    $icons = array(
        'Posts' => '📝', 'Pages' => '📄', 'Content' => '🗂️', 'Taxonomy' => '🏷️', 'Comments' => '💬',
        'Media' => '🖼️', 'Users' => '👥', 'Search' => '🔍', 'Menus' => '🧭', 'Site' => '🌐', 'Performance' => '🚀', 'SEO' => '📈', 'History' => '↩️',
        'Design' => '🎨', 'Site Editor' => '🖌️', 'GeneratePress' => '🧱', 'Code Snippets' => '🧩',
        'Elementor' => '⚡', 'QuickCal' => '📅', 'Rank Math' => '🏆', 'Rank Math Fixes' => '🔀', 'Other MCP Tools' => '🔌', 'Advanced' => '🛠️',
    );

    // Stats reflect EFFECTIVE (addon-gated) state. Other plugins' tools whose addon is
    // off are available as their plugin ships them (unless writes are paused).
    $total   = count( $registry );
    $enabled = 0;
    $writes  = 0;
    foreach ( $registry as $key => $cfg ) {
        $addon_key = att_mcp_addon_for_group( $cfg['group'] );
        $governed  = 0 !== strpos( $key, 'att/' ) && '' !== $addon_key && ! att_mcp_addon_is_enabled( $addon_key );
        $on        = $governed ? ! ( 'write' === $cfg['access'] && att_mcp_writes_paused() ) : att_mcp_is_enabled( $key );
        if ( $on ) {
            $enabled++;
            if ( 'write' === $cfg['access'] ) {
                $writes++;
            }
        }
    }
    ?>
    <div class="att-wrap">
        <?php att_mcp_admin_header( 'settings' ); ?>
        <?php settings_errors(); ?>
        <p class="att-desc">
            <?php
            echo wp_kses(
                __( 'Enable an <strong>addon</strong> for each plugin/theme you use, then toggle its individual <strong>read</strong>/<strong>write</strong> abilities.', 'att-mcp-abilities' ),
                array( 'strong' => array() )
            );
            ?>
            <span class="att-warn"><?php esc_html_e( '⚠ Write abilities modify your live site — enable with care. Most write abilities can be undone (History & undo).', 'att-mcp-abilities' ); ?></span>
        </p>

        <div class="att-stats">
            <div class="att-stat"><div class="att-stat-n"><?php echo esc_html( $total ); ?></div><div class="att-stat-l"><?php esc_html_e( 'Total Abilities', 'att-mcp-abilities' ); ?></div></div>
            <div class="att-stat att-stat--on"><div class="att-stat-n"><?php echo esc_html( $enabled ); ?></div><div class="att-stat-l"><?php esc_html_e( 'Active', 'att-mcp-abilities' ); ?></div></div>
            <div class="att-stat att-stat--wr"><div class="att-stat-n"><?php echo esc_html( $writes ); ?></div><div class="att-stat-l"><?php esc_html_e( 'Write Access Active', 'att-mcp-abilities' ); ?></div></div>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'att_mcp_settings_group' ); ?>

            <div class="att-addon att-controls">
                <div class="att-addon-head">
                    <div class="att-addon-meta">
                        <div class="att-addon-title">🕹️ <?php esc_html_e( 'MCP Controls', 'att-mcp-abilities' ); ?></div>
                        <div class="att-addon-desc"><?php esc_html_e( 'Master switch, read-only safety valve, audit logging, and limits — your emergency brakes.', 'att-mcp-abilities' ); ?></div>
                    </div>
                </div>
                <?php
                att_mcp_control_row( 'active', __( 'MCP enabled', 'att-mcp-abilities' ), __( 'Master switch. When off, <strong>no</strong> abilities are exposed to any agent.', 'att-mcp-abilities' ), $controls['active'] );
                att_mcp_control_row( 'writes_paused', __( 'Pause writes', 'att-mcp-abilities' ), __( 'Agents can still read, but every write ability is blocked. Per-ability toggles are preserved.', 'att-mcp-abilities' ), $controls['writes_paused'], __( 'read-only', 'att-mcp-abilities' ) );
                att_mcp_control_row( 'audit', __( 'Audit logging', 'att-mcp-abilities' ), __( 'Record every ability call in <strong>MCP &gt; Activity</strong> (inputs redacted; newest 500 kept).', 'att-mcp-abilities' ), $controls['audit'] );
                att_mcp_control_row( 'notify', __( 'Daily email summary', 'att-mcp-abilities' ), __( 'Email the site admin once a day when agents made changes (needs audit logging).', 'att-mcp-abilities' ), $controls['notify'] );
                att_mcp_control_row(
                    'allow_php',
                    __( 'Allow PHP', 'att-mcp-abilities' ),
                    $php_locked
                        ? __( 'Locked off: this site sets <code>DISALLOW_FILE_EDIT</code> (or <code>ATT_MCP_DISALLOW_PHP</code>).', 'att-mcp-abilities' )
                        : __( 'Let agents save <strong>PHP</strong> Code Snippets and GeneratePress hook Elements that execute PHP. Off by default — PHP can break your site.', 'att-mcp-abilities' ),
                    $controls['allow_php'] && ! $php_locked,
                    __( 'danger', 'att-mcp-abilities' ),
                    $php_locked ? array( 'disabled' => 'disabled' ) : array( 'data-confirm-php' => '1' )
                );
                ?>
                <div class="att-row">
                    <div>
                        <div class="att-al"><label for="att-mcp-rate-limit"><?php esc_html_e( 'Write limit per minute', 'att-mcp-abilities' ); ?></label></div>
                        <div class="att-ad"><?php esc_html_e( 'Maximum write calls per user per minute (0 = unlimited). Stops a runaway agent.', 'att-mcp-abilities' ); ?></div>
                    </div>
                    <input type="number" id="att-mcp-rate-limit" class="att-num" min="0" max="1000" step="1"
                        name="<?php echo esc_attr( ATT_MCP_CONTROLS_OPTION . '[rate_limit]' ); ?>"
                        value="<?php echo esc_attr( (string) $controls['rate_limit'] ); ?>">
                </div>
            </div>

            <?php foreach ( $addons as $akey => $addon ) :
                $is_core   = ! empty( $addon['core'] );
                $available = att_mcp_addon_is_available( $akey );
                $on        = $is_core ? true : ! empty( $addon_settings[ $akey ] );
                $active    = $available && $on;

                $addon_groups = array();
                foreach ( (array) $addon['groups'] as $g ) {
                    if ( isset( $groups[ $g ] ) ) {
                        $addon_groups[ $g ] = $groups[ $g ];
                    }
                }
                $first_group = ! empty( $addon['groups'] ) ? reset( $addon['groups'] ) : '';
                $icon        = isset( $icons[ $first_group ] ) ? $icons[ $first_group ] : '🧩';
                ?>
            <div class="att-addon" data-addon-card="<?php echo esc_attr( $akey ); ?>">
                <div class="att-addon-head">
                    <div class="att-addon-meta">
                        <div class="att-addon-title">
                            <?php echo esc_html( $icon . ' ' . $addon['label'] ); ?>
                            <?php if ( $is_core ) : ?>
                                <span class="att-badge att-badge--core"><?php esc_html_e( 'Always on', 'att-mcp-abilities' ); ?></span>
                            <?php elseif ( ! $available ) : ?>
                                <span class="att-badge att-badge--off"><?php esc_html_e( 'Not installed', 'att-mcp-abilities' ); ?></span>
                            <?php else : ?>
                                <span class="att-badge att-badge--ok"><?php esc_html_e( 'Detected', 'att-mcp-abilities' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="att-addon-desc"><?php echo esc_html( isset( $addon['description'] ) ? $addon['description'] : '' ); ?></div>
                        <?php
                        if ( ! empty( $addon['governs'] ) && $available ) :
                            // This addon controls other plugins' own MCP tools (governance.php).
                            $own_total  = 0;
                            $own_writes = 0;
                            foreach ( (array) $addon['groups'] as $g ) {
                                foreach ( isset( $groups[ $g ] ) ? $groups[ $g ] : array() as $gkey => $gcfg ) {
                                    if ( 0 !== strpos( $gkey, 'att/' ) ) {
                                        $own_total++;
                                        $own_writes += 'write' === $gcfg['access'] ? 1 : 0;
                                    }
                                }
                            }
                            ?>
                            <?php if ( $own_total ) : ?>
                        <div class="att-addon-desc att-addon-govern">
                            <?php
                            if ( $active ) {
                                esc_html_e( 'Tools you switch off below are hidden from agents and refuse to run over MCP. The ones you allow follow the MCP Controls above and are logged in MCP › Activity; changes made by their write tools can be undone.', 'att-mcp-abilities' );
                            } else {
                                /* translators: 1: number of tools, 2: number of them that change things */
                                echo esc_html( sprintf( __( 'These %1$d MCP tools (%2$d can change your site) are offered to agents by the plugins that add them. While this addon is off they stay available as those plugins ship them — the kill switch, read-only mode, write limit, activity log and undo above still apply to them. Turn the addon on to choose them one by one.', 'att-mcp-abilities' ), $own_total, $own_writes ) );
                            }
                            ?>
                        </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ( ! $is_core ) : ?>
                    <label class="att-sw">
                        <input type="checkbox" class="att-addon-master"
                            name="<?php echo esc_attr( ATT_MCP_ADDONS_OPTION . '[' . $akey . ']' ); ?>"
                            value="1"
                            data-addon="<?php echo esc_attr( $akey ); ?>"
                            <?php disabled( ! $available ); ?>
                            <?php checked( $active ); ?>>
                        <span class="att-sl"></span>
                        <span class="screen-reader-text"><?php echo esc_html( $addon['label'] ); ?></span>
                    </label>
                    <?php endif; ?>
                </div>

                <?php if ( $available && ! empty( $addon_groups ) ) : ?>
                <div class="<?php echo esc_attr( 'att-addon-body' . ( $active ? '' : ' att-dim' ) ); ?>" data-addon-body="<?php echo esc_attr( $akey ); ?>">
                    <?php foreach ( $addon_groups as $gname => $abilities ) : ?>
                    <div class="att-gh">
                        <h3 class="att-gt"><?php echo esc_html( ( isset( $icons[ $gname ] ) ? $icons[ $gname ] . ' ' : '' ) . att_mcp_group_label( $gname ) ); ?></h3>
                        <button type="button" class="att-toggle-all" data-group="<?php echo esc_attr( $gname ); ?>"><?php esc_html_e( 'Toggle All', 'att-mcp-abilities' ); ?></button>
                    </div>
                        <?php foreach ( $abilities as $key => $cfg ) : ?>
                    <div class="att-row">
                        <div>
                            <div class="att-al">
                                <?php echo esc_html( $cfg['label'] ); ?>
                                <span class="<?php echo esc_attr( 'att-ac att-ac--' . $cfg['access'] ); ?>"><?php echo esc_html( 'write' === $cfg['access'] ? __( 'write', 'att-mcp-abilities' ) : __( 'read', 'att-mcp-abilities' ) ); ?></span>
                            </div>
                            <div class="att-ad"><?php echo esc_html( $cfg['description'] ); ?> <code class="att-key"><?php echo esc_html( $key ); ?></code></div>
                        </div>
                        <label class="att-sw">
                            <input type="checkbox"
                                name="<?php echo esc_attr( ATT_MCP_OPTION . '[' . $key . ']' ); ?>"
                                value="1"
                                data-group="<?php echo esc_attr( $gname ); ?>"
                                data-access="<?php echo esc_attr( $cfg['access'] ); ?>"
                                <?php checked( ! empty( $settings[ $key ] ) ); ?>>
                            <span class="att-sl"></span>
                            <span class="screen-reader-text"><?php echo esc_html( $cfg['label'] ); ?></span>
                        </label>
                    </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
                <?php elseif ( ! $available ) : ?>
                <div class="att-addon-note"><?php esc_html_e( "Activate the target plugin/theme to use this addon's abilities. Your saved choices are kept.", 'att-mcp-abilities' ); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="att-savebar">
                <?php submit_button( __( 'Save Settings', 'att-mcp-abilities' ), 'primary', 'submit', false ); ?>
                <span class="att-savenote"><?php esc_html_e( 'Enable an addon to activate its abilities. Changes take effect immediately for active MCP sessions.', 'att-mcp-abilities' ); ?></span>
            </div>
        </form>
        <?php att_mcp_admin_credit(); ?>
    </div>
    <?php
}
