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

add_filter( 'wp_update_attachment_metadata', __NAMESPACE__ . '\\on_update_attachment_metadata', 10, 2 );
add_filter( 'posts_clauses', __NAMESPACE__ . '\\filter_query_attachment_keywords' );
add_filter( 'admin_init', __NAMESPACE__ . '\\Admin\\bootstrap' );
add_action( CRON_NAME, __NAMESPACE__ . '\\update_attachment_keywords' );
