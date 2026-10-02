<?php
/**
 * MCP > Connect — generate an Application Password (the credential AI clients
 * use) and copy ready-made connection config for each client.
 *
 * The password is created client-side through core's own REST endpoint
 * (POST /wp/v2/users/me/application-passwords, cookie + wp_rest nonce), so the
 * plaintext is only ever shown once in this browser tab — this plugin never
 * stores or logs it. Revoking uses the same core endpoint.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** One copyable config block. */
function att_mcp_config_block( $id, $title, $instructions, $code ) {
    ?>
    <div class="<?php echo esc_attr( 'att-tab-panel' . ( 'claude' === $id ? ' att-tab-panel-active' : '' ) ); ?>" id="<?php echo esc_attr( 'att-tab-' . $id ); ?>" role="tabpanel">
        <div class="att-config-box">
            <div class="att-instructions">
                <?php foreach ( $instructions as $line ) : ?>
                    <p><?php echo wp_kses( $line, array( 'strong' => array(), 'code' => array() ) ); ?></p>
                <?php endforeach; ?>
            </div>
            <div class="att-config-header">
                <span class="att-config-title"><?php echo esc_html( $title ); ?></span>
                <button type="button" class="att-copy-btn" data-copy-target="<?php echo esc_attr( 'att-code-' . $id ); ?>">
                    <span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <span class="att-copy-label"><?php esc_html_e( 'Copy to Clipboard', 'att-mcp-abilities' ); ?></span>
                </button>
            </div>
            <pre class="att-code-area" id="<?php echo esc_attr( 'att-code-' . $id ); ?>" data-att-config="1"><?php echo esc_html( $code ); ?></pre>
        </div>
    </div>
    <?php
}

function att_mcp_config_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $user        = wp_get_current_user();
    $username    = $user->exists() ? $user->user_login : 'your-wordpress-user-name';
    $api_url     = untrailingslashit( rest_url( ltrim( att_mcp_endpoint_route(), '/' ) ) );
    $placeholder = 'replace-with-your-application-password';
    $site_host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
    $server_key  = 'att-' . trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $site_host ) ), '-' );

    $env = array(
        'WP_API_URL'      => $api_url,
        'WP_API_USERNAME' => $username,
        'WP_API_PASSWORD' => $placeholder,
    );
    $server = array(
        'command' => 'npx',
        'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
        'env'     => $env,
    );
    $config_json = wp_json_encode( array( 'mcpServers' => array( $server_key => $server ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

    $toml = "[mcp_servers.{$server_key}]\n"
        . "command = \"npx\"\n"
        . "args = [\"-y\", \"@automattic/mcp-wordpress-remote@latest\"]\n\n"
        . "[mcp_servers.{$server_key}.env]\n"
        . "WP_API_URL = \"{$api_url}\"\n"
        . "WP_API_USERNAME = \"{$username}\"\n"
        . "WP_API_PASSWORD = \"{$placeholder}\"";

    $claude_code = "claude mcp add {$server_key} \\\n"
        . "  -e WP_API_URL={$api_url} \\\n"
        . "  -e WP_API_USERNAME={$username} \\\n"
        . "  -e \"WP_API_PASSWORD={$placeholder}\" \\\n"
        . '  -- npx -y @automattic/mcp-wordpress-remote@latest';

    $supported = function_exists( 'wp_is_application_passwords_supported' ) && wp_is_application_passwords_supported();
    $available = $supported && wp_is_application_passwords_available_for_user( $user );
    $existing  = ( $available && class_exists( 'WP_Application_Passwords' ) ) ? WP_Application_Passwords::get_user_application_passwords( $user->ID ) : array();

    $step_password = __( 'Use the Application Password from step 1 (it is filled in automatically after you generate one).', 'att-mcp-abilities' );
    ?>
    <div class="att-wrap">
        <?php att_mcp_admin_header( 'config' ); ?>
        <p class="att-desc"><?php esc_html_e( 'Connect an AI client (Claude, Cursor, Codex, Antigravity…) to this site in two steps. Your API URL and username are filled in automatically.', 'att-mcp-abilities' ); ?></p>
        <?php if ( att_mcp_adapter_active() && ! att_mcp_endpoint_available() ) : ?>
            <div class="notice notice-error inline att-endpoint-missing"><p>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: the API URL AI clients connect to */
                        __( '<strong>AI clients cannot connect yet:</strong> the address %s does not exist on this site (they get a 404 "No route was found"). Check that <strong>MCP enabled</strong> is on in MCP › Settings, that the MCP Adapter plugin is active and up to date (0.6 or newer), and that no other plugin switches off the default server of MCP Adapter.', 'att-mcp-abilities' ),
                        '<code>' . esc_html( $api_url ) . '</code>'
                    ),
                    array( 'strong' => array(), 'code' => array() )
                );
                ?>
            </p></div>
        <?php endif; ?>

        <!-- Step 1: Application Password -->
        <div class="att-addon" id="att-mcp-app-passwords">
            <div class="att-addon-head">
                <div class="att-addon-meta">
                    <div class="att-addon-title">🔑 <?php esc_html_e( '1. Create an Application Password', 'att-mcp-abilities' ); ?></div>
                    <div class="att-addon-desc">
                        <?php
                        printf(
                            /* translators: %s: the WordPress username */
                            esc_html__( 'AI clients sign in as your user (%s) with a dedicated Application Password — not your real password. Create one per client so you can revoke it independently.', 'att-mcp-abilities' ),
                            '<strong>' . esc_html( $username ) . '</strong>'
                        );
                        ?>
                    </div>
                </div>
            </div>
            <div class="att-pw-body">
                <?php if ( ! $supported ) : ?>
                    <p class="att-pw-note att-pw-note--warn">
                        <?php
                        echo wp_kses(
                            __( 'Application Passwords are unavailable because this site is not served over <strong>HTTPS</strong>. Enable HTTPS, or for a local test site set <code>WP_ENVIRONMENT_TYPE</code> to <code>local</code> in wp-config.php.', 'att-mcp-abilities' ),
                            array( 'strong' => array(), 'code' => array() )
                        );
                        ?>
                    </p>
                <?php elseif ( ! $available ) : ?>
                    <p class="att-pw-note att-pw-note--warn"><?php esc_html_e( 'Application Passwords are disabled for your account (often by a security plugin). Allow them for administrators, then reload this page.', 'att-mcp-abilities' ); ?></p>
                <?php else : ?>
                    <div class="att-pw-form">
                        <label for="att-mcp-pw-name" class="att-al"><?php esc_html_e( 'Name', 'att-mcp-abilities' ); ?></label>
                        <input type="text" id="att-mcp-pw-name" class="regular-text" maxlength="100"
                            value="<?php echo esc_attr( sprintf( /* translators: %s: date */ __( 'AI agent (MCP) – %s', 'att-mcp-abilities' ), wp_date( 'Y-m-d' ) ) ); ?>">
                        <button type="button" class="button button-primary" id="att-mcp-pw-generate" data-app-id="<?php echo esc_attr( ATT_MCP_APP_ID ); ?>"><?php esc_html_e( 'Generate password', 'att-mcp-abilities' ); ?></button>
                    </div>
                    <div class="att-pw-result" id="att-mcp-pw-result" hidden>
                        <p class="att-pw-note att-pw-note--ok"><?php esc_html_e( 'Your new Application Password — copy it now, it will not be shown again. It has also been inserted into every config below.', 'att-mcp-abilities' ); ?></p>
                        <div class="att-pw-value">
                            <code id="att-mcp-pw-value"></code>
                            <button type="button" class="att-copy-btn" data-copy-target="att-mcp-pw-value">
                                <span class="dashicons dashicons-clipboard" aria-hidden="true"></span> <span class="att-copy-label"><?php esc_html_e( 'Copy', 'att-mcp-abilities' ); ?></span>
                            </button>
                        </div>
                    </div>
                    <p class="att-pw-error" id="att-mcp-pw-error" role="alert" hidden></p>

                    <table class="att-table att-pw-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Name', 'att-mcp-abilities' ); ?></th>
                                <th><?php esc_html_e( 'Created', 'att-mcp-abilities' ); ?></th>
                                <th><?php esc_html_e( 'Last used', 'att-mcp-abilities' ); ?></th>
                                <th style="width:90px;"></th>
                            </tr>
                        </thead>
                        <tbody id="att-mcp-pw-rows">
                            <?php if ( empty( $existing ) ) : ?>
                                <tr class="att-pw-empty"><td colspan="4" class="att-empty"><?php esc_html_e( 'No Application Passwords yet.', 'att-mcp-abilities' ); ?></td></tr>
                            <?php else : foreach ( $existing as $item ) : ?>
                                <tr>
                                    <td>
                                        <?php echo esc_html( $item['name'] ); ?>
                                        <?php if ( isset( $item['app_id'] ) && ATT_MCP_APP_ID === $item['app_id'] ) : ?><span class="att-badge att-badge--core">MCP</span><?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $item['created'] ) ); ?></td>
                                    <td><?php echo $item['last_used'] ? esc_html( wp_date( get_option( 'date_format' ), (int) $item['last_used'] ) ) : esc_html__( 'Never', 'att-mcp-abilities' ); ?></td>
                                    <td><button type="button" class="button button-link-delete att-pw-revoke" data-uuid="<?php echo esc_attr( $item['uuid'] ); ?>"><?php esc_html_e( 'Revoke', 'att-mcp-abilities' ); ?></button></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    <p class="att-pw-foot">
                        <a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Manage all Application Passwords in your profile →', 'att-mcp-abilities' ); ?></a>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Step 2: client config -->
        <h2 class="att-h2"><?php esc_html_e( '2. Add this site to your AI client', 'att-mcp-abilities' ); ?></h2>
        <div class="att-tabs" role="tablist">
            <button type="button" class="att-tab-btn att-tab-active" data-tab="claude" role="tab"><?php esc_html_e( 'Claude Desktop', 'att-mcp-abilities' ); ?></button>
            <button type="button" class="att-tab-btn" data-tab="claude-code" role="tab"><?php esc_html_e( 'Claude Code', 'att-mcp-abilities' ); ?></button>
            <button type="button" class="att-tab-btn" data-tab="cursor" role="tab"><?php esc_html_e( 'Cursor', 'att-mcp-abilities' ); ?></button>
            <button type="button" class="att-tab-btn" data-tab="codex" role="tab"><?php esc_html_e( 'Codex', 'att-mcp-abilities' ); ?></button>
            <button type="button" class="att-tab-btn" data-tab="antigravity" role="tab"><?php esc_html_e( 'Antigravity', 'att-mcp-abilities' ); ?></button>
        </div>

        <?php
        att_mcp_config_block( 'claude', 'claude_desktop_config.json', array(
            $step_password,
            __( 'In Claude Desktop open <strong>Settings &gt; Developer &gt; Edit Config</strong>, merge this into <code>claude_desktop_config.json</code>, save, and restart Claude.', 'att-mcp-abilities' ),
        ), $config_json );
        att_mcp_config_block( 'claude-code', __( 'Terminal', 'att-mcp-abilities' ), array(
            $step_password,
            __( 'Run this in a terminal (Node.js 18+ required). Add <code>-s user</code> after <code>add</code> to make it available in every project.', 'att-mcp-abilities' ),
        ), $claude_code );
        att_mcp_config_block( 'cursor', '~/.cursor/mcp.json', array(
            $step_password,
            __( 'Paste into <code>~/.cursor/mcp.json</code> (global) or <code>.cursor/mcp.json</code> in your project root.', 'att-mcp-abilities' ),
        ), $config_json );
        att_mcp_config_block( 'codex', '~/.codex/config.toml', array(
            $step_password,
            __( 'Paste into your <code>~/.codex/config.toml</code> file.', 'att-mcp-abilities' ),
        ), $toml );
        att_mcp_config_block( 'antigravity', '~/.gemini/config/mcp_config.json', array(
            $step_password,
            __( 'Paste into <code>~/.gemini/config/mcp_config.json</code>, or open <strong>Manage MCP Servers &gt; View raw config</strong> in Antigravity.', 'att-mcp-abilities' ),
        ), $config_json );
        ?>

        <p class="att-desc att-tip">
            <?php esc_html_e( 'Tip: the connection has exactly the permissions of your user, narrowed by the abilities you enable in MCP > Settings. For a least-privilege setup, create a dedicated Editor user for AI agents and generate its Application Password while logged in as that user.', 'att-mcp-abilities' ); ?>
        </p>
        <?php att_mcp_admin_credit(); ?>
    </div>
    <?php
}
