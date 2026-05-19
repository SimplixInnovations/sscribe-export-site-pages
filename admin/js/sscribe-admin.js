/**
 * SScribe Admin JavaScript
 *
 * Handles AJAX batch processing with animated progress tracking.
 *
 * @package SScribe
 */

(function ($) {
	'use strict';

	const SScribe = {
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

		/**
		 * Parse a localized integer from text (handles comma/period separators).
		 *
		 * @param {string} text Text containing a number.
		 * @returns {number} Parsed integer.
		 */
		parseLocalizedInt: function (text) {
			if (typeof text === 'number') {
				return Math.floor(text);
			}
			if (!text || typeof text !== 'string') {
				return 0;
			}
			// Strip all dots and commas — this handles both English (1,000) and
			// European (1.000) thousands separators. Decimal precision is irrelevant
			// for page counts, so we treat both as thousands separators.
			const cleaned = text.replace(/[.,]/g, '');
			const num = parseInt(cleaned, 10);
			return isNaN(num) ? 0 : num;
		},

		init: function () {
			if (typeof sscribe_data === 'undefined' || !sscribe_data) {
				return;
			}
			this.bindEvents();
			this.updateConfigSummary();
			this.toggleHistorySkeleton(false);

			const defaultPostType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const defaultLanguage = $('input[name="sscribe_language"]:checked').val() || '';
			this.refreshStatusAndLanguageCounts(defaultPostType, defaultLanguage);

			const self = this;
			let supportTriggered = false;
			const observer = new IntersectionObserver(
				function (entries) {
					if (entries[0].isIntersecting && !supportTriggered) {
						supportTriggered = true;
						self.loadSupportInfo();
						observer.disconnect();
					}
				},
				{ threshold: 0.1 }
			);
			const supportSection = document.getElementById('sscribe-support-grid');
			if (supportSection) {
				observer.observe(supportSection);
			}
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
			$('input[name="sscribe_post_status"]').on('change', $.proxy(this.updateConfigSummary, this));
			$('input[name="sscribe_format"]').on('change', $.proxy(this.onFormatChange, this));

			$(document).on('click', '.sscribe-delete-btn', $.proxy(this.deleteExport, this));
			$(document).on('click', '.sscribe-log-btn', $.proxy(this.showExportLog, this));
			$(document).on('change', '.sscribe-history-check', $.proxy(this.updateBulkBar, this));
			$(document).on('change', '#sscribe-bulk-select-all', $.proxy(this.toggleSelectAll, this));
			$(document).on('click', '#sscribe-bulk-delete-btn', $.proxy(this.bulkDeleteSelected, this));
			$(document).on('click', '#sscribe-bulk-download-btn', $.proxy(this.bulkDownload, this));
			$(document).on('click', '#sscribe-support-refresh-btn', $.proxy(this.loadSupportInfo, this));
			$(document).on('click', '#sscribe-support-copy-btn', $.proxy(this.copySupportInfo, this));
			$(document).on('click', '#sscribe-modal-close', $.proxy(this.closeModal, this));
			$(document).on('click', '.sscribe-history-actions > a', $.proxy(this.downloadExport, this));

			// Keyboard shortcuts: Ctrl+E = Export, Ctrl+P = Preview.
			$(document).on('keydown', function (e) {
				if (!e.ctrlKey && !e.metaKey) { return; }
				// Don't trigger shortcuts when user is typing in an input, textarea, or contenteditable.
				const tag = e.target.tagName;
				if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable) { return; }
				if (e.key === 'e' || e.key === 'E') {
					e.preventDefault();
					const $btn = $('#sscribe-export-btn');
					if (!$btn.prop('disabled')) {
						$btn.trigger('click');
					}
				}
				if (e.key === 'p' || e.key === 'P') {
					e.preventDefault();
					const $btn = $('#sscribe-preview-btn');
					if (!$btn.prop('disabled')) {
						$btn.trigger('click');
					}
				}
			});

			// Tab switching logic — handles ARIA roles and aria-selected state for accessibility.
			$('.sscribe-tab-btn').on('click', function (e) {
				e.preventDefault();
				const $btn = $(this);
				const tabId = $btn.data('tab');

				// Update ARIA states for all tab buttons.
				$('.sscribe-tab-btn').attr('aria-selected', 'false').removeClass('sscribe-tab-active');
				$btn.attr('aria-selected', 'true').addClass('sscribe-tab-active');

				// Switch the visible tab panel.
				$('.sscribe-tab-content').removeClass('sscribe-tab-active');
				$('#sscribe-tab-' + tabId).addClass('sscribe-tab-active');
			});
		},

		onPostTypeChange: function () {
			if (this.isProcessing) {
				return;
			}

			const postType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const language = $('input[name="sscribe_language"]:checked').val() || '';

			this.refreshStatusAndLanguageCounts(postType, language);
		},

		refreshStatusAndLanguageCounts: function (postType, language) {
			const self = this;

			$('.sscribe-status-card-label').addClass('sscribe-loading');
			$('#sscribe-post-count, #sscribe-both-count').addClass('sscribe-loading-count');

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_status_counts',
					nonce: sscribe_data.nonce,
					language: language,
					post_type: postType,
				},
				success: function (response) {
					if (response.success && response.data.counts) {
						self.updateStatusCounts(response.data.counts);
						self.updateConfigSummary();
						self.updateExportButton();

						const total = self.parseLocalizedInt(response.data.counts.all) || 0;
						if (postType === 'page') {
							$('#sscribe-page-count').text(total.toLocaleString());
						} else if (postType === 'post') {
							$('#sscribe-post-count').text(total.toLocaleString());
						} else if (postType === 'any') {
							$('#sscribe-both-count').text(total.toLocaleString());
						}
					}
					$('.sscribe-status-card-label').removeClass('sscribe-loading');
					$('#sscribe-post-count, #sscribe-both-count').removeClass('sscribe-loading-count');
				},
				error: function () {
					$('.sscribe-status-card-label').removeClass('sscribe-loading');
					$('#sscribe-post-count, #sscribe-both-count').removeClass('sscribe-loading-count');
				},
			});

			$('input[name="sscribe_language"]').each(function () {
				const langCode = $(this).val();
				if (!langCode) { return; }

				$.ajax({
					url: sscribe_data.ajaxurl,
					type: 'POST',
					timeout: 15000,
					data: {
						action: 'sscribe_get_status_counts',
						nonce: sscribe_data.nonce,
						language: langCode,
						post_type: postType,
					},
					success: function (response) {
						if (response.success && response.data.counts) {
							const total = self.parseLocalizedInt(response.data.counts.all) || 0;
							$('input[name="sscribe_language"][value="' + langCode + '"]')
								.closest('.sscribe-lang-card-label')
								.find('.sscribe-lang-count')
								.text(total.toLocaleString());
						}
					},
					error: function () {
							$('.sscribe-status-card-label').removeClass('sscribe-loading');
							$('#sscribe-post-count, #sscribe-both-count').removeClass('sscribe-loading-count');
						},
				});
			});

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 15000,
				data: {
					action: 'sscribe_get_status_counts',
					nonce: sscribe_data.nonce,
					language: '',
					post_type: postType,
				},
				success: function (response) {
					if (response.success && response.data.counts) {
						const total = self.parseLocalizedInt(response.data.counts.all) || 0;
						$('input[name="sscribe_language"][value=""]')
							.closest('.sscribe-lang-card-label')
							.find('.sscribe-lang-count')
							.text(total.toLocaleString());
					}
				},
				error: function () {
					$('.sscribe-status-card-label').removeClass('sscribe-loading');
					$('#sscribe-post-count, #sscribe-both-count').removeClass('sscribe-loading-count');
				},
			});
		},

		updateStatusCounts: function (counts) {
			const currentSelected = $('input[name="sscribe_post_status"]:checked');
			let currentStillValid = false;
			let firstAvailable = null;
			const self = this;

			$('.sscribe-status-card-label').each(function () {
				const $label = $(this);
				const $input = $label.find('input[type="radio"]');
				const status = $input.val();
				const count = self.parseLocalizedInt(counts[status]) || 0;

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
		},

		onLanguageChange: function () {
			if (this.isProcessing) {
				return;
			}
			const postType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const language = $('input[name="sscribe_language"]:checked').val() || '';
			this.refreshStatusAndLanguageCounts(postType, language);
		},

		onFormatChange: function () {
			this.updateConfigSummary();
			this.updateExportButton();
		},

		updateConfigSummary: function () {
			const $selectedStatus = $('input[name="sscribe_post_status"]:checked');
			let count = 0;
			if ($selectedStatus.length && !$selectedStatus.prop('disabled')) {
				count =
					this.parseLocalizedInt(
						$selectedStatus.closest('.sscribe-status-card-label').find('.sscribe-status-count').text()
					) || 0;
			}

			this.selectedPageCount = count;

			const postType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const language = $('input[name="sscribe_language"]:checked').val();
			const format = $('input[name="sscribe_format"]:checked').val() || 'all';
			const status = $selectedStatus.val() || 'publish';
			const strings = sscribe_data.strings || {};

			const postTypeLabels = { page: 'Pages', post: 'Posts', any: 'Both' };
			const statusLabels = {
				publish: 'Published', draft: 'Draft', private: 'Private',
				future: 'Scheduled', pending: 'Pending', all: 'All',
			};

			$('#sscribe-summary-post-type').text(postTypeLabels[postType] || postType);
			$('#sscribe-summary-status').text(statusLabels[status] || status);
			$('#sscribe-summary-language').text(language ? language.toUpperCase() : 'All');
			$('#sscribe-summary-format').text(format === 'all' ? 'All' : format.toUpperCase());
			$('#sscribe-summary-pages').text('~' + count + ' ' + sscribe_data.strings.log_pages);

			// Calculate time estimate.
			const times = { docx: 1.5, pdf: 8, html: 1, markdown: 0.5 };
			let estSeconds = 0;
			if (format === 'all') {
				estSeconds = (1.5 + 8 + 1 + 0.5) * count;
			} else {
				estSeconds = (times[format] || 2) * count;
			}

			let timeText = '';
			if (estSeconds < 60) {
				timeText = '~' + Math.ceil(estSeconds) + 's';
			} else if (estSeconds < 3600) {
				timeText = '~' + Math.ceil(estSeconds / 60) + 'm';
			} else {
				const h = Math.floor(estSeconds / 3600);
				const m = Math.ceil((estSeconds % 3600) / 60);
				timeText = '~' + h + 'h ' + m + 'm';
			}
			$('#sscribe-summary-time').text(timeText);

			this.updateExportButton();
		},

		updateExportButton: function () {
			const hasPostType = $('input[name="sscribe_post_type"]:checked').length > 0;
			const hasLanguage =
				$('input[name="sscribe_language"]:checked').length > 0 ||
				$('input[name="sscribe_language"]').length === 0;
			const hasStatus =
				$('input[name="sscribe_post_status"]:checked').length > 0 &&
				!$('input[name="sscribe_post_status"]:checked').prop('disabled');
			const hasFormat = $('input[name="sscribe_format"]:checked').length > 0;
			const hasPages = this.selectedPageCount > 0;

			const canExport = hasPostType && hasLanguage && hasStatus && hasFormat && hasPages;

			$('#sscribe-export-btn').prop('disabled', !canExport);
			$('#sscribe-preview-btn').prop('disabled', !canExport);

			const $reason = $('#sscribe-export-disabled-reason');
			if (!canExport) {
				if (!hasPages) {
					$reason.text(sscribe_data.strings && sscribe_data.strings.err_no_pages || 'No pages match selected options');
				} else {
					$reason.text('');
				}
			} else {
				$reason.text('');
			}
		},

		startExport: function (e) {
			e.preventDefault();

			if (this.isProcessing) {
				return;
			}

			this.isProcessing = true;
			this.batchRetries = 0;
			this.resetUI();
			this.updateExportButton();

			const language = $('input[name="sscribe_language"]:checked').val() || '';
			const postStatus = $('input[name="sscribe_post_status"]:checked').val() || 'publish';
			const postType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const format = $('input[name="sscribe_format"]:checked').val() || 'docx';

			let formats = [];
			if (format === 'all') {
				formats = ['docx', 'pdf', 'html', 'markdown'];
			} else {
				formats = [format];
			}

			const self = this;

			// Run pre-flight checks before starting export.
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_preflight_check',
					nonce: sscribe_data.nonce,
					page_count: this.selectedPageCount,
					formats: formats,
				},
				success: function (response) {
					if (response.success) {
						const diagnostics = response.data;

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
				},
			});
		},

		showPreflightWarnings: function (diagnostics, onProceed) {
			const checks = diagnostics.checks || {};
			const errors = [];
			const warnings = [];

			for (const key in checks) {
				if (Object.prototype.hasOwnProperty.call(checks, key)) {
					const check = checks[key];
					if (check.status === 'error') {
						errors.push(check);
					} else if (check.status === 'warning') {
						warnings.push(check);
					}
				}
			}

			let bannerHtml = '<div class="sscribe-preflight-banner">';
			bannerHtml += '<div class="sscribe-preflight-header">';
			bannerHtml += '<span class="sscribe-preflight-icon" aria-hidden="true">⚠</span>';
			bannerHtml +=
				'<h3>' + this.escapeHtml(sscribe_data.strings.preflight_title || 'Export Readiness Check') + '</h3>';
			bannerHtml +=
				'<button type="button" class="sscribe-preflight-close" aria-label="' +
				this.escapeHtml(sscribe_data.strings.close || 'Close') +
				'">&times;</button>';
			bannerHtml += '</div>';
			bannerHtml += '<div class="sscribe-preflight-body">';

			if (errors.length > 0) {
				bannerHtml += '<div class="sscribe-preflight-section sscribe-preflight-errors">';
				bannerHtml +=
					'<h4 class="sscribe-preflight-section-title">' +
					this.escapeHtml(sscribe_data.strings.preflight_errors || 'Critical Issues') +
					'</h4>';
				bannerHtml += '<ul class="sscribe-preflight-list">';
				for (let i = 0; i < errors.length; i++) {
					bannerHtml +=
						'<li><strong>' +
						this.escapeHtml(errors[i].name) +
						':</strong> ' +
						this.escapeHtml(errors[i].message);
					if (errors[i].fix) {
						bannerHtml +=
							'<br><em class="sscribe-preflight-fix">' + this.escapeHtml(errors[i].fix) + '</em>';
					}
					bannerHtml += '</li>';
				}
				bannerHtml += '</ul></div>';
			}

			if (warnings.length > 0) {
				bannerHtml += '<div class="sscribe-preflight-section sscribe-preflight-warnings">';
				bannerHtml +=
					'<h4 class="sscribe-preflight-section-title">' +
					this.escapeHtml(sscribe_data.strings.preflight_warnings || 'Recommendations') +
					'</h4>';
				bannerHtml += '<ul class="sscribe-preflight-list">';
				for (let j = 0; j < warnings.length; j++) {
					bannerHtml +=
						'<li><strong>' +
						this.escapeHtml(warnings[j].name) +
						':</strong> ' +
						this.escapeHtml(warnings[j].message);
					if (warnings[j].fix) {
						bannerHtml +=
							'<br><em class="sscribe-preflight-fix">' + this.escapeHtml(warnings[j].fix) + '</em>';
					}
					bannerHtml += '</li>';
				}
				bannerHtml += '</ul></div>';
			}

			bannerHtml += '</div>';
			bannerHtml += '<div class="sscribe-preflight-actions">';
			bannerHtml +=
				'<button type="button" class="sscribe-button sscribe-button-primary sscribe-preflight-proceed">' +
				this.escapeHtml(sscribe_data.strings.preflight_continue || 'Continue Anyway') +
				'</button>';
			bannerHtml +=
				'<button type="button" class="sscribe-button sscribe-button-ghost sscribe-preflight-cancel">' +
				this.escapeHtml(sscribe_data.strings.preflight_cancel || 'Go Back') +
				'</button>';
			bannerHtml += '</div></div>';

			$('.sscribe-preflight-banner').remove();

			$('.sscribe-workspace').prepend(bannerHtml);

			const $banner = $('.sscribe-preflight-banner');

			$banner.on('click.sscribe-preflight', '.sscribe-preflight-proceed', function () {
				$banner.fadeOut(200, function () {
					$banner.remove();
				});
				onProceed();
			});

			$banner.on('click.sscribe-preflight', '.sscribe-preflight-cancel', function () {
				$banner.fadeOut(200, function () {
					$banner.remove();
				});
				SScribe.isProcessing = false;
				SScribe.resetUI();
				SScribe.updateExportButton();
			});

			$banner.on('click.sscribe-preflight', '.sscribe-preflight-close', function () {
				$banner.fadeOut(200, function () {
					$banner.remove();
				});
				SScribe.isProcessing = false;
				SScribe.resetUI();
				SScribe.updateExportButton();
			});

			const bannerOffset = $banner.offset();
			if (bannerOffset) {
				$('html, body').animate(
					{
						scrollTop: bannerOffset.top - 20,
					},
					300
				);
			}
		},

		proceedWithExport: function (language, postStatus, postType, formats) {
			this.clearSessionWithRetry(language, postStatus, postType, formats, 0);
		},

		clearSessionWithRetry: function (language, postStatus, postType, formats, attempt) {
			const maxAttempts = 3;
			const self = this;

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 15000,
				data: {
					action: 'sscribe_clear_session',
					nonce: sscribe_data.nonce,
					force: true,
				},
				success: function () {
					self.doStartExport(language, postStatus, postType, formats);
				},
				error: function () {
					if (attempt < maxAttempts - 1) {
						self.clearSessionWithRetry(language, postStatus, postType, formats, attempt + 1);
					} else {
						self.doStartExport(language, postStatus, postType, formats);
					}
				},
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
					formats: formats,
				},
				success: function (response) {
					if (response.success) {
						SScribe.showProgress();
						SScribe.sessionId = response.data.session_id;
						SScribe.updateStatus(response.data.message);
						SScribe.processBatch();
					} else {
						SScribe.showError(response.data.message, false, SScribe.normalizeErrorData(response.data));
					}
				},
				error: function (xhr) {
					const msg = SScribe.getNetworkErrorMessage(xhr, 'start_export');
					SScribe.showError(msg);
				},
			});
		},

		scheduleNextBatch: function (retryInMs) {
			const self = this;
			let delay = 0;

			if (typeof retryInMs === 'number' && isFinite(retryInMs) && retryInMs > 0) {
				delay = Math.max(0, Math.floor(retryInMs));
				self.pollBackoff = 0;
			} else {
				const base = self.pollBackoffBase * Math.pow(2, Math.max(0, self.pollBackoff));
				delay = Math.min(self.pollBackoffMax, Math.floor(base));
				if (self.pollBackoffBase * Math.pow(2, self.pollBackoff) < self.pollBackoffMax) {
					self.pollBackoff++;
				}
			}

			const jitter = Math.floor(Math.random() * (self.pollJitter * 2 + 1)) - self.pollJitter;
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

			const self = this;

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 180000, // Must exceed PHP set_time_limit (120s) to prevent false network errors. 180s = 60s grace over PHP's 120s.
				data: {
					action: 'sscribe_process_batch',
					nonce: sscribe_data.nonce,
					session_id: this.sessionId,
				},
				success: function (response) {
					self._batchInProgress = false;
					if (response.success) {
						SScribe.batchRetries = 0;
						const data = response.data;

						SScribe.updateProgress(data.percentage);
						SScribe.updateStatus(data.message);
						if (data.status === 'processing') {
							SScribe.updatePhase('processing');
						}

						if (data.current_page) {
							$('#sscribe-current-page').text(data.current_page).show();
						}

						if (data.time_remaining !== undefined && data.time_remaining > 0) {
							const minutes = Math.floor(data.time_remaining / 60);
							const seconds = data.time_remaining % 60;
							const strings = sscribe_data.strings || {};
							let timeStr = '';
							if (minutes > 0) {
								timeStr =
									strings.min_sec_remaining
										? strings.min_sec_remaining.replace( '%1$d', minutes ).replace( '%2$d', seconds )
										: minutes + ' min ' + seconds + ' sec remaining';
							} else {
								timeStr = seconds + ' ' + (strings.sec_remaining || 'sec remaining');
							}
							$('#sscribe-time-remaining').text(timeStr).show();
						}

						if (data.status === 'complete') {
							SScribe.exportComplete(data);
						} else if (data.status === 'finalizing') {
							// All pages processed; now polling for ZIP finalization.
							const finalizeDelay = SScribe.finalizePollInterval || 2000;
							SScribe.pollFinalize(SScribe.sessionId, 0, finalizeDelay);
						} else {
							SScribe.pollBackoff = 0;
							SScribe.scheduleNextBatch();
						}
					} else {
						const isCancelled = response.data.cancelled === true;
						if (response.data.retry === true) {
							SScribe.scheduleNextBatch(response.data && response.data.retry_in);
						} else {
							SScribe.showError(
								response.data.message,
								isCancelled,
								SScribe.normalizeErrorData(response.data)
							);
						}
					}
				},
				error: function (xhr) {
					SScribe._batchInProgress = false;
					SScribe.batchRetries++;
					if (SScribe.batchRetries <= SScribe.maxBatchRetries) {
						SScribe.scheduleNextBatch();
					} else {
						const msg = SScribe.getNetworkErrorMessage(xhr, 'process_batch');
						SScribe.showError(msg);
					}
				},
			});
		},

		cancelExport: function (e) {
			e.preventDefault();

			if (!this.sessionId) {
				return;
			}

			$('#sscribe-cancel-btn')
				.prop('disabled', true)
				.text(sscribe_data.strings.cancelling || 'Cancelling...');

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_cancel_export',
					nonce: sscribe_data.nonce,
					session_id: this.sessionId,
				},
				success: function () {
					SScribe.isProcessing = false;
					SScribe.sessionId = null;
				},
				error: function () {
					SScribe.isProcessing = false;
					$('#sscribe-cancel-btn')
						.prop('disabled', false)
						.text(sscribe_data.strings.cancel || 'Cancel Export');
				},
			});
		},

		exportComplete: function (data) {
			const progressFill = document.getElementById('sscribe-progress-bar');
			if (progressFill) {
				progressFill.classList.remove('sscribe-progress-bar-fill-finalizing');
			}
			$('#sscribe-progress-area').slideUp(300, function () {
				$('#sscribe-download-area').removeClass('sscribe-hidden').hide().fadeIn(400);
				$('#sscribe-download-btn').attr('href', data.download_url);

				$('html, body').animate({ scrollTop: $('#sscribe-download-area').offset().top - 20 }, 300);

				const $iframe = $('<iframe>').css({ display: 'none', width: 0, height: 0 });
				$('body').append($iframe);
				$iframe.attr('src', data.download_url);

				setTimeout(function () {
					$iframe.remove();
				}, 30000);

				SScribe.refreshRecentExports();
			});
		},

		pollFinalize: function (sessionId, attempt, delay) {
			this.isProcessing = true;

			$('#sscribe-status-text').text(sscribe_data.strings.packaging || 'Packaging files into ZIP archive...');
			SScribe.updatePhase('packaging');

			const progressFill = document.getElementById('sscribe-progress-bar');
			if (progressFill) {
				progressFill.classList.add('sscribe-progress-bar-fill-finalizing');
			}

			const maxAttempts = 180;
			const self = this;

			setTimeout(function () {
				$.ajax({
					url: sscribe_data.ajaxurl,
					type: 'POST',
					timeout: 120000,
					data: {
						action: 'sscribe_finalize_export',
						nonce: sscribe_data.nonce,
						session_id: sessionId,
					},
					success: function (response) {
						if (response.success) {
							self.isProcessing = false;
							self.updateProgress(100);
							self.exportComplete(response.data);
							return;
						}

						if (response.data && response.data.code === 'not_finalizing') {
							// Session gone but ZIP may have been created. Check recent exports before failing.
							$.ajax({
								url: sscribe_data.ajaxurl,
								type: 'POST',
								timeout: 15000,
								data: { action: 'sscribe_get_recent_exports', nonce: sscribe_data.nonce },
								success: function (recentResp) {
									if (
										recentResp.success &&
										recentResp.data &&
										recentResp.data.exports &&
										recentResp.data.exports.length > 0
									) {
										const latest = recentResp.data.exports[0];
										const resultData = {
											download_url: latest.url,
											filename: latest.filename,
											percentage: 100,
											processed: 0,
											total: 0,
										};
										self.isProcessing = false;
										self.updateProgress(100);
										self.exportComplete(resultData);
									} else {
										self.isProcessing = false;
										self.showError(
											response.data.message || 'Export failed to finalize. Please try again.',
											false,
											{}
										);
									}
								},
								error: function () {
									self.isProcessing = false;
									self.showError(
										response.data.message || 'Export failed to finalize. Please try again.',
										false,
										{}
									);
								},
							});
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
						// HTTP 404 = session was deleted (session cleanup on failure).
						// Stop retrying and show error — the session is gone so retry is futile.
						if (xhr.status === 404) {
							self.isProcessing = false;
							self.showError(
								sscribe_data.strings.err_zip ||
									'Export failed: no files were generated. Please check your format selection and try again.',
								false,
								{}
							);
							return;
						}

						// For 500 and other errors, retry up to maxAttempts.
						if (attempt < maxAttempts) {
							self.pollFinalize(sessionId, attempt + 1, 2000);
						} else {
							self.isProcessing = false;
							const msg = self.getNetworkErrorMessage(xhr, 'finalize_export');
							self.showError(msg);
						}
					},
				});
			}, delay);
		},

		toggleHistorySkeleton: function (show) {
			const $skel = $('#sscribe-history-skeleton');
			const $table = $('#sscribe-history-table');
			if (!$skel.length) { return; }
			if (show) {
				$skel.removeClass('sscribe-hidden');
				$table.addClass('sscribe-hidden');
			} else {
				$skel.addClass('sscribe-hidden');
				$table.removeClass('sscribe-hidden');
			}
		},

		refreshRecentExports: function () {
			this.toggleHistorySkeleton(true);
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_recent_exports',
					nonce: sscribe_data.nonce,
				},
				success: function (response) {
					if (response.success && response.data.exports) {
						SScribe.renderRecentExports(response.data.exports);
					}
					SScribe.toggleHistorySkeleton(false);
				},
				error: function () {
					SScribe.toggleHistorySkeleton(false);
				},
			});
		},

		renderRecentExports: function (exports) {
			const $table = $('#sscribe-history-table');
			const strings = sscribe_data.strings || {};

			if (!exports || exports.length === 0) {
				$table.html(
					'<div class="sscribe-history-empty"><em>' +
						this.escapeHtml(strings.history_empty || 'Your recent export packages will appear here.') +
						'</em></div>'
				);
				this.updateBulkBar();
				return;
			}

			let html = '';
			for (let i = 0; i < exports.length; i++) {
				const exp = exports[i];
				html += '<div class="sscribe-history-row" data-filename="' + this.escapeHtml(exp.filename) + '">';
				html +=
					'<label class="sscribe-history-check-label">' +
					'<input type="checkbox" class="sscribe-history-check" value="' +
					this.escapeHtml(exp.filename) +
					'">' +
					'<span class="sscribe-check-visual"></span>' +
					'</label>';
				html += '<div class="sscribe-history-file">';
				html += '<div class="sscribe-file-icon">';

				if (exp.flag_url) {
					html +=
						'<img src="' +
						this.escapeHtml(exp.flag_url) +
						'" alt="' +
						this.escapeHtml(exp.lang_name || '') +
						'" class="sscribe-file-icon-img">';
				} else {
					html +=
						'<span class="sscribe-file-icon-text">' +
						this.escapeHtml((exp.lang_code || 'EN').substring(0, 2).toUpperCase()) +
						'</span>';
				}

				html += '</div>';
				html += '<div class="sscribe-file-details">';
				html += '<strong>' + this.escapeHtml(exp.filename) + '</strong>';
				html +=
					'<span>' +
					this.escapeHtml(exp.date || '') +
					' — ' +
					this.escapeHtml(exp.size_formatted || exp.size || '') +
					'</span>';
				html += '</div></div>';
				html += '<div class="sscribe-history-actions">';
				html +=
					'<a href="' +
					this.escapeHtml(exp.url) +
					'" class="sscribe-button sscribe-button-icon sscribe-button-sm" download title="' +
					this.escapeHtml(strings.download_tooltip || 'Download') +
					'" aria-label="' +
					this.escapeHtml(strings.download_tooltip || 'Download') +
					'">';
				html +=
					'<img src="' +
					this.escapeHtml(sscribe_data.icons_url + 'download-file.svg') +
					'" width="16" height="16" alt="">';
				html += '</a>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-icon sscribe-button-sm sscribe-log-btn" data-filename="' +
					this.escapeHtml(exp.filename) +
					'" title="' +
					this.escapeHtml(strings.log_tooltip || 'View Log') +
					'" aria-label="' +
					this.escapeHtml(strings.log_tooltip || 'View Log') +
					'">';
				html +=
					'<img src="' +
					this.escapeHtml(sscribe_data.icons_url + 'file-log.svg') +
					'" width="16" height="16" alt="">';
				html += '</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-icon sscribe-button-sm sscribe-button-danger sscribe-delete-btn" data-filename="' +
					this.escapeHtml(exp.filename) +
					'" title="' +
					this.escapeHtml(strings.delete_tooltip || 'Delete') +
					'" aria-label="' +
					this.escapeHtml(strings.delete_tooltip || 'Delete') +
					'">';
				html +=
					'<img src="' +
					this.escapeHtml(sscribe_data.icons_url + 'trash.svg') +
					'" width="16" height="16" alt="">';
				html += '</button>';
				html += '</div></div>';
			}

			$table.html(html);
		},

		updateBulkBar: function () {
			const $checks = $('.sscribe-history-check:checked');
			const $all = $('.sscribe-history-check');
			const $bar = $('#sscribe-bulk-bar');
			const count = $checks.length;
			const total = $all.length;
			if (count > 0) {
				$bar.removeClass('sscribe-hidden');
				$('#sscribe-bulk-count').text(count + ' ' + (sscribe_data.strings.selected || 'selected'));
				$('.sscribe-history-row').removeClass('sscribe-row-selected');
				$checks.closest('.sscribe-history-row').addClass('sscribe-row-selected');
				const $selectAll = $('#sscribe-bulk-select-all');
				if (count === total) {
					$selectAll.prop('checked', true).prop('indeterminate', false);
				} else {
					$selectAll.prop('checked', false).prop('indeterminate', true);
				}
			} else {
				$bar.addClass('sscribe-hidden');
				$('.sscribe-history-row').removeClass('sscribe-row-selected');
				$('#sscribe-bulk-select-all').prop('checked', false).prop('indeterminate', false);
			}
		},

		toggleSelectAll: function (e) {
			const isChecked = $(e.currentTarget).prop('checked');
			$('.sscribe-history-check').prop('checked', isChecked);
			this.updateBulkBar();
		},

		deleteSingleExport: function (filename, callback) {
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_delete_export',
					nonce: sscribe_data.download_nonce,
					file: filename,
				},
				success: function (response) {
					if (response.success) {
						SScribe.showToast(sscribe_data.strings && sscribe_data.strings.delete_success || 'Export deleted.', 'success');
					} else {
						SScribe.showError(
							response.data.message ||
								(sscribe_data.strings && sscribe_data.strings.delete_failed) ||
								'Failed to delete export.'
						);
					}
					if (typeof callback === 'function') { callback(); }
				},
				error: function () {
					SScribe.showError(
						(sscribe_data.strings && sscribe_data.strings.delete_failed) || 'Failed to delete export.'
					);
					if (typeof callback === 'function') { callback(); }
				},
			});
		},

		bulkDeleteSelected: function () {
			const $checks = $('.sscribe-history-check:checked');
			if ($checks.length === 0) { return; }
			const filenames = [];
			$checks.each(function () {
				filenames.push($(this).val());
			});
			$checks.closest('.sscribe-history-row').addClass('sscribe-row-deleting');
			if (typeof this.bulkDeleteQueue === 'undefined') {
				this.bulkDeleteQueue = [];
			}
			this.bulkDeleteQueue = filenames.slice();
			this.processBulkDelete();
		},

		bulkDownload: function () {
			const $checks = $('.sscribe-history-check:checked');
			if ($checks.length === 0) { return; }
			$checks.each(function () {
				const $row = $(this).closest('.sscribe-history-row');
				const $downloadLink = $row.find('.sscribe-history-actions > a');
				if ($downloadLink.length) {
					const link = $downloadLink[0];
					link.click();
				}
			});
		},

		processBulkDelete: function () {
			if (this.bulkDeleteQueue.length === 0) {
				this.updateBulkBar();
				this.refreshRecentExports();
				return;
			}
			const filename = this.bulkDeleteQueue.shift();
			this.deleteSingleExport(filename, $.proxy(this.processBulkDelete, this));
		},

		updateProgress: function (percentage) {
			percentage = Number(percentage);
			if (!isFinite(percentage)) {
				percentage = 0;
			}
			percentage = Math.min(100, Math.max(0, percentage));

			const progressBar = document.getElementById('sscribe-progress-bar');
			if (progressBar) {
				progressBar.style.transform = 'scaleX(' + percentage / 100 + ')';
				progressBar.setAttribute('aria-valuenow', percentage);
			} else {
				$('#sscribe-progress-bar').css('width', percentage + '%');
			}
			$('#sscribe-progress-text').text(percentage + '%');

			const liveRegion = document.getElementById('sscribe-live-region');
			if (liveRegion) {
				const msg = (sscribe_data.strings && sscribe_data.strings.export_progress_prefix) || 'Export progress:';
				liveRegion.textContent = msg + ' ' + percentage + '%';
			}

			document.title = '(' + percentage + '%) SScribe Export';
		},

		updatePhase: function (phase) {
			const $steps = $('.sscribe-phase-step');
			if (!$steps.length) { return; }

			let found = false;
			$steps.each(function () {
				const $step = $(this);
				const stepPhase = $step.data('phase');

				if (stepPhase === phase) {
					$step.removeClass('sscribe-phase-completed sscribe-phase-active').addClass('sscribe-phase-active');
					found = true;
				} else if (!found) {
					$step.removeClass('sscribe-phase-active').addClass('sscribe-phase-completed');
				} else {
					$step.removeClass('sscribe-phase-completed sscribe-phase-active');
				}
			});
		},

		updateStatus: function (message) {
			$('#sscribe-status-text').text(message);

			const liveRegion = document.getElementById('sscribe-live-region');
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
			$('#sscribe-cancel-btn')
				.prop('disabled', false)
				.text(sscribe_data.strings.cancel || 'Cancel Export');
			this.updateProgress(0);
			this.updatePhase('fetching');
			$('#sscribe-export-btn, #sscribe-preview-btn').prop('disabled', true);
		},

		showError: function (message, isCancelled, errorData) {
			this.isProcessing = false;
			$('#sscribe-progress-area').fadeOut(200);

			let displayMessage = message;
			let guidance = '';

			if (errorData) {
				if (errorData.code) {
					displayMessage = '[' + errorData.code + '] ' + message;
				}
				if (errorData.guidance) {
					guidance = errorData.guidance;
				}
				if (errorData.fix_steps && errorData.fix_steps.length > 0) {
					guidance += (guidance ? '\n\n' : '') + (sscribe_data.strings.fix_steps || 'Steps to fix:') + '\n';
					for (let i = 0; i < errorData.fix_steps.length; i++) {
						guidance += i + 1 + '. ' + errorData.fix_steps[i] + '\n';
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

			const alertRegion = document.getElementById('sscribe-alert-region');
			if (alertRegion) {
				alertRegion.textContent = 'Export failed: ' + message;
			}

			if (isCancelled) {
				this.sessionId = null;
			}

			this.updateExportButton();
		},

		normalizeErrorData: function (errorData) {
			const normalized = errorData || {};
			const diagnostics = normalized.error_diagnostics || normalized.diagnostics || null;

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
			let escaped = this.escapeHtml(text);
			escaped = escaped.replace(/\n/g, '<br>');
			return escaped;
		},

		getErrorGuidance: function (message) {
			if (!message) { return ''; }
			const msg = message.toLowerCase();
			const s = sscribe_data.strings || {};

			if (msg.indexOf('permission') !== -1 || msg.indexOf('not allowed') !== -1) {
				return s.err_permission || '';
			}
			if (
				msg.indexOf('session expired') !== -1 ||
				msg.indexOf('session not found') !== -1 ||
				msg.indexOf('start again') !== -1
			) {
				return s.err_session_expired || '';
			}
			if (msg.indexOf('session data corrupted') !== -1) {
				return s.err_data_corrupted || '';
			}
			if (msg.indexOf('rate limit') !== -1 || msg.indexOf('too many requests') !== -1) {
				return s.err_rate_limit || '';
			}
			if (msg.indexOf('no pages found') !== -1) {
				return s.err_no_pages || '';
			}
			if (msg.indexOf('zip') !== -1 || msg.indexOf('package') !== -1) {
				return s.err_zip || '';
			}
			if (msg.indexOf('timeout') !== -1 || msg.indexOf('timed out') !== -1) {
				return s.err_timeout || '';
			}
			if (msg.indexOf('memory') !== -1) {
				return s.err_memory || '';
			}
			if (msg.indexOf('connection') !== -1 || msg.indexOf('network') !== -1) {
				return s.err_connection || '';
			}
			if (msg.indexOf('invalid language') !== -1) {
				return s.err_invalid_lang || '';
			}
			if (msg.indexOf('already have an export') !== -1 || msg.indexOf('in progress') !== -1) {
				return s.err_in_progress || '';
			}
			if (msg.indexOf('500') !== -1 || msg.indexOf('internal server error') !== -1) {
				return s.err_500 || '';
			}
			if (msg.indexOf('403') !== -1 || msg.indexOf('forbidden') !== -1) {
				return s.err_403 || '';
			}

			return s.err_generic || '';
		},

		getNetworkErrorMessage: function (xhr, _context) {
			const s = sscribe_data.strings || {};

			if (xhr && xhr.status === 0) {
				return s.net_connection_lost || '';
			}
			if (xhr && xhr.status === 403) {
				return s.net_403 || '';
			}
			if (xhr && xhr.status === 500) {
				return s.net_500 || '';
			}
			if (xhr && xhr.status === 502) {
				return s.net_502 || '';
			}
			if (xhr && xhr.status === 503) {
				return s.net_503 || '';
			}
			if (xhr && xhr.status === 504) {
				return s.net_504 || '';
			}
			if (xhr && xhr.statusText === 'timeout') {
				return s.net_timeout || '';
			}

			const statusCode = xhr && xhr.status ? xhr.status : 0;
			return (s.net_unknown || '').replace('%d', statusCode);
		},

		resetUI: function () {
			$('#sscribe-download-area').addClass('sscribe-hidden');
			$('#sscribe-error-area').addClass('sscribe-hidden');
			$('#sscribe-progress-area').addClass('sscribe-hidden');
			this.updateProgress(0);
			document.title = 'SScribe Export';
		},

		retry: function (e) {
			e.preventDefault();
			const self = this;

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_clear_session',
					nonce: sscribe_data.nonce,
					force: true,
				},
				success: function () {
					self.sessionId = null;
					self.isProcessing = false;
					self.resetUI();
					self.updateExportButton();
				},
				error: function () {
					self.sessionId = null;
					self.isProcessing = false;
					self.resetUI();
					self.updateExportButton();
				},
			});
		},

		downloadExport: function (e) {
			const $link = $(e.currentTarget);
			const originalHref = $link.attr('href');
			// Skip if not a download link (e.g., if event bubbling from a child button).
			if (!originalHref || !originalHref.includes('action=sscribe_download')) {
				return;
			}

			e.preventDefault();

			// Get a fresh nonce just before downloading to avoid expiry.
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 10000,
				data: {
					action: 'sscribe_refresh_download_nonce',
					nonce: sscribe_data.nonce,
				},
				success: function (response) {
					if (response.success && response.data && response.data.nonce) {
						const freshUrl = originalHref.replace(/nonce=[^&]+/, 'nonce=' + encodeURIComponent(response.data.nonce));
						window.location.href = freshUrl;
					} else {
						// Fallback: use the original URL (may fail with 403 if nonce expired, but better than breaking).
						window.location.href = originalHref;
					}
				},
				error: function () {
					// Fallback: proceed with original URL on network failure.
					window.location.href = originalHref;
				},
			});
		},

		deleteExport: function (e) {
			e.preventDefault();
			const $btn = $(e.currentTarget);
			const filename = $btn.data('filename');

			$btn.hide();
			const $confirmWrap = $('<span class="sscribe-inline-confirm" style="display:flex;align-items:center;gap:6px;">' +
				'<span style="font-size:12px;color:var(--sscribe-text-secondary);">' +
				(this.escapeHtml(sscribe_data.strings && sscribe_data.strings.confirm_delete) || 'Delete this?') +
				'</span>' +
				'<button type="button" class="sscribe-button sscribe-button-danger sscribe-btn-xs sscribe-confirm-yes">' +
				this.escapeHtml(sscribe_data.strings && sscribe_data.strings.delete || 'Delete') +
				'</button>' +
				'<button type="button" class="sscribe-button sscribe-button-ghost sscribe-btn-xs sscribe-confirm-no">Cancel</button>' +
				'</span>');
			$btn.after($confirmWrap);

			// Restore the delete button in case AJAX fails silently.
			$confirmWrap.find('.sscribe-confirm-yes').on('click', function () {
				$confirmWrap.remove();
				$btn.closest('.sscribe-history-row').fadeOut(300, function () {
					$(this).remove();
					if ($('.sscribe-history-row').length === 0) {
						const emptyMsg =
							(sscribe_data.strings && sscribe_data.strings.history_empty) ||
							'Your recent export packages will appear here.';
						$('#sscribe-history-table').html(
							'<div class="sscribe-history-empty"><em>' + SScribe.escapeHtml(emptyMsg) + '</em></div>'
						);
					}
					SScribe.updateBulkBar();
				});
				SScribe.deleteSingleExport(filename);
			});

			$confirmWrap.find('.sscribe-confirm-no').on('click', function () {
				$confirmWrap.remove();
				$btn.show();
			});
		},

		showExportLog: function (e) {
			e.preventDefault();
			const $btn = $(e.currentTarget);
			const filename = $btn.data('filename');

			this.saveFocus();

			$('#sscribe-log-modal').removeClass('sscribe-hidden');
			const loadingText = (sscribe_data.strings && sscribe_data.strings.loading_log) || 'Loading log...';
			$('#sscribe-log-content').html(
				'<div class="sscribe-log-loading"><span>' + this.escapeHtml(loadingText) + '</span></div>'
			);

			const modal = document.getElementById('sscribe-log-modal');
			if (!modal) {
				return;
			}
			this.trapFocus(modal);
			const self = this;
			const escapeHandler = function (e) {
				if (e.key === 'Escape') {
					self.closeModal();
				}
			};
			modal._sscribeEscapeHandler = escapeHandler;
			modal.addEventListener('keydown', escapeHandler);
			const closeBtn = modal.querySelector('.sscribe-modal-close');
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
					file: filename,
				},
				success: function (response) {
					if (response.success && response.data.log) {
						SScribe.renderLog(response.data.log, response.data.diagnostics || null);
					} else {
						const failMsg =
							(response && response.data && response.data.message) ||
							(sscribe_data.strings && sscribe_data.strings.log_not_found) ||
							'Log not found.';
						$('#sscribe-log-content').html('<div class="sscribe-log-empty"><p></p></div>');
						$('#sscribe-log-content').find('p').text(failMsg);
					}
				},
				error: function () {
					$('#sscribe-log-content').html('<div class="sscribe-log-empty"><p></p></div>');
					$('#sscribe-log-content')
						.find('p')
						.text((sscribe_data.strings && sscribe_data.strings.log_load_failed) || 'Failed to load log.');
				},
			});
		},

		renderLog: function (log, diagnostics) {
			const self = this;
			const strings = sscribe_data.strings || {};
			diagnostics = diagnostics || log.diagnostics || null;
			let html = '<div class="sscribe-log-summary">';
			html +=
				'<div class="sscribe-log-stat"><strong>' +
				self.escapeHtml(strings.log_total || 'Total:') +
				'</strong> ' +
				(log.total_pages || 0) +
				' ' +
				self.escapeHtml(strings.log_pages || 'pages') +
				'</div>';
			html +=
				'<div class="sscribe-log-stat"><strong>' +
				self.escapeHtml(strings.log_success_label || 'Success:') +
				'</strong> <span class="sscribe-log-success">' +
				(log.success || 0) +
				'</span></div>';
			html +=
				'<div class="sscribe-log-stat"><strong>' +
				self.escapeHtml(strings.log_failed_label || 'Failed:') +
				'</strong> <span class="sscribe-log-failed">' +
				(log.failed || 0) +
				'</span></div>';
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

				for (const pageId in log.pages) {
					const page = log.pages[pageId];
					const statusClass =
						page.status === 'success' ? 'sscribe-log-status-success' : 'sscribe-log-status-failed';
					const formats = page.formats ? Object.keys(page.formats).join(', ') : '';

					html += '<tr>';
					html += '<td>' + self.escapeHtml(String(page.id || '')) + '</td>';
					html += '<td>' + self.escapeHtml(page.title || strings.log_unknown || 'Unknown') + '</td>';
					html += '<td class="' + statusClass + '">' + self.escapeHtml(page.status) + '</td>';
					html +=
						'<td>' +
						(page.duration
							? self.escapeHtml(String(page.duration)) + (strings.log_seconds_suffix || 's')
							: strings.log_no_duration || '-') +
						'</td>';
					html += '<td>' + self.escapeHtml(formats) + '</td>';
					html += '</tr>';
				}

				html += '</tbody></table></div>';
			}

			if (log.errors && log.errors.length > 0) {
				html += '<div class="sscribe-log-errors">';
				html += '<h4>' + self.escapeHtml(strings.log_errors || 'Errors') + '</h4>';
				html += '<ul>';
				for (let i = 0; i < log.errors.length; i++) {
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
			let html = '<div class="sscribe-log-errors sscribe-log-diagnostics">';
			html +=
				'<h4>' +
				this.escapeHtml((sscribe_data.strings && sscribe_data.strings.log_diagnostics) || 'Diagnostics') +
				'</h4>';

			if (diagnostics.categories && diagnostics.categories.length) {
				html += '<div class="sscribe-log-diagnostic-badges">';
				for (let i = 0; i < diagnostics.categories.length; i++) {
					const category = diagnostics.categories[i];
					html +=
						'<span class="sscribe-badge ' +
						this.escapeHtml(this.getDiagnosticBadgeClass(category)) +
						'">' +
						this.escapeHtml(this.humanizeSupportKey(String(category))) +
						'</span>';
				}
				html += '</div>';
			}

			if (diagnostics.entries && diagnostics.entries.length) {
				html += '<ul class="sscribe-log-diagnostic-list">';
				for (let j = 0; j < diagnostics.entries.length; j++) {
					const entry = diagnostics.entries[j] || {};
					html += '<li>';
					html +=
						'<strong>' +
						this.escapeHtml(this.humanizeSupportKey(String(entry.category || 'unknown'))) +
						'</strong>';
					if (entry.severity) {
						html +=
							' <span class="sscribe-badge ' +
							this.escapeHtml(this.getSeverityBadgeClass(entry.severity)) +
							'">' +
							this.escapeHtml(String(entry.severity)) +
							'</span>';
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
				html +=
					'<h5>' +
					this.escapeHtml((sscribe_data.strings && sscribe_data.strings.fix_steps) || 'Steps to fix:') +
					'</h5>';
				html += '<ol>';
				for (let k = 0; k < diagnostics.fix_steps.length; k++) {
					html += '<li>' + this.escapeHtml(String(diagnostics.fix_steps[k])) + '</li>';
				}
				html += '</ol>';
			}

			if (diagnostics.technical) {
				html +=
					'<details class="sscribe-log-diagnostic-details"><summary>' +
					this.escapeHtml(
						(sscribe_data.strings && sscribe_data.strings.technical_details) || 'Technical details'
					) +
					'</summary>';
				html += '<pre>' + this.escapeHtml(JSON.stringify(diagnostics.technical, null, 2)) + '</pre>';
				html += '</details>';
			}

			html += '</div>';
			return html;
		},

		getDiagnosticBadgeClass: function (category) {
			const map = {
				memory_exhausted: 'sscribe-badge-danger',
				timeout: 'sscribe-badge-warning',
				pdf_generation: 'sscribe-badge-info',
				pdf_missing_library: 'sscribe-badge-info',
				pdf_filesystem: 'sscribe-badge-warning',
				zip_creation: 'sscribe-badge-warning',
			};

			return map[category] || 'sscribe-badge-muted';
		},

		getSeverityBadgeClass: function (severity) {
			const map = {
				critical: 'sscribe-badge-danger',
				error: 'sscribe-badge-warning',
				warning: 'sscribe-badge-muted',
			};

			return map[severity] || 'sscribe-badge-muted';
		},

		escapeHtml: function (text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		closeModal: function () {
			const modal = document.getElementById('sscribe-log-modal');
			if (modal && modal._sscribeTrapHandler) {
				modal.removeEventListener('keydown', modal._sscribeTrapHandler);
				modal._sscribeTrapHandler = null;
			}
			if (modal && modal._sscribeEscapeHandler) {
				modal.removeEventListener('keydown', modal._sscribeEscapeHandler);
				modal._sscribeEscapeHandler = null;
			}
			$('#sscribe-log-modal').addClass('sscribe-hidden');
			this.restoreFocus();
		},

		showPreview: function (e) {
			e.preventDefault();

			const language = $('input[name="sscribe_language"]:checked').val() || '';
			const postStatus = $('input[name="sscribe_post_status"]:checked').val() || 'publish';
			const postType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const format = $('input[name="sscribe_format"]:checked').val() || 'docx';

			const $panel = $('#sscribe-preview-panel');
			const $content = $('#sscribe-preview-content');

			$panel.removeClass('sscribe-hidden');

			const panel = $panel[0];
			this.trapFocus(panel);
			const closeBtn = panel.querySelector('#sscribe-preview-close');
			if (closeBtn) {
				closeBtn.focus();
			}
			const self = this;
			const escapeHandler = function (ev) {
				if (ev.key === 'Escape') {
					self.closePreview();
				}
			};
			panel._sscribeEscapeHandler = escapeHandler;
			panel.addEventListener('keydown', escapeHandler);
			$content.html(
				'<div class="sscribe-preview-loading"><span class="sscribe-loading-spinner"></span><span>' +
					(sscribe_data.strings.generating_preview || 'Generating preview...') +
					'</span></div>'
			);

			this.saveFocus();

			const panelOffset = $panel.offset();
			if (panelOffset) {
				$('html, body').animate(
					{
						scrollTop: panelOffset.top - 20,
					},
					300
				);
			}

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
					format: format,
				},
				success: function (response) {
					if (response.success && response.data) {
						self.renderPreview(response.data);
					} else {
						const fallbackMsg =
							(response && response.data && response.data.message) || 'Preview not available';
						$content.html('<p class="sscribe-preview-note">' + self.escapeHtml(fallbackMsg) + '</p>');
					}
				},
				error: function () {
					self.renderFallbackPreview(language, postStatus, format);
				},
			});
		},

		renderPreview: function (data) {
			const $content = $('#sscribe-preview-content');
			const strings = sscribe_data.strings || {};

			let html = '<div class="sscribe-preview-sample">';
			html += '<h1>' + this.escapeHtml(data.title || strings.preview_sample_title || 'Sample Page') + '</h1>';

			if (data.url) {
				html +=
					'<p style="color:var(--sscribe-text-muted);font-size:13px;margin:0 0 12px;">' +
					this.escapeHtml(data.url) +
					'</p>';
			}

			if (data.content) {
				const previewContent = data.content.length > 300 ? data.content.substring(0, 300) + '...' : data.content;
				html +=
					'<div style="color:var(--sscribe-text-secondary);font-size:13px;line-height:1.6;">' +
					this.escapeHtml(previewContent) +
					'</div>';
			}

			html += '<div class="sscribe-preview-meta">';

			if (data.total_pages !== undefined) {
				html +=
					'<div class="sscribe-preview-meta-item"><strong>' +
					this.escapeHtml(strings.preview_total_pages || 'Total Pages') +
					'</strong>' +
					data.total_pages +
					'</div>';
			}
			if (data.format) {
				const formatLabels = {
					docx: strings.format_docx || 'Word Document (DOCX)',
					pdf: strings.format_pdf || 'PDF Document',
					html: strings.format_html || 'HTML Page',
					markdown: strings.format_markdown || 'Markdown',
				};
				html +=
					'<div class="sscribe-preview-meta-item"><strong>' +
					this.escapeHtml(strings.preview_format || 'Format') +
					'</strong>' +
					this.escapeHtml(formatLabels[data.format] || data.format.toUpperCase()) +
					'</div>';
			}
			if (data.estimated_time) {
				html +=
					'<div class="sscribe-preview-meta-item"><strong>' +
					this.escapeHtml(strings.preview_estimated_time || 'Estimated Time') +
					'</strong>' +
					this.escapeHtml(data.estimated_time) +
					'</div>';
			}
			if (data.file_size_estimate) {
				html +=
					'<div class="sscribe-preview-meta-item"><strong>' +
					this.escapeHtml(strings.preview_file_size || 'Estimated Size') +
					'</strong>' +
					this.escapeHtml(data.file_size_estimate) +
					'</div>';
			}

			html += '</div>';

			html +=
				'<p class="sscribe-preview-note">' +
				this.escapeHtml(
					strings.preview_note ||
						'This is a preview of your export configuration. The actual export will include all selected pages with full formatting and SEO metadata.'
				) +
				'</p>';
			html += '</div>';

			$content.html(html);
		},

		renderFallbackPreview: function (language, postStatus, format) {
			const $content = $('#sscribe-preview-content');
			const strings = sscribe_data.strings || {};

			const formatLabels = {
				docx: strings.format_docx || 'Word Document (DOCX)',
				pdf: strings.format_pdf || 'PDF Document',
				html: strings.format_html || 'HTML Page',
				markdown: strings.format_markdown || 'Markdown',
			};

			let html = '<div class="sscribe-preview-sample">';
			html += '<h1>' + this.escapeHtml(strings.preview_sample_title || 'Sample Export') + '</h1>';
			html += '<div class="sscribe-preview-meta">';
			html +=
				'<div class="sscribe-preview-meta-item"><strong>' +
				this.escapeHtml(strings.preview_total_pages || 'Total Pages') +
				'</strong>' +
				this.selectedPageCount +
				'</div>';
			html +=
				'<div class="sscribe-preview-meta-item"><strong>' +
				this.escapeHtml(strings.preview_format || 'Format') +
				'</strong>' +
				this.escapeHtml(formatLabels[format] || format) +
				'</div>';
			html +=
				'<div class="sscribe-preview-meta-item"><strong>' +
				this.escapeHtml(strings.preview_language || 'Language') +
				'</strong>' +
				this.escapeHtml(language || 'All Languages') +
				'</div>';
			html +=
				'<div class="sscribe-preview-meta-item"><strong>' +
				this.escapeHtml(strings.preview_status || 'Status') +
				'</strong>' +
				this.escapeHtml(postStatus) +
				'</div>';
			html += '</div>';
			html +=
				'<p class="sscribe-preview-note">' +
				this.escapeHtml(
					strings.preview_fallback_note ||
						'Preview shows your export configuration. The actual export will include all selected pages with professional formatting.'
				) +
				'</p>';
			html += '</div>';

			$content.html(html);
		},

		closePreview: function () {
			const panel = $('#sscribe-preview-panel')[0];
			if (panel && panel._sscribeEscapeHandler) {
				panel.removeEventListener('keydown', panel._sscribeEscapeHandler);
				panel._sscribeEscapeHandler = null;
			}
			if (panel && panel._sscribeTrapHandler) {
				panel.removeEventListener('keydown', panel._sscribeTrapHandler);
				panel._sscribeTrapHandler = null;
			}
			$('#sscribe-preview-panel').addClass('sscribe-hidden');
			this.restoreFocus();
		},

		loadSupportInfo: function (e) {
			if (e) {
				e.preventDefault();
			}

			// Guard against concurrent calls — disable button during AJAX.
			const $btn = $('#sscribe-support-refresh-btn');
			if ($btn.length && $btn.prop('disabled')) {
				return;
			}
			$btn.prop('disabled', true);

			const strings = sscribe_data.strings || {};
			const $grid = $('#sscribe-support-grid');
			const $copy = $('#sscribe-support-copy-text');
			const $feedback = $('#sscribe-support-feedback');

			$grid.html(
				'<div class="sscribe-support-loading">' +
					this.escapeHtml(strings.support_loading || 'Loading support information...') +
					'</div>'
			);
			$copy.val('');
			$('#sscribe-support-copy-btn').prop('disabled', true);
			$feedback.addClass('sscribe-hidden').text('');

			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_support_info',
					nonce: sscribe_data.nonce,
				},
				success: function (response) {
					if (response.success && response.data) {
						SScribe.renderSupportInfo(response.data);
						$btn.prop('disabled', false);
						return;
					}

					SScribe.renderSupportError(
						(response.data && response.data.message) ||
							strings.support_error ||
							'Unable to load support information right now.'
					);
					$btn.prop('disabled', false);
				},
				error: function () {
					SScribe.renderSupportError(
						strings.support_error || 'Unable to load support information right now.'
					);
					$btn.prop('disabled', false);
				},
			});
		},

		renderSupportInfo: function (data) {
			const strings = sscribe_data.strings || {};
			let html = '';

			if (data.sections) {
				for (const key in data.sections) {
					if (!Object.prototype.hasOwnProperty.call(data.sections, key)) { continue; }
					const section = data.sections[key];
					html += '<section class="sscribe-support-section">';
					html += '<h3 class="sscribe-support-section-title">' + this.escapeHtml(section.label) + '</h3>';
					html += '<dl class="sscribe-support-list">';
					for (const itemKey in section.items) {
						if (!Object.prototype.hasOwnProperty.call(section.items, itemKey)) { continue; }
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
				for (let i = 0; i < data.audit_events.length; i++) {
					const event = data.audit_events[i];
					html +=
						'<li><strong>' +
						this.escapeHtml(String(event.event || '')) +
						'</strong><span>' +
						this.escapeHtml(String(event.timestamp || '')) +
						'</span></li>';
				}
				html += '</ul></section>';
			}

			$('#sscribe-support-grid').html(
				html ||
					'<div class="sscribe-support-loading">' +
						this.escapeHtml(strings.support_error || 'Unable to load support information right now.') +
						'</div>'
			);
			$('#sscribe-support-copy-text').val(data.copy_text || '');
			$('#sscribe-support-copy-btn').prop('disabled', !(data.copy_text && data.copy_text.length));
			$('#sscribe-support-debug-note').toggleClass('sscribe-hidden', !data.has_debug_mode);

			if (data.generated_at) {
				$('#sscribe-support-feedback')
					.removeClass('sscribe-hidden')
					.text((strings.support_generated || 'Generated') + ': ' + data.generated_at + ' UTC');
			}
		},

		renderSupportError: function (message) {
			$('#sscribe-support-grid').html(
				'<div class="sscribe-support-error">' + this.escapeHtml(message) + '</div>'
			);
			$('#sscribe-support-copy-btn').prop('disabled', true);
		},

		showToast: function (message, type, duration) {
			type = type || 'info';
			duration = (typeof duration === 'number') ? duration : 4000;

			const $container = $('#sscribe-toast-container');
			if (!$container.length) { return; }

			const icons = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' };
			const icon = icons[type] || 'ℹ';

			// Cap visible toasts at 5 to prevent overflow.
			const $existing = $container.children('.sscribe-toast');
			if ($existing.length >= 5) {
				$existing.last().addClass('sscribe-toast-removing');
				setTimeout(function () { $existing.last().remove(); }, 200);
			}

			const $toast = $(
				'<div class="sscribe-toast sscribe-toast-' + type + '" role="status">' +
					'<span class="sscribe-toast-icon">' + icon + '</span>' +
					'<span class="sscribe-toast-message">' + this.escapeHtml(message) + '</span>' +
					'<button type="button" class="sscribe-toast-dismiss" aria-label="Dismiss">&times;</button>' +
				'</div>'
			);

			$container.append($toast);

			$toast.find('.sscribe-toast-dismiss').on('click', function () {
				$toast.addClass('sscribe-toast-removing');
				setTimeout(function () { $toast.remove(); }, 200);
			});

			if (duration > 0) {
				setTimeout(function () {
					$toast.addClass('sscribe-toast-removing');
					setTimeout(function () { $toast.remove(); }, 200);
				}, duration);
			}
		},

		copySupportInfo: function (e) {
			e.preventDefault();
			const text = $('#sscribe-support-copy-text').val();
			const strings = sscribe_data.strings || {};

			if (!text) {
				return;
			}

			const onSuccess = function () {
				SScribe.showToast(strings.support_copied || 'Support information copied.', 'success');
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
			const textarea = document.getElementById('sscribe-support-copy-text');
			if (!textarea) {
				return;
			}
			textarea.focus();
			textarea.select();
			try {
				document.execCommand('copy');
				onSuccess();
			} catch {
				$('#sscribe-support-feedback')
					.removeClass('sscribe-hidden')
					.text(sscribe_data.strings && sscribe_data.strings.support_copy_error || 'Copy failed. Try selecting the text manually.');
			}
		},

		humanizeSupportKey: function (key) {
			return key.replace(/_/g, ' ').replace(/\b\w/g, function (char) {
				return char.toUpperCase();
			});
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

			const focusableSelectors =
				'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
			const focusableElements = container.querySelectorAll(focusableSelectors);

			if (focusableElements.length === 0) {
				return;
			}

			const firstFocusable = focusableElements[0];
			const lastFocusable = focusableElements[focusableElements.length - 1];

			if (container._sscribeTrapHandler) {
				container.removeEventListener('keydown', container._sscribeTrapHandler);
			}

			const handler = function (e) {
				if (e.key !== 'Tab') { return; }
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
