<?php
// Enables every ability + addon. The ability registry is built once per request,
// so this runs in its own process before the test runner.
require __DIR__ . '/bootstrap.php';

$all = array();
foreach ( att_mcp_ability_registry() as $key => $cfg ) {
	$all[ $key ] = true;
}
update_option( 'att_mcp_abilities', $all );
update_option( 'att_mcp_addons', array( 'core' => true, 'design' => true, 'generatepress' => true, 'elementor' => true, 'code_snippets' => true, 'advanced' => true ) );
update_option( 'att_mcp_controls', array( 'active' => true, 'writes_paused' => false, 'audit' => true, 'allow_php' => false, 'rate_limit' => 0, 'notify' => false ) );
update_option( 'permalink_structure', '' );
echo 'Enabled ' . count( $all ) . " abilities\n";
