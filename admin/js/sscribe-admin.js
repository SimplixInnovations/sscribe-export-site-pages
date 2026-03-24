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
			
			sscribeDebugLog('Language changed to: ' + (language || 'all'));

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
							var counts = response.data.counts;
							var firstNonZeroSelected = false;

							sscribeDebugLog('Status counts received', counts);

							$( '.sscribe-status-card-label' ).each( function () {
								var $label  = $( this );
								var $input  = $label.find( 'input[type="radio"]' );
								var status  = $input.val();
								var count   = counts[ status ] || 0;

								$label.find( '.sscribe-status-count' ).text( count );

								if ( count === 0 ) {
									$input.prop( 'disabled', true ).prop( 'checked', false );
									$label.addClass( 'sscribe-status-disabled' );
								} else {
									$input.prop( 'disabled', false );
									$label.removeClass( 'sscribe-status-disabled' );
									if ( ! firstNonZeroSelected ) {
										$input.prop( 'checked', true );
										firstNonZeroSelected = true;
									}
								}
							} );
							
							sscribeDebugLog('Status cards updated');
						}
					},
					error: function(xhr, status, error) {
						sscribeDebugLog('ERROR getting status counts: ' + error);
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

			sscribeDebugLog('Starting export', {
				language: language || 'all',
				post_status: postStatus
			});

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
								
								sscribeDebugLog('Export started', {
									session_id: this.sessionId,
									total: response.data.total,
									batch_size: response.data.batch_size,
									debug_info: response.data.debug_info
								});
								
								this.processBatch();
							} else {
								sscribeDebugLog('ERROR: ' + response.data.message);
								this.showError( response.data.message );
							}
						},
						this
					),
					error: $.proxy(
						function (xhr, status, error) {
							sscribeDebugLog('AJAX ERROR: ' + error);
							this.showError( sscribe_data.strings.error );
						},
						this
					)
				}
			);
		},

		processBatch: function () {
			if ( ! this.sessionId) {
				sscribeDebugLog('ERROR: No session ID');
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

								sscribeDebugLog('Batch processed', {
									status: data.status,
									processed: data.processed,
									total: data.total,
									percentage: data.percentage,
									debug_info: data.debug_info
								});

								if (data.status === 'complete') {
									this.exportComplete( data );
								} else {
									setTimeout( $.proxy( this.processBatch, this ), 200 );
								}
							} else {
								var errorMsg = response.data.message;
								var isCancelled = response.data.cancelled === true;
								sscribeDebugLog('ERROR: ' + errorMsg, response.data.debug_info);
								this.showError( errorMsg, isCancelled );
							}
						},
						this
					),
					error: $.proxy(
						function (xhr, status, error) {
							sscribeDebugLog('AJAX ERROR in batch: ' + error);
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
			sscribeDebugLog('Cancelling export...');

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
							sscribeDebugLog('Export cancelled');
						},
						this
					)
				}
			);
		},

		exportComplete: function (data) {
			this.isProcessing = false;
			this.updateProgress( 100 );

			sscribeDebugLog('Export complete!', {
				download_url: data.download_url,
				filename: data.filename,
				errors: data.errors,
				debug_info: data.debug_info
			});

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

// Debug logger function (global)
function scribeDebugLog(message, data) {
	var logEl = document.getElementById('sscribe-live-log');
	if (!logEl) return;
	
	var timestamp = new Date().toLocaleTimeString();
	var entry = document.createElement('div');
	entry.style.marginBottom = '4px';
	entry.style.padding = '4px 8px';
	entry.style.background = 'rgba(0,0,0,0.3)';
	entry.style.borderRadius = '4px';
	
	var msgSpan = document.createElement('span');
	msgSpan.style.color = '#60A5FA';
	msgSpan.textContent = '[' + timestamp + '] ';
	entry.appendChild(msgSpan);
	
	var textSpan = document.createElement('span');
	textSpan.style.color = '#F8FAFC';
	textSpan.textContent = message;
	entry.appendChild(textSpan);
	
	if (data) {
		try {
			var dataSpan = document.createElement('div');
			dataSpan.style.color = '#94A3B8';
			dataSpan.style.fontSize = '10px';
			dataSpan.style.marginLeft = '12px';
			dataSpan.style.marginTop = '4px';
			dataSpan.style.whiteSpace = 'pre-wrap';
			dataSpan.style.wordBreak = 'break-all';
			dataSpan.textContent = JSON.stringify(data, null, 2);
			entry.appendChild(dataSpan);
		} catch(e) {
			// Ignore JSON errors
		}
	}
	
	logEl.appendChild(entry);
	logEl.scrollTop = logEl.scrollHeight;
	
	// Clear initial message
	var firstChild = logEl.firstChild;
	if (firstChild && firstChild.textContent && firstChild.textContent.includes('Waiting')) {
		logEl.innerHTML = '';
		logEl.appendChild(entry);
	}
}
