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

			// Allow clicking anywhere on language option card to select it
			$( '.sscribe-lang-card-label' ).on(
				'click',
				function () {
					// Focus styling handled by CSS :checked pseudoselector, no JS class toggling needed.
					$( this ).find( 'input[type="radio"]' ).prop( 'checked', true );
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

			$.ajax(
				{
					url: sscribe_data.ajaxurl,
					type: 'POST',
					data: {
						action: 'sscribe_start_export',
						nonce: sscribe_data.nonce,
						language: language
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

								if (data.status === 'complete') {
									this.exportComplete( data );
								} else {
									// Continue processing next batch.
									setTimeout( $.proxy( this.processBatch, this ), 200 );
								}
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

		exportComplete: function (data) {
			this.isProcessing = false;
			this.updateProgress( 100 );

			$( '#sscribe-progress-area' ).slideUp(
				300,
				function () {
					$( '#sscribe-download-area' ).css( 'display', 'flex' ).hide().fadeIn( 400 );
					$( '#sscribe-download-btn' ).attr( 'href', data.download_url );

					// Automatically trigger the download
					window.location.href = data.download_url;

					// Refresh the page after download starts so Recent Exports history populates
					setTimeout(
						function () {
							window.location.reload();
						},
						2500
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
			$( '#sscribe-download-area' ).hide();
			$( '#sscribe-error-area' ).hide();
			$( '#sscribe-progress-area' ).css( 'display', 'flex' ).hide().fadeIn( 400 );
			this.updateProgress( 0 );
		},

		showError: function (message) {
			this.isProcessing = false;
			$( '.sscribe-action-row' ).slideDown( 200 );
			$( '#sscribe-progress-area' ).fadeOut( 200 );
			$( '#sscribe-error-text' ).text( message );
			$( '#sscribe-error-area' ).css( 'display', 'flex' ).hide().fadeIn( 300 );
		},

		resetUI: function () {
			$( '#sscribe-download-area' ).hide();
			$( '#sscribe-error-area' ).hide();
			$( '#sscribe-progress-area' ).hide();
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
