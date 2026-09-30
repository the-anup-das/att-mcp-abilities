<?php
/**
 * Must-use plugin for the throwaway e2e site only (setup.sh copies it to
 * wordpress/wp-content/mu-plugins/). Admin pages normally trigger WordPress's
 * background update checks to api.wordpress.org; on a slow link those time out and
 * log a core warning, which would fail run-all.sh's "debug.log must stay empty" rule.
 */
remove_action( 'admin_init', '_maybe_update_core' );
remove_action( 'admin_init', '_maybe_update_plugins' );
remove_action( 'admin_init', '_maybe_update_themes' );

// Mail never leaves the throwaway site: wp_mail() is answered here, and the last 20
// messages are kept in the option att_e2e_mail_log for the tests to look at.
add_filter( 'pre_wp_mail', function ( $return, $atts ) {
	if ( null !== $return ) {
		return $return;
	}
	$log   = get_option( 'att_e2e_mail_log', array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'message' => substr( (string) $atts['message'], 0, 4000 ) );
	update_option( 'att_e2e_mail_log', array_slice( $log, -20 ), false );
	return true;
}, 5, 2 );
