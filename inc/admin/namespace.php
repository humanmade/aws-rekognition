<?php

namespace HM\AWS_Rekognition\Admin;

use HM\AWS_Rekognition;
use WP_Post;

function bootstrap() {
	add_meta_box( 'hm_aws_rekognition_image_labels', 'Image Labels', __NAMESPACE__ . '\\output_metabox', 'attachment', 'side' );
	add_action( 'wp_ajax_hm-aws-rekognition-update-labels', __NAMESPACE__ . '\\admin_ajax_update_labels' );
}

function output_metabox( WP_Post $post ) {
	wp_enqueue_script( 'hm-aws-rekognition', plugins_url( '/assets/admin.js', dirname( dirname( __FILE__ ) ) ), [ 'jquery' ] );
	wp_localize_script( 'hm-aws-rekognition', 'HMAWSRekognition', [ 'update_labels_nonce' => wp_create_nonce( 'hm-aws-rekognition-update-labels-' . $post->ID ), 'post_id' => $post->ID ] );
	$labels = AWS_Rekognition\get_attachment_labels( $post->ID );
	?>
	<pre style="white-space: pre-wrap" id="hm-aws-rekognition-labels"><?php echo esc_html( implode( ', ', $labels ) ) ?></pre>
	<button class="button button-secondary hm-aws-rekognition-update-labels"><?php _e( 'Re-process', 'aws-rekognition' ) ?></button>
	<?php
}

function admin_ajax_update_labels() {
	error_reporting( E_ALL );
	ini_set( 'display_errors', 'on' );

	$post_id = intval( $_GET['id'] );
	$update = AWS_Rekognition\update_attachment_data( $post_id );

	if ( is_wp_error( $update ) ) {
		wp_send_json_error( $update );
		exit;
	}

	$labels = AWS_Rekognition\get_attachment_labels( $post_id );

	echo wp_json_encode( $labels );
	exit;
}
