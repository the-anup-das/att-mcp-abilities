<?php
/**
 * MCP > Activity — audit feed of every MCP ability call, plus the recent
 * undoable changes (see includes/history.php).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_activity_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $rows    = att_mcp_audit_recent( 200 );
    $enabled = att_mcp_audit_enabled();
    $changes = att_mcp_recent_changes( 10 );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
    $cleared = isset( $_GET['cleared'] );
    ?>
    <div class="att-wrap">
        <?php att_mcp_admin_header( 'activity' ); ?>

        <?php if ( $cleared ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Activity log cleared.', 'att-mcp-abilities' ); ?></p></div>
        <?php endif; ?>

        <p class="att-desc">
            <?php esc_html_e( 'Every AI-agent action via MCP, newest first. Inputs are stored with secret-looking values redacted; the newest 500 entries are kept.', 'att-mcp-abilities' ); ?>
            <?php if ( ! $enabled ) : ?><strong class="att-warn"><?php esc_html_e( 'Logging is currently OFF — enable it in MCP › Settings.', 'att-mcp-abilities' ); ?></strong><?php endif; ?>
        </p>

        <?php if ( $changes ) : ?>
        <h2 class="att-h2"><?php esc_html_e( 'Recent undoable changes', 'att-mcp-abilities' ); ?></h2>
        <p class="att-desc"><?php esc_html_e( 'Ask your agent to run att/undo-change with a change id (or enable "Undo Change" and say "undo the last change").', 'att-mcp-abilities' ); ?></p>
        <table class="att-table att-table--spaced">
            <thead>
                <tr>
                    <th style="width:60px;"><?php esc_html_e( 'ID', 'att-mcp-abilities' ); ?></th>
                    <th style="width:150px;"><?php esc_html_e( 'Time', 'att-mcp-abilities' ); ?></th>
                    <th><?php esc_html_e( 'Change', 'att-mcp-abilities' ); ?></th>
                    <th style="width:120px;"><?php esc_html_e( 'Status', 'att-mcp-abilities' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $changes as $c ) : ?>
                <tr>
                    <td>#<?php echo esc_html( (string) (int) $c->id ); ?></td>
                    <td><?php echo esc_html( $c->created ); ?></td>
                    <td><code><?php echo esc_html( $c->ability ); ?></code> <?php echo esc_html( $c->label ); ?></td>
                    <td><?php echo $c->undone ? esc_html__( 'Undone', 'att-mcp-abilities' ) : esc_html__( 'Applied', 'att-mcp-abilities' ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2 class="att-h2"><?php esc_html_e( 'All calls', 'att-mcp-abilities' ); ?></h2>
        <table class="att-table">
            <thead>
                <tr>
                    <th style="width:150px;"><?php esc_html_e( 'Time', 'att-mcp-abilities' ); ?></th>
                    <th><?php esc_html_e( 'Ability', 'att-mcp-abilities' ); ?></th>
                    <th style="width:70px;"><?php esc_html_e( 'Access', 'att-mcp-abilities' ); ?></th>
                    <th style="width:120px;"><?php esc_html_e( 'User', 'att-mcp-abilities' ); ?></th>
                    <th style="width:70px;"><?php esc_html_e( 'Status', 'att-mcp-abilities' ); ?></th>
                    <th style="width:60px;"><?php esc_html_e( 'ms', 'att-mcp-abilities' ); ?></th>
                    <th><?php esc_html_e( 'Result / Inputs', 'att-mcp-abilities' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7" class="att-empty"><?php esc_html_e( 'No activity recorded yet.', 'att-mcp-abilities' ); ?></td></tr>
                <?php else : foreach ( $rows as $r ) :
                    $user   = $r->user_id ? get_userdata( (int) $r->user_id ) : null;
                    $uname  = $user ? $user->display_name : ( $r->user_id ? '#' . (int) $r->user_id : __( 'system', 'att-mcp-abilities' ) );
                    $is_err = ( 'error' === $r->status );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $r->created ); ?></td>
                        <td><code><?php echo esc_html( $r->ability ); ?></code></td>
                        <td><span class="<?php echo esc_attr( 'att-ac att-ac--' . ( 'write' === $r->access ? 'write' : 'read' ) ); ?>"><?php echo esc_html( $r->access ); ?></span></td>
                        <td><?php echo esc_html( $uname ); ?></td>
                        <td class="<?php echo esc_attr( $is_err ? 'att-st-error' : 'att-st-ok' ); ?>"><?php echo esc_html( $r->status ); ?></td>
                        <td><?php echo esc_html( (string) (int) $r->duration_ms ); ?></td>
                        <td>
                            <div><?php echo esc_html( $r->summary ); ?></div>
                            <?php if ( ! empty( $r->inputs ) && '[]' !== $r->inputs && '{}' !== $r->inputs ) : ?>
                            <details>
                                <summary class="att-summary"><?php esc_html_e( 'inputs', 'att-mcp-abilities' ); ?></summary>
                                <pre class="att-pre"><?php echo esc_html( $r->inputs ); ?></pre>
                            </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $rows ) ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="att-clear-form">
            <input type="hidden" name="action" value="att_mcp_clear_audit">
            <?php wp_nonce_field( 'att_mcp_clear_audit' ); ?>
            <?php submit_button( __( 'Clear activity log', 'att-mcp-abilities' ), 'secondary', 'submit', false ); ?>
        </form>
        <?php endif; ?>

        <?php att_mcp_admin_credit(); ?>
    </div>
    <?php
}
