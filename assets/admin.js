jQuery( document ).ready( function () {
	jQuery( '.hm-aws-rekognition-update-labels' ).click( function ( e ) {
		e.preventDefault();
		jQuery.getJSON(
			ajaxurl,
			{ action: 'hm-aws-rekognition-update-labels', nonce: HMAWSRekognition.update_labels_nonce, id: HMAWSRekognition.post_id },
			function ( labels ) {
				jQuery( '#hm-aws-rekognition-labels' ).text( labels.map( function ( label ) {
					return label.Name + ' (' + Math.round( label.Confidence ) + '%)'
				} ).join( ', ' ) );
			}
		);
	} );
} );
