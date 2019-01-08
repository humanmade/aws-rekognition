<?php

namespace HM\AWS_Rekognition\Admin;

use HM\AWS_Rekognition;
use WP_Post;

function bootstrap() {
	add_meta_box( 'hm_aws_rekognition_image_labels', __( 'Detected Image Labels', 'hm-aws-rekognition' ), __NAMESPACE__ . '\\output_metabox', 'attachment', 'side' );
	add_filter( 'attachment_fields_to_edit', __NAMESPACE__ . '\\attachment_fields', 10, 2 );
}

function output_metabox( WP_Post $post ) {
	echo get_keywords_html( $post->ID, 20 );
}

function attachment_fields( $fields, WP_Post $post ) {
	$action  = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_STRING );
	$post_id = filter_input( INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT );

	// We use a metabox on the attachment edit screen.
	if ( $action === 'edit' && intval( $post_id ) === $post->ID ) {
		return $fields;
	}

	$form_fields['hm-aws-rekognition-labels'] = [
		'label' => __( 'Detected Image labels', 'hm-aws-rekognition' ),
		'input' => 'html',
		'html'  => get_keywords_html( $post->ID ),
	];

	return $form_fields;
}

function get_keywords_html( $post_id, $limit = 10 ) : string {
	$labels = AWS_Rekognition\get_attachment_labels( $post_id );

	if ( empty( $labels ) ) {
		return '';
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
