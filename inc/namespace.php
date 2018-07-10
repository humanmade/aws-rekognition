<?php

namespace HM\AWS_Rekognition;

use Aws\Rekognition\RekognitionClient;
use Exception;
use WP_Error;

const CRON_NAME = 'hm_aws_rekognition_update_image';

/**
 * Register hooks here.
 */
function setup() {
	add_filter( 'wp_update_attachment_metadata', __NAMESPACE__ . '\\on_update_attachment_metadata', 10, 2 );
	add_filter( 'pre_get_posts', __NAMESPACE__ . '\\filter_query' );
	add_filter( 'admin_init', __NAMESPACE__ . '\\Admin\\bootstrap' );
	add_action( CRON_NAME, __NAMESPACE__ . '\\update_attachment_keywords' );
	add_action( 'init', __NAMESPACE__ . '\\attachment_taxonomies', 1000 );
}

/**
 * Use the wp_update_attachment_metadata to make sure the image
 * is (re)processed when the image meta data is changed.
 *
 * @param array $data
 * @param int   $id
 * @return $data
 */
function on_update_attachment_metadata( array $data, int $id ) : array {
	$image_types = [ IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP ];
	$mime        = exif_imagetype( get_attached_file( $id ) );
	if ( ! in_array( $mime, $image_types, true ) ) {
		return $data;
	}
	wp_schedule_single_event( time(), CRON_NAME, [ $id ] );
	return $data;
}

/**
 * Update the image keyworks etc from a given attachment id.
 *
 * @param int $id
 * @return bool|WP_Error
 */
function fetch_labels_for_attachment( int $id ) {
	$file   = get_attached_file( $id );
	$client = get_rekognition_client();

	if ( preg_match( '#s3://(?P<bucket>[^/]+)/(?P<path>.*)#', $file, $matches ) ) {
		$image_args = [
			'S3Object' => [
				'Bucket' => $matches['bucket'],
				'Name'   => $matches['path'],
			],
		];
	} else {
		$image_args = [
			'Bytes' => file_get_contents( $file ),
		];
	}

	try {
		$response = $client->detectLabels( [
			'Image'         => $image_args,
			'MinConfidence' => 80,
		] );
	} catch ( Exception $e ) {
		return new WP_Error( 'aws-error', $e->getMessage() );
	}

	$labels = wp_list_pluck( $response['Labels'], 'Name' );
	return $labels;
}

function update_attachment_keywords( int $id ) {
	$labels = fetch_labels_for_attachment( $id );
	if ( is_wp_error( $labels ) ) {
		update_post_meta( $id, 'hm_aws_rekogition_error', $labels );
		return;
	}

	update_post_meta( $id, 'hm_aws_rekogition_labels', $labels );
	update_post_meta( $id, 'hm_aws_rekogition_keywords', implode( ' ', $labels ) );

	// Set labels as tags.
	wp_set_object_terms( $id, $labels, 'post_tag', true );
}

function get_attachment_labels( int $id ) : array {
	return get_post_meta( $id, 'hm_aws_rekogition_labels', true ) ?: [];
}

/**
 * Get the AWS Rekognition client.
 *
 * @return \Aws\Rekognition\RekognitionClient
 */
function get_rekognition_client() : RekognitionClient {
	if ( defined( 'S3_UPLOADS_KEY' ) && defined( 'S3_UPLOADS_SECRET' ) ) {
		$credentials = [
			'key'    => S3_UPLOADS_KEY,
			'secret' => S3_UPLOADS_SECRET,
		];
	} else {
		$credentials = null;
	}
	return RekognitionClient::factory( [
		'version'     => '2016-06-27',
		'region'      => S3_UPLOADS_REGION,
		'credentials' => $credentials,
	] );
}

/**
 * Filter the SQL clauses of an attachment query to include keywords.
 *
 * @param array $clauses An array including WHERE, GROUP BY, JOIN, ORDER BY,
 *                       DISTINCT, fields (SELECT), and LIMITS clauses.
 * @return array The modified clauses.
 */
function filter_query_attachment_keywords( array $clauses ) : array {
	global $wpdb;
	remove_filter( 'posts_clauses', __FUNCTION__ );

	// Add a LEFT JOIN of the postmeta table so we don't trample existing JOINs.
	$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS sq_hm_aws_rekogition_keywords ON ( {$wpdb->posts}.ID = sq_hm_aws_rekogition_keywords.post_id AND sq_hm_aws_rekogition_keywords.meta_key = 'hm_aws_rekogition_keywords' )";

	$clauses['groupby'] = "{$wpdb->posts}.ID";

	$clauses['where'] = preg_replace(
		"/\({$wpdb->posts}.post_content (NOT LIKE|LIKE) (\'[^']+\')\)/",
		'$0 OR ( sq_hm_aws_rekogition_keywords.meta_value $1 $2 )',
		$clauses['where']
	);

	return $clauses;
}

/**
 * Register / add attachment taxonomies here.
 */
function attachment_taxonomies() {
	register_taxonomy_for_object_type( 'post_tag', 'attachment' );
}
