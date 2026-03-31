/**
 * SScribe Admin JavaScript
 *
 * Handles AJAX batch processing with animated progress tracking.
 *
 * @package SScribe
 */

(function ($) {
	'use strict';

	var SScribe = {
		sessionId: null,
		isProcessing: false,
		selectedPageCount: 0,
		batchRetries: 0,
		maxBatchRetries: 3,

		init: function () {
			if (typeof sscribe_data === 'undefined' || !sscribe_data) {
				return;
			}
			this.bindEvents();
			this.wizardStep(1);
			this.updateTimeEstimate();
		},

		bindEvents: function () {
			$(document).on('click', '#sscribe-export-btn', $.proxy(this.startExport, this));
			$('#sscribe-retry-btn, #sscribe-error-try-again').on('click', $.proxy(this.retry, this));
			$('#sscribe-cancel-btn').on('click', $.proxy(this.cancelExport, this));

			$('.sscribe-lang-card-label').on('click', function () {
				$(this).find('input[type="radio"]').prop('checked', true);
			});

			$('input[name="sscribe_language"]').on('change', $.proxy(this.onLanguageChange, this));
			$('input[name="sscribe_post_status"]').on('change', $.proxy(this.updateTimeEstimate, this));
			$('input[name="sscribe_format"]').on('change', $.proxy(this.onFormatChange, this));

			$(document).on('click', '.sscribe-delete-btn', $.proxy(this.deleteExport, this));
			$(document).on('click', '.sscribe-log-btn', $.proxy(this.showExportLog, this));
			$('#sscribe-modal-close').on('click', $.proxy(this.closeModal, this));

			$(document).on('click', '.sscribe-wizard-next', function () {
				var next = parseInt($(this).data('next'));
				if (next === 2) {
					SScribe.onLanguageChange();
				}
				SScribe.wizardStep(next);
			});
			$(document).on('click', '.sscribe-wizard-back', function () {
				SScribe.wizardStep(parseInt($(this).data('prev')));
			});
		},

		wizardStep: function (step) {
			$('.sscribe-wizard-panel').removeClass('sscribe-wizard-panel-active');
			$('.sscribe-wizard-panel[data-step="' + step + '"]').addClass('sscribe-wizard-panel-active');
			$('.sscribe-wizard-step').removeClass('active completed');
			$('.sscribe-wizard-step').each(function () {
				var s = parseInt($(this).data('step'));
				if (s < step) $(this).addClass('completed');
				if (s === step) $(this).addClass('active');
			});
		},

		onLanguageChange: function () {
			if (this.isProcessing) {
				return;
			}

			var language = $('input[name="sscribe_language"]:checked').val() || '';
			var self = this;

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_status_counts',
					nonce: sscribe_data.nonce,
					language: language
				},
				success: function (response) {
					if (response.success && response.data.counts) {
						var counts = response.data.counts;
						var currentSelected = $('input[name="sscribe_post_status"]:checked');
						var currentStillValid = false;
						var firstAvailable = null;

						$('.sscribe-status-card-label').each(function () {
							var $label = $(this);
							var $input = $label.find('input[type="radio"]');
							var status = $input.val();
							var count = counts[status] || 0;

							$label.find('.sscribe-status-count').text(count);
							$label.attr('data-count', count);

							if (count === 0) {
								$input.prop('disabled', true).prop('checked', false);
								$label.addClass('sscribe-status-disabled');
							} else {
								$input.prop('disabled', false);
								$label.removeClass('sscribe-status-disabled');
								if (!firstAvailable) {
									firstAvailable = $input;
								}
								if (currentSelected.length && currentSelected.val() === status) {
									currentStillValid = true;
								}
							}
						});

						if (!currentStillValid && firstAvailable) {
							firstAvailable.prop('checked', true);
						}

						self.updateTimeEstimate();
					}
				},
				error: function () {
					// Silently fail on status count refresh; user can still export.
				}
			});
		},

		onFormatChange: function () {
			this.updateTimeEstimate();
			this.updateExportButton();
		},

		updateTimeEstimate: function () {
			var $selectedStatus = $('input[name="sscribe_post_status"]:checked');
			var count = 0;
			if ($selectedStatus.length && !$selectedStatus.prop('disabled')) {
				count = parseInt($selectedStatus.closest('.sscribe-status-card-label').find('.sscribe-status-count').text()) || 0;
			}

			this.selectedPageCount = count;

			var format = $('input[name="sscribe_format"]:checked').val() || 'all';
			var estimate = '';
			var strings = sscribe_data.strings || {};

			if (format === 'all') {
				var totalSeconds = (1.2 + 8 + 1 + 0.5) * count;
				var minutes = Math.ceil(totalSeconds / 60);
				if (minutes < 60) {
					estimate = (strings.estimated_time || 'Estimated time:') + ' ~' + minutes + ' ' + (minutes === 1 ? (strings.minute || 'minute') : (strings.minutes || 'minutes'));
				} else {
					var hours = Math.floor(minutes / 60);
					var mins = minutes % 60;
					estimate = (strings.estimated_time || 'Estimated time:') + ' ~' + hours + (strings.hour_suffix || 'h') + ' ' + mins + (strings.minute_suffix || 'm');
				}
			} else {
				var times = {
					'docx': 1.2,
					'pdf': 8,
					'html': 1,
					'markdown': 0.5
				};
				var seconds = (times[format] || 2) * count;
				if (seconds < 60) {
					estimate = (strings.estimated_time || 'Estimated time:') + ' ~' + Math.ceil(seconds) + ' ' + (strings.seconds || 'seconds');
				} else {
					let mins = Math.ceil(seconds / 60);
					estimate = (strings.estimated_time || 'Estimated time:') + ' ~' + mins + ' ' + (mins === 1 ? (strings.minute || 'minute') : (strings.minutes || 'minutes'));
				}
			}

			$('#sscribe-time-estimate-text').text(estimate);
			this.updateExportButton();
		},

		updateExportButton: function () {
			var hasLanguage = $('input[name="sscribe_language"]:checked').length > 0 || $('input[name="sscribe_language"]').length === 0;
			var hasStatus = $('input[name="sscribe_post_status"]:checked').length > 0 && !$('input[name="sscribe_post_status"]:checked').prop('disabled');
			var hasFormat = $('input[name="sscribe_format"]:checked').length > 0;
			var hasPages = this.selectedPageCount > 0;

			var canExport = hasLanguage && hasStatus && hasFormat && hasPages;

			$('#sscribe-export-btn').prop('disabled', !canExport);
		},

		startExport: function (e) {
			e.preventDefault();

			if (this.isProcessing) {
				return;
			}

			this.isProcessing = true;
			this.batchRetries = 0;
			this.resetUI();
			this.showProgress();

			var language = $('input[name="sscribe_language"]:checked').val() || '';
			var postStatus = $('input[name="sscribe_post_status"]:checked').val() || 'publish';
			var format = $('input[name="sscribe_format"]:checked').val() || 'docx';

			var formats = [];
			if (format === 'all') {
				formats = ['docx', 'pdf', 'html', 'markdown'];
			} else {
				formats = [format];
			}

			// Force-clear any stale sessions/locks before starting.
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 15000,
				data: {
					action: 'sscribe_clear_session',
					nonce: sscribe_data.nonce,
					force: true
				},
				complete: function () {
					SScribe.doStartExport(language, postStatus, formats);
				}
			});
		},

		doStartExport: function (language, postStatus, formats) {
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 60000,
				data: {
					action: 'sscribe_start_export',
					nonce: sscribe_data.nonce,
					language: language,
					post_status: postStatus,
					formats: formats
				},
				success: function (response) {
					if (response.success) {
						SScribe.sessionId = response.data.session_id;
						SScribe.updateStatus(response.data.message);
						SScribe.processBatch();
					} else {
						SScribe.showError(response.data.message);
					}
				},
				error: function (xhr) {
					var msg = SScribe.getNetworkErrorMessage(xhr, 'start_export');
					SScribe.showError(msg);
				}
			});
		},

		processBatch: function () {
			if (!this.sessionId) {
				this.showError(sscribe_data.strings.error);
				return;
			}

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 120000,
				data: {
					action: 'sscribe_process_batch',
					nonce: sscribe_data.nonce,
					session_id: this.sessionId
				},
				success: function (response) {
					if (response.success) {
						SScribe.batchRetries = 0;
						var data = response.data;

						SScribe.updateProgress(data.percentage);
						SScribe.updateStatus(data.message);

						if (data.current_page) {
							$('#sscribe-current-page').text(data.current_page).show();
						}

						if (data.time_remaining !== undefined && data.time_remaining > 0) {
							var minutes = Math.floor(data.time_remaining / 60);
							var seconds = data.time_remaining % 60;
							var strings = sscribe_data.strings || {};
							var timeStr = '';
							if (minutes > 0) {
								timeStr = minutes + ' ' + (strings.min_sec_remaining ? strings.min_sec_remaining.replace('%s', seconds) : 'min ' + seconds + ' sec remaining');
							} else {
								timeStr = seconds + ' ' + (strings.sec_remaining || 'sec remaining');
							}
							$('#sscribe-time-remaining').text(timeStr).show();
						}

						if (data.status === 'complete') {
							SScribe.exportComplete(data);
						} else {
							setTimeout($.proxy(SScribe.processBatch, SScribe), 200);
						}
					} else {
						var isCancelled = response.data.cancelled === true;
						// If the server says retry (lock contention), wait and retry instead of failing.
						if (response.data.retry === true) {
							setTimeout($.proxy(SScribe.processBatch, SScribe), 2000);
						} else {
							SScribe.showError(response.data.message, isCancelled);
						}
					}
				},
				error: function (xhr) {
					SScribe.batchRetries++;
					if (SScribe.batchRetries <= SScribe.maxBatchRetries) {
						setTimeout($.proxy(SScribe.processBatch, SScribe), 3000);
					} else {
						var msg = SScribe.getNetworkErrorMessage(xhr, 'process_batch');
						SScribe.showError(msg);
					}
				}
			});
		},

		cancelExport: function (e) {
			e.preventDefault();

			if (!this.sessionId) {
				return;
			}

			$('#sscribe-cancel-btn').prop('disabled', true).text(sscribe_data.strings.cancelling || 'Cancelling...');

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_cancel_export',
					nonce: sscribe_data.nonce,
					session_id: this.sessionId
				},
				success: function () {
					SScribe.isProcessing = false;
					SScribe.sessionId = null;
				},
				error: function () {
					SScribe.isProcessing = false;
					$('#sscribe-cancel-btn').prop('disabled', false).text(sscribe_data.strings.cancel || 'Cancel Export');
				}
			});
		},

		exportComplete: function (data) {
			this.isProcessing = false;
			this.updateProgress(100);

			$('#sscribe-progress-area').slideUp(300, function () {
				$('#sscribe-download-area').removeClass('sscribe-hidden').hide().fadeIn(400);
				$('#sscribe-download-btn').attr('href', data.download_url);

				var $iframe = $('<iframe>').css({ display: 'none', width: 0, height: 0 });
				$('body').append($iframe);
				$iframe.attr('src', data.download_url);

				setTimeout(function () { $iframe.remove(); }, 30000);
			});
		},

		updateProgress: function (percentage) {
			percentage = Math.min(100, Math.max(0, percentage));
			$('#sscribe-progress-bar').css('width', percentage + '%');
			$('#sscribe-progress-text').text(percentage + '%');
		},

		updateStatus: function (message) {
			$('#sscribe-status-text').text(message);
		},

		showProgress: function () {
			$('.sscribe-wizard-panel').removeClass('sscribe-wizard-panel-active');
			$('.sscribe-wizard-steps').hide();
			$('#sscribe-download-area').addClass('sscribe-hidden');
			$('#sscribe-error-area').addClass('sscribe-hidden');
			$('#sscribe-progress-area').removeClass('sscribe-hidden').hide().fadeIn(400);
			$('#sscribe-current-page').text('').hide();
			$('#sscribe-time-remaining').text('').hide();
			$('#sscribe-cancel-btn').prop('disabled', false).text(sscribe_data.strings.cancel || 'Cancel Export');
			this.updateProgress(0);
		},

		showError: function (message, isCancelled) {
			this.isProcessing = false;
			$('.sscribe-wizard-panel').removeClass('sscribe-wizard-panel-active');
			$('.sscribe-wizard-panel[data-step="3"]').addClass('sscribe-wizard-panel-active');
			$('.sscribe-wizard-steps').show();
			$('#sscribe-progress-area').fadeOut(200);
			$('#sscribe-error-text').text(message);

			// Show contextual guidance based on error type.
			var guidance = this.getErrorGuidance(message);
			if (guidance && !isCancelled) {
				$('#sscribe-error-guidance-text').text(guidance);
				$('#sscribe-error-guidance').removeClass('sscribe-hidden');
			} else {
				$('#sscribe-error-guidance').addClass('sscribe-hidden');
			}

			$('#sscribe-error-area').removeClass('sscribe-hidden').hide().fadeIn(300);

			if (isCancelled) {
				this.sessionId = null;
			}
		},

		getErrorGuidance: function (message) {
			if (!message) return '';
			var msg = message.toLowerCase();

			if (msg.indexOf('permission') !== -1 || msg.indexOf('not allowed') !== -1) {
				return 'Your WordPress user role does not have the required capability (manage_options). Please contact your site administrator to grant export permissions, or log in with an Administrator account.';
			}
			if (msg.indexOf('session expired') !== -1 || msg.indexOf('session not found') !== -1 || msg.indexOf('start again') !== -1) {
				return 'The export session was lost — this typically happens when the PHP session or database connection timed out. Click "Try Again" to start a fresh export. If this keeps happening, ask your hosting provider to increase the PHP max_execution_time (recommended: 120s or higher).';
			}
			if (msg.indexOf('session data corrupted') !== -1) {
				return 'The session data in the database became invalid. This can happen if your database ran out of storage or a caching plugin (e.g., WP Rocket, W3 Total Cache) is caching wp_options. Click "Try Again" — the old session has been cleaned up. If it recurs, exclude "sscribe_session_*" from object caching.';
			}
			if (msg.indexOf('rate limit') !== -1 || msg.indexOf('too many requests') !== -1) {
				return 'You have exceeded the request rate limit (60 requests per minute). Please wait about 1 minute and then click "Try Again". This limit protects your server from overload.';
			}
			if (msg.indexOf('no pages found') !== -1) {
				return 'No pages match the selected language and status combination. Go back and verify your selection. If using WPML, ensure the selected language has pages assigned to it.';
			}
			if (msg.indexOf('zip') !== -1 || msg.indexOf('package') !== -1) {
				return 'The server could not create the ZIP archive. Common causes: (1) The wp-content/uploads/sscribe-exports/ directory is not writable — check folder permissions (should be 755). (2) The server ran out of disk space. (3) The PHP zip extension is not installed. Contact your hosting provider if this persists.';
			}
			if (msg.indexOf('timeout') !== -1 || msg.indexOf('timed out') !== -1) {
				return 'The server took too long to respond. This usually happens with large pages or slow server hardware. Click "Try Again" — the plugin processes pages individually, so it will resume from where it left off. If this keeps happening, ask your hosting provider to increase max_execution_time to at least 120 seconds.';
			}
			if (msg.indexOf('memory') !== -1) {
				return 'The server ran out of PHP memory during export. Ask your hosting provider to increase the WordPress memory limit (wp-config.php: WP_MEMORY_LIMIT) to at least 256M. You can also try exporting fewer pages at a time by selecting a specific language.';
			}
			if (msg.indexOf('connection') !== -1 || msg.indexOf('network') !== -1) {
				return 'The connection to your server was interrupted. Check your internet connection, then click "Try Again". If you are behind a proxy or CDN (e.g., Cloudflare), ensure AJAX requests are not being blocked or cached.';
			}
			if (msg.indexOf('invalid language') !== -1) {
				return 'The selected language code is not recognized by WPML. Go back to step 1 and select a valid language. If you recently changed your WPML configuration, refresh this page first.';
			}
			if (msg.indexOf('already have an export') !== -1 || msg.indexOf('in progress') !== -1) {
				return 'A previous export session is still active. Click "Try Again" to force-clear it and start fresh. This can happen if a previous export was interrupted without proper cleanup.';
			}
			if (msg.indexOf('500') !== -1 || msg.indexOf('internal server error') !== -1) {
				return 'Your server encountered an internal error (HTTP 500). Check your server\'s PHP error log for details. Common causes: (1) A conflicting plugin. (2) PHP memory limit too low. (3) A corrupted .htaccess file. Try deactivating other plugins temporarily to isolate the issue.';
			}
			if (msg.indexOf('403') !== -1 || msg.indexOf('forbidden') !== -1) {
				return 'The server rejected the request (HTTP 403 Forbidden). This is usually caused by a security plugin (e.g., Wordfence, Sucuri, iThemes Security) or server-level firewall blocking AJAX requests. Whitelist the SScribe AJAX actions in your security plugin settings.';
			}

			// Generic fallback with actionable steps.
			return 'Click "Try Again" to retry the export. If the problem continues: (1) Refresh the page and try again. (2) Check your browser\'s developer console (F12) for details. (3) Contact your hosting provider to review PHP error logs.';
		},

		getNetworkErrorMessage: function (xhr, context) {
			if (xhr && xhr.status === 0) {
				return 'Connection lost — the server did not respond. Please check your internet connection and try again.';
			}
			if (xhr && xhr.status === 403) {
				return 'Access denied (HTTP 403). A security plugin or firewall may be blocking this request.';
			}
			if (xhr && xhr.status === 500) {
				return 'Internal server error (HTTP 500). The server encountered a problem — check your PHP error log for details.';
			}
			if (xhr && xhr.status === 502) {
				return 'Bad gateway (HTTP 502). Your server or reverse proxy (Nginx/Cloudflare) is unavailable. Please wait a moment and try again.';
			}
			if (xhr && xhr.status === 503) {
				return 'Service unavailable (HTTP 503). Your server is temporarily overloaded or under maintenance. Please wait a moment and try again.';
			}
			if (xhr && xhr.status === 504) {
				return 'Gateway timeout (HTTP 504). The request took too long to process. Ask your hosting provider to increase the PHP max_execution_time.';
			}
			if (xhr && xhr.statusText === 'timeout') {
				return 'Request timed out — the server took too long to respond. This may happen with large exports. Please try again.';
			}

			// Unknown HTTP error.
			var statusCode = (xhr && xhr.status) ? ' (HTTP ' + xhr.status + ')' : '';
			return 'A network error occurred' + statusCode + '. Please check your connection and try again.';
		},

		resetUI: function () {
			$('#sscribe-download-area').addClass('sscribe-hidden');
			$('#sscribe-error-area').addClass('sscribe-hidden');
			$('#sscribe-progress-area').addClass('sscribe-hidden');
			this.updateProgress(0);
		},

		retry: function (e) {
			e.preventDefault();
			var self = this;

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_clear_session',
					nonce: sscribe_data.nonce,
					force: true
				},
				success: function () {
					self.sessionId = null;
					self.isProcessing = false;
					self.resetUI();
					$('.sscribe-wizard-steps').show();
					self.wizardStep(1);
				},
				error: function () {
					self.sessionId = null;
					self.isProcessing = false;
					self.resetUI();
					$('.sscribe-wizard-steps').show();
					self.wizardStep(1);
				}
			});
		},

		deleteExport: function (e) {
			e.preventDefault();
			var $btn = $(e.currentTarget);
			var filename = $btn.data('filename');

			if (!confirm(sscribe_data.strings.confirm_delete || 'Delete this export file?')) {
				return;
			}

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_delete_export',
					nonce: sscribe_data.download_nonce,
					file: filename
				},
				success: function (response) {
					if (response.success) {
						$btn.closest('.sscribe-history-row').fadeOut(300, function () {
							$(this).remove();
							if ($('.sscribe-history-row').length === 0) {
								var emptyMsg = (sscribe_data.strings && sscribe_data.strings.history_empty) || 'Your recent export packages will appear here.';
								$('#sscribe-history-table').html('<div class="sscribe-history-empty"><em></em></div>');
								$('#sscribe-history-table').find('em').text(emptyMsg);
							}
						});
					} else {
						SScribe.showError(response.data.message || ((sscribe_data.strings && sscribe_data.strings.delete_failed) || 'Failed to delete export.'));
					}
				},
				error: function () {
					SScribe.showError((sscribe_data.strings && sscribe_data.strings.delete_failed) || 'Failed to delete export.');
				}
			});
		},

		showExportLog: function (e) {
			e.preventDefault();
			var $btn = $(e.currentTarget);
			var filename = $btn.data('filename');

			$('#sscribe-log-modal').removeClass('sscribe-hidden');
			$('#sscribe-log-content').html('<div class="sscribe-log-loading"><span></span></div>');
			$('#sscribe-log-content').find('span').text((sscribe_data.strings && sscribe_data.strings.loading_log) || 'Loading log...');

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_export_log',
					nonce: sscribe_data.download_nonce,
					file: filename
				},
				success: function (response) {
					if (response.success && response.data.log) {
						SScribe.renderLog(response.data.log);
					} else {
						$('#sscribe-log-content').html('<div class="sscribe-log-empty"><p></p></div>');
						$('#sscribe-log-content').find('p').text(response.data.message || (sscribe_data.strings && sscribe_data.strings.log_not_found) || 'Log not found.');
					}
				},
				error: function () {
					$('#sscribe-log-content').html('<div class="sscribe-log-empty"><p></p></div>');
					$('#sscribe-log-content').find('p').text((sscribe_data.strings && sscribe_data.strings.log_load_failed) || 'Failed to load log.');
				}
			});
		},

		renderLog: function (log) {
			var self = this;
			var strings = sscribe_data.strings || {};
			var html = '<div class="sscribe-log-summary">';
			html += '<div class="sscribe-log-stat"><strong>' + self.escapeHtml(strings.log_total || 'Total:') + '</strong> ' + (log.total_pages || 0) + ' ' + self.escapeHtml(strings.log_pages || 'pages') + '</div>';
			html += '<div class="sscribe-log-stat"><strong>' + self.escapeHtml(strings.log_success_label || 'Success:') + '</strong> <span class="sscribe-log-success">' + (log.success || 0) + '</span></div>';
			html += '<div class="sscribe-log-stat"><strong>' + self.escapeHtml(strings.log_failed_label || 'Failed:') + '</strong> <span class="sscribe-log-failed">' + (log.failed || 0) + '</span></div>';
			html += '</div>';

			if (log.pages && Object.keys(log.pages).length > 0) {
				html += '<div class="sscribe-log-pages">';
				html += '<h4>' + self.escapeHtml(strings.log_page_details || 'Page Details') + '</h4>';
				html += '<table class="sscribe-log-table"><thead><tr>';
				html += '<th>' + self.escapeHtml(strings.log_col_id || 'ID') + '</th>';
				html += '<th>' + self.escapeHtml(strings.log_col_title || 'Title') + '</th>';
				html += '<th>' + self.escapeHtml(strings.log_col_status || 'Status') + '</th>';
				html += '<th>' + self.escapeHtml(strings.log_col_time || 'Time') + '</th>';
				html += '<th>' + self.escapeHtml(strings.log_col_formats || 'Formats') + '</th>';
				html += '</tr></thead><tbody>';

				for (var pageId in log.pages) {
					var page = log.pages[pageId];
					var statusClass = page.status === 'success' ? 'sscribe-log-status-success' : 'sscribe-log-status-failed';
					var formats = page.formats ? Object.keys(page.formats).join(', ') : '';

					html += '<tr>';
					html += '<td>' + page.id + '</td>';
					html += '<td>' + self.escapeHtml(page.title || (strings.log_unknown || 'Unknown')) + '</td>';
					html += '<td class="' + statusClass + '">' + self.escapeHtml(page.status) + '</td>';
					html += '<td>' + (page.duration ? page.duration + (strings.log_seconds_suffix || 's') : (strings.log_no_duration || '-')) + '</td>';
					html += '<td>' + self.escapeHtml(formats) + '</td>';
					html += '</tr>';
				}

				html += '</tbody></table></div>';
			}

			if (log.errors && log.errors.length > 0) {
				html += '<div class="sscribe-log-errors">';
				html += '<h4>' + self.escapeHtml(strings.log_errors || 'Errors') + '</h4>';
				html += '<ul>';
				for (var i = 0; i < log.errors.length; i++) {
					html += '<li>' + self.escapeHtml(log.errors[i].message || log.errors[i]) + '</li>';
				}
				html += '</ul></div>';
			}

			$('#sscribe-log-content').html(html);
		},

		escapeHtml: function (text) {
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		closeModal: function () {
			$('#sscribe-log-modal').addClass('sscribe-hidden');
		}
	};

	$(document).ready(function () {
		SScribe.init();
	});

})(jQuery);
