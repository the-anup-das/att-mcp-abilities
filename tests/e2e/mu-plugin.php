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
