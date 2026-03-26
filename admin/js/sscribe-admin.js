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

		init: function () {
			this.bindEvents();
			this.onLanguageChange();
			this.updateTimeEstimate();
		},

		bindEvents: function () {
			$('#sscribe-export-btn').on('click', $.proxy(this.startExport, this));
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
		},

		onLanguageChange: function () {
			if (this.isProcessing) {
				return;
			}

			var language = $('input[name="sscribe_language"]:checked').val() || '';

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				data: {
					action: 'sscribe_get_status_counts',
					nonce: sscribe_data.nonce,
					language: language
				},
				success: function (response) {
					if (response.success && response.data.counts) {
						var counts = response.data.counts;
						var firstNonZeroSelected = false;

						$('.sscribe-status-card-label').each(function () {
							var $label = $(this);
							var $input = $label.find('input[type="radio"]');
							var status = $input.val();
							var count = counts[status] || 0;

							$label.find('.sscribe-status-count').text(count);

							if (count === 0) {
								$input.prop('disabled', true).prop('checked', false);
								$label.addClass('sscribe-status-disabled');
							} else {
								$input.prop('disabled', false);
								$label.removeClass('sscribe-status-disabled');
								if (!firstNonZeroSelected) {
									$input.prop('checked', true);
									firstNonZeroSelected = true;
								}
							}
						});

						SScribe.updateTimeEstimate();
					}
				}
			});
		},

		onFormatChange: function () {
			this.updateTimeEstimate();
			this.updateExportButton();
		},

		updateTimeEstimate: function () {
			var status = $('input[name="sscribe_post_status"]:checked').val() || 'publish';
			var count = parseInt($('.sscribe-status-card-label:not(.sscribe-status-disabled) .sscribe-status-count').first().text()) || 0;
			
			this.selectedPageCount = count;

			var format = $('input[name="sscribe_format"]:checked').val() || 'all';
			var estimate = '';

			if (format === 'all') {
				var totalSeconds = (1.2 + 8 + 1 + 0.5) * count;
				var minutes = Math.ceil(totalSeconds / 60);
				if (minutes < 60) {
					estimate = 'Estimated time: ~' + minutes + ' ' + (minutes === 1 ? 'minute' : 'minutes');
				} else {
					var hours = Math.floor(minutes / 60);
					var mins = minutes % 60;
					estimate = 'Estimated time: ~' + hours + 'h ' + mins + 'm';
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
					estimate = 'Estimated time: ~' + Math.ceil(seconds) + ' seconds';
				} else {
					var mins = Math.ceil(seconds / 60);
					estimate = 'Estimated time: ~' + mins + ' ' + (mins === 1 ? 'minute' : 'minutes');
				}
			}

			$('#sscribe-time-estimate-text').text(estimate);
			this.updateExportButton();
		},

		updateExportButton: function () {
			var hasLanguage = $('input[name="sscribe_language"]:checked').length > 0;
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

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
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
				error: function () {
					SScribe.showError(sscribe_data.strings.error);
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
				data: {
					action: 'sscribe_process_batch',
					nonce: sscribe_data.nonce,
					session_id: this.sessionId
				},
				success: function (response) {
					if (response.success) {
						var data = response.data;

						SScribe.updateProgress(data.percentage);
						SScribe.updateStatus(data.message);

						if (data.current_page) {
							$('#sscribe-current-page').text(data.current_page).show();
						}

						if (data.time_remaining !== undefined && data.time_remaining > 0) {
							var minutes = Math.floor(data.time_remaining / 60);
							var seconds = data.time_remaining % 60;
							var timeStr = '';
							if (minutes > 0) {
								timeStr = minutes + ' min ' + seconds + ' sec remaining';
							} else {
								timeStr = seconds + ' sec remaining';
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
						SScribe.showError(response.data.message, isCancelled);
					}
				},
				error: function () {
					SScribe.showError(sscribe_data.strings.error);
				}
			});
		},

		cancelExport: function (e) {
			e.preventDefault();

			if (!this.sessionId) {
				return;
			}

			$('#sscribe-cancel-btn').prop('disabled', true).text('Cancelling...');

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				data: {
					action: 'sscribe_cancel_export',
					nonce: scribe_data.nonce,
					session_id: this.sessionId
				},
				success: function () {
					SScribe.isProcessing = false;
					SScribe.sessionId = null;
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

				setTimeout(function () {
					window.location.reload();
				}, 3000);
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
			$('.sscribe-action-row').slideUp(200);
			$('#sscribe-download-area').addClass('sscribe-hidden');
			$('#sscribe-error-area').addClass('sscribe-hidden');
			$('#sscribe-progress-area').removeClass('sscribe-hidden').hide().fadeIn(400);
			$('#sscribe-current-page').text('').hide();
			$('#sscribe-time-remaining').text('').hide();
			$('#sscribe-cancel-btn').prop('disabled', false).text('Cancel Export');
			this.updateProgress(0);
		},

		showError: function (message, isCancelled) {
			this.isProcessing = false;
			$('.sscribe-action-row').slideDown(200);
			$('#sscribe-progress-area').fadeOut(200);
			$('#sscribe-error-text').text(message);
			$('#sscribe-error-area').removeClass('sscribe-hidden').hide().fadeIn(300);

			if (isCancelled) {
				this.sessionId = null;
			}
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
				async: false,
				data: {
					action: 'sscribe_clear_session',
					nonce: sscribe_data.nonce
				},
				complete: function () {
					self.sessionId = null;
					self.isProcessing = false;
					self.resetUI();
					$('.sscribe-action-row').slideDown(200);
				}
			});
		},

		deleteExport: function (e) {
			e.preventDefault();
			var $btn = $(e.currentTarget);
			var filename = $btn.data('filename');

			if (!confirm(sscribe_data.strings.confirm_delete || 'Delete this export?')) {
				return;
			}

			$.ajax({
				url: scribe_data.ajaxurl,
				type: 'POST',
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
								$('#sscribe-history-table').html('<div class="sscribe-history-empty"><em>Your recent export packages will appear here.</em></div>');
							}
						});
					} else {
						alert(response.data.message || 'Failed to delete export.');
					}
				}
			});
		},

		showExportLog: function (e) {
			e.preventDefault();
			var $btn = $(e.currentTarget);
			var filename = $btn.data('filename');

			$('#sscribe-log-modal').removeClass('sscribe-hidden');
			$('#sscribe-log-content').html('<div class="sscribe-log-loading"><span>Loading log...</span></div>');

			$.ajax({
				url: scribe_data.ajaxurl,
				type: 'POST',
				data: {
					action: 'sscribe_get_export_log',
					nonce: scribe_data.download_nonce,
					file: filename
				},
				success: function (response) {
					if (response.success && response.data.log) {
						SScribe.renderLog(response.data.log);
					} else {
						$('#sscribe-log-content').html('<div class="sscribe-log-empty"><p>' + (response.data.message || 'Log not found.') + '</p></div>');
					}
				},
				error: function () {
					$('#sscribe-log-content').html('<div class="sscribe-log-empty"><p>Failed to load log.</p></div>');
				}
			});
		},

		renderLog: function (log) {
			var html = '<div class="sscribe-log-summary">';
			html += '<div class="sscribe-log-stat"><strong>Total:</strong> ' + (log.total_pages || 0) + ' pages</div>';
			html += '<div class="sscribe-log-stat"><strong>Success:</strong> <span class="sscribe-log-success">' + (log.success || 0) + '</span></div>';
			html += '<div class="sscribe-log-stat"><strong>Failed:</strong> <span class="sscribe-log-failed">' + (log.failed || 0) + '</span></div>';
			html += '</div>';

			if (log.pages && Object.keys(log.pages).length > 0) {
				html += '<div class="sscribe-log-pages">';
				html += '<h4>Page Details</h4>';
				html += '<table class="sscribe-log-table"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Time</th><th>Formats</th></tr></thead><tbody>';

				for (var pageId in log.pages) {
					var page = log.pages[pageId];
					var statusClass = page.status === 'success' ? 'sscribe-log-status-success' : 'sscribe-log-status-failed';
					var formats = page.formats ? Object.keys(page.formats).join(', ') : '';

					html += '<tr>';
					html += '<td>' + page.id + '</td>';
					html += '<td>' + this.escapeHtml(page.title || 'Unknown') + '</td>';
					html += '<td class="' + statusClass + '">' + page.status + '</td>';
					html += '<td>' + (page.duration ? page.duration + 's' : '-') + '</td>';
					html += '<td>' + formats + '</td>';
					html += '</tr>';
				}

				html += '</tbody></table></div>';
			}

			if (log.errors && log.errors.length > 0) {
				html += '<div class="sscribe-log-errors">';
				html += '<h4>Errors</h4>';
				html += '<ul>';
				for (var i = 0; i < log.errors.length; i++) {
					html += '<li>' + this.escapeHtml(log.errors[i].message || log.errors[i]) + '</li>';
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
