/**
 * SScribe Admin JavaScript
 *
 * Handles AJAX batch processing with animated progress tracking.
 * Maps to the Award-Winning UI class structure.
 *
 * @package SScribe
 */

(function ($) {
	'use strict';

	var SScribe = {
		sessionId: null,
		isProcessing: false,

		init: function () {
			this.bindEvents();
		},

		bindEvents: function () {
			$( '#sscribe-export-btn' ).on( 'click', $.proxy( this.startExport, this ) );
			$( '#sscribe-retry-btn, #sscribe-error-try-again' ).on( 'click', $.proxy( this.retry, this ) );
			$( '#sscribe-cancel-btn' ).on( 'click', $.proxy( this.cancelExport, this ) );

			$( '.sscribe-lang-card-label' ).on(
				'click',
				function () {
					$( this ).find( 'input[type="radio"]' ).prop( 'checked', true );
				}
			);

			$( 'input[name="sscribe_language"]' ).on( 'change', $.proxy( this.onLanguageChange, this ) );
		},

		onLanguageChange: function () {
			if ( this.isProcessing ) {
				return;
			}

			var language = $( 'input[name="sscribe_language"]:checked' ).val() || '';

			$.ajax(
				{
					url: sscribe_data.ajaxurl,
					type: 'POST',
					data: {
						action: 'sscribe_get_status_counts',
						nonce: sscribe_data.nonce,
						language: language
					},
					success: function (response) {
						if ( response.success && response.data.counts ) {
							var firstEnabled = null;
							$( '.sscribe-status-count' ).each(
								function () {
									var status = $( this ).data( 'status' );
									var count = response.data.counts[ status ] || 0;
									$( this ).text( count );
									
									var $label = $( this ).closest( '.sscribe-status-card-label' );
									var $input = $label.find( 'input[type="radio"]' );
									
									if ( status !== 'all' && count === 0 ) {
										$label.addClass( 'sscribe-disabled' );
										$input.prop( 'disabled', true );
									} else {
										$label.removeClass( 'sscribe-disabled' );
										$input.prop( 'disabled', false );
										if ( !firstEnabled ) {
											firstEnabled = $input;
										}
									}
								}
							);
							
							var $checked = $( 'input[name="sscribe_post_status"]:checked' );
							if ( $checked.prop( 'disabled' ) && firstEnabled ) {
								firstEnabled.prop( 'checked', true );
							}
						}
					}
				}
			);
		},

		startExport: function (e) {
			e.preventDefault();

			if (this.isProcessing) {
				return;
			}

			this.isProcessing = true;
			this.resetUI();
			this.showProgress();

			var language = $( 'input[name="sscribe_language"]:checked' ).val() || '';
			var postStatus = $( 'input[name="sscribe_post_status"]:checked' ).val() || 'publish';

			$.ajax(
				{
					url: sscribe_data.ajaxurl,
					type: 'POST',
					data: {
						action: 'sscribe_start_export',
						nonce: sscribe_data.nonce,
						language: language,
						post_status: postStatus
					},
					success: $.proxy(
						function (response) {
							if (response.success) {
								this.sessionId = response.data.session_id;
								this.updateStatus( response.data.message );
								this.processBatch();
							} else {
								this.showError( response.data.message );
							}
						},
						this
					),
				error: $.proxy(
					function () {
						this.showError( sscribe_data.strings.error );
					},
					this
				)
				}
			);
		},

		processBatch: function () {
			if ( ! this.sessionId) {
				this.showError( sscribe_data.strings.error );
				return;
			}

			$.ajax(
				{
					url: sscribe_data.ajaxurl,
					type: 'POST',
					data: {
						action: 'sscribe_process_batch',
						nonce: sscribe_data.nonce,
						session_id: this.sessionId
					},
					success: $.proxy(
						function (response) {
							if (response.success) {
								var data = response.data;

								this.updateProgress( data.percentage );
								this.updateStatus( data.message );

								if ( data.current_page ) {
									$( '#sscribe-current-page' ).text( data.current_page ).show();
								}

								if ( data.time_remaining !== undefined && data.time_remaining > 0 ) {
									var minutes = Math.floor( data.time_remaining / 60 );
									var seconds = data.time_remaining % 60;
									var timeStr = '';
									if ( minutes > 0 ) {
										timeStr = minutes + ' min ' + seconds + ' sec remaining';
									} else {
										timeStr = seconds + ' sec remaining';
									}
									$( '#sscribe-time-remaining' ).text( timeStr ).show();
								}

								if (data.status === 'complete') {
									this.exportComplete( data );
								} else {
									setTimeout( $.proxy( this.processBatch, this ), 200 );
								}
							} else {
								var errorMsg = response.data.message;
								var isCancelled = response.data.cancelled === true;
								this.showError( errorMsg, isCancelled );
							}
						},
						this
					),
				error: $.proxy(
					function () {
						this.showError( sscribe_data.strings.error );
					},
					this
				)
				}
			);
		},

		cancelExport: function (e) {
			e.preventDefault();

			if ( ! this.sessionId ) {
				return;
			}

			$( '#sscribe-cancel-btn' ).prop( 'disabled', true ).text( 'Cancelling...' );

			$.ajax(
				{
					url: sscribe_data.ajaxurl,
					type: 'POST',
					data: {
						action: 'sscribe_cancel_export',
						nonce: sscribe_data.nonce,
						session_id: this.sessionId
					},
					success: $.proxy(
						function () {
							this.isProcessing = false;
							this.sessionId = null;
						},
						this
					)
				}
			);
		},

		exportComplete: function (data) {
			this.isProcessing = false;
			this.updateProgress( 100 );

			$( '#sscribe-progress-area' ).slideUp(
				300,
				function () {
					$( '#sscribe-download-area' ).removeClass( 'sscribe-hidden' ).hide().fadeIn( 400 );
					$( '#sscribe-download-btn' ).attr( 'href', data.download_url );

					var $iframe = $( '<iframe>' ).css( { display: 'none', width: 0, height: 0 } );
					$( 'body' ).append( $iframe );
					$iframe.attr( 'src', data.download_url );

					setTimeout( function () { $iframe.remove(); }, 30000 );

					setTimeout(
						function () {
							window.location.reload();
						},
						3000
					);
				}
			);
		},

		updateProgress: function (percentage) {
			percentage = Math.min( 100, Math.max( 0, percentage ) );
			$( '#sscribe-progress-bar' ).css( 'width', percentage + '%' );
			$( '#sscribe-progress-text' ).text( percentage + '%' );
		},

		updateStatus: function (message) {
			$( '#sscribe-status-text' ).text( message );
		},

		showProgress: function () {
			$( '.sscribe-action-row' ).slideUp( 200 );
			$( '#sscribe-download-area' ).addClass( 'sscribe-hidden' );
			$( '#sscribe-error-area' ).addClass( 'sscribe-hidden' );
			$( '#sscribe-progress-area' ).removeClass( 'sscribe-hidden' ).hide().fadeIn( 400 );
			$( '#sscribe-current-page' ).text( '' ).hide();
			$( '#sscribe-time-remaining' ).text( '' ).hide();
			$( '#sscribe-cancel-btn' ).prop( 'disabled', false ).text( 'Cancel Export' );
			this.updateProgress( 0 );
		},

		showError: function (message, isCancelled) {
			this.isProcessing = false;
			$( '.sscribe-action-row' ).slideDown( 200 );
			$( '#sscribe-progress-area' ).fadeOut( 200 );
			$( '#sscribe-error-text' ).text( message );
			$( '#sscribe-error-area' ).removeClass( 'sscribe-hidden' ).hide().fadeIn( 300 );

			if ( isCancelled ) {
				this.sessionId = null;
			}
		},

		resetUI: function () {
			$( '#sscribe-download-area' ).addClass( 'sscribe-hidden' );
			$( '#sscribe-error-area' ).addClass( 'sscribe-hidden' );
			$( '#sscribe-progress-area' ).addClass( 'sscribe-hidden' );
			this.updateProgress( 0 );
		},

		retry: function (e) {
			e.preventDefault();
			this.sessionId    = null;
			this.isProcessing = false;
			this.resetUI();
			$( '.sscribe-action-row' ).slideDown( 200 );
		}
	};

	$( document ).ready(
		function () {
			SScribe.init();
		}
	);

})( jQuery );
