<?php

namespace HM\AWS_Rekognition;

use WP_CLI_Command;

class CLI_Command extends WP_CLI_Command {

	/**
	 * List rekognition keyworkds for a given attachment.
	 *
	 * @subcommand list-data-for-attachment <attachment-id>
	 */
	public function list_data_for_attachment( array $args, array $args_assoc ) {
		print_r( fetch_data_for_attachment( $args[0] ) );
	}

	/**
	 * Update keywords for attachments.
	 *
	 * @subcommand update-keywords
	 * @synopsis [--attachments=<ids>]
	 */
	public function update_keywords( array $args, array $args_assoc ) {
		if ( isset( $args_assoc['attachments'] ) ) {
			$attachments = explode( ',', $args_assoc['attachments'] );
		} else {
			$attachments = get_posts( [ 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => -1 ] );
		}

		foreach ( $attachments as $attachment ) {
			update_attachment_data( $attachment );
		}
	}
}
