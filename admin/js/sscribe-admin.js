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
		pollBackoff: 0,
		pollBackoffBase: 1000,
		pollBackoffMax: 30000,
		pollJitter: 200,
		finalizePollInterval: 2000,

		init: function () {
			if (typeof sscribe_data === 'undefined' || !sscribe_data) {
				return;
			}
			this.bindEvents();
			this.updateTimeEstimate();
			this.loadSupportInfo();
		},

		bindEvents: function () {
			$(document).on('click', '#sscribe-export-btn', $.proxy(this.startExport, this));
			$(document).on('click', '#sscribe-preview-btn', $.proxy(this.showPreview, this));
			$(document).on('click', '#sscribe-preview-close', $.proxy(this.closePreview, this));
			$(document).on('click', '#sscribe-retry-btn, #sscribe-error-try-again', $.proxy(this.retry, this));
			$(document).on('click', '#sscribe-cancel-btn', $.proxy(this.cancelExport, this));

			$('.sscribe-lang-card-label').on('click', function () {
				$(this).find('input[type="radio"]').prop('checked', true);
			});

			$('input[name="sscribe_post_type"]').on('change', $.proxy(this.onPostTypeChange, this));
			$('input[name="sscribe_language"]').on('change', $.proxy(this.onLanguageChange, this));
			$('input[name="sscribe_post_status"]').on('change', $.proxy(this.updateTimeEstimate, this));
			$('input[name="sscribe_format"]').on('change', $.proxy(this.onFormatChange, this));

			$(document).on('click', '.sscribe-delete-btn', $.proxy(this.deleteExport, this));
			$(document).on('click', '.sscribe-log-btn', $.proxy(this.showExportLog, this));
			$(document).on('click', '#sscribe-support-refresh-btn', $.proxy(this.loadSupportInfo, this));
			$(document).on('click', '#sscribe-support-copy-btn', $.proxy(this.copySupportInfo, this));
			$(document).on('click', '#sscribe-modal-close', $.proxy(this.closeModal, this));
		},

		onPostTypeChange: function () {
			if (this.isProcessing) {
				return;
			}

			var postType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			var language = $('input[name="sscribe_language"]:checked').val() || '';
			var self = this;

			// For 'any', we need separate counts for 'page' and 'post'
			// to correctly populate cards without double-counting.
			if (postType === 'any') {
				$.ajax({
					url: sscribe_data.ajaxurl,
					type: 'POST',
					timeout: 30000,
					data: {
						action: 'sscribe_get_status_counts',
						nonce: sscribe_data.nonce,
						language: language,
						post_type: 'post'
					},
					success: function (response) {
						if (response.success && response.data.counts) {
							var postCounts = response.data.counts;
							var postTotal = postCounts.all || 0;
							var $pageCard = $('.sscribe-post-type-card[data-post-type="page"]');
							var pageTotal = parseInt($pageCard.find('.sscribe-post-type-count').text()) || 0;

							$('.sscribe-post-type-card[data-post-type="post"]').find('.sscribe-post-type-count').text(postTotal + ' Posts');
							$('.sscribe-post-type-card[data-post-type="any"]').find('.sscribe-post-type-count').text((pageTotal + postTotal) + ' Total');

							self.updateStatusCountsForAny(language);
						}
					},
					error: function () {
						var msg = (sscribe_data.strings && sscribe_data.strings.refresh_counts_failed) || 'Failed to refresh counts.';
						$('#sscribe-alert-region').text(msg);
					}
				});
				return;
			}

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_status_counts',
					nonce: sscribe_data.nonce,
					language: language,
					post_type: postType
				},
				success: function (response) {
					if (response.success && response.data.counts) {
						var counts = response.data.counts;
						var totalPosts = counts.all || 0;
						var $postTypeCards = $('.sscribe-post-type-card');

						$postTypeCards.each(function () {
							var $card = $(this);
							var type = $card.data('post-type');
							var countEl = $card.find('.sscribe-post-type-count');

							if (type === 'post') {
								countEl.text(totalPosts + ' Posts');
							}
						});

						self.updateStatusCounts(counts);
						self.updateTimeEstimate();
						self.updateExportButton();
					}
				},
				error: function () {
					var msg = (sscribe_data.strings && sscribe_data.strings.refresh_counts_failed) || 'Failed to refresh counts. Please try again.';
					$('#sscribe-alert-region').text(msg);
				}
			});
		},

		updateStatusCountsForAny: function (language) {
			var self = this;
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_status_counts',
					nonce: sscribe_data.nonce,
					language: language,
					post_type: 'any'
				},
				success: function (response) {
					if (response.success && response.data.counts) {
						self.updateStatusCounts(response.data.counts);
						self.updateTimeEstimate();
						self.updateExportButton();
					}
				}
			});
		},

		updateStatusCounts: function (counts) {
			var currentSelected = $('input[name="sscribe_post_status"]:checked');
			var currentStillValid = false;
			var firstAvailable = null;

			$( '.sscribe-status-card-label' ).each(function () {
				var $label = $(this);
				var $input = $label.find( 'input[type="radio"]' );
				var status = $input.val();
				var count = parseInt( counts[status], 10 ) || 0;

				$label.find( '.sscribe-status-count' ).text( count );
				$label.attr( 'data-count', count );

				if (count === 0) {
					$input.prop( 'disabled', true ).prop( 'checked', false );
					$label.addClass( 'sscribe-status-disabled' );
				} else {
					$input.prop( 'disabled', false );
					$label.removeClass( 'sscribe-status-disabled' );
					if (!firstAvailable) {
						firstAvailable = $input;
					}
					if (currentSelected.length && currentSelected.val() === status) {
						currentStillValid = true;
					}
				}
			});

			if (!currentStillValid && firstAvailable) {
				firstAvailable.prop( 'checked', true );
			}
		},

		onLanguageChange: function () {
			if (this.isProcessing) {
				return;
			}

			var language = $('input[name="sscribe_language"]:checked').val() || '';
			var self = this;

			$('.sscribe-status-card-label').addClass('sscribe-loading');

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_status_counts',
					nonce: sscribe_data.nonce,
					language: language,
					post_type: $('input[name="sscribe_post_type"]:checked').val() || 'page'
				},
				success: function (response) {
					if (response.success && response.data.counts) {
						self.updateStatusCounts(response.data.counts);
						self.updateTimeEstimate();
					}
				},
				error: function () {
					var msg = (sscribe_data.strings && sscribe_data.strings.refresh_counts_failed) || 'Failed to refresh counts. Please try again.';
					$('#sscribe-alert-region').text(msg);
				},
				complete: function () {
					$('.sscribe-status-card-label').removeClass('sscribe-loading');
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
					var mins = Math.ceil(seconds / 60);
					estimate = (strings.estimated_time || 'Estimated time:') + ' ~' + mins + ' ' + (mins === 1 ? (strings.minute || 'minute') : (strings.minutes || 'minutes'));
				}
			}

			$('#sscribe-time-estimate-text').text(estimate);
			this.updateExportButton();
		},

		updateExportButton: function () {
			var hasPostType = $('input[name="sscribe_post_type"]:checked').length > 0;
			var hasLanguage = $('input[name="sscribe_language"]:checked').length > 0 || $('input[name="sscribe_language"]').length === 0;
			var hasStatus = $('input[name="sscribe_post_status"]:checked').length > 0 && !$('input[name="sscribe_post_status"]:checked').prop('disabled');
			var hasFormat = $('input[name="sscribe_format"]:checked').length > 0;
			var hasPages = this.selectedPageCount > 0;

			var canExport = hasPostType && hasLanguage && hasStatus && hasFormat && hasPages;

			$('#sscribe-export-btn').prop('disabled', !canExport);
			$('#sscribe-preview-btn').prop('disabled', !canExport);
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
			var postType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			var format = $('input[name="sscribe_format"]:checked').val() || 'docx';

			var formats = [];
			if (format === 'all') {
				formats = ['docx', 'pdf', 'html', 'markdown'];
			} else {
				formats = [format];
			}

			var self = this;

			// Run pre-flight checks before starting export.
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_preflight_check',
					nonce: sscribe_data.nonce,
					formats: formats
				},
				success: function (response) {
					if (response.success) {
						var diagnostics = response.data;
						
						// If there are errors, show a warning but allow user to proceed.
						if (diagnostics.status === 'error') {
							self.showPreflightWarnings(diagnostics, function () {
								self.proceedWithExport(language, postStatus, postType, formats);
							});
						} else {
							self.proceedWithExport(language, postStatus, postType, formats);
						}
					} else {
						self.proceedWithExport(language, postStatus, postType, formats);
					}
				},
				error: function () {
					self.proceedWithExport(language, postStatus, postType, formats);
				}
			});
		},

		showPreflightWarnings: function (diagnostics, onProceed) {
			var checks = diagnostics.checks || {};
			var errors = [];
			var warnings = [];

			for (var key in checks) {
				if (checks.hasOwnProperty(key)) {
					var check = checks[key];
					if (check.status === 'error') {
						errors.push(check);
					} else if (check.status === 'warning') {
						warnings.push(check);
					}
				}
			}

			var bannerHtml = '<div class="sscribe-preflight-banner">';
			bannerHtml += '<div class="sscribe-preflight-header">';
			bannerHtml += '<span class="sscribe-preflight-icon" aria-hidden="true">⚠</span>';
			bannerHtml += '<h3>' + this.escapeHtml(sscribe_data.strings.preflight_title || 'Export Readiness Check') + '</h3>';
			bannerHtml += '<button type="button" class="sscribe-preflight-close" aria-label="' + this.escapeHtml(sscribe_data.strings.close || 'Close') + '">&times;</button>';
			bannerHtml += '</div>';
			bannerHtml += '<div class="sscribe-preflight-body">';

			if (errors.length > 0) {
				bannerHtml += '<div class="sscribe-preflight-section sscribe-preflight-errors">';
				bannerHtml += '<h4 class="sscribe-preflight-section-title">' + this.escapeHtml(sscribe_data.strings.preflight_errors || 'Critical Issues') + '</h4>';
				bannerHtml += '<ul class="sscribe-preflight-list">';
				for (var i = 0; i < errors.length; i++) {
					bannerHtml += '<li><strong>' + this.escapeHtml(errors[i].name) + ':</strong> ' + this.escapeHtml(errors[i].message);
					if (errors[i].fix) {
						bannerHtml += '<br><em class="sscribe-preflight-fix">' + this.escapeHtml(errors[i].fix) + '</em>';
					}
					bannerHtml += '</li>';
				}
				bannerHtml += '</ul></div>';
			}

			if (warnings.length > 0) {
				bannerHtml += '<div class="sscribe-preflight-section sscribe-preflight-warnings">';
				bannerHtml += '<h4 class="sscribe-preflight-section-title">' + this.escapeHtml(sscribe_data.strings.preflight_warnings || 'Recommendations') + '</h4>';
				bannerHtml += '<ul class="sscribe-preflight-list">';
				for (var j = 0; j < warnings.length; j++) {
					bannerHtml += '<li><strong>' + this.escapeHtml(warnings[j].name) + ':</strong> ' + this.escapeHtml(warnings[j].message);
					if (warnings[j].fix) {
						bannerHtml += '<br><em class="sscribe-preflight-fix">' + this.escapeHtml(warnings[j].fix) + '</em>';
					}
					bannerHtml += '</li>';
				}
				bannerHtml += '</ul></div>';
			}

			bannerHtml += '</div>';
			bannerHtml += '<div class="sscribe-preflight-actions">';
			bannerHtml += '<button type="button" class="sscribe-button sscribe-button-primary sscribe-preflight-proceed">' + this.escapeHtml(sscribe_data.strings.preflight_continue || 'Continue Anyway') + '</button>';
			bannerHtml += '<button type="button" class="sscribe-button sscribe-button-ghost sscribe-preflight-cancel">' + this.escapeHtml(sscribe_data.strings.preflight_cancel || 'Go Back') + '</button>';
			bannerHtml += '</div></div>';

			$('.sscribe-preflight-banner').remove();

			$('.sscribe-workspace').prepend(bannerHtml);

			var $banner = $('.sscribe-preflight-banner');

			$banner.on('click.sscribe-preflight', '.sscribe-preflight-proceed', function () {
				$banner.fadeOut(200, function () { $banner.remove(); });
				onProceed();
			});

			$banner.on('click.sscribe-preflight', '.sscribe-preflight-cancel', function () {
				$banner.fadeOut(200, function () { $banner.remove(); });
				SScribe.isProcessing = false;
				SScribe.resetUI();
			});

			$banner.on('click.sscribe-preflight', '.sscribe-preflight-close', function () {
				$banner.fadeOut(200, function () { $banner.remove(); });
				SScribe.isProcessing = false;
				SScribe.resetUI();
			});

			var bannerOffset = $banner.offset();
			if (bannerOffset) {
				$('html, body').animate({
					scrollTop: bannerOffset.top - 20
				}, 300);
			}
		},

		proceedWithExport: function (language, postStatus, postType, formats) {
			var self = this;

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
					SScribe.doStartExport(language, postStatus, postType, formats);
				}
			});
		},

		doStartExport: function (language, postStatus, postType, formats) {
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 60000,
				data: {
					action: 'sscribe_start_export',
					nonce: sscribe_data.nonce,
					language: language,
					post_status: postStatus,
					post_type: postType,
					formats: formats
				},
				success: function (response) {
					if (response.success) {
						SScribe.sessionId = response.data.session_id;
						SScribe.updateStatus(response.data.message);
						SScribe.processBatch();
					} else {
						SScribe.showError(response.data.message, false, SScribe.normalizeErrorData(response.data));
					}
				},
				error: function (xhr) {
					var msg = SScribe.getNetworkErrorMessage(xhr, 'start_export');
					SScribe.showError(msg);
				}
			});
		},

		scheduleNextBatch: function(retryInMs) {
			var self = this;
			var delay = 0;

			if (typeof retryInMs === 'number' && isFinite(retryInMs) && retryInMs > 0) {
				delay = Math.max(0, Math.floor(retryInMs));
				self.pollBackoff = 0;
			} else {
				var base = self.pollBackoffBase * Math.pow(2, Math.max(0, self.pollBackoff));
				delay = Math.min(self.pollBackoffMax, Math.floor(base));
				if (self.pollBackoffBase * Math.pow(2, self.pollBackoff) < self.pollBackoffMax) {
					self.pollBackoff++;
				}
			}

			var jitter = Math.floor(Math.random() * (self.pollJitter * 2 + 1)) - self.pollJitter;
			delay = Math.max(0, delay + jitter);
			delay = Math.max(300, delay);

			setTimeout(function () {
				self.processBatch();
			}, delay);
		},

		processBatch: function () {
			if (!this.sessionId) {
				this.showError(sscribe_data.strings.error);
				return;
			}

			if (this._batchInProgress) {
				return;
			}
			this._batchInProgress = true;

			var self = this;

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
					self._batchInProgress = false;
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
						} else if (data.status === 'finalizing') {
							// All pages processed; now polling for ZIP finalization.
							var finalizeDelay = SScribe.finalizePollInterval || 2000;
							SScribe.pollFinalize(SScribe.sessionId, 0, finalizeDelay);
						} else {
							SScribe.pollBackoff = 0;
							SScribe.scheduleNextBatch();
						}
					} else {
						var isCancelled = response.data.cancelled === true;
						if (response.data.retry === true) {
							SScribe.scheduleNextBatch(response.data && response.data.retry_in);
						} else {
							SScribe.showError(response.data.message, isCancelled, SScribe.normalizeErrorData(response.data));
						}
					}
				},
				error: function (xhr) {
					SScribe._batchInProgress = false;
					SScribe.batchRetries++;
					if (SScribe.batchRetries <= SScribe.maxBatchRetries) {
						SScribe.scheduleNextBatch();
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

			$('#sscribe-progress-area').slideUp(300, function () {
				$('#sscribe-download-area').removeClass('sscribe-hidden').hide().fadeIn(400);
				$('#sscribe-download-btn').attr('href', data.download_url);

				var $iframe = $('<iframe>').css({ display: 'none', width: 0, height: 0 });
				$('body').append($iframe);
				$iframe.attr('src', data.download_url);

				setTimeout(function () { $iframe.remove(); }, 30000);

				SScribe.refreshRecentExports();
			});
		},

		pollFinalize: function (sessionId, attempt, delay) {
			this.isProcessing = true;

			$('#sscribe-status-text').text(sscribe_data.strings.packaging || 'Packaging files into ZIP archive...');

			var maxAttempts = 30;
			var self = this;

			setTimeout(function () {
				$.ajax({
					url: sscribe_data.ajaxurl,
					type: 'POST',
					timeout: 120000,
					data: {
						action: 'sscribe_finalize_export',
						nonce: sscribe_data.nonce,
						session_id: sessionId
					},
					success: function (response) {
						if (response.success) {
							self.isProcessing = false;
							self.updateProgress(100);
							self.exportComplete(response.data);
							return;
						}

						if (response.data && response.data.code === 'not_finalizing') {
							self.isProcessing = false;
							self.showError(response.data.message || 'Export failed to finalize. Please try again.', false, {});
							return;
						}

						if (attempt < maxAttempts) {
							self.pollFinalize(sessionId, attempt + 1, 2000);
						} else {
							self.isProcessing = false;
							self.showError('Export finalization timed out. Please try again.', false, {});
						}
					},
					error: function (xhr) {
						if (attempt < maxAttempts) {
							self.pollFinalize(sessionId, attempt + 1, 2000);
						} else {
							self.isProcessing = false;
							var msg = self.getNetworkErrorMessage(xhr, 'finalize_export');
							self.showError(msg);
						}
					}
				});
			}, delay);
		},

		refreshRecentExports: function () {
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_recent_exports',
					nonce: sscribe_data.nonce
				},
				success: function (response) {
					if (response.success && response.data.exports) {
						SScribe.renderRecentExports(response.data.exports);
					}
				}
			});
		},

		renderRecentExports: function (exports) {
			var $table = $('#sscribe-history-table');
			var strings = sscribe_data.strings || {};

			if (!exports || exports.length === 0) {
				$table.html('<div class="sscribe-history-empty"><em>' + this.escapeHtml(strings.history_empty || 'Your recent export packages will appear here.') + '</em></div>');
				return;
			}

			var html = '';
			for (var i = 0; i < exports.length; i++) {
				var exp = exports[i];
				html += '<div class="sscribe-history-row" data-filename="' + this.escapeHtml(exp.filename) + '">';
				html += '<div class="sscribe-history-file">';
				html += '<div class="sscribe-file-icon">';

				if (exp.flag_url) {
					html += '<img src="' + this.escapeHtml(exp.flag_url) + '" alt="' + this.escapeHtml(exp.lang_name || '') + '" class="sscribe-file-icon-img">';
				} else {
					html += '<span class="sscribe-file-icon-text">' + this.escapeHtml((exp.lang_code || 'EN').substring(0, 2).toUpperCase()) + '</span>';
				}

				html += '</div>';
				html += '<div class="sscribe-file-details">';
				html += '<strong>' + this.escapeHtml(exp.filename) + '</strong>';
				html += '<span>' + this.escapeHtml(exp.date || '') + ' — ' + this.escapeHtml(exp.size_formatted || exp.size || '') + '</span>';
				html += '</div></div>';
				html += '<div class="sscribe-history-actions">';
				html += '<a href="' + this.escapeHtml(exp.url) + '" class="sscribe-button sscribe-button-icon sscribe-button-sm" download title="' + this.escapeHtml(strings.download_tooltip || 'Download') + '" aria-label="' + this.escapeHtml(strings.download_tooltip || 'Download') + '">';
				html += '<img src="' + this.escapeHtml(sscribe_data.icons_url + 'download-file.svg') + '" width="16" height="16" alt="">';
				html += '</a>';
				html += '<button type="button" class="sscribe-button sscribe-button-icon sscribe-button-sm sscribe-log-btn" data-filename="' + this.escapeHtml(exp.filename) + '" title="' + this.escapeHtml(strings.log_tooltip || 'View Log') + '" aria-label="' + this.escapeHtml(strings.log_tooltip || 'View Log') + '">';
				html += '<img src="' + this.escapeHtml(sscribe_data.icons_url + 'file-log.svg') + '" width="16" height="16" alt="">';
				html += '</button>';
				html += '<button type="button" class="sscribe-button sscribe-button-icon sscribe-button-sm sscribe-button-danger sscribe-delete-btn" data-filename="' + this.escapeHtml(exp.filename) + '" title="' + this.escapeHtml(strings.delete_tooltip || 'Delete') + '" aria-label="' + this.escapeHtml(strings.delete_tooltip || 'Delete') + '">';
				html += '<img src="' + this.escapeHtml(sscribe_data.icons_url + 'trash.svg') + '" width="16" height="16" alt="">';
				html += '</button>';
				html += '</div></div>';
			}

			$table.html(html);
		},

		updateProgress: function (percentage) {
			percentage = Number(percentage);
			if (!isFinite(percentage)) {
				percentage = 0;
			}
			percentage = Math.min(100, Math.max(0, percentage));

			var progressBar = document.getElementById('sscribe-progress-bar');
			if (progressBar) {
				progressBar.style.transform = 'scaleX(' + (percentage / 100) + ')';
				progressBar.setAttribute('aria-valuenow', percentage);
			} else {
				$('#sscribe-progress-bar').css('width', percentage + '%');
			}
			$('#sscribe-progress-text').text(percentage + '%');

			var liveRegion = document.getElementById('sscribe-live-region');
			if (liveRegion) {
				var msg = (sscribe_data.strings && sscribe_data.strings.export_progress_prefix) || 'Export progress:';
				liveRegion.textContent = msg + ' ' + percentage + '%';
			}
		},

		updateStatus: function (message) {
			$('#sscribe-status-text').text(message);

			var liveRegion = document.getElementById('sscribe-live-region');
			if (liveRegion) {
				liveRegion.textContent = message;
			}
		},

		showProgress: function () {
			$('#sscribe-download-area').addClass('sscribe-hidden');
			$('#sscribe-error-area').addClass('sscribe-hidden');
			$('#sscribe-progress-area').removeClass('sscribe-hidden').hide().fadeIn(400);
			$('#sscribe-current-page').text('').hide();
			$('#sscribe-time-remaining').text('').hide();
			$('#sscribe-cancel-btn').prop('disabled', false).text(sscribe_data.strings.cancel || 'Cancel Export');
			this.updateProgress(0);
		},

		showError: function (message, isCancelled, errorData) {
			this.isProcessing = false;
			$('#sscribe-progress-area').fadeOut(200);

			var displayMessage = message;
			var guidance = '';

			if (errorData) {
				if (errorData.code) {
					displayMessage = '[' + errorData.code + '] ' + message;
				}
				if (errorData.guidance) {
					guidance = errorData.guidance;
				}
				if (errorData.fix_steps && errorData.fix_steps.length > 0) {
					guidance += (guidance ? '\n\n' : '') + (sscribe_data.strings.fix_steps || 'Steps to fix:') + '\n';
					for (var i = 0; i < errorData.fix_steps.length; i++) {
						guidance += (i + 1) + '. ' + errorData.fix_steps[i] + '\n';
					}
				}
			}

			if (!guidance && !isCancelled) {
				guidance = this.getErrorGuidance(message);
			}

			$('#sscribe-error-text').text(displayMessage);

			if (guidance && !isCancelled) {
				$('#sscribe-error-guidance-text').html(this.formatGuidance(guidance));
				$('#sscribe-error-guidance').removeClass('sscribe-hidden');
			} else {
				$('#sscribe-error-guidance').addClass('sscribe-hidden');
			}

			$('#sscribe-error-area').removeClass('sscribe-hidden').hide().fadeIn(300);

			var alertRegion = document.getElementById('sscribe-alert-region');
			if (alertRegion) {
				alertRegion.textContent = 'Export failed: ' + message;
			}

			if (isCancelled) {
				this.sessionId = null;
			}
		},

		normalizeErrorData: function (errorData) {
			var normalized = errorData || {};
			var diagnostics = normalized.error_diagnostics || normalized.diagnostics || null;

			if (!diagnostics) {
				return normalized;
			}

			if (!normalized.guidance && diagnostics.guidance) {
				normalized.guidance = diagnostics.guidance;
			}

			if ((!normalized.fix_steps || !normalized.fix_steps.length) && diagnostics.fix_steps) {
				normalized.fix_steps = diagnostics.fix_steps;
			}

			if (!normalized.technical && diagnostics.technical) {
				normalized.technical = diagnostics.technical;
			}

			return normalized;
		},

		formatGuidance: function (text) {
			var escaped = this.escapeHtml(text);
			escaped = escaped.replace(/\n/g, '<br>');
			return escaped;
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
				},
				error: function () {
					self.sessionId = null;
					self.isProcessing = false;
					self.resetUI();
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
								$('#sscribe-history-table').html('<div class="sscribe-history-empty"><em>' + emptyMsg + '</em></div>');
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

			this.saveFocus();

			$('#sscribe-log-modal').removeClass('sscribe-hidden');
			$('#sscribe-log-content').html('<div class="sscribe-log-loading"><span></span></div>');
			$('#sscribe-log-content').find('span').text((sscribe_data.strings && sscribe_data.strings.loading_log) || 'Loading log...');

			var modal = document.getElementById('sscribe-log-modal');
			if (!modal) {
				return;
			}
			this.trapFocus(modal);
			var closeBtn = modal.querySelector('.sscribe-modal-close');
			if (closeBtn) {
				closeBtn.focus();
			}

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
						SScribe.renderLog(response.data.log, response.data.diagnostics || null);
} else {
						var failMsg = (response && response.data && response.data.message) || (sscribe_data.strings && sscribe_data.strings.log_not_found) || 'Log not found.';
						$('#sscribe-log-content').html('<div class="sscribe-log-empty"><p></p></div>');
						$('#sscribe-log-content').find('p').text(failMsg);
					}
				},
				error: function () {
					$('#sscribe-log-content').html('<div class="sscribe-log-empty"><p></p></div>');
					$('#sscribe-log-content').find('p').text((sscribe_data.strings && sscribe_data.strings.log_load_failed) || 'Failed to load log.');
				}
			});
		},

		renderLog: function (log, diagnostics) {
			var self = this;
			var strings = sscribe_data.strings || {};
			diagnostics = diagnostics || log.diagnostics || null;
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
					html += '<td>' + self.escapeHtml(String(page.id || '')) + '</td>';
					html += '<td>' + self.escapeHtml(page.title || (strings.log_unknown || 'Unknown')) + '</td>';
					html += '<td class="' + statusClass + '">' + self.escapeHtml(page.status) + '</td>';
					html += '<td>' + (page.duration ? self.escapeHtml(String(page.duration)) + (strings.log_seconds_suffix || 's') : (strings.log_no_duration || '-')) + '</td>';
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

			if (diagnostics && (diagnostics.categories || diagnostics.fix_steps || diagnostics.technical)) {
				html += self.renderLogDiagnostics(diagnostics);
			}

			$('#sscribe-log-content').html(html);
		},

		renderLogDiagnostics: function (diagnostics) {
			var html = '<div class="sscribe-log-errors sscribe-log-diagnostics">';
			html += '<h4>' + this.escapeHtml((sscribe_data.strings && sscribe_data.strings.log_diagnostics) || 'Diagnostics') + '</h4>';

			if (diagnostics.categories && diagnostics.categories.length) {
				html += '<div class="sscribe-log-diagnostic-badges">';
				for (var i = 0; i < diagnostics.categories.length; i++) {
					var category = diagnostics.categories[i];
					html += '<span class="sscribe-badge ' + this.escapeHtml(this.getDiagnosticBadgeClass(category)) + '">' + this.escapeHtml(this.humanizeSupportKey(String(category))) + '</span>';
				}
				html += '</div>';
			}

			if (diagnostics.entries && diagnostics.entries.length) {
				html += '<ul class="sscribe-log-diagnostic-list">';
				for (var j = 0; j < diagnostics.entries.length; j++) {
					var entry = diagnostics.entries[j] || {};
					html += '<li>';
					html += '<strong>' + this.escapeHtml(this.humanizeSupportKey(String(entry.category || 'unknown'))) + '</strong>';
					if (entry.severity) {
						html += ' <span class="sscribe-badge ' + this.escapeHtml(this.getSeverityBadgeClass(entry.severity)) + '">' + this.escapeHtml(String(entry.severity)) + '</span>';
					}
					if (entry.error) {
						html += '<div>' + this.escapeHtml(String(entry.error)) + '</div>';
					}
					html += '</li>';
				}
				html += '</ul>';
			}

			if (diagnostics.guidance) {
				html += '<p>' + this.formatGuidance(diagnostics.guidance) + '</p>';
			}

			if (diagnostics.fix_steps && diagnostics.fix_steps.length) {
				html += '<h5>' + this.escapeHtml((sscribe_data.strings && sscribe_data.strings.fix_steps) || 'Steps to fix:') + '</h5>';
				html += '<ol>';
				for (var k = 0; k < diagnostics.fix_steps.length; k++) {
					html += '<li>' + this.escapeHtml(String(diagnostics.fix_steps[k])) + '</li>';
				}
				html += '</ol>';
			}

			if (diagnostics.technical) {
				html += '<details class="sscribe-log-diagnostic-details"><summary>' + this.escapeHtml((sscribe_data.strings && sscribe_data.strings.technical_details) || 'Technical details') + '</summary>';
				html += '<pre>' + this.escapeHtml(JSON.stringify(diagnostics.technical, null, 2)) + '</pre>';
				html += '</details>';
			}

			html += '</div>';
			return html;
		},

		getDiagnosticBadgeClass: function (category) {
			var map = {
				memory_exhausted: 'sscribe-badge-danger',
				timeout: 'sscribe-badge-warning',
				pdf_generation: 'sscribe-badge-info',
				pdf_missing_library: 'sscribe-badge-info',
				pdf_filesystem: 'sscribe-badge-warning',
				zip_creation: 'sscribe-badge-warning'
			};

			return map[category] || 'sscribe-badge-muted';
		},

		getSeverityBadgeClass: function (severity) {
			var map = {
				critical: 'sscribe-badge-danger',
				error: 'sscribe-badge-warning',
				warning: 'sscribe-badge-muted'
			};

			return map[severity] || 'sscribe-badge-muted';
		},

		escapeHtml: function (text) {
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		closeModal: function () {
			var modal = document.getElementById('sscribe-log-modal');
			if (modal && modal._sscribeTrapHandler) {
				modal.removeEventListener('keydown', modal._sscribeTrapHandler);
				modal._sscribeTrapHandler = null;
			}
			$('#sscribe-log-modal').addClass('sscribe-hidden');
			this.restoreFocus();
		},

		showPreview: function (e) {
			e.preventDefault();

			var language = $('input[name="sscribe_language"]:checked').val() || '';
			var postStatus = $('input[name="sscribe_post_status"]:checked').val() || 'publish';
			var postType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			var format = $('input[name="sscribe_format"]:checked').val() || 'docx';

			var $panel = $('#sscribe-preview-panel');
			var $content = $('#sscribe-preview-content');

			$panel.removeClass('sscribe-hidden');
			$content.html('<div class="sscribe-preview-loading"><span class="sscribe-loading-spinner"></span><span>' + (sscribe_data.strings.generating_preview || 'Generating preview...') + '</span></div>');

			this.saveFocus();

			var panelOffset = $panel.offset();
			if (panelOffset) {
				$('html, body').animate({
					scrollTop: panelOffset.top - 20
				}, 300);
			}

			var self = this;

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_export_preview',
					nonce: sscribe_data.nonce,
					language: language,
					post_status: postStatus,
					post_type: postType,
					format: format
				},
				success: function (response) {
					if (response.success && response.data) {
						self.renderPreview(response.data);
					} else {
						var fallbackMsg = (response && response.data && response.data.message) || 'Preview not available';
						$content.html('<p class="sscribe-preview-note">' + self.escapeHtml(fallbackMsg) + '</p>');
					}
				},
				error: function () {
					self.renderFallbackPreview(language, postStatus, format);
				}
			});
		},

		renderPreview: function (data) {
			var $content = $('#sscribe-preview-content');
			var strings = sscribe_data.strings || {};

			var html = '<div class="sscribe-preview-sample">';
			html += '<h1>' + this.escapeHtml(data.title || strings.preview_sample_title || 'Sample Page') + '</h1>';

			if (data.url) {
				html += '<p style="color:var(--sscribe-text-muted);font-size:13px;margin:0 0 12px;">' + this.escapeHtml(data.url) + '</p>';
			}

			if (data.content) {
				var previewContent = data.content.length > 300 ? data.content.substring(0, 300) + '...' : data.content;
				html += '<div style="color:var(--sscribe-text-secondary);font-size:13px;line-height:1.6;">' + this.escapeHtml(previewContent) + '</div>';
			}

			html += '<div class="sscribe-preview-meta">';

			if (data.total_pages !== undefined) {
				html += '<div class="sscribe-preview-meta-item"><strong>' + this.escapeHtml(strings.preview_total_pages || 'Total Pages') + '</strong>' + data.total_pages + '</div>';
			}
			if (data.format) {
				html += '<div class="sscribe-preview-meta-item"><strong>' + this.escapeHtml(strings.preview_format || 'Format') + '</strong>' + this.escapeHtml(data.format.toUpperCase()) + '</div>';
			}
			if (data.estimated_time) {
				html += '<div class="sscribe-preview-meta-item"><strong>' + this.escapeHtml(strings.preview_estimated_time || 'Estimated Time') + '</strong>' + this.escapeHtml(data.estimated_time) + '</div>';
			}
			if (data.file_size_estimate) {
				html += '<div class="sscribe-preview-meta-item"><strong>' + this.escapeHtml(strings.preview_file_size || 'Estimated Size') + '</strong>' + this.escapeHtml(data.file_size_estimate) + '</div>';
			}

			html += '</div>';

			html += '<p class="sscribe-preview-note">' + this.escapeHtml(strings.preview_note || 'This is a preview of your export configuration. The actual export will include all selected pages with full formatting and SEO metadata.') + '</p>';
			html += '</div>';

			$content.html(html);
		},

		renderFallbackPreview: function (language, postStatus, format) {
			var $content = $('#sscribe-preview-content');
			var strings = sscribe_data.strings || {};

			var formatLabels = {
				'docx': 'Word Document (DOCX)',
				'pdf': 'PDF Document',
				'html': 'HTML Page',
				'markdown': 'Markdown'
			};

			var html = '<div class="sscribe-preview-sample">';
			html += '<h1>' + this.escapeHtml(strings.preview_sample_title || 'Sample Export') + '</h1>';
			html += '<div class="sscribe-preview-meta">';
			html += '<div class="sscribe-preview-meta-item"><strong>' + this.escapeHtml(strings.preview_total_pages || 'Total Pages') + '</strong>' + this.selectedPageCount + '</div>';
			html += '<div class="sscribe-preview-meta-item"><strong>' + this.escapeHtml(strings.preview_format || 'Format') + '</strong>' + this.escapeHtml(formatLabels[format] || format) + '</div>';
			html += '<div class="sscribe-preview-meta-item"><strong>' + this.escapeHtml(strings.preview_language || 'Language') + '</strong>' + this.escapeHtml(language || 'All Languages') + '</div>';
			html += '<div class="sscribe-preview-meta-item"><strong>' + this.escapeHtml(strings.preview_status || 'Status') + '</strong>' + this.escapeHtml(postStatus) + '</div>';
			html += '</div>';
			html += '<p class="sscribe-preview-note">' + this.escapeHtml(strings.preview_fallback_note || 'Preview shows your export configuration. The actual export will include all selected pages with professional formatting.') + '</p>';
			html += '</div>';

			$content.html(html);
		},

		closePreview: function () {
			$('#sscribe-preview-panel').addClass('sscribe-hidden');
			this.restoreFocus();
		},

		loadSupportInfo: function (e) {
			if (e) {
				e.preventDefault();
			}

			var strings = sscribe_data.strings || {};
			var $grid = $('#sscribe-support-grid');
			var $copy = $('#sscribe-support-copy-text');
			var $feedback = $('#sscribe-support-feedback');

			$grid.html('<div class="sscribe-support-loading">' + this.escapeHtml(strings.support_loading || 'Loading support information...') + '</div>');
			$copy.val('');
			$('#sscribe-support-copy-btn').prop('disabled', true);
			$feedback.addClass('sscribe-hidden').text('');

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_support_info',
					nonce: sscribe_data.nonce
				},
				success: function (response) {
					if (response.success && response.data) {
						SScribe.renderSupportInfo(response.data);
						return;
					}

					SScribe.renderSupportError((response.data && response.data.message) || strings.support_error || 'Unable to load support information right now.');
				},
				error: function () {
					SScribe.renderSupportError(strings.support_error || 'Unable to load support information right now.');
				}
			});
		},

		renderSupportInfo: function (data) {
			var strings = sscribe_data.strings || {};
			var html = '';

			if (data.sections) {
				for (var key in data.sections) {
					if (!data.sections.hasOwnProperty(key)) continue;
					var section = data.sections[key];
					html += '<section class="sscribe-support-section">';
					html += '<h3 class="sscribe-support-section-title">' + this.escapeHtml(section.label) + '</h3>';
					html += '<dl class="sscribe-support-list">';
				for (var itemKey in section.items) {
					if (!section.items.hasOwnProperty(itemKey)) continue;
					html += '<div class="sscribe-support-list-row">';
					html += '<dt>' + this.escapeHtml(this.humanizeSupportKey(itemKey)) + '</dt>';
					html += '<dd>' + this.escapeHtml(String(section.items[itemKey] || '')) + '</dd>';
					html += '</div>';
					}
					html += '</dl></section>';
				}
			}

			if (data.audit_events && data.audit_events.length) {
				html += '<section class="sscribe-support-section">';
				html += '<h3 class="sscribe-support-section-title">Recent Audit Events</h3>';
				html += '<ul class="sscribe-support-events">';
				for (var i = 0; i < data.audit_events.length; i++) {
					var event = data.audit_events[i];
					html += '<li><strong>' + this.escapeHtml(String(event.event || '')) + '</strong><span>' + this.escapeHtml(String(event.timestamp || '')) + '</span></li>';
				}
				html += '</ul></section>';
			}

			$('#sscribe-support-grid').html(html || '<div class="sscribe-support-loading">' + this.escapeHtml(strings.support_error || 'Unable to load support information right now.') + '</div>');
			$('#sscribe-support-copy-text').val(data.copy_text || '');
			$('#sscribe-support-copy-btn').prop('disabled', !(data.copy_text && data.copy_text.length));
			$('#sscribe-support-debug-note').toggleClass('sscribe-hidden', !data.has_debug_mode);

			if (data.generated_at) {
				$('#sscribe-support-feedback').removeClass('sscribe-hidden').text((strings.support_generated || 'Generated') + ': ' + data.generated_at + ' UTC');
			}
		},

		renderSupportError: function (message) {
			$('#sscribe-support-grid').html('<div class="sscribe-support-error">' + this.escapeHtml(message) + '</div>');
			$('#sscribe-support-copy-btn').prop('disabled', true);
		},

		copySupportInfo: function (e) {
			e.preventDefault();
			var text = $('#sscribe-support-copy-text').val();
			var strings = sscribe_data.strings || {};

			if (!text) {
				return;
			}

			var onSuccess = function () {
				$('#sscribe-support-feedback').removeClass('sscribe-hidden').text(strings.support_copied || 'Support information copied.');
			};

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(onSuccess, function () {
					SScribe.copyViaTextarea(text, onSuccess);
				});
				return;
			}

			SScribe.copyViaTextarea(text, onSuccess);
		},

		copyViaTextarea: function (text, onSuccess) {
			var textarea = document.getElementById('sscribe-support-copy-text');
			if (!textarea) {
				return;
			}
			textarea.focus();
			textarea.select();
			try {
				document.execCommand('copy');
				onSuccess();
			} catch (e) {
				// eslint-disable-next-line no-empty
			}
		},

		humanizeSupportKey: function (key) {
			return key.replace(/_/g, ' ').replace(/\b\w/g, function (char) { return char.toUpperCase(); });
		},

		saveFocus: function () {
			this._lastFocusedElement = document.activeElement;
		},

		restoreFocus: function () {
			if (this._lastFocusedElement && typeof this._lastFocusedElement.focus === 'function') {
				this._lastFocusedElement.focus();
				this._lastFocusedElement = null;
			}
		},

		trapFocus: function (container) {
			if (!container || typeof container.querySelectorAll !== 'function') {
				return;
			}

			var focusableSelectors = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
			var focusableElements = container.querySelectorAll(focusableSelectors);

			if (focusableElements.length === 0) {
				return;
			}

			var firstFocusable = focusableElements[0];
			var lastFocusable = focusableElements[focusableElements.length - 1];

			if (container._sscribeTrapHandler) {
				container.removeEventListener('keydown', container._sscribeTrapHandler);
			}

			var handler = function (e) {
				if (e.key !== 'Tab') return;
				if (e.shiftKey) {
					if (document.activeElement === firstFocusable) {
						e.preventDefault();
						lastFocusable.focus();
					}
				} else {
					if (document.activeElement === lastFocusable) {
						e.preventDefault();
						firstFocusable.focus();
					}
				}
			};

			container._sscribeTrapHandler = handler;
			container.addEventListener('keydown', handler);
		},
	};

	$(document).ready(function () {
		SScribe.init();
	});

})(jQuery);
