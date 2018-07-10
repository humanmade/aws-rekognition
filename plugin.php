<?php

namespace HM\AWS_Rekognition;

use WP_CLI;

/**
 * Name: AWS Rekognition
 * Plugin Author: Joe Hoyle | Human Made
 */

require __DIR__ . '/inc/namespace.php';
require __DIR__ . '/inc/admin/namespace.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require __DIR__ . '/inc/class-cli-command.php';
	WP_CLI::add_command( 'aws-rekognition', __NAMESPACE__ . '\\CLI_Commands' );
}

add_action( 'plugins_loaded', function () {
	setup();
} );
