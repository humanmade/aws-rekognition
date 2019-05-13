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
	add_filter( 'posts_clauses', __NAMESPACE__ . '\\filter_query_attachment_keywords' );
	add_filter( 'admin_init', __NAMESPACE__ . '\\Admin\\bootstrap' );
	add_action( CRON_NAME, __NAMESPACE__ . '\\update_attachment_data' );
	add_action( 'init', __NAMESPACE__ . '\\attachment_taxonomies', 1000 );
	add_filter( 'wp_prepare_attachment_for_js', __NAMESPACE__ . '\\attachment_js', 10, 3 );
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
function fetch_data_for_attachment( int $id ) {
	$file   = get_attached_file( $id );
	$client = get_rekognition_client();

	$region = $client->getRegion();

	// Supported Rekognition regions.
	$supported_regions = [
		'us-east-1',
		'us-east-2',
		'us-west-2',
		'eu-west-1',
		'ap-south-1',
		'ap-northeast-1',
		'ap-northeast-2',
		'ap-southeast-2',
	];

	/**
	 * Pass the S3 object's bucket and location to the AWS Rekogition API, rather than sending the bytes over the wire.
	 *
	 * @param bool $use_s3_object_path
	 * @param int  $id
	 */
	$use_s3_object_path = apply_filters( 'hm.aws.rekognition.use_s3_object_path', in_array( $region, $supported_regions, true ), $id );

	// Set up image argument.
	if ( preg_match( '#s3://(?P<bucket>[^/]+)/(?P<path>.*)#', $file, $matches ) && $use_s3_object_path ) {
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

	// Collect responses & errors.
	$responses = [];

	/**
	 * Allows you to toggle fetching labels from rekognition by passing false.
	 * Defaults to true.
	 *
	 * @param bool $get_labels
	 * @param int  $id
	 */
	$get_labels = apply_filters( 'hm.aws.rekognition.labels', true, $id );

	if ( $get_labels ) {
		try {
			$labels_response = $client->detectLabels( [
				'Image'         => $image_args,
				'MinConfidence' => 60.0,
				'MaxLabels'     => 50,
			] );

			$responses['labels'] = $labels_response['Labels'];
		} catch ( Exception $e ) {
			trigger_error( $e->getMessage(), E_USER_WARNING );
			$responses['labels'] = new WP_Error( 'aws-error', $e->getMessage() );
		}
	}

	/**
	 * Allows you to toggle fetching moderation labels from rekognition by passing false.
	 * Defaults to true.
	 *
	 * @param bool $get_moderation_labels
	 * @param int  $id
	 */
	$get_moderation_labels = apply_filters( 'hm.aws.rekognition.moderation', false, $id );

	if ( $get_moderation_labels ) {
		try {
			$moderation_response = $client->detectModerationLabels( [
				'Image'         => $image_args,
				'MinConfidence' => 60.0,
				'MaxLabels'     => 50,
			] );

			$responses['moderation'] = $moderation_response['ModerationLabels'];
		} catch ( Exception $e ) {
			$responses['moderation'] = new WP_Error( 'aws-error', $e->getMessage() );
		}
	}

	/**
	 * Allows you to toggle fetching faces from Rekognition by passing false.
	 * Defaults to true.
	 *
	 * @param bool $get_faces
	 */
	$get_faces = apply_filters( 'hm.aws.rekognition.faces', false, $id );

	if ( $get_faces ) {
		try {
			$faces_response = $client->detectFaces( [
				'Image'      => $image_args,
				'Attributes' => [ 'ALL' ],
			] );

			$responses['faces'] = $faces_response['FaceDetails'];
		} catch ( Exception $e ) {
			$responses['faces'] = new WP_Error( 'aws-error', $e->getMessage() );
		}
	}

	/**
	 * Allows you to toggle searching for celebrities in images.
	 *
	 * @param bool $get_celebrities
	 * @param int  $id
	 */
	$get_celebrities = apply_filters( 'hm.aws.rekognition.celebrities', false, $id );

	if ( $get_celebrities ) {
		try {
			$celebrities_response = $client->recognizeCelebrities( [
				'Image' => $image_args,
			] );

			$responses['celebrities'] = $celebrities_response['CelebrityFaces'];
		} catch ( Exception $e ) {
			$responses['celebrities'] = new WP_Error( 'aws-error', $e->getMessage() );
		}
	}

	/**
	 * Allows you to toggle detecting text in images.
	 *
	 * @param bool $get_text
	 * @param int  $id
	 */
	$get_text = apply_filters( 'hm.aws.rekognition.text', false, $id );

	if ( $get_text && method_exists( $client, 'detectText' ) ) {
		try {
			$text_response = $client->detectText( [
				'Image' => $image_args,
			] );

			$responses['text'] = $text_response['TextDetections'];
		} catch ( Exception $e ) {
			$responses['text'] = new WP_Error( 'aws-error', $e->getMessage() );
		}
	}

	/**
	 * Allow a convenient place to hook in and use the Rekognition client instance.
	 *
	 * @param Aws\Rekognition\RekognitionClient $client The Rekognition client.
	 * @param int $id The attachment ID.
	 * @param array $image_args The processed image data to set as the 'Image' key 
	 *                          in calls to client methods.
	 */
	do_action( 'hm.aws.rekognition.process', $client, $id, $image_args );

	return $responses;
}

function update_attachment_data( int $id ) {
	$data = fetch_data_for_attachment( $id );
	$post = get_post( $id );

	// Get current alt text.
	$alt_text     = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	$new_alt_text = '';

	// Collect keywords to factor into searches.
	$keywords = [];

	foreach ( $data as $type => $response ) {
		if ( is_wp_error( $response ) ) {
			update_post_meta( $id, "hm_aws_rekognition_error_{$type}", $response );
			continue;
		}

		// Skip processing if response is empty.
		if ( empty( $response ) ) {
			continue;
		}

		// Save the metadata.
		update_post_meta( $id, "hm_aws_rekognition_{$type}", $response );

		// Carry out custom handling & processing.
		switch ( $type ) {
			case 'labels':
				$labels = wp_list_pluck( $response, 'Name' );

				// Add all labels as keywords.
				$keywords = array_merge( $keywords, $labels );
				wp_set_object_terms( $id, $labels, 'rekognition_labels', true );

				// Use best 3 labels as alt text.
				$new_alt_text = implode( ', ', array_slice( $labels, 0, 3 ) );
				break;
			case 'moderation':
				$keywords = array_merge( $keywords, wp_list_pluck( $response, 'Name' ) );
				break;
			case 'faces':
				foreach ( $response as $face ) {
					if ( isset( $face['Gender'] ) ) {
						$keywords[] = $face['Gender']['Value'];
					}
					if ( isset( $face['Emotions'] ) ) {
						$emotions = wp_list_pluck( $face['Emotions'], 'Type' );
						$keywords = array_merge( $keywords, $emotions );
					}
				}
				break;
			case 'celebrities':
				$keywords = array_merge( wp_list_pluck( $response, 'Name' ), $keywords );

				// Use names as alt text.
				$new_alt_text = implode( ', ', wp_list_pluck( $response, 'Name' ) );

				// Set default caption.
				if ( empty( $post->post_excerpt ) ) {
					wp_update_post( [
						'ID'           => $id,
						'post_excerpt' => $new_alt_text,
					] );
				}
				break;
			case 'text':
				$keywords = array_merge( $keywprds, wp_list_pluck( $response, 'DetectedText' ) );
				break;
		}
	}

	// Set alt text.
	if ( empty( $alt_text ) ) {
		/**
		 * Filters the alt text generated from Rekognition data.
		 *
		 * @param string $alt_text The alt text string.
		 * @param array  $data     The full data collection returned.
		 * @param int    $id       The attachment ID.
		 */
		$new_alt_text = apply_filters( 'hm.aws.rekognition.alt_text', $new_alt_text, $data, $id );
		update_post_meta( $id, '_wp_attachment_image_alt', $new_alt_text );
	}

	$keywords = array_filter( $keywords );
	$keywords = array_unique( $keywords );

	/**
	 * Filter the keywords array used to enhance the media library search results.
	 *
	 * @param array $keywords The current keywords array.
	 * @param array $data     The full data collection returned.
	 * @param int   $id       The attachment ID.
	 */
	$keywords = apply_filters( 'hm.aws.rekognition.keywords', $keywords, $data, $id );

	// Store keywords for use with search queries.
	update_post_meta( $id, 'hm_aws_rekognition_keywords', strtolower( implode( "\t", (array) $keywords ) ) );
}

function get_attachment_labels( int $id ) : array {
	return get_post_meta( $id, 'hm_aws_rekognition_labels', true ) ?: [];
}

/**
 * Get the AWS Rekognition client.
 *
 * @return \Aws\Rekognition\RekognitionClient
 */
function get_rekognition_client() : RekognitionClient {
	static $client;
	if ( $client ) {
		return $client;
	}

	$client_args = [
		'version' => '2016-06-27',
		'region'  => 'us-east-1',
	];

	if ( defined( 'AWS_REKOGNITION_REGION' ) ) {
		$client_args['region'] = AWS_REKOGNITION_REGION;
	}

	if ( defined( 'AWS_REKOGNITION_KEY' ) && defined( 'AWS_REKOGNITION_SECRET' ) && defined( 'AWS_REKOGNITION_REGION' ) ) {
		$client_args['credentials'] = [
			'key'    => AWS_REKOGNITION_KEY,
			'secret' => AWS_REKOGNITION_SECRET,
			'region' => AWS_REKOGNITION_REGION,
		];
	}

	/**
	 * Modify the RekognitionClient configuration.
	 *
	 * @param array $client_args Args used to instantiate the RekognitionClient
	 */
	$client_args = apply_filters( 'hm.aws.rekognition.client_args', $client_args );

	/**
	 * Modify the RekognitionClient client object.
	 *
	 * @param null  $client      The client object to override
	 * @param array $client_args Args used to instantiate the RekognitionClient
	 */
	$client = apply_filters( 'hm.aws.rekognition.client', null, $client_args );
	if ( $client ) {
		return $client;
	}

	$client = RekognitionClient::factory( $client_args );
	return $client;
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

	if ( ! preg_match( "/\({$wpdb->posts}.post_content (NOT LIKE|LIKE) (\'[^']+\')\)/", $clauses['where'] ) ) {
		return $clauses;
	}

	// Add a LEFT JOIN of the postmeta table so we don't trample existing JOINs.
	$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS sq_hm_aws_rekognition_keywords ON ( {$wpdb->posts}.ID = sq_hm_aws_rekognition_keywords.post_id AND sq_hm_aws_rekognition_keywords.meta_key = 'hm_aws_rekognition_keywords' )";

	$clauses['groupby'] = "{$wpdb->posts}.ID";

	$clauses['where'] = preg_replace(
		"/\({$wpdb->posts}.post_content (NOT LIKE|LIKE) (\'[^']+\')\)/",
		'$0 OR ( sq_hm_aws_rekognition_keywords.meta_value $1 $2 )',
		$clauses['where']
	);

	return $clauses;
}

/**
 * Register / add attachment taxonomies here.
 */
function attachment_taxonomies() {
	$labels = [
		'name'              => _x( 'Labels', 'taxonomy general name', 'hm-aws-rekognition' ),
		'singular_name'     => _x( 'Label', 'taxonomy singular name', 'hm-aws-rekognition' ),
		'search_items'      => __( 'Search Labels', 'hm-aws-rekognition' ),
		'all_items'         => __( 'All Labels', 'hm-aws-rekognition' ),
		'parent_item'       => __( 'Parent Label', 'hm-aws-rekognition' ),
		'parent_item_colon' => __( 'Parent Label:', 'hm-aws-rekognition' ),
		'edit_item'         => __( 'Edit Label', 'hm-aws-rekognition' ),
		'update_item'       => __( 'Update Label', 'hm-aws-rekognition' ),
		'add_new_item'      => __( 'Add New Label', 'hm-aws-rekognition' ),
		'new_item_name'     => __( 'New Label Name', 'hm-aws-rekognition' ),
		'menu_name'         => __( 'Label', 'hm-aws-rekognition' ),
	];

	$args = [
		'hierarchical'      => false,
		'labels'            => $labels,
		'show_ui'           => false,
		'show_admin_column' => false,
		'query_var'         => true,
		'public'            => false,
		'rewrite'           => [ 'slug' => 'label' ],
	];

	register_taxonomy( 'rekognition_labels', [ 'attachment' ], $args );
}

/**
 * Add extra meta data to attachment model JSON.
 *
 * @param  array $response
 * @param  \WP_Post $attachment
 * @return array
 */
function attachment_js( $response, $attachment ) {
	if ( wp_attachment_is_image( $attachment ) ) {
		return $response;
	}

	$response['rekognition'] = [
		'labels'      => get_post_meta( $attachment->ID, 'hm_aws_rekognition_labels', true ) ?: [],
		'moderation'  => get_post_meta( $attachment->ID, 'hm_aws_rekognition_moderation', true ) ?: [],
		'faces'       => get_post_meta( $attachment->ID, 'hm_aws_rekognition_faces', true ) ?: [],
		'celebrities' => get_post_meta( $attachment->ID, 'hm_aws_rekognition_celebrities', true ) ?: [],
		'text'        => get_post_meta( $attachment->ID, 'hm_aws_rekognition_text', true ) ?: [],
	];

	return $response;
}
