<?php

define( 'ABSPATH', __DIR__ );

function add_action() {}
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }

require dirname( __DIR__ ) . '/remote-media-fallback.php';

function expect_same( $expected, $actual ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

expect_same( '2021/04/photo one.jpg', Remote_Media_Fallback::relative_upload_path( '/wp-content/uploads/2021/04/photo%20one.jpg', '/wp-content/uploads' ) );
expect_same( null, Remote_Media_Fallback::relative_upload_path( '/wp-content/uploads/../wp-config.php', '/wp-content/uploads' ) );
expect_same( null, Remote_Media_Fallback::relative_upload_path( '/not-uploads/photo.jpg', '/wp-content/uploads' ) );
expect_same( true, Remote_Media_Fallback::is_allowed_content_type( 'image/webp' ) );
expect_same( false, Remote_Media_Fallback::is_allowed_content_type( 'text/html' ) );
expect_same( 'WordPress-Remote-Media-Fallback/0.1.1 (+https://example.com/)', Remote_Media_Fallback::user_agent( 'https://example.com' ) );

echo "All checks passed.\n";
