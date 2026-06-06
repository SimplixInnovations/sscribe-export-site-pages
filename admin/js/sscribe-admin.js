/**
 * SScribe Admin JavaScript
 *
 * Handles AJAX batch processing with animated progress tracking.
 *
 * @package SScribe
 * @version 1.1.1
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
		_originalTitle: '',
		_batchXHR: null,
		_finalizingXHR: null,
		_langCountsXHRs: null,

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
			const cleaned = text.trim().replace(/[.,' ]/g, '');
			const num = parseInt(cleaned, 10);
			return isNaN(num) ? 0 : num;
		},

		init: function () {
			if (typeof sscribe_data === 'undefined' || !sscribe_data) {
				return;
			}
			this._originalTitle = document.title;
			this.bindEvents();
			this.initializeTabs();
			this.adjustToastContainerPosition();

			// Set initial disabled-reason text while AJAX counts load.
			$('#sscribe-export-disabled-reason').text(
				(sscribe_data.strings && sscribe_data.strings.loading_counts) || 'Loading page counts...'
			);

			this.updateConfigSummary();

			const defaultPostType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const defaultLanguage = $('input[name="sscribe_language"]:checked').val() || '';
			this.refreshStatusAndLanguageCounts(defaultPostType, defaultLanguage);

			// Check for active session on page load - restore UI if one exists.
			this.checkActiveSession();

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
			// Unbind any previously bound namespaced events to prevent duplicates.
			$(document).off('.sscribe');
			$('.sscribe-lang-card-label').off('.sscribe');
			$('input[name="sscribe_post_type"]').off('.sscribe');
			$('input[name="sscribe_language"]').off('.sscribe');
			$('input[name="sscribe_post_status"]').off('.sscribe');
			$('input[name="sscribe_format"]').off('.sscribe');

			$(document).on('click.sscribe', '#sscribe-export-btn', $.proxy(this.startExport, this));
			$(document).on('click.sscribe', '#sscribe-preview-btn', $.proxy(this.showPreview, this));
			$(document).on('click.sscribe', '#sscribe-preview-close', $.proxy(this.closePreview, this));
			$(document).on('click.sscribe', '#sscribe-preview-dismiss-btn', $.proxy(this.closePreview, this));
			$(document).on('click.sscribe', '#sscribe-preview-start-btn', $.proxy(this.startExportFromPreview, this));
			$(document).on('click.sscribe', '#sscribe-new-export-btn', function (e) {
				e.preventDefault();
				window.location.reload();
			});
			$(document).on('click.sscribe', '#sscribe-error-try-again', $.proxy(this.retry, this));
			$(document).on('click.sscribe', '#sscribe-cancel-btn', $.proxy(this.cancelExport, this));

			$('.sscribe-lang-card-label').on('click.sscribe', function () {
				$(this).find('input[type="radio"]').prop('checked', true);
			});

			$('input[name="sscribe_post_type"]').on('change.sscribe', $.proxy(this.onPostTypeChange, this));
			$('input[name="sscribe_language"]').on('change.sscribe', $.proxy(this.onLanguageChange, this));
			$('input[name="sscribe_post_status"]').on('change.sscribe', $.proxy(this.updateConfigSummary, this));
			$('input[name="sscribe_format"]').on('change.sscribe', $.proxy(this.onFormatChange, this));

			$(document).on('click.sscribe', '.sscribe-delete-btn', $.proxy(this.deleteExport, this));
			$(document).on('click.sscribe', '.sscribe-log-btn', $.proxy(this.showExportLog, this));
			$(document).on('change.sscribe', '.sscribe-history-check', $.proxy(this.updateBulkBar, this));
			$(document).on('change.sscribe', '#sscribe-bulk-select-all', $.proxy(this.toggleSelectAll, this));
			$(document).on('click.sscribe', '#sscribe-bulk-delete-btn', $.proxy(this.bulkDeleteSelected, this));
			$(document).on('click.sscribe', '#sscribe-bulk-download-btn', $.proxy(this.bulkDownload, this));
			$(document).on('click.sscribe', '#sscribe-support-refresh-btn', $.proxy(this.loadSupportInfo, this));
			$(document).on('click.sscribe', '#sscribe-support-copy-btn', $.proxy(this.copySupportInfo, this));
			$(document).on('click.sscribe', '#sscribe-modal-close', $.proxy(this.closeModal, this));
			$(document).on('click.sscribe', '.sscribe-history-actions > a', $.proxy(this.downloadExport, this));
			$(document).on('click.sscribe', '#sscribe-preview-panel', function (e) {
				if (e.target === this) {
					SScribe.closePreview();
				}
			});

			const self = this;

			// Keyboard shortcuts + modal escape handling.
			$(document).on('keydown.sscribe', function (e) {
				if (e.key === 'Escape' || e.key === 'Esc') {
					const $previewPanel = $('#sscribe-preview-panel');
					const $logModal = $('#sscribe-log-modal');
					const $preflight = $('.sscribe-preflight-banner');
					if ($preflight.length) {
						e.preventDefault();
						$preflight.find('.sscribe-preflight-close').trigger('click');
						return;
					}
					if ($previewPanel.length && !$previewPanel.hasClass('sscribe-hidden')) {
						e.preventDefault();
						self.closePreview();
						return;
					}
					if ($logModal.length && !$logModal.hasClass('sscribe-hidden')) {
						e.preventDefault();
						self.closeModal();
						return;
					}
				}

				if (!e.ctrlKey && !e.metaKey) {
					return;
				}
				// Don't trigger shortcuts when user is typing in an input, textarea, or contenteditable.
				const tag = e.target.tagName;
				if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable) {
					return;
				}
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
		},

		initializeTabs: function () {
			const self = this;
			const $tabs = $('.sscribe-tab-btn');
			if (!$tabs.length) {
				return;
			}

			let activeTabId = $tabs.filter('.sscribe-tab-active').first().data('tab');
			if (!activeTabId) {
				activeTabId = $tabs.first().data('tab');
			}

			this.setActiveTab(activeTabId);

			$tabs.on('click', function (e) {
				e.preventDefault();
				const $btn = $(this);
				self.activateTab($btn.data('tab'), true);
			});

			$tabs.on('keydown', function (e) {
				const key = e.key;
				if (!['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(key)) {
					return;
				}
				e.preventDefault();

				const $orderedTabs = $('.sscribe-tab-btn');
				const currentIndex = $orderedTabs.index(this);
				if (currentIndex === -1) {
					return;
				}

				let nextIndex = currentIndex;
				if (key === 'ArrowRight') {
					nextIndex = (currentIndex + 1) % $orderedTabs.length;
				} else if (key === 'ArrowLeft') {
					nextIndex = (currentIndex - 1 + $orderedTabs.length) % $orderedTabs.length;
				} else if (key === 'Home') {
					nextIndex = 0;
				} else if (key === 'End') {
					nextIndex = $orderedTabs.length - 1;
				}

				const $nextTab = $orderedTabs.eq(nextIndex);
				self.activateTab($nextTab.data('tab'), true);
			});
		},

		activateTab: function (tabId, moveFocus) {
			if (!tabId) {
				return;
			}

			const currentTabId = $('.sscribe-tab-btn[aria-selected="true"]').data('tab');
			if (currentTabId === tabId) {
				if (moveFocus) {
					$('.sscribe-tab-btn[data-tab="' + tabId + '"]').trigger('focus');
				}
				return;
			}

			this.setActiveTab(tabId);

			if (moveFocus) {
				$('.sscribe-tab-btn[data-tab="' + tabId + '"]').trigger('focus');
			}

			if (tabId === 'debug' && typeof window.SScribeDebugConsole !== 'undefined') {
				if (!window.SScribeDebugConsole.initialized) {
					window.SScribeDebugConsole.init();
				} else if (window.SScribeDebugConsole.isAutoRefresh) {
					window.SScribeDebugConsole.startAutoRefresh();
				}
			} else if (currentTabId === 'debug' && typeof window.SScribeDebugConsole !== 'undefined') {
				window.SScribeDebugConsole.stopAutoRefresh();
			}
		},

		setActiveTab: function (tabId) {
			const self = this;

			$('.sscribe-tab-btn').each(function () {
				const $button = $(this);
				const isActive = $button.data('tab') === tabId;
				$button
					.attr('aria-selected', isActive ? 'true' : 'false')
					.attr('tabindex', isActive ? '0' : '-1')
					.toggleClass('sscribe-tab-active', isActive);
			});

			$('.sscribe-tab-content').each(function () {
				const $panel = $(this);
				const isActive = $panel.attr('id') === 'sscribe-tab-' + tabId;

				$panel.find(':focus').trigger('blur');

				if (isActive) {
					$panel
						.addClass('sscribe-tab-active')
						.removeAttr('aria-hidden')
						.attr('tabindex', '0')
						.removeAttr('hidden');
				} else {
					$panel
						.removeClass('sscribe-tab-active')
						.attr('aria-hidden', 'true')
						.attr('tabindex', '-1')
						.removeAttr('hidden');

					self.releaseFocusTrap($panel[0]);
				}
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
			$('#sscribe-page-count, #sscribe-post-count, #sscribe-both-count').addClass('sscribe-loading-count');

			if (self._countsXHR && self._countsXHR.abort) {
				self._countsXHR.abort();
			}
			self._countsXHR = $.ajax({
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
					if (response.success && response.data) {
						const allCounts = response.data.counts || {};
						const pageCounts = response.data.counts_page || allCounts;
						const postCounts = response.data.counts_post || {};
						const anyCounts = response.data.counts_any || {};

						self.updateStatusCounts(allCounts);

						const pageTotal = self.parseLocalizedInt(pageCounts.all) || 0;
						const postTotal = self.parseLocalizedInt(postCounts.all) || 0;
						const anyTotal = self.parseLocalizedInt(anyCounts.all) || 0;

						$('#sscribe-page-count').text(pageTotal.toLocaleString());
						$('#sscribe-post-count').text(postTotal.toLocaleString());
						$('#sscribe-both-count').text(anyTotal.toLocaleString());

						self._countsLoaded = true;
						self.updateConfigSummary();
						self.updateExportButton();
					}
					$('.sscribe-status-card-label').removeClass('sscribe-loading');
					$('#sscribe-page-count, #sscribe-post-count, #sscribe-both-count').removeClass('sscribe-loading-count');
				},
				error: function () {
					$('.sscribe-status-card-label').removeClass('sscribe-loading');
					$('#sscribe-page-count, #sscribe-post-count, #sscribe-both-count').removeClass('sscribe-loading-count');
				},
			});

			if (!this._langCountsXHRs) {
				this._langCountsXHRs = [];
			}
			const pending = this._langCountsXHRs.slice();
			this._langCountsXHRs = [];
			pending.forEach(function (xhr) {
				if (xhr && xhr.abort) {
					xhr.abort();
				}
			});

			$('input[name="sscribe_language"]').each(function () {
				const langCode = $(this).val();
				if (!langCode) {
					return;
				}

				if (self._langXHRsByCode && self._langXHRsByCode[langCode] && self._langXHRsByCode[langCode].abort) {
					self._langXHRsByCode[langCode].abort();
				}
				if (!self._langXHRsByCode) {
					self._langXHRsByCode = {};
				}

				const xhr = $.ajax({
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
						if (response.success && response.data) {
							const pageCounts = response.data.counts_page || response.data.counts || {};
							const postCounts = response.data.counts_post || {};
							const anyCounts = response.data.counts_any || {};

							const pageTotal = self.parseLocalizedInt(pageCounts.all) || 0;
							const postTotal = self.parseLocalizedInt(postCounts.all) || 0;
							const anyTotal = self.parseLocalizedInt(anyCounts.all) || 0;

							const currentPostType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
							const displayTotal = 'post' === currentPostType
								? postTotal
								: ( 'any' === currentPostType ? anyTotal : pageTotal );

							const $langLabel = $('input[name="sscribe_language"][value="' + langCode + '"]')
								.closest('.sscribe-lang-card-label');
							$langLabel.find('.sscribe-lang-count').text(displayTotal.toLocaleString());
							$langLabel.attr('data-count-page', pageTotal);
							$langLabel.attr('data-count-post', postTotal);
							$langLabel.attr('data-count-any', anyTotal);
						}
					},
					error: function () {},
					complete: function () {
						const idx = self._langCountsXHRs.indexOf(xhr);
						if (idx > -1) {
							self._langCountsXHRs.splice(idx, 1);
						}
					},
				});
				self._langCountsXHRs.push(xhr);
				self._langXHRsByCode[langCode] = xhr;
			});
		},

		checkActiveSession: function () {
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 10000,
				data: {
					action: 'sscribe_check_active_session',
					nonce: sscribe_data.nonce,
				},
				success: function (response) {
					if (response.success && response.data && response.data.has_active) {
						SScribe.isProcessing = true;
						SScribe.sessionId = response.data.session_id;
						SScribe.updateProgress(response.data.percentage);
						SScribe.updatePhase(response.data.status);
						$('#sscribe-cancel-btn')
							.prop('disabled', false)
							.text(sscribe_data.strings.cancel || 'Cancel Export');
						$('#sscribe-export-btn, #sscribe-preview-btn').prop('disabled', true);
						$('#sscribe-progress-area').show();
						SScribe.processBatch();
					} else {
						SScribe.isProcessing = false;
					}
				},
				error: function () {
					SScribe.isProcessing = false;
					SScribe.updateExportButton();
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
				this.updateConfigSummary();
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

			const postTypeLabels = { page: 'Pages', post: 'Posts', any: 'Both' };
			const statusLabels = {
				publish: 'Published',
				draft: 'Draft',
				private: 'Private',
				future: 'Scheduled',
				pending: 'Pending',
				all: 'All',
			};

			$('#sscribe-summary-post-type').text(postTypeLabels[postType] || postType);
			$('#sscribe-summary-status').text(statusLabels[status] || status);
			$('#sscribe-summary-language').text(language ? language.toUpperCase() : 'All');
			$('#sscribe-summary-format').text(format === 'all' ? 'All' : format.toUpperCase());
			$('#sscribe-summary-pages').text('~' + count + ' ' + sscribe_data.strings.log_pages);
			$('#sscribe-summary-time').text(sscribe_data.strings.calculating_time || 'Calculating...');

			if (this._configSummaryXHR && this._configSummaryXHR.abort) {
				this._configSummaryXHR.abort();
			}
			const self = this;
			clearTimeout(this._configSummaryDebounceTimer);
			if (!this._countsLoaded || count === 0) {
				$('#sscribe-summary-time').text(sscribe_data.strings.summary_time_hint || 'See Preview');
				return;
			}
			this._configSummaryDebounceTimer = setTimeout(function () {
				self._configSummaryXHR = $.ajax({
					url: sscribe_data.ajaxurl,
					type: 'POST',
					timeout: 15000,
					data: {
						action: 'sscribe_get_export_preview',
						nonce: sscribe_data.nonce,
						language: language || '',
						post_status: status,
						post_type: postType,
						format: format,
						formats: format === 'all' ? ['docx', 'pdf', 'html', 'markdown'] : [format],
					},
					success: function (response) {
						if (response.success && response.data && response.data.estimated_time) {
							$('#sscribe-summary-time').text(response.data.estimated_time);
						} else {
							$('#sscribe-summary-time').text(sscribe_data.strings.summary_time_hint || 'See Preview');
						}
					},
					error: function (xhr, status) {
						if (status === 'abort') {
							return;
						}
						$('#sscribe-summary-time').text(sscribe_data.strings.summary_time_hint || 'See Preview');
					},
				});
			}, 300);

			const liveRegion = document.getElementById('sscribe-live-region');
			if (liveRegion) {
				if (count > 0) {
					liveRegion.textContent =
						count + ' ' + (sscribe_data.strings.log_pages || 'pages') + ' ready for export';
				} else {
					liveRegion.textContent = 'No pages match selected options. Export button is disabled.';
				}
			}

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
					$reason.text(
						(sscribe_data.strings && sscribe_data.strings.err_no_pages) || 'No pages match selected options'
					);
				} else if (!hasStatus) {
					$reason.text(sscribe_data.strings.select_status || 'Select a post status');
				} else if (!hasFormat) {
					$reason.text(sscribe_data.strings.select_format || 'Select a format');
				} else if (!hasPostType) {
					$reason.text(sscribe_data.strings.select_post_type || 'Select a post type');
				} else if (!hasLanguage) {
					$reason.text(sscribe_data.strings.select_language || 'Select a language');
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

			// Clear any pending config summary debounce timer.
			clearTimeout(this._configSummaryDebounceTimer);
			if (this._configSummaryXHR && this._configSummaryXHR.abort) {
				this._configSummaryXHR.abort();
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
				const errorCount = errors.length;
				for (let i = 0; i < errorCount; i++) {
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
				const warnCount = warnings.length;
				for (let j = 0; j < warnCount; j++) {
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
			$banner.attr('role', 'alert');

			const $firstFocusable = $banner.find('.sscribe-preflight-close');
			if ($firstFocusable.length && typeof $firstFocusable[0].focus === 'function') {
				setTimeout(function () {
					$firstFocusable[0].focus();
				}, 100);
			}

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
				SScribe.refreshStatusAndLanguageCounts(
					$('input[name="sscribe_post_type"]:checked').val() || 'page',
					$('input[name="sscribe_language"]:checked').val() || ''
				);
			});

			$banner.on('click.sscribe-preflight', '.sscribe-preflight-close', function () {
				$banner.fadeOut(200, function () {
					$banner.remove();
				});
				SScribe.isProcessing = false;
				SScribe.resetUI();
				SScribe.updateExportButton();
				SScribe.refreshStatusAndLanguageCounts(
					$('input[name="sscribe_post_type"]:checked').val() || 'page',
					$('input[name="sscribe_language"]:checked').val() || ''
				);
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
						// All cleanup attempts exhausted — do not proceed, as a stale
						// session ghost may remain and conflict with a new export.
						self.isProcessing = false;
						self.resetUI();
						self.updateExportButton();
						self.showError(
							sscribe_data.strings.err_clear_session ||
								'Could not clear the previous export session. Please try again in a moment.',
							false,
							{}
						);
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
					SScribe.isProcessing = false;
					const serverMsg = SScribe.parseServerError(xhr);
					const msg = serverMsg || SScribe.getNetworkErrorMessage(xhr, 'start_export');
					const errData = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
					SScribe.showError(msg, false, SScribe.normalizeErrorData(errData));
				},
			});
		},

		scheduleNextBatch: function (retryInMs, isRetry) {
			const self = this;
			let delay;

			if (typeof retryInMs === 'number' && isFinite(retryInMs) && retryInMs > 0) {
				delay = Math.max(0, Math.floor(retryInMs));
				self.pollBackoff = 0;
			} else if (isRetry) {
				const backoffMultiplier = Math.pow(2, Math.max(0, self.pollBackoff));
				const base = self.pollBackoffBase * backoffMultiplier;
				delay = Math.min(self.pollBackoffMax, Math.floor(base));
				if (backoffMultiplier < self.pollBackoffMax / self.pollBackoffBase) {
					self.pollBackoff++;
				}
			} else {
				delay = Math.min(self.pollBackoffMax, Math.floor(self.pollBackoffBase));
				self.pollBackoff = 0;
			}

			const jitter = Math.floor(Math.random() * (self.pollJitter * 2 + 1)) - self.pollJitter;
			delay = Math.max(0, delay + jitter);
			delay = Math.max(1500, delay);

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

			this._batchXHR = $.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 200000,
				data: {
					action: 'sscribe_process_batch',
					nonce: sscribe_data.nonce,
					session_id: this.sessionId,
				},
				success: function (response) {
					self._batchInProgress = false;
					self._batchXHR = null;
					if (response.success) {
						SScribe.batchRetries = 0;
						const data = response.data;

						SScribe.updateProgress(data.percentage, data.current_page, data.total_pages);
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
							let timeStr;
							if (minutes > 0) {
								timeStr = strings.min_sec_remaining
									? strings.min_sec_remaining.replace('%1$d', minutes).replace('%2$d', seconds)
									: minutes + ' min ' + seconds + ' sec remaining';
							} else {
								timeStr = seconds + ' ' + (strings.sec_remaining || 'sec remaining');
							}
							$('#sscribe-time-remaining').text(timeStr).show();
						}

						if (data.status === 'complete') {
							SScribe.exportComplete(data);
						} else if (data.status === 'finalizing') {
							const finalizeDelay = SScribe.finalizePollInterval || 2000;
							SScribe.pollFinalize(SScribe.sessionId, 0, finalizeDelay);
						} else {
							SScribe.pollBackoff = 0;
							SScribe.scheduleNextBatch(0, false);
						}
					} else {
						const isCancelled = response.data.cancelled === true;
						if (response.data.retry === true) {
							SScribe.scheduleNextBatch(response.data && response.data.retry_in, true);
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
					self._batchInProgress = false;
					self._batchXHR = null;
					SScribe.batchRetries++;
					if (SScribe.batchRetries <= SScribe.maxBatchRetries) {
						SScribe.scheduleNextBatch(undefined, true);
					} else {
						const serverMsg = SScribe.parseServerError(xhr);
						const msg = serverMsg || SScribe.getNetworkErrorMessage(xhr, 'process_batch');
						const errData = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
						SScribe.showError(msg, false, SScribe.normalizeErrorData(errData));
					}
				},
			});
		},

		cancelExport: function (e) {
			e.preventDefault();

			if (!this.sessionId) {
				return;
			}

			if (this._batchXHR) {
				this._batchXHR.abort();
				this._batchXHR = null;
			}
			if (this._finalizingXHR) {
				this._finalizingXHR.abort();
				this._finalizingXHR = null;
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
					SScribe._batchInProgress = false;
					SScribe.resetUI();
					SScribe.updateExportButton();
					$('#sscribe-cancel-btn')
						.prop('disabled', false)
						.text(sscribe_data.strings.cancel || 'Cancel Export');
					SScribe.showToast(sscribe_data.strings.export_cancelled || 'Export cancelled.', 'info');
				},
				error: function () {
					SScribe.isProcessing = false;
					$('#sscribe-cancel-btn')
						.prop('disabled', false)
						.text(sscribe_data.strings.cancel || 'Cancel Export');
				},
			});
		},

		showExportCompleteNotice: function () {
			const msg =
				sscribe_data.strings && sscribe_data.strings.export_complete_notice
					? sscribe_data.strings.export_complete_notice
					: 'Export complete! You can start a new export now.';
			this.showToast(msg, 'success', 6000);
		},

		exportComplete: function (data, isAutoDownload) {
			this.isProcessing = false;
			const progressFill = document.getElementById('sscribe-progress-bar');
			if (progressFill) {
				progressFill.classList.remove('sscribe-progress-bar-fill-finalizing');
			}

			if (this._originalTitle) {
				document.title = this._originalTitle;
			}

			const liveRegion = document.getElementById('sscribe-live-region');
			if (liveRegion) {
				liveRegion.textContent = (sscribe_data.strings && sscribe_data.strings.complete) || 'Export complete!';
			}

			const self = this;
			$('#sscribe-progress-area').slideUp(300, function () {
				$('#sscribe-download-area')
					.removeClass('sscribe-hidden')
					.hide()
					.fadeIn(400, function () {
						if (data.download_url) {
							$('#sscribe-download-btn').attr('href', data.download_url);
							if (isAutoDownload !== false) {
								const a = document.createElement('a');
								a.href = data.download_url;
								a.download = '';
								document.body.appendChild(a);
								a.click();
								setTimeout(function () {
									a.remove();
								}, 1000);
							}
						} else {
							$('#sscribe-download-btn').removeAttr('href');
							self.showToast(
								sscribe_data.strings.download_unavailable || 'Download unavailable.',
								'warning'
							);
						}

						$('html, body').animate({ scrollTop: 0 }, 300);

						SScribe.refreshRecentExports();

						if (
							typeof window.SScribeDebugConsole !== 'undefined' &&
							window.SScribeDebugConsole.initialized
						) {
							window.SScribeDebugConsole.fetchLogs();
						}

						self.showExportCompleteNotice();
					});
			});
		},

		findExportInRecentExports: function (resultData, callback) {
			const selfSessionId = resultData.session_id || null;
			const selfStartTime = resultData.created_at || 0;
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
						let matched = null;
						const exportCount = recentResp.data.exports.length;
						for (let i = 0; i < exportCount; i++) {
							const exp = recentResp.data.exports[i];
							if (selfSessionId && exp.session_id === selfSessionId) {
								matched = exp;
								break;
							}
							if (selfStartTime && exp.time >= selfStartTime - 5 && exp.time <= selfStartTime + 60) {
								matched = exp;
								break;
							}
						}
						callback(matched || recentResp.data.exports[0]);
					} else {
						callback(null);
					}
				},
				error: function () {
					callback(null);
				},
			});
		},

		pollFinalize: function (sessionId, attempt, delay) {
			if (this._finalizingXHR) {
				return;
			}

			this.isProcessing = true;

			const maxAttempts = 180;
			if (attempt === 0) {
				this._finalizeStartTime = Date.now();
			}

			const finalizeStart = this._finalizeStartTime || Date.now();
			const elapsedSec = Math.floor((Date.now() - finalizeStart) / 1000);
			const min = Math.floor(elapsedSec / 60);
			const sec = elapsedSec % 60;
			const timeStr = min > 0 ? min + 'm ' + sec + 's' : sec + 's';
			$('#sscribe-status-text').text(
				(sscribe_data.strings.packaging || 'Packaging files into ZIP archive...') + ' (' + timeStr + ')'
			);
			SScribe.updatePhase('packaging');

			const progressFill = document.getElementById('sscribe-progress-bar');
			if (progressFill) {
				progressFill.classList.add('sscribe-progress-bar-fill-finalizing');
			}

			const self = this;

			setTimeout(function () {
				self._finalizingXHR = $.ajax({
					url: sscribe_data.ajaxurl,
					type: 'POST',
					timeout: 120000,
					data: {
						action: 'sscribe_finalize_export',
						nonce: sscribe_data.nonce,
						session_id: sessionId,
					},
					success: function (response) {
						self._finalizingXHR = null;
						if (response.success) {
							self.isProcessing = false;
							self.updateProgress(100);
							self.exportComplete(response.data);
							return;
						}

						if (response.data && response.data.code === 'not_finalizing') {
							const resultData = { session_id: sessionId, created_at: response.data.created_at || 0 };
							self.findExportInRecentExports(resultData, function (matched) {
								if (matched) {
									matched.percentage = 100;
									matched.processed = 0;
									matched.total = 0;
									self.updateProgress(100);
									self.exportComplete(matched, false);
								} else {
									self.isProcessing = false;
									self.showError(
										response.data.message || 'Export failed to finalize. Please try again.',
										false,
										{}
									);
								}
							});
							return;
						}

						if (attempt < maxAttempts) {
							self.pollFinalize(sessionId, attempt + 1, self.finalizePollInterval || 2000);
						} else {
							self.isProcessing = false;
							self.showError('Export finalization timed out. Please try again.', false, {});
						}
					},
					error: function (xhr) {
						self._finalizingXHR = null;
						const response = xhr.responseJSON || {};
						if (xhr.status === 404) {
							self.isProcessing = false;
							const resultData = {
								session_id: sessionId,
								created_at: response.data && response.data.created_at ? response.data.created_at : 0,
							};
							self.findExportInRecentExports(resultData, function (matched) {
								if (matched) {
									matched.percentage = 100;
									matched.processed = 0;
									matched.total = 0;
									self.updateProgress(100);
									self.exportComplete(matched, false);
								} else {
									self.showError(
										sscribe_data.strings.err_zip ||
											'Export failed: no files were generated. Please check your format selection and try again.',
										false,
										{}
									);
								}
							});
							return;
						}

						if (attempt < maxAttempts) {
							self.pollFinalize(sessionId, attempt + 1, self.finalizePollInterval || 2000);
						} else {
							self.isProcessing = false;
							const msg = self.getNetworkErrorMessage(xhr, 'finalize_export');
							self.showError(msg, false, {});
						}
					},
				});
			}, delay);
		},

		toggleHistorySkeleton: function (show) {
			const $skel = $('#sscribe-history-skeleton');
			const $table = $('#sscribe-history-table');
			if (!$skel.length) {
				return;
			}
			if (show) {
				$skel.removeClass('sscribe-hidden');
				$table.addClass('sscribe-hidden');
			} else {
				$skel.addClass('sscribe-hidden');
				$table.removeClass('sscribe-hidden');
			}
		},

		refreshRecentExports: function () {
			const $table = $('#sscribe-history-table');
			const hasExistingRows = $table.find('.sscribe-history-row').length > 0;
			if (!hasExistingRows) {
				this.toggleHistorySkeleton(true);
			}
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

			// Cancel any pending confirmation timers before replacing the DOM.
			$table.find('.sscribe-delete-btn').each(function () {
				const $btn = $(this);
				if ($btn.data('sscribe-confirm-timeout')) {
					clearTimeout($btn.data('sscribe-confirm-timeout'));
					$btn.removeData('sscribe-confirming').removeData('sscribe-confirm-timeout');
				}
			});

			if (!exports || exports.length === 0) {
				$table.html(
					'<div class="sscribe-history-empty"><em>' +
						this.escapeHtml(strings.history_empty || 'Your recent export packages will appear here.') +
						'</em></div>'
				);
				this.updateBulkBar();
				return;
			}

			const maxRows = 50;
			const totalExports = exports.length;
			let html = '';
			for (let i = 0; i < Math.min(totalExports, maxRows); i++) {
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
						SScribe.showToast(
							(sscribe_data.strings && sscribe_data.strings.delete_success) || 'Export deleted.',
							'success'
						);
					} else {
						SScribe.showError(
							response.data.message ||
								(sscribe_data.strings && sscribe_data.strings.delete_failed) ||
								'Failed to delete export.'
						);
					}
					if (typeof callback === 'function') {
						callback();
					}
				},
				error: function () {
					SScribe.showError(
						(sscribe_data.strings && sscribe_data.strings.delete_failed) || 'Failed to delete export.'
					);
					if (typeof callback === 'function') {
						callback();
					}
				},
			});
		},

		bulkDeleteSelected: function () {
			if (this.isProcessing) {
				return;
			}
			const $checks = $('.sscribe-history-check:checked');
			if ($checks.length === 0) {
				return;
			}
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
			if (this.isProcessing) {
				return;
			}
			const $checks = $('.sscribe-history-check:checked');
			if ($checks.length === 0) {
				return;
			}
			$checks.each(function (i) {
				const $row = $(this).closest('.sscribe-history-row');
				const $downloadLink = $row.find('.sscribe-history-actions > a');
				if ($downloadLink.length) {
					// Stagger each download to avoid browser popup blocking.
					setTimeout(function () {
						$downloadLink[0].click();
					}, i * 800);
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

		updateProgress: function (percentage, currentPage, totalPages) {
			percentage = Number(percentage);
			if (!isFinite(percentage)) {
				percentage = 0;
			}
			percentage = Math.min(100, Math.max(0, percentage));

			const progressBar = document.getElementById('sscribe-progress-bar');
			if (progressBar) {
				progressBar.style.transform = 'scaleX(' + percentage / 100 + ')';
				progressBar.setAttribute('aria-valuenow', percentage);
				if (typeof currentPage === 'number' && typeof totalPages === 'number' && totalPages > 0) {
					progressBar.setAttribute(
						'aria-valuetext',
						'Processing ' + currentPage + ' of ' + totalPages + ' pages'
					);
				}
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
			if (!$steps.length) {
				return;
			}

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

		/**
		 * Log a structured AJAX error diagnostic to the browser console.
		 *
		 * Outputs a collapsible console group with request details, response
		 * status, timing, and any available diagnostic metadata from the
		 * server. This is the primary tool for support/debugging sessions.
		 *
		 * @param {object} requestData The data object sent in the AJAX request.
		 * @param {jqXHR}  xhr         The jQuery XHR object.
		 * @param {*}      exception   The exception object (if any).
		 */
		logAJAXError: function (requestData, xhr, exception) {
			if (!window.console || !window.console.group) {
				return;
			}

			const action = (requestData && requestData.action) || 'unknown';
			const timestamp = new Date().toISOString();
			const statusCode = xhr ? xhr.status : 0;
			const statusText = xhr ? xhr.statusText : 'N/A';
			const responseText = xhr && xhr.responseText ? xhr.responseText.substring(0, 500) : 'N/A';

			let diagnostics = null;
			if (xhr && xhr.responseText) {
				try {
					const parsed = JSON.parse(xhr.responseText);
					if (parsed && parsed.data && parsed.data._diagnostics) {
						diagnostics = parsed.data._diagnostics;
					}
				} catch {
					// Not JSON — the response body wasn't meant to be parsed, nothing to extract.
				}
			}

			/* eslint-disable no-console */
			console.groupCollapsed('[SSCRIBE] AJAX Error — %s (HTTP %d %s)', action, statusCode, statusText);

			console.log('Timestamp:', timestamp);
			console.log('Action:', action);
			console.log('HTTP Status:', statusCode, statusText);
			console.log('Request Data:', requestData || {});
			console.log('Response Headers:', xhr ? xhr.getAllResponseHeaders() : 'N/A');
			console.log('Response Text (first 500):', responseText);
			console.log('Exception:', exception || 'None');

			if (diagnostics) {
				console.log('Server Diagnostics:', diagnostics);
			}

			if (typeof window.SSCRIBE_DEBUG !== 'undefined' && window.SSCRIBE_DEBUG) {
				console.log('Full XHR:', xhr);
			}

			console.groupEnd();
			/* eslint-enable no-console */
		},

		/**
		 * Show a non-blocking toast notification.
		 *
		 * Auto-dismisses after the specified duration. Uses CSS classes
		 * for styling (defined in sscribe-admin.css). Suitable for transient
		 * status messages (e.g., "Export deleted", "Support info copied")
		 * that should not block the user's workflow.
		 *
		 * @param {string} message  The message to display.
		 * @param {string} type     One of 'success', 'error', 'warning', 'info'.
		 * @param {number} duration Auto-dismiss timeout in ms (default: 4000).
		 */
		adjustToastContainerPosition: function () {
			const $container = $('#sscribe-toast-container');
			if (!$container.length) {
				return;
			}
			const $wpadminbar = $('#wpadminbar');
			const adminBarHeight = $wpadminbar.length ? $wpadminbar.outerHeight() : 0;
			$container.css('top', Math.max(adminBarHeight, 32) + 'px');
		},

		showToast: function (message, type, duration) {
			type = type || 'info';
			duration = typeof duration === 'number' ? duration : 4000;

			const $container = $('#sscribe-toast-container');
			if (!$container.length) {
				return;
			}

			const icons = { success: '&#10003;', error: '&#10005;', warning: '&#9888;', info: '&#9432;' };
			const icon = icons[type] || '&#9432;';
			const isAssertive = type === 'error' || type === 'warning';
			const role = isAssertive ? 'alert' : 'status';
			const ariaLive = isAssertive ? 'assertive' : 'polite';
			const dismissLabel =
				(sscribe_data.strings && sscribe_data.strings.dismiss_notification) || 'Dismiss notification';

			// Cap visible toasts at 5 to prevent overflow.
			const $existing = $container.children('.sscribe-toast');
			if ($existing.length >= 5) {
				const $oldest = $existing.first();
				$oldest.addClass('sscribe-toast-removing');
				setTimeout(function () {
					$oldest.remove();
				}, 200);
			}

			const $toast = $(
				'<div class="sscribe-toast sscribe-toast-' +
					type +
					'" role="' +
					role +
					'" aria-live="' +
					ariaLive +
					'" aria-atomic="true">' +
					'<span class="sscribe-toast-icon">' +
					icon +
					'</span>' +
					'<span class="sscribe-toast-message">' +
					this.escapeHtml(message) +
					'</span>' +
					'<button type="button" class="sscribe-toast-dismiss" aria-label="' +
					this.escapeHtml(dismissLabel) +
					'">&times;</button>' +
					'</div>'
			);

			$container.append($toast);

			$toast.find('.sscribe-toast-dismiss').on('click', function () {
				$toast.addClass('sscribe-toast-removing');
				setTimeout(function () {
					$toast.remove();
				}, 200);
			});

			if (duration > 0) {
				setTimeout(function () {
					$toast.addClass('sscribe-toast-removing');
					setTimeout(function () {
						$toast.remove();
					}, 200);
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
					.text(
						(sscribe_data.strings && sscribe_data.strings.support_copy_error) ||
							'Copy failed. Try selecting the text manually.'
					);
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
			if (
				this._lastFocusedElement &&
				typeof this._lastFocusedElement.focus === 'function' &&
				document.contains(this._lastFocusedElement) &&
				!this._lastFocusedElement.disabled
			) {
				this._lastFocusedElement.focus();
			}
			this._lastFocusedElement = null;
		},

		trapFocus: function (container) {
			if (!container || typeof container.querySelectorAll !== 'function') {
				return;
			}

			const focusableSelectors =
				'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

			if (container._sscribeTrapHandler) {
				container.removeEventListener('keydown', container._sscribeTrapHandler);
			}

			const handler = function (e) {
				if (e.key !== 'Tab') {
					return;
				}
				const focusableElements = container.querySelectorAll(focusableSelectors);
				if (focusableElements.length === 0) {
					return;
				}
				const firstFocusable = focusableElements[0];
				const lastFocusable = focusableElements[focusableElements.length - 1];
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

		releaseFocusTrap: function (container) {
			if (!container || !container._sscribeTrapHandler) {
				return;
			}
			container.removeEventListener('keydown', container._sscribeTrapHandler);
			delete container._sscribeTrapHandler;
		},

		focusFirstInteractive: function (container, fallbackSelector) {
			if (!container || typeof container.querySelector !== 'function') {
				return;
			}

			const focusableSelector =
				'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
			const firstFocusable = container.querySelector(focusableSelector);
			if (firstFocusable && typeof firstFocusable.focus === 'function') {
				firstFocusable.focus();
				return;
			}

			if (fallbackSelector) {
				const fallback = document.querySelector(fallbackSelector);
				if (fallback && typeof fallback.focus === 'function') {
					fallback.focus();
				}
			}
		},

		/**
		 * Escape HTML special characters to prevent XSS.
		 *
		 * @param {string} str String to escape.
		 * @returns {string} Escaped string.
		 */
		escapeHtml: function (str) {
			if (str === null || str === undefined) {
				return '';
			}
			const div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		},

		/**
		 * Show the export preview modal.
		 *
		 * @param {Event} e Click event.
		 */
		showPreview: function (e) {
			e.preventDefault();
			if (this.isProcessing) {
				return;
			}

			// Abort any existing preview AJAX request to prevent race conditions.
			if (this._previewXHR && this._previewXHR.abort) {
				this._previewXHR.abort();
			}

			this._previewTrigger = e.currentTarget;

			const language = $('input[name="sscribe_language"]:checked').val() || '';
			const postStatus = $('input[name="sscribe_post_status"]:checked').val() || 'publish';
			const postType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const format = $('input[name="sscribe_format"]:checked').val() || 'docx';
			const formats = format === 'all' ? ['docx', 'pdf', 'html', 'markdown'] : [format];

			const $panel = $('#sscribe-preview-panel');
			const $content = $('#sscribe-preview-content');
			const $startBtn = $('#sscribe-preview-start-btn');

			$content.html(
				'<div class="sscribe-preview-loading">' +
					'<span class="sscribe-loading-spinner"></span>' +
					'<span>' +
					this.escapeHtml(sscribe_data.strings.generating_preview || 'Generating preview...') +
					'</span>' +
					'</div>'
			);
			$startBtn.prop('disabled', true);

			$panel.attr('aria-hidden', 'false').removeClass('sscribe-hidden').prop('hidden', false).hide().fadeIn(300);
			this.saveFocus();
			this.trapFocus($panel[0]);
			this.focusFirstInteractive($panel[0], '#sscribe-preview-close');

			const self = this;
			this._previewXHR = $.ajax({
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
					formats: formats,
				},
				success: function (response) {
					if (response.success && response.data) {
						self.renderPreview(response.data);
					} else {
						self.closePreview();
						self.showToast(sscribe_data.strings.preview_error || 'Failed to generate preview.', 'error');
					}
				},
				error: function () {
					self.closePreview();
					self.showToast(sscribe_data.strings.preview_error || 'Failed to generate preview.', 'error');
				},
			});
		},

		/**
		 * Render the preview content in the modal.
		 *
		 * @param {object} data Preview data from server.
		 */
		renderPreview: function (data) {
			const $content = $('#sscribe-preview-content');
			const strings = sscribe_data.strings || {};
			const totalPages = parseInt(data.total_pages, 10) || 0;
			const formatLabels = {
				docx: strings.format_docx || 'Word Document (DOCX)',
				pdf: strings.format_pdf || 'PDF Document',
				html: strings.format_html || 'HTML Page',
				markdown: strings.format_markdown || 'Markdown',
			};

			let formatText = '';
			if (Array.isArray(data.formats) && data.formats.length > 0) {
				const labels = data.formats.map(function (fmt) {
					return formatLabels[fmt] || fmt.toUpperCase();
				});
				formatText = labels.join(', ');
			} else if (data.format) {
				formatText = formatLabels[data.format] || data.format.toUpperCase();
			}

			let html = '<div class="sscribe-preview-result">';
			html += '<div class="sscribe-preview-grid">';

			html += '<div class="sscribe-preview-stat">';
			html +=
				'<span class="sscribe-preview-label">' +
				this.escapeHtml(strings.preview_total_pages || 'Total pages:') +
				'</span>';
			html += '<span class="sscribe-preview-value">' + this.escapeHtml(String(totalPages)) + '</span>';
			html += '</div>';

			html += '<div class="sscribe-preview-stat">';
			html +=
				'<span class="sscribe-preview-label">' +
				this.escapeHtml(strings.preview_format || 'Format:') +
				'</span>';
			html += '<span class="sscribe-preview-value">' + this.escapeHtml(formatText || 'N/A') + '</span>';
			html += '</div>';

			html += '<div class="sscribe-preview-stat">';
			html +=
				'<span class="sscribe-preview-label">' +
				this.escapeHtml(strings.preview_language || 'Language:') +
				'</span>';
			html += '<span class="sscribe-preview-value">' + this.escapeHtml(data.language || 'All') + '</span>';
			html += '</div>';

			html += '<div class="sscribe-preview-stat">';
			html +=
				'<span class="sscribe-preview-label">' +
				this.escapeHtml(strings.preview_status || 'Status:') +
				'</span>';
			html += '<span class="sscribe-preview-value">' + this.escapeHtml(data.post_status || 'publish') + '</span>';
			html += '</div>';

			html += '<div class="sscribe-preview-stat">';
			html +=
				'<span class="sscribe-preview-label">' +
				this.escapeHtml(strings.preview_estimated_time || 'Estimated time:') +
				'</span>';
			html += '<span class="sscribe-preview-value">' + this.escapeHtml(data.estimated_time || 'N/A') + '</span>';
			html += '</div>';

			html += '<div class="sscribe-preview-stat">';
			html +=
				'<span class="sscribe-preview-label">' +
				this.escapeHtml(strings.preview_file_size || 'Est. file size:') +
				'</span>';
			html +=
				'<span class="sscribe-preview-value">' + this.escapeHtml(data.file_size_estimate || 'N/A') + '</span>';
			html += '</div>';

			html += '</div>';

			if (totalPages === 0) {
				html +=
					'<div class="sscribe-preview-empty">' +
					this.escapeHtml(sscribe_data.strings.err_no_pages || 'No pages match selected options.') +
					'</div>';
			}

			if (data.title && data.content) {
				html += '<div class="sscribe-preview-sample">';
				html += '<h4>' + this.escapeHtml(strings.preview_sample_title || 'Sample:') + '</h4>';
				html += '<p class="sscribe-preview-title">' + this.escapeHtml(data.title) + '</p>';
				html += '<p class="sscribe-preview-excerpt">' + this.escapeHtml(data.content) + '</p>';
				html += '</div>';
			}

			html +=
				'<p class="sscribe-preview-note">' +
				this.escapeHtml(strings.preview_fallback_note || 'Only the first few pages are shown in the preview.') +
				'</p>';
			html += '</div>';

			$content.html(html);
			$('#sscribe-preview-start-btn').prop('disabled', totalPages <= 0);
		},

		/**
		 * Close the preview modal.
		 *
		 * @param {Event} e Click event (optional).
		 */
		closePreview: function (e) {
			if (e) {
				e.preventDefault();
			}
			// Abort any pending preview AJAX request when closing.
			if (this._previewXHR && this._previewXHR.abort) {
				this._previewXHR.abort();
			}
			const $panel = $('#sscribe-preview-panel');
			const panelEl = $panel[0];
			const self = this;
			$panel.fadeOut(200, function () {
				$panel.attr('aria-hidden', 'true').addClass('sscribe-hidden').prop('hidden', true);
				self.releaseFocusTrap(panelEl);
				const trigger = self._previewTrigger;
				if (trigger && typeof trigger.focus === 'function' && document.contains(trigger) && !trigger.disabled) {
					trigger.focus();
				}
				self._previewTrigger = null;
			});
		},

		/**
		 * Start export directly from preview modal.
		 *
		 * @param {Event} e Click event.
		 */
		startExportFromPreview: function (e) {
			e.preventDefault();
			if (this.isProcessing) {
				return;
			}

			this.closePreview();
			$('#sscribe-export-btn').trigger('click');
		},

		/**
		 * Load support information from the server.
		 */
		loadSupportInfo: function () {
			const $grid = $('#sscribe-support-grid');
			const $textarea = $('#sscribe-support-copy-text');
			const $btn = $('#sscribe-support-copy-btn');

			$textarea.val('');
			$btn.prop('disabled', true);
			$grid.html(
				'<div class="sscribe-support-loading">' +
					'<span class="sscribe-loading-spinner"></span>' +
					'<span>' +
					this.escapeHtml(sscribe_data.strings.support_loading || 'Loading support information...') +
					'</span>' +
					'</div>'
			);

			const self = this;
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
						self.renderSupportInfo(response.data);
					} else {
						$grid.html(
							'<div class="sscribe-support-error">' +
								self.escapeHtml(
									sscribe_data.strings.support_error ||
										'Unable to load support information right now.'
								) +
								'</div>'
						);
					}
				},
				error: function () {
					$grid.html(
						'<div class="sscribe-support-error">' +
							self.escapeHtml(
								sscribe_data.strings.support_error || 'Unable to load support information right now.'
							) +
							'</div>'
					);
				},
			});
		},

		/**
		 * Render support information in the UI.
		 *
		 * @param {object} data Support info data.
		 */
		renderSupportInfo: function (data) {
			const $grid = $('#sscribe-support-grid');
			const $textarea = $('#sscribe-support-copy-text');
			const $btn = $('#sscribe-support-copy-btn');

			let html = '<div class="sscribe-support-grid-inner">';

			if (data.sections) {
				Object.keys(data.sections).forEach(function (sectionKey) {
					const section = data.sections[sectionKey];
					if (section && section.items) {
						Object.keys(section.items).forEach(function (itemKey) {
							const value = section.items[itemKey];
							html += '<div class="sscribe-support-item">';
							html += '<span class="sscribe-support-label">' + this.escapeHtml(itemKey) + ':</span>';
							html += '<span class="sscribe-support-value">' + this.escapeHtml(String(value)) + '</span>';
							html += '</div>';
						}, this);
					}
				}, this);
			}

			html += '</div>';
			$grid.html(html);

			// Use the copy_text that PHP already builds in the proper format.
			$textarea.val(data.copy_text || '');
			$btn.prop('disabled', false);
		},

		/**
		 * Delete an export file.
		 *
		 * @param {Event} e Click event.
		 */
		deleteExport: function (e) {
			e.preventDefault();
			const $btn = $(e.currentTarget);
			const filename = $btn.data('filename');
			const $row = $btn.closest('.sscribe-history-row');

			if (!filename) {
				return;
			}

			// Check for double-click confirmation.
			if ($btn.data('sscribe-confirming')) {
				// Second click — actually delete.
				$btn.removeData('sscribe-confirming');
				clearTimeout($btn.data('sscribe-confirm-timeout'));
				$row.addClass('sscribe-row-deleting');
				SScribe.deleteSingleExport(filename, function () {
					$row.fadeOut(200, function () {
						$(this).remove();
						SScribe.refreshRecentExports();
					});
				});
				return;
			}

			// First click — ask for confirmation.
			const originalText = $btn.text();
			$btn.data('sscribe-confirming', true)
				.text(sscribe_data.strings.click_again || 'Click again')
				.prop('disabled', false);

			const tid = setTimeout(function () {
				if ($btn.data('sscribe-confirming')) {
					$btn.removeData('sscribe-confirming').text(originalText);
				}
			}, 3000);
			$btn.data('sscribe-confirm-timeout', tid);
		},

		/**
		 * Show export log in modal.
		 *
		 * @param {Event} e Click event.
		 */
		showExportLog: function (e) {
			e.preventDefault();
			const $btn = $(e.currentTarget);
			const filename = $btn.data('filename');

			if (!filename) {
				return;
			}

			const $modal = $('#sscribe-log-modal');
			const $content = $('#sscribe-log-content');

			$content.html(
				'<div class="sscribe-log-loading">' +
					'<span class="sscribe-loading-spinner"></span>' +
					'<span>' +
					this.escapeHtml(sscribe_data.strings.loading_log || 'Loading log...') +
					'</span>' +
					'</div>'
			);

			$modal.attr('aria-hidden', 'false').removeClass('sscribe-hidden').prop('hidden', false).hide().fadeIn(300);
			this.saveFocus();
			this.trapFocus($modal[0]);
			this.focusFirstInteractive($modal[0], '#sscribe-modal-close');

			const self = this;
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
					if (response.success && response.data && response.data.log) {
						self.renderExportLog(response.data);
					} else {
						const message =
							response && response.data && response.data.message
								? response.data.message
								: sscribe_data.strings.log_not_found || 'Log not found.';
						$content.html('<div class="sscribe-log-error">' + self.escapeHtml(message) + '</div>');
					}
				},
				error: function () {
					$content.html(
						'<div class="sscribe-log-error">' +
							self.escapeHtml(sscribe_data.strings.log_load_failed || 'Failed to load log.') +
							'</div>'
					);
				},
			});
		},

		/**
		 * Render export log content.
		 *
		 * @param {object} data Log data.
		 */
		renderExportLog: function (data) {
			const $content = $('#sscribe-log-content');
			const strings = sscribe_data.strings || {};
			const log = data.log;
			if (!log || typeof log !== 'object') {
				$content.html(
					'<div class="sscribe-log-error">' +
						this.escapeHtml(strings.log_not_found || 'Log not found.') +
						'</div>'
				);
				return;
			}

			const pagesObj = log.pages && typeof log.pages === 'object' ? log.pages : {};
			const pages = Object.keys(pagesObj).map(function (key) {
				return pagesObj[key];
			});
			const errors = Array.isArray(log.errors) ? log.errors : [];

			let html = '<div class="sscribe-log-summary">';
			html +=
				'<div class="sscribe-log-stat"><strong>' +
				this.escapeHtml(strings.log_total || 'Total:') +
				'</strong> ' +
				this.escapeHtml(String(log.total_pages || 0)) +
				'</div>';
			html +=
				'<div class="sscribe-log-stat sscribe-log-success"><strong>' +
				this.escapeHtml(strings.log_success_label || 'Success:') +
				'</strong> ' +
				this.escapeHtml(String(log.success || 0)) +
				'</div>';
			html +=
				'<div class="sscribe-log-stat sscribe-log-failed"><strong>' +
				this.escapeHtml(strings.log_failed_label || 'Failed:') +
				'</strong> ' +
				this.escapeHtml(String(log.failed || 0)) +
				'</div>';
			html += '</div>';

			if (pages.length > 0) {
				html += '<div class="sscribe-log-pages">';
				html += '<h4>' + this.escapeHtml(strings.log_page_details || 'Page Details') + '</h4>';
				html += '<table class="sscribe-log-table">';
				html += '<thead><tr>';
				html += '<th>' + this.escapeHtml(strings.log_col_id || 'ID') + '</th>';
				html += '<th>' + this.escapeHtml(strings.log_col_title || 'Title') + '</th>';
				html += '<th>' + this.escapeHtml(strings.log_col_status || 'Status') + '</th>';
				html += '<th>' + this.escapeHtml(strings.log_col_time || 'Time') + '</th>';
				html += '<th>' + this.escapeHtml(strings.log_col_formats || 'Formats') + '</th>';
				html += '</tr></thead><tbody>';

				pages.forEach(
					function (page) {
						const pageId = page.id || '-';
						const title = page.title || strings.log_unknown || 'Unknown';
						const status = page.status || strings.log_unknown || 'Unknown';
						const duration =
							page.duration !== null && page.duration !== undefined
								? String(page.duration) + (strings.log_seconds_suffix || 's')
								: '-';

						let formatText = '-';
						if (page.formats && typeof page.formats === 'object') {
							const formatKeys = Object.keys(page.formats).filter(function (fmt) {
								return page.formats[fmt] && page.formats[fmt].success;
							});
							if (formatKeys.length > 0) {
								formatText = formatKeys.join(', ');
							}
						}

						const statusClass =
							status === 'success'
								? 'sscribe-log-status-success'
								: status === 'failed'
									? 'sscribe-log-status-failed'
									: '';
						html += '<tr>';
						html += '<td>' + this.escapeHtml(String(pageId)) + '</td>';
						html += '<td>' + this.escapeHtml(title) + '</td>';
						html += '<td class="' + this.escapeHtml(statusClass) + '">' + this.escapeHtml(status) + '</td>';
						html += '<td>' + this.escapeHtml(duration) + '</td>';
						html += '<td>' + this.escapeHtml(formatText) + '</td>';
						html += '</tr>';
					}.bind(this)
				);

				html += '</tbody></table></div>';
			} else {
				html +=
					'<div class="sscribe-log-empty">' +
					this.escapeHtml(strings.log_not_found || 'Log not found.') +
					'</div>';
			}

			if (errors.length > 0) {
				html += '<div class="sscribe-log-errors">';
				html += '<h4>' + this.escapeHtml(strings.log_errors || 'Errors') + '</h4>';
				html += '<ul>';
				errors.forEach(
					function (errorEntry) {
						const message =
							errorEntry && errorEntry.message ? errorEntry.message : strings.log_unknown || 'Unknown';
						html += '<li>' + this.escapeHtml(message) + '</li>';
					}.bind(this)
				);
				html += '</ul></div>';
			}

			$content.html(html);
		},

		/**
		 * Close the log modal.
		 *
		 * @param {Event} e Click event.
		 */
		closeModal: function (e) {
			if (e) {
				e.preventDefault();
			}
			const $modal = $('#sscribe-log-modal');
			const modalEl = $modal[0];
			const self = this;
			$modal.fadeOut(200, function () {
				$modal.attr('aria-hidden', 'true').addClass('sscribe-hidden').prop('hidden', true);
				self.releaseFocusTrap(modalEl);
				self.restoreFocus();
			});
		},

		/**
		 * Download an export file.
		 *
		 * @param {Event} e Click event.
		 */
		downloadExport: function (e) {
			const $link = $(e.currentTarget);
			const href = $link.attr('href');
			if (href && href !== '#') {
				return;
			}
			e.preventDefault();
			const filename = $link.data('filename') || $link.attr('href');
			if (filename && filename !== '#') {
				window.location.href = filename;
			}
		},

		/**
		 * Retry failed operation.
		 *
		 * @param {Event} e Click event.
		 */
		retry: function (e) {
			e.preventDefault();
			$('#sscribe-error-area').addClass('sscribe-hidden');
			$('#sscribe-download-area').addClass('sscribe-hidden');
			this.isProcessing = false;
			this.pollBackoff = 0;
			this._batchInProgress = false;
			this.resetUI();
			this.updateExportButton();
			$('#sscribe-export-btn').trigger('click');
		},

		/**
		 * Reset UI to initial state.
		 */
		resetUI: function () {
			$('#sscribe-progress-area').addClass('sscribe-hidden');
			$('#sscribe-error-area').addClass('sscribe-hidden');
			$('#sscribe-download-area').addClass('sscribe-hidden');
			$('#sscribe-progress-bar').css('width', '0%').css('transform', 'scaleX(0)');
			$('#sscribe-progress-text').text('0%');
			$('#sscribe-status-text').text('');
			$('#sscribe-current-page').text('').hide();
			$('#sscribe-time-remaining').text('').hide();
			const progressBar = document.getElementById('sscribe-progress-bar');
			if (progressBar) {
				progressBar.setAttribute('aria-valuetext', '');
				progressBar.setAttribute('aria-valuenow', '0');
			}
			this.isProcessing = false;
			this.sessionId = null;
			this.batchRetries = 0;
		},

		/**
		 * Get error guidance based on error message.
		 *
		 * @param {string} message Error message.
		 * @returns {string} Guidance text.
		 */
		getErrorGuidance: function (message) {
			const msg = message || '';
			if (msg.indexOf('session') !== -1 || msg.indexOf('timeout') !== -1) {
				return sscribe_data.strings.err_session_expired || '';
			}
			if (msg.indexOf('memory') !== -1) {
				return sscribe_data.strings.err_memory || '';
			}
			if (msg.indexOf('zip') !== -1 || msg.indexOf('archive') !== -1) {
				return sscribe_data.strings.err_zip || '';
			}
			if (msg.indexOf('rate') !== -1 || msg.indexOf('limit') !== -1) {
				return sscribe_data.strings.err_rate_limit || '';
			}
			return sscribe_data.strings.err_generic || '';
		},

		/**
		 * Format guidance text for display.
		 *
		 * @param {string} guidance Raw guidance text.
		 * @returns {string} HTML formatted guidance.
		 */
		formatGuidance: function (guidance) {
			if (!guidance) {
				return '';
			}
			return this.escapeHtml(guidance).replace(/\n/g, '<br>');
		},

		/**
		 * Normalize error data from server response.
		 *
		 * @param {object} data Error data.
		 * @returns {object} Normalized error data.
		 */
		normalizeErrorData: function (data) {
			if (!data || typeof data !== 'object') {
				return {};
			}
			return {
				code: data.code || null,
				message: data.message || null,
				guidance: data.guidance || null,
				fix_steps: data.fix_steps || null,
				_diagnostics: data._diagnostics || null,
			};
		},

		/**
		 * Show error with user-friendly message.
		 *
		 * @param {string} message Error message.
		 * @param {boolean} isCancelled Whether operation was cancelled.
		 * @param {object} errorData Additional error data.
		 */
		showError: function (message, isCancelled, errorData) {
			this.isProcessing = false;

			// Restore original page title.
			if (this._originalTitle) {
				document.title = this._originalTitle;
			}

			$('#sscribe-progress-area').fadeOut(200);

			// Announce error to screen readers via assertive live region.
			const alertRegion = document.getElementById('sscribe-alert-region');
			if (alertRegion) {
				alertRegion.textContent =
					message || (sscribe_data.strings && sscribe_data.strings.error) || 'Export failed.';
			}

			let displayMessage = message;
			let guidance = '';
			let diagnosticInfo = null;

			if (errorData) {
				diagnosticInfo = this.normalizeErrorData(errorData);
				if (diagnosticInfo && window.console) {
					/* eslint-disable no-console */
					console.log('[SSCRIBE] Server diagnostics for this error:', diagnosticInfo);
					/* eslint-enable no-console */
				}

				if (diagnosticInfo.code) {
					displayMessage = '[' + diagnosticInfo.code + '] ' + message;
				}
				if (diagnosticInfo.guidance) {
					guidance = diagnosticInfo.guidance;
				}
				if (diagnosticInfo.fix_steps && diagnosticInfo.fix_steps.length > 0) {
					guidance += (guidance ? '\n\n' : '') + (sscribe_data.strings.fix_steps || 'Steps to fix:') + '\n';
					const fixStepCount = diagnosticInfo.fix_steps.length;
					for (let i = 0; i < fixStepCount; i++) {
						guidance += i + 1 + '. ' + diagnosticInfo.fix_steps[i] + '\n';
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

			if (diagnosticInfo && typeof window.SSCRIBE_DEBUG !== 'undefined' && window.SSCRIBE_DEBUG) {
				const $techDetails = $('#sscribe-error-technical-details');
				const techInfo = JSON.stringify(diagnosticInfo._diagnostics || diagnosticInfo, null, 2);
				$techDetails.find('pre').text(techInfo);
				$techDetails.removeClass('sscribe-hidden');
			}

			$('#sscribe-error-area').removeClass('sscribe-hidden').hide().fadeIn(300);
			$('#sscribe-export-btn, #sscribe-preview-btn').prop('disabled', false);
		},

		/**
		 * Get user-friendly network error message.
		 *
		 * @param {jqXHR} xhr  The jQuery XHR object.
		 * @param {string} action The action that failed.
		 * @returns {string} Error message.
		 */
		/**
		 * Parse server error message from a failed AJAX response.
		 *
		 * Server errors (HTTP 4xx/5xx) typically still send JSON with a
		 * data.message field. This extracts it so the user sees the real
		 * error instead of a generic status code string.
		 *
		 * @param {jqXHR} xhr The jQuery XHR object.
		 * @returns {string|null} Server message or null.
		 */
		parseServerError: function (xhr) {
			if (!xhr) {
				return null;
			}
			try {
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					return xhr.responseJSON.data.message;
				}
				if (xhr.responseText) {
					const parsed = JSON.parse(xhr.responseText);
					if (parsed && parsed.data && parsed.data.message) {
						return parsed.data.message;
					}
				}
			} catch {
				return null;
			}
			return null;
		},

		getNetworkErrorMessage: function (xhr, _action) {
			const strings = sscribe_data.strings || {};
			const status = xhr ? xhr.status : 0;

			if (status === 0) {
				return strings.net_connection_lost || strings.err_connection || 'Connection lost.';
			}
			if (status === 403) {
				return strings.net_403 || strings.err_403 || 'Access denied (403).';
			}
			if (status === 500) {
				return strings.net_500 || strings.err_500 || 'Internal server error (500).';
			}
			if (status === 502) {
				return strings.net_502 || 'Bad gateway (502).';
			}
			if (status === 503) {
				return strings.net_503 || 'Service unavailable (503).';
			}
			if (status === 504) {
				return strings.net_504 || strings.err_timeout || 'Gateway timeout (504).';
			}
			if (status === 12029 || status === 12030 || status === 12031) {
				return strings.net_connection_lost || strings.err_connection || 'Connection lost.';
			}

			const unknownMsg = strings.net_unknown || 'A network error occurred (HTTP %d).';
			return unknownMsg.replace('%d', status);
		},
	};

	/**
	 * Global AJAX error diagnostics.
	 *
	 * Every failed admin-ajax.php request is captured here and logged to the
	 * browser console with structured diagnostic context (action name, HTTP
	 * status, response body, timing, server diagnostics). This provides a
	 * complete audit trail for support and debugging without modifying
	 * individual AJAX callers.
	 *
	 * Logs are grouped under a collapsible "[SSCRIBE] AJAX Error" label for
	 * clean DevTools output.
	 */
	$(document).ajaxError(function (_event, jqXHR, _settings, exception) {
		let requestData = null;
		if (_settings && _settings.data) {
			if (typeof _settings.data === 'string') {
				requestData = {};
				_settings.data.replace(/([^&=]+)=([^&]*)/g, function (_, key, val) {
					requestData[decodeURIComponent(key)] = decodeURIComponent(val);
				});
			} else {
				requestData = _settings.data;
			}
		}
		if (requestData && requestData.action && requestData.action.indexOf('sscribe_') === 0) {
			SScribe.logAJAXError(requestData, jqXHR, exception);
		}
	});

	$(document).ready(function () {
		SScribe.init();
	});
})(jQuery);
