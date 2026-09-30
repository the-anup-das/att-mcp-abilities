<?php
/**
 * What an AI agent sees of Rank Math's own tools over a real MCP session,
 * after integrations.php (seo-by-rank-math rm-tools) turned the Rank Math addon
 * on and switched rank-math/set-sitemap-settings off: the tools left on are
 * listed and run, the tool switched off is neither listed nor runnable.
 * Needs app-password.txt from mcp-setup.php (run-all.sh creates it).
 */
require __DIR__ . '/mcp-lib.php';

if ( '' === $password ) {
	echo "  (skipped: no app-password.txt — run mcp-setup.php first)\n";
	exit( 0 );
}

echo "== Rank Math's tools over MCP\n";
list( $names, $discover, , $execute ) = mcp_connect();
check( 'adapter tools available', $discover && $execute, implode( ',', $names ) );

list( , $text ) = call_tool( $discover, array() );
check( 'discover lists the Rank Math tools left on', lists_ability( $text, 'rank-math/get-settings' ) && lists_ability( $text, 'rank-math/audit-site-seo' ), $text );
check( '... but not the one switched off in MCP > Settings', ! lists_ability( $text, 'rank-math/set-sitemap-settings' ), $text );
check( '... and lists the fix tools this plugin adds', lists_ability( $text, 'att/rank-math-save-redirection' ) && lists_ability( $text, 'att/bulk-update-seo-meta' ), $text );

list( $err, $text ) = call_tool( $execute, array( 'ability_name' => 'rank-math/get-robots-txt', 'parameters' => (object) array() ) );
check( 'execute rank-math/get-robots-txt', ! $err && '' !== $text, $text );

list( $err, $text ) = call_tool( $execute, array( 'ability_name' => 'rank-math/set-sitemap-settings', 'parameters' => array( 'include_images' => true ) ) );
check( 'the switched-off tool cannot be executed', $err || false !== stripos( $text, 'not exposed' ) || false !== stripos( $text, 'turned off' ), $text );

mcp_finish();
