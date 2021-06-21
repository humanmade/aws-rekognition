<?php

namespace HM\AWS_Rekognition\Admin;

use HM\AWS_Rekognition;
use WP_Post;

function bootstrap() {
	add_meta_box( 'hm_aws_rekognition_image_labels', __( 'Detected Image Labels', 'hm-aws-rekognition' ), __NAMESPACE__ . '\\output_metabox', 'attachment', 'side' );
	add_filter( 'attachment_fields_to_edit', __NAMESPACE__ . '\\attachment_fields', 10, 2 );
}

function output_metabox( WP_Post $post ) {
	if ( ! wp_attachment_is_image( $post->ID ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo get_keywords_html( $post->ID, 20 );
}

function attachment_fields( $fields, WP_Post $post ) {
	$action  = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_STRING );
	$post_id = filter_input( INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT );

	// We use a metabox on the attachment edit screen.
	if ( $action === 'edit' && intval( $post_id ) === $post->ID ) {
		return $fields;
	}

	// Check attachment type.
	if ( ! wp_attachment_is_image( $post->ID ) ) {
		return $fields;
	}

	$fields['hm-aws-rekognition-labels'] = [
		'label' => __( 'Detected Image labels', 'hm-aws-rekognition' ),
		'input' => 'html',
		'html'  => get_keywords_html( $post->ID ),
	];

	return $fields;
}

function get_keywords_html( $post_id, $limit = 10 ) : string {
	$error_markup = '<style>
		.compat-field-hm-aws-rekognition-labels p { margin: 6px 0; }
	</style>
	<p>%s</p>';

	if ( ! AWS_Rekognition\is_available() ) {
		return sprintf(
			$error_markup,
			esc_html__( 'Image recognition is currently unavailable.', 'hm-aws-rekognition' )
		);
	}

	$labels = AWS_Rekognition\get_attachment_labels( $post_id );

	if ( empty( $labels ) ) {
		// Check if an error was returned.
		$label_errors = get_post_meta( $post_id, 'hm_aws_rekognition_error_labels', true );
		if ( is_wp_error( $label_errors ) ) {
			return sprintf(
				$error_markup,
				esc_html__( 'There was an error analyzing the image.', 'hm-aws-rekognition' )
			);
		}

		// Image is still in processing.
		return sprintf(
			'<style>
				.compat-field-hm-aws-rekognition-labels .spinner { float: none; margin: -2px 5px 0 0; }
				.compat-field-hm-aws-rekognition-labels p { margin: 6px 0; }
			</style>
			<p><span class="spinner is-active"></span> %s</p>',
			esc_html__( 'Analyzing...', 'hm-aws-rekognition' )
		);
	}

	// Limit results.
	$labels = array_slice( $labels, 0, $limit );

	$list = array_reduce( $labels, function ( $output, $label ) {
		$output .= sprintf(
			'<li>
				<span class="hm-aws-rekognition-keywords-label">%1$s</span>
				<span class="hm-aws-rekognition-keywords-label-confidence" style="width:%2$f%%;"><span class="screen-reader-text">%4$s</span> %3$s%%</span>
			</li>',
			esc_html( $label['Name'] ),
			floatval( $label['Confidence'] ),
			number_format( floatval( $label['Confidence'] ), 0 ),
			esc_html__( 'Confidence', 'hm-aws-rekognition' )
		);

		return $output;
	}, '' );

	$output = '
		<style>
			.hm-aws-rekognition-keywords ol { list-style: none; margin: 6px 0; padding: 0; }
			.hm-aws-rekognition-keywords li { color: #fff; position: relative; margin: 1px 0; }
			.hm-aws-rekognition-keywords-label { display: block; position: relative; z-index: 2; padding: 1px 6px; }
			.hm-aws-rekognition-keywords-label-confidence { font-size: 90%; position: absolute; z-index: 1; left: 0; top: 0; bottom: 0; box-sizing: border-box; background: rgba(121,186,73,1); text-align: right; padding: 1px 6px; border-radius: 10px; }
		</style>
		<div class="hm-aws-rekognition-keywords">
			<ol>' . $list . '</ol>
		</div>';

	return $output;
}
