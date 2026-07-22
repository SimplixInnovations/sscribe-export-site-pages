/**
 * SScribe Admin JavaScript
 *
 * Handles AJAX batch processing with animated progress tracking.
 *
 * @package SScribe
 * @version 1.1.3
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
		 * In-flight nonce refresh request. Used to coalesce concurrent 403
		 * responses : if two AJAX calls fail with 403 simultaneously, only
		 * one refresh request is sent; the second waits for the first's
		 * completion before retrying its original request.
		 *
		 * @type {Promise<string|null>|null}
		 */
		_nonceRefreshInFlight: null,
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
		/**
		 * Map an ISO 639-1/2 language code to a human-readable label.
		 *
		 * Used by the preview modal and the config summary so the user sees
		 * "Arabic" / "English" rather than the raw locale code (`ar` / `en`).
		 * Falls back to the uppercased code for languages we don't have a
		 * label for, and to `'All'` for an empty/null input.
		 *
		 * @param {string|null|undefined} code Two/three-letter language code.
		 * @returns {string} Human-readable language label.
		 */
		getLanguageLabel: function (code) {
			if (code === '' || code === null || code === undefined) {
				return 'All';
			}
			const labels = {
				en: 'English',
				ar: 'Arabic',
				fr: 'French',
				de: 'German',
				es: 'Spanish',
				it: 'Italian',
				pt: 'Portuguese',
				nl: 'Dutch',
				ru: 'Russian',
				zh: 'Chinese',
				ja: 'Japanese',
				ko: 'Korean',
				he: 'Hebrew',
				fa: 'Persian',
				ur: 'Urdu',
				tr: 'Turkish',
				pl: 'Polish',
				sv: 'Swedish',
				da: 'Danish',
				fi: 'Finnish',
				no: 'Norwegian',
				nb: 'Norwegian (Bokmål)',
				cs: 'Czech',
				sk: 'Slovak',
				hu: 'Hungarian',
				ro: 'Romanian',
				bg: 'Bulgarian',
				hr: 'Croatian',
				sr: 'Serbian',
				uk: 'Ukrainian',
				vi: 'Vietnamese',
				th: 'Thai',
				id: 'Indonesian',
				ms: 'Malay',
				el: 'Greek',
				hi: 'Hindi',
				bn: 'Bengali',
				lt: 'Lithuanian',
				lv: 'Latvian',
				et: 'Estonian',
				sl: 'Slovenian',
				ps: 'Pashto',
				ku: 'Kurdish',
				sd: 'Sindhi',
				yi: 'Yiddish',
				iw: 'Hebrew', 
				ji: 'Yiddish', 
			};
			const normalized = String(code).toLowerCase();
			return labels[normalized] || normalized.toUpperCase();
		},
		getPostStatusLabel: function (slug) {
			if (slug === '' || slug === null || slug === undefined) {
				return '';
			}
			const labels = {
				publish: 'Published',
				draft: 'Draft',
				private: 'Private',
				future: 'Scheduled',
				pending: 'Pending Review',
				trash: 'Trash',
				inherit: 'Inherit',
			};
			const normalized = String(slug).toLowerCase();
			return labels[normalized] || normalized.charAt(0).toUpperCase() + normalized.slice(1);
		},
		/**
		 * Refresh the export nonce from the server.
		 *
		 * Long-running batch exports can outlive the WP nonce lifetime
		 * (default 12h). On a 403 the AJAX wrapper calls refreshNonce,
		 * fetches a fresh nonce, updates sscribe_data.download_nonce,
		 * and retries the original request.
		 *
		 * @returns {Promise<string|null>} The new nonce, or null on failure.
		 */
		refreshNonce: function () {
			const self = this;
			if (self._nonceRefreshInFlight) {
				return self._nonceRefreshInFlight;
			}
			const promise = new Promise(function (resolve) {
				$.ajax({
					url: sscribe_data.ajaxurl,
					type: 'POST',
					timeout: 15000,
					data: {
						action: 'sscribe_refresh_nonce',
						nonce: sscribe_data.nonce,
					},
					success: function (response) {
						if (response && response.success && response.data && response.data.nonce) {
							sscribe_data.nonce = response.data.nonce;
							resolve(response.data.nonce);
						} else {
							resolve(null);
						}
					},
					error: function () {
						resolve(null);
					},
				});
			});
			self._nonceRefreshInFlight = promise;
			promise.finally(function () {
				if (self._nonceRefreshInFlight === promise) {
					self._nonceRefreshInFlight = null;
				}
			});
			return promise;
		},
		/**
		 * Wrap a $.ajax options object so that on 403 (nonce expired) the
		 * nonce is refreshed and the request is retried once.
		 *
		 * Mutates the passed options object's `data.nonce` field on retry.
		 * The wrapper preserves the original success/error callbacks.
		 *
		 * @param {object} options jQuery $.ajax options.
		 * @returns {object} The jQuery XHR object (caller can .abort()).
		 */
		ajaxWithNonceRefresh: function (options) {
			const self = this;
			const dataNonceKey = options.data && options.data.nonce ? true : false;
			const originalError = options.error;
			let retried = false;
			options.error = function (xhr, status, thrown) {
				const isNonceFailure =
					xhr && xhr.status === 403 ||
					(status === 'error' && xhr && xhr.status === 403);
				if (!retried && isNonceFailure && dataNonceKey) {
					retried = true;
					self.refreshNonce().then(function (newNonce) {
						if (newNonce && options.data) {
							options.data.nonce = newNonce;
							$.ajax(options);
						} else {
							if (typeof originalError === 'function') {
								originalError(xhr, status, thrown);
							}
						}
					});
					return;
				}
				if (typeof originalError === 'function') {
					originalError(xhr, status, thrown);
				}
			};
			return $.ajax(options);
		},
		init: function () {
			if (typeof sscribe_data === 'undefined' || !sscribe_data) {
				return;
			}
			this._originalTitle = document.title;
			this.bindEvents();
			this.initializeTabs();
			this.adjustToastContainerPosition();
			$('#sscribe-export-disabled-reason').text(
				(sscribe_data.strings && sscribe_data.strings.loading_counts) || 'Loading page counts...'
			);
			this.updateConfigSummary();
			this.updateFormatOptionPanels();
			const defaultPostType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const defaultLanguage = $('input[name="sscribe_language"]:checked').val() || '';
			this.refreshStatusAndLanguageCounts(defaultPostType, defaultLanguage);
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
			$(document).on('click.sscribe', '#sscribe-view-history-btn', $.proxy(this.openHistoryFromSuccess, this));
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
			$(document).on('click.sscribe', '#sscribe-onboarding-dismiss', $.proxy(this.dismissOnboarding, this));
			$(document).on('click.sscribe', '#sscribe-error-toggle-details', $.proxy(this.toggleErrorDetails, this));
			$(document).on('input.sscribe', '#sscribe-history-search', $.proxy(this.filterHistory, this));
			$(document).on('click.sscribe', '#sscribe-empty-start-export-btn', $.proxy(this.startFirstExportFromEmpty, this));
			$(document).on('click.sscribe', '#sscribe-preview-panel', function (e) {
				if (e.target === this) {
					SScribe.closePreview();
				}
			});
			const self = this;
			$(document).on('keydown.sscribe', function (e) {
				if (e.key === 'Escape' || e.key === 'Esc') {
					const $preflight = $('.sscribe-preflight-banner');
					if ($preflight.length) {
						e.preventDefault();
						$preflight.find('.sscribe-preflight-close').trigger('click');
						return;
					}
					const $previewPanel = $('#sscribe-preview-panel');
					const $logModal = $('#sscribe-log-modal');
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
					const tag = e.target && e.target.tagName;
					if (tag === 'INPUT' || tag === 'TEXTAREA' || (e.target && e.target.isContentEditable)) {
						return;
					}
					const $confirmModal = $('#sscribe-confirm-modal');
					if ($confirmModal.length && !$confirmModal.hasClass('sscribe-hidden')) {
						e.preventDefault();
						$confirmModal.find('#sscribe-confirm-cancel').trigger('click');
						return;
					}
				}
				if (!e.ctrlKey && !e.metaKey) {
					return;
				}
				const tag = e.target.tagName;
				if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable) {
					return;
				}
				if ((e.key === 'e' || e.key === 'E') && e.shiftKey) {
					e.preventDefault();
					const $btn = $('#sscribe-export-btn');
					if (!$btn.prop('disabled')) {
						$btn.trigger('click');
					}
				}
				if ((e.key === 'p' || e.key === 'P') && e.shiftKey) {
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
// Auto-load support diagnostics when Support tab is activated
 if (tabId === 'support') {
 // Auto-load diagnostics if textarea is empty
 setTimeout(function () {
 if (!$('#sscribe-support-copy-text').val().trim()) {
 SScribe.loadSupportInfo();
 }
 }, 200);
 }
 },
 setActiveTab: function (tabId) {
			const self = this;
			const announce = document.getElementById('sscribe-tab-announce');
			if (announce) {
				let label = '';
				$('.sscribe-tab-btn').each(function () {
					if ($(this).data('tab') === tabId) {
						label = $(this).text().trim();
					}
				});
				announce.textContent = label
					? (label + ' tab selected')
					: 'Tab changed';
			}
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
						.prop('hidden', true);
					self.releaseFocusTrap($panel[0]);
				}
			});
			$(document).trigger('sscribe:tab:activated', [ tabId ]);
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
			self.selectedPageCount = 0;
			self._countsLoaded = false;
			self.updateExportButton();
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
					} else {
						$('#sscribe-page-count').text('0');
						$('#sscribe-post-count').text('0');
						$('#sscribe-both-count').text('0');
					}
					$('.sscribe-status-card-label').removeClass('sscribe-loading');
					$('#sscribe-page-count, #sscribe-post-count, #sscribe-both-count').removeClass('sscribe-loading-count');
				},
				error: function () {
					$('#sscribe-page-count').text('0');
					$('#sscribe-post-count').text('0');
					$('#sscribe-both-count').text('0');
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
						$('#sscribe-cancel-btn')
							.prop('disabled', false)
							.text(sscribe_data.strings.cancel || 'Cancel Export');
						$('#sscribe-export-btn, #sscribe-preview-btn').prop('disabled', true);
						const restoredPct = Number(response.data.percentage) || 0;
						if (restoredPct < 1) {
							$('#sscribe-progress-bar').addClass('sscribe-progress-initializing');
						}
						$('#sscribe-progress-area').show();
						SScribe.updateProgress(restoredPct);
						SScribe.updatePhase(response.data.status);
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
			this.updateFormatOptionPanels();
		},
		/**
		 * Show the option panel for the currently selected export format and
		 * hide the others. The "all" format shows no per-format panel.
		 *
		 * The "all" radio card and the panels are siblings in the DOM; the
		 * panels live in #sscribe-format-options. Hiding is done via the
		 * `hidden` HTML attribute (so screen readers and CSS both see it)
		 * and the wrapper's `.sscribe-hidden` class is toggled for the
		 * case where no panel is visible (e.g., "all" selected).
		 */
		updateFormatOptionPanels: function () {
			const format = $('input[name="sscribe_format"]:checked').val() || 'all';
			const $wrapper = $('#sscribe-format-options');
			const $panels = $wrapper.find('.sscribe-format-option-panel');
			let anyVisible = false;
			$panels.each(function () {
				const $panel = $(this);
				const matches = $panel.attr('data-format') === format;
				if (matches) {
					$panel.removeAttr('hidden');
					anyVisible = true;
				} else {
					$panel.attr('hidden', 'hidden');
				}
			});
			if (anyVisible) {
				$wrapper.removeClass('sscribe-hidden');
			} else {
				$wrapper.addClass('sscribe-hidden');
			}
		},
		/**
		 * Collect the per-format option values from the option panels.
		 * Returned as a flat object suitable for sending in an AJAX
		 * request. Checkboxes that are unchecked are included with value
		 * "0" so the server can distinguish "user unchecked it" from
		 * "field not present".
		 *
		 * @returns {object} Map of option name → value.
		 */
		collectFormatOptions: function () {
			const options = {};
			$('#sscribe-format-options')
				.find('input, select, textarea')
				.each(function () {
					const $field = $(this);
					const name = $field.attr('name');
					if (!name) {
						return;
					}
					if ($field.attr('type') === 'checkbox') {
						options[name] = $field.is(':checked') ? '1' : '0';
					} else {
						options[name] = $field.val();
					}
				});
			return options;
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
			const S = sscribe_data.strings || {};
			const postTypeLabels = {
				page: S.post_type_page || 'Pages',
				post: S.post_type_post || 'Posts',
				any:  S.post_type_any  || 'Both',
			};
			const statusLabels = {
				publish: S.status_publish || 'Published',
				draft: S.status_draft || 'Draft',
				private: S.status_private || 'Private',
				future:  S.status_future  || 'Scheduled',
				pending: S.status_pending || 'Pending',
				all: S.status_all || 'All',
			};
			$('#sscribe-summary-post-type').text(postTypeLabels[postType] || postType);
			$('#sscribe-summary-status').text(statusLabels[status] || status);
			$('#sscribe-summary-language').text(this.getLanguageLabel(language));
			$('#sscribe-summary-format').text(format === 'all' ? (S.status_all || 'All') : format.toUpperCase());
			$('#sscribe-summary-pages').text((this._countsLoaded ? '' : '~') + count + ' ' + (count === 1 ? ((S && S.log_page) || 'page') : ((S && S.log_pages) || 'pages')));
			$('#sscribe-summary-time').text(sscribe_data.strings.calculating_time || 'Calculating...').addClass('sscribe-summary-time-pending');
			if (this._configSummaryXHR && this._configSummaryXHR.abort) {
				this._configSummaryXHR.abort();
			}
			const self = this;
			clearTimeout(this._configSummaryDebounceTimer);
			if (!this._countsLoaded || count === 0) {
				$('#sscribe-summary-time').text(sscribe_data.strings.summary_time_hint || 'See Preview').removeClass('sscribe-summary-time-pending');
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
							$('#sscribe-summary-time').text(response.data.estimated_time).removeClass('sscribe-summary-time-pending');
						} else {
							$('#sscribe-summary-time').text(sscribe_data.strings.summary_time_hint || 'See Preview').removeClass('sscribe-summary-time-pending');
						}
					},
					error: function (xhr, status) {
						if (status === 'abort') {
							return;
						}
						$('#sscribe-summary-time').text(sscribe_data.strings.summary_time_hint || 'See Preview').removeClass('sscribe-summary-time-pending');
					},
				});
			}, 300);
			const liveRegion = document.getElementById('sscribe-live-region');
			if (liveRegion) {
				if (count > 0) {
					const tplReady = (sscribe_data.strings && sscribe_data.strings.live_region_ready)
						|| '%1$d %2$s ready for export';
					liveRegion.textContent = tplReady
						.replace('%1$d', count)
						.replace('%2$s', (count === 1 ? ((sscribe_data.strings && sscribe_data.strings.log_page) || 'page') : ((sscribe_data.strings && sscribe_data.strings.log_pages) || 'pages')));
				} else {
					liveRegion.textContent =
						(sscribe_data.strings && sscribe_data.strings.live_region_no_pages)
						|| 'No pages match selected options. Export button is disabled.';
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
			clearTimeout(this._configSummaryDebounceTimer);
			if (this._configSummaryXHR && this._configSummaryXHR.abort) {
				this._configSummaryXHR.abort();
			}
			this.isProcessing = true;
			this.batchRetries = 0;
			const $exportBtns = $('#sscribe-export-btn, #sscribe-preview-btn');
			$exportBtns.prop('disabled', true).addClass('sscribe-btn-busy');
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
					format_options: this.collectFormatOptions(),
				},
				success: function (response) {
					if (response.success) {
						SScribe.showProgress();
						SScribe.sessionId = response.data.session_id;
						SScribe.updateStatus(response.data.message);
						if (response.data.partial_export) {
							SScribe.showWarning(
								response.data.partial_export_message,
								false
							);
						}
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
			const confirmTitle =
				(sscribe_data.strings && sscribe_data.strings.cancel_title) ||
				'Cancel this export?';
			const confirmDesc =
				(sscribe_data.strings && sscribe_data.strings.cancel_confirm) ||
				'Cancel the current export? Partial progress will be discarded.';
			const confirmBtn =
				(sscribe_data.strings && sscribe_data.strings.cancel) ||
				'Cancel Export';
			this.showConfirm({
				title: confirmTitle,
				description: confirmDesc,
				confirmLabel: confirmBtn,
				variant: 'danger',
				onProceed: function () {
					SScribe._doCancelExport();
				},
				onCancel: function () {
					SScribe.announce(sscribe_data.strings.cancel_aborted || 'Cancellation aborted.');
				},
			});
		},
		/**
		 * Internal: send the cancel-export AJAX request after the user confirms.
		 * Split out so the confirm modal's onProceed closure can call it safely.
		 */
		_doCancelExport: function () {
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
				error: function (xhr) {
					SScribe.isProcessing = false;
					SScribe.resetUI();
					$('#sscribe-cancel-btn')
						.prop('disabled', false)
						.text(sscribe_data.strings.cancel || 'Cancel Export');
					const msg = SScribe.parseServerError(xhr)
						|| (sscribe_data.strings && sscribe_data.strings.err_cancel_failed)
						|| 'Could not confirm cancellation : the server may still be processing. Reload the page before starting a new export.';
					SScribe.showToast(msg, 'warning', 0);
					$('#sscribe-export-btn, #sscribe-preview-btn').prop('disabled', true);
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
			this.populateSuccessMeta(data);
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
						if (typeof response.data.total_count !== 'undefined') {
							const $stat = $('#sscribe-stat-recent-exports');
							if ($stat.length) {
								$stat.text(response.data.total_count);
							}
						}
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
					' : ' +
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
			const $checks = $('.sscribe-history-check:checked').not('.sscribe-history-row-hidden .sscribe-history-check');
			const $all = $('.sscribe-history-check').not('.sscribe-history-row-hidden .sscribe-history-check');
			const $bar = $('#sscribe-bulk-bar');
			if (!$bar.length) {
				return;
			}
			const count = $checks.length;
			const total = $all.length;
			if (count > 0) {
				$bar.removeClass('sscribe-hidden').attr('data-active', 'true');
				$('#sscribe-bulk-count').text(count + ' ' + (sscribe_data.strings.selected || 'selected'));
				$('.sscribe-history-row').removeClass('sscribe-row-selected');
				$checks.closest('.sscribe-history-row').addClass('sscribe-row-selected');
				const $selectAll = $('#sscribe-bulk-select-all');
				if (count === total && total > 0) {
					$selectAll.prop('checked', true).prop('indeterminate', false);
				} else {
					$selectAll.prop('checked', false).prop('indeterminate', true);
				}
				$('#sscribe-bulk-download-btn, #sscribe-bulk-delete-btn').prop('disabled', false);
			} else {
				$bar.attr('data-active', 'false');
				$('.sscribe-history-row').removeClass('sscribe-row-selected');
				$('#sscribe-bulk-select-all').prop('checked', false).prop('indeterminate', false);
				$('#sscribe-bulk-download-btn, #sscribe-bulk-delete-btn').prop('disabled', true);
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
						if (typeof callback === 'function') {
							callback(true);
						}
					} else {
						SScribe.showError(
							response.data.message ||
								(sscribe_data.strings && sscribe_data.strings.delete_failed) ||
								'Failed to delete export.'
						);
						if (typeof callback === 'function') {
							callback(false);
						}
					}
				},
				error: function () {
					SScribe.showError(
						(sscribe_data.strings && sscribe_data.strings.delete_failed) || 'Failed to delete export.'
					);
					if (typeof callback === 'function') {
						callback(false);
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
			const count = $checks.length;
			const template =
				(sscribe_data.strings && sscribe_data.strings.bulk_delete_confirm) ||
				'Delete %d selected export(s)? This cannot be undone.';
			const message = template.replace('%d', String(count));
			const proceedLabel =
				(sscribe_data.strings && sscribe_data.strings.bulk_delete_label) ||
				'Delete forever';
			const self = this;
			this.showConfirm({
				title: (sscribe_data.strings && sscribe_data.strings.bulk_delete_title) ||
					'Delete ' + count + ' export(s)?',
				description: (sscribe_data.strings && sscribe_data.strings.bulk_delete_desc) ||
					'This permanently removes the selected packages from your uploads folder. The deletion cannot be undone.',
				body: '<ul class="sscribe-confirm-list">' +
					$checks.map(function () {
						const name = SScribe.escapeHtml($(this).val());
						return '<li>' + name + '</li>';
					}).get().join('') +
					'</ul>',
				confirmLabel: proceedLabel,
				variant: 'danger',
				onProceed: function () {
					SScribe._doBulkDeleteSelected();
				},
				onCancel: function () {
					SScribe.announce(sscribe_data.strings.bulk_delete_cancelled || 'Bulk delete cancelled.');
				},
			});
			return;
		},
		/**
		 * Internal: actually run the bulk-delete AJAX flow.
		 */
		_doBulkDeleteSelected: function () {
			const $checks = $('.sscribe-history-check:checked');
			if ($checks.length === 0) {
				return;
			}
			const count = $checks.length;
			const filenames = [];
			$checks.each(function () {
				filenames.push($(this).val());
			});
			$checks.closest('.sscribe-history-row').addClass('sscribe-row-deleting');
			if (typeof this.bulkDeleteQueue === 'undefined') {
				this.bulkDeleteQueue = [];
			}
			this.bulkDeleteQueue = filenames.slice();
			SScribe.announce(
				(sscribe_data.strings && sscribe_data.strings.bulk_delete_started) ||
					'Deleting %d export(s)...'.replace('%d', String(count))
			);
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
				if (percentage >= 1) {
					progressBar.classList.remove('sscribe-progress-initializing');
				}
				progressBar.style.transform = 'scaleX(' + percentage / 100 + ')';
				progressBar.setAttribute('aria-valuenow', percentage);
				if (typeof currentPage === 'number' && typeof totalPages === 'number' && totalPages > 0) {
					const tpl = (sscribe_data.strings && sscribe_data.strings.progress_pages)
						|| 'Processing %1$d of %2$d pages';
					progressBar.setAttribute(
						'aria-valuetext',
						tpl.replace('%1$d', currentPage).replace('%2$d', totalPages)
					);
				}
			} else {
				$('#sscribe-progress-bar').removeClass('sscribe-progress-initializing').css('width', percentage + '%');
			}
			$('#sscribe-progress-text').text(percentage + '%');
			const liveRegion = document.getElementById('sscribe-live-region');
			if (liveRegion) {
				const prefix = (sscribe_data.strings && sscribe_data.strings.export_progress_prefix)
					|| 'Export progress:';
				const tplProg = (sscribe_data.strings && sscribe_data.strings.live_region_progress)
					|| '%1$s %2$d%%';
				liveRegion.textContent = tplProg
					.replace('%1$s', prefix)
					.replace('%2$d', percentage);
			}
			const titleTpl = (sscribe_data.strings && sscribe_data.strings.document_title)
				|| '(%d%%) SScribe Export';
			document.title = titleTpl
				.replace('%d', percentage)
				.replace('%%', '%');
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
			$('#sscribe-progress-area')
				.removeClass('sscribe-hidden')
				.hide()
				.fadeIn(400, function () {
					$('#sscribe-progress-bar').addClass('sscribe-progress-initializing');
					SScribe.updateProgress(0);
					SScribe.updatePhase('fetching');
				});
			$('#sscribe-current-page').text('').hide();
			$('#sscribe-time-remaining').text('').hide();
			$('#sscribe-cancel-btn')
				.prop('disabled', false)
				.text(sscribe_data.strings.cancel || 'Cancel Export');
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
		 * @param {jqXHR}  xhr The jQuery XHR object.
		 * @param {*} exception The exception object (if any).
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
				}
			}
			console.groupCollapsed('[SSCRIBE] AJAX Error : %s (HTTP %d %s)', action, statusCode, statusText);
			console.log('Timestamp:', timestamp);
			console.log('Action:', action);
			console.log('HTTP Status:', statusCode, statusText);
			console.log('Request Data:', SScribe.redactNonce(requestData) || {});
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
		},
		/**
		 * Return a shallow copy of `data` with any `nonce` field replaced
		 * by a `[REDACTED]` placeholder. Does not mutate the input.
		 *
		 * Used before logging to the browser console so a copy-paste
		 * mistake or a browser extension reading the console cannot
		 * exfiltrate the live SScribe export nonce (a 12h-valid token).
		 *
		 * @param {object|null|undefined} data The AJAX request data.
		 * @return {object|null} A safe-to-log copy of the data.
		 */
		redactNonce: function (data) {
			if (!data || typeof data !== 'object') {
				return data;
			}
			const safe = {};
			for (const key of Object.keys(data)) {
				if (key === 'nonce') {
					safe[key] = '[REDACTED]';
				} else {
					safe[key] = data[key];
				}
			}
			return safe;
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
		 * @param {string} type One of 'success', 'error', 'warning', 'info'.
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
		/**
		 * Push a polite announcement to the screen-reader live region.
		 *
		 * Throttled to once per second per message : without throttling,
		 * the per-page progress updates fire dozens of announcements per
		 * second, which floods SR users and obscures the actual state.
		 * The live region is shared across the admin surface, so callers
		 * get a single channel that re-announces on demand via a
		 * trailing-edge debounce.
		 *
		 * @param {string} message Plain-text message for assistive tech.
		 */
		announce: function (message) {
			if (!message) {
				return;
			}
			const $region = $('#sscribe-live-region');
			if (!$region.length) {
				return;
			}
			const now = Date.now();
			const last = this._lastAnnounce || { text: '', at: 0 };
			if (last.text === message && now - last.at < 1000) {
				return;
			}
			this._lastAnnounce = { text: message, at: now };
			$region.text('');
			setTimeout(function () {
				$region.text(message);
			}, 30);
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
			const dismissLabel =
				(sscribe_data.strings && sscribe_data.strings.dismiss_notification) || 'Dismiss notification';
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
			const totalPages = this.parseLocalizedInt(data.total_pages);
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
			html += '<span class="sscribe-preview-value">' + this.escapeHtml(this.getLanguageLabel(data.language)) + '</span>';
			html += '</div>';
			html += '<div class="sscribe-preview-stat">';
			html +=
				'<span class="sscribe-preview-label">' +
				this.escapeHtml(strings.preview_status || 'Status:') +
				'</span>';
			html += '<span class="sscribe-preview-value">' + this.escapeHtml(this.getPostStatusLabel(data.post_status || 'publish')) + '</span>';
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
		 * Open the confirm (alertdialog) modal. Replaces native window.confirm().
		 *
		 * @param {Object} opts Options.
		 * @param {string} opts.title Heading text (already localized).
		 * @param {string} opts.description Short description shown under title.
		 * @param {string} [opts.body] Optional HTML body content (already escaped).
		 * @param {string} [opts.confirmLabel] Proceed button label. Default: "Confirm".
		 * @param {string} [opts.cancelLabel] Cancel button label. Default: "Cancel".
		 * @param {string} [opts.variant] "danger" (red proceed) or default.
		 * @param {Function} opts.onProceed Called when user confirms. Receives `done(true)`.
		 * @param {Function} [opts.onCancel] Called when user dismisses. Receives `done(false)`.
		 * @returns {void}
		 */
		showConfirm: function (opts) {
			if (!opts || typeof opts.onProceed !== 'function') {
				return;
			}
			const $modal = $('#sscribe-confirm-modal');
			if (!$modal.length) {
				opts.onProceed(function () { return true; });
				return;
			}
			const $title = $('#sscribe-confirm-title');
			const $desc = $('#sscribe-confirm-desc');
			const $body = $('#sscribe-confirm-body');
			const $proceed = $('#sscribe-confirm-proceed');
			const $cancel = $('#sscribe-confirm-cancel');
			$title.text(opts.title || 'Confirm action');
			$desc.text(opts.description || 'Are you sure?');
			const bodyHtml = opts.body || '';
			$body.html(bodyHtml);
			if (bodyHtml.trim() === '') {
				$modal.addClass('sscribe-confirm-empty');
			} else {
				$modal.removeClass('sscribe-confirm-empty');
			}
			const proceedLabel = opts.confirmLabel || 'Confirm';
			const cancelLabel = opts.cancelLabel || 'Cancel';
			$proceed.text(proceedLabel);
			$cancel.text(cancelLabel);
			$proceed.removeClass('sscribe-button-danger sscribe-button-primary');
			if (opts.variant === 'danger') {
				$proceed.addClass('sscribe-button-danger');
			} else {
				$proceed.addClass('sscribe-button-primary');
			}
			const self = this;
			$modal.removeClass('sscribe-hidden').attr('aria-hidden', 'false').prop('hidden', false);
			this.trapFocus($modal[0]);
			const cancel = function () {
				$modal.addClass('sscribe-hidden').attr('aria-hidden', 'true').prop('hidden', true);
				self.releaseFocusTrap($modal[0]);
				if (typeof opts.onCancel === 'function') {
					opts.onCancel();
				}
			};
			const proceed = function () {
				$modal.addClass('sscribe-hidden').attr('aria-hidden', 'true').prop('hidden', true);
				self.releaseFocusTrap($modal[0]);
				opts.onProceed();
			};
			$proceed.off('click.sscribe-confirm').one('click.sscribe-confirm', function (e) {
				e.preventDefault();
				proceed();
			});
			$cancel.off('click.sscribe-confirm').one('click.sscribe-confirm', function (e) {
				e.preventDefault();
				cancel();
			});
			$modal.off('click.sscribe-confirm-overlay').one('click.sscribe-confirm-overlay', function (e) {
				if (e.target === this) {
					cancel();
				}
			});
			setTimeout(function () {
				if ($proceed[0] && !$proceed.prop('disabled')) {
					$proceed.trigger('focus');
				} else if ($cancel[0]) {
					$cancel.trigger('focus');
				}
			}, 0);
		},
		/**
		 * Dismiss the onboarding banner and remember the dismissal across sessions.
		 * @param {Event} e Click event.
		 */
		dismissOnboarding: function (e) {
			if (e) {
				e.preventDefault();
			}
			const $banner = $('#sscribe-onboarding-banner');
			$banner.fadeOut(160, function () {
				$banner.remove();
			});
			// localStorage persists dismissal across tabs/sessions; sessionStorage
			// would re-show the banner every new tab. Fall back gracefully when
			// storage is unavailable (private browsing, locked-down profile).
			try {
				localStorage.setItem('sscribe_onboarding_dismissed', '1');
			} catch (_err) {
				try {
					sessionStorage.setItem('sscribe_onboarding_dismissed', '1');
				} catch (__err) {
					/* storage unavailable - banner stays in DOM until next load */
				}
			}
		},
		/**
		 * Toggle the technical-details section under the error card.
		 * @param {Event} e Click event.
		 */
		toggleErrorDetails: function (e) {
			if (e) {
				e.preventDefault();
			}
			const $btn = $('#sscribe-error-toggle-details');
			const $details = $('#sscribe-error-technical-details');
			if (!$btn.length || !$details.length) {
				return;
			}
			const open = $btn.attr('aria-expanded') === 'true';
			$btn.attr('aria-expanded', open ? 'false' : 'true');
			$('#sscribe-error-toggle-details-label').text(
				open
					? (sscribe_data.strings && sscribe_data.strings.error_show_details) || 'Show technical details'
					: (sscribe_data.strings && sscribe_data.strings.error_hide_details) || 'Hide technical details'
			);
			$details.prop('hidden', open);
		},
		/**
		 * Filter history rows by name or size.
		 * @param {Event} e Input event.
		 */
		filterHistory: function (e) {
			const raw = (e && e.target && e.target.value) || '';
			const needle = String(raw).toLowerCase().trim();
			const $rows = $('#sscribe-history-table .sscribe-history-row');
			let visible = 0;
			$rows.each(function () {
				if (needle === '') {
					$(this).removeClass('sscribe-history-row-hidden');
					visible++;
					return;
				}
				const text = $(this).text().toLowerCase();
				if (text.indexOf(needle) !== -1) {
					$(this).removeClass('sscribe-history-row-hidden');
					visible++;
				} else {
					$(this).addClass('sscribe-history-row-hidden');
				}
			});
			this.updateBulkBar();
			if (needle !== '' && visible === 0) {
				this.announce(
					(sscribe_data.strings && sscribe_data.strings.history_no_match) ||
					'No exports match your filter.'
				);
			}
		},
		/**
		 * Empty-state CTA: switch to the export tab and focus the post-type section.
		 */
		startFirstExportFromEmpty: function (e) {
			if (e) {
				e.preventDefault();
			}
			this.activateTab('export', true);
			const target = document.getElementById('sscribe-main-content');
			if (target && typeof target.focus === 'function') {
				target.focus();
			}
		},
		/**
		 * Success-state CTA: switch to the History tab so the user can see
		 * the export they just completed (and any prior exports). Keeps the
		 * success section visible briefly so screen readers announce the
		 * tab change.
		 */
		openHistoryFromSuccess: function (e) {
			if (e) {
				e.preventDefault();
			}
			this.activateTab('history', true);
		},
		/**
		 * Populate the success meta block (pages, formats, size, generated).
		 *
		 * @param {Object} data Export completion data from server.
		 */
		populateSuccessMeta: function (data) {
			if (!data || typeof data !== 'object') {
				return;
			}
			const setVal = function (id, val) {
				const $el = document.getElementById(id);
				if ($el) {
					$el.textContent = val;
				}
			};
			if (typeof data.pages !== 'undefined') {
				setVal('sscribe-success-pages', String(data.pages));
			} else if (typeof data.processed !== 'undefined') {
				setVal('sscribe-success-pages', String(data.processed));
			} else if (typeof data.total !== 'undefined') {
				setVal('sscribe-success-pages', String(data.total));
			}
			if (data.formats && data.formats.length) {
				setVal('sscribe-success-formats', data.formats.length > 1
					? data.formats.length + ' formats: ' + data.formats.join(', ').toUpperCase()
					: String(data.formats[0]).toUpperCase());
			} else if (typeof data.format !== 'undefined') {
				setVal('sscribe-success-formats', String(data.format).toUpperCase());
			} else if (data.filename && /\.zip$/i.test(data.filename)) {
				const stem = data.filename.replace(/\.zip$/i, '');
				setVal('sscribe-success-formats', stem.toUpperCase());
			}
			if (typeof data.size !== 'undefined' && data.size) {
				const formatted = (typeof this.formatBytes === 'function')
					? this.formatBytes(data.size)
					: Math.round(data.size / 1024) + ' KB';
				setVal('sscribe-success-size', formatted);
			} else if (typeof data.file_size !== 'undefined' && data.file_size) {
				const formatted = (typeof this.formatBytes === 'function')
					? this.formatBytes(data.file_size)
					: Math.round(data.file_size / 1024) + ' KB';
				setVal('sscribe-success-size', formatted);
			}
			if (typeof data.elapsed !== 'undefined') {
				setVal('sscribe-success-time', 'in ' + data.elapsed + 's');
			} else if (data.created_at) {
				const stamp = new Date(data.created_at * 1000 || Date.now());
				setVal('sscribe-success-time', stamp.toLocaleString());
			}
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
			$grid.removeClass('sscribe-hidden').html(
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
			if ($btn.data('sscribe-confirming')) {
				$btn.removeData('sscribe-confirming');
				clearTimeout($btn.data('sscribe-confirm-timeout'));
				$row.addClass('sscribe-row-deleting');
				SScribe.deleteSingleExport(filename, function (success) {
					if (success === false) {
						$row.removeClass('sscribe-row-deleting');
						return;
					}
					$row.fadeOut(200, function () {
						$(this).remove();
						SScribe.refreshRecentExports();
					});
				});
				return;
			}
			const originalText = $btn.text();
			const confirmMsg =
				(sscribe_data.strings && sscribe_data.strings.delete_confirm_hint) ||
				'Click again within 3 seconds to confirm deletion.';
			$btn
				.data('sscribe-confirming', true)
				.data('sscribe-confirm-original', originalText)
				.text(sscribe_data.strings.click_again || 'Click again')
				.attr('aria-label', confirmMsg)
				.prop('disabled', false);
			SScribe.announce(confirmMsg);
			const tid = setTimeout(function () {
				if ($btn.data('sscribe-confirming')) {
					$btn
						.removeData('sscribe-confirming')
						.removeData('sscribe-confirm-original')
						.text(originalText)
						.removeAttr('aria-label');
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
			const renderLoading = () => {
				$content.html(
					'<div class="sscribe-log-loading">' +
						'<span class="sscribe-loading-spinner"></span>' +
						'<span>' +
						this.escapeHtml(sscribe_data.strings.loading_log || 'Loading log...') +
						'</span>' +
						'</div>'
				);
			};
			const renderError = (message) => {
				const safe = this.escapeHtml(message);
				$content.html(
					'<div class="sscribe-log-error" role="alert">' +
						'<p>' + safe + '</p>' +
						'<button type="button" class="sscribe-button sscribe-button-ghost sscribe-log-retry">' +
						this.escapeHtml(sscribe_data.strings.retry || 'Retry') +
						'</button>' +
						'</div>'
				);
				$content.find('.sscribe-log-retry').on('click', () => {
					renderLoading();
					fetchLog();
				});
			};
			renderLoading();
			$modal.attr('aria-hidden', 'false').removeClass('sscribe-hidden').prop('hidden', false).hide().fadeIn(300);
			this.saveFocus();
			this.trapFocus($modal[0]);
			this.focusFirstInteractive($modal[0], '#sscribe-modal-close');
			const self = this;
			const fetchLog = () => {
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
							renderError(message);
						}
					},
					error: function () {
						renderError(sscribe_data.strings.log_load_failed || 'Failed to load log.');
					},
				});
			};
			fetchLog();
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
			// Mark hidden synchronously so AT and the focus trap see the modal
			// as gone the instant the user dismisses it, then animate the fade.
			// Doing it inside the fadeOut callback left the modal partly visible
			// yet announced as hidden for the full 200 ms transition.
			$modal.attr('aria-hidden', 'true').addClass('sscribe-hidden').prop('hidden', true);
			self.releaseFocusTrap(modalEl);
			self.restoreFocus();
			$modal.fadeOut(200);
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
			$('#sscribe-progress-bar')
				.removeClass('sscribe-progress-initializing')
				.css('transform', 'scaleX(0)');
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
		 * Get error guidance based on a stable server-side error code.
		 *
		 * Prefer {@link data.code} when the server returns it (introduced in 1.1.3
		 * via SScribe_AJAX_Guard) so the message can stay localized without
		 * breaking guidance lookup. Fall back to substring matching only when
		 * an older endpoint hasn't been migrated yet.
		 *
		 * @param {string} code    Stable error code from response.data.code.
		 * @param {string} message Error message (fallback heuristic only).
		 * @returns {string} Guidance text.
		 */
		getErrorGuidance: function (code, message) {
			const strings = (sscribe_data && sscribe_data.strings) || {};
			if (code) {
				switch (code) {
					case 'invalid_nonce':
					case 'permission_denied':
					case 'session_expired':
						return strings.err_session_expired || strings.err_generic || '';
					case 'memory':
					case 'memory_exhausted':
						return strings.err_memory || strings.err_generic || '';
					case 'zip_failed':
					case 'archive_failed':
						return strings.err_zip || strings.err_generic || '';
					case 'rate_limit':
					case 'rate_limited':
						return strings.err_rate_limit || strings.err_generic || '';
					default:
						return strings.err_generic || '';
				}
			}
			const msg = message || '';
			if (msg.indexOf('session') !== -1 || msg.indexOf('timeout') !== -1) {
				return strings.err_session_expired || strings.err_generic || '';
			}
			if (msg.indexOf('memory') !== -1) {
				return strings.err_memory || strings.err_generic || '';
			}
			if (msg.indexOf('zip') !== -1 || msg.indexOf('archive') !== -1) {
				return strings.err_zip || strings.err_generic || '';
			}
			if (msg.indexOf('rate') !== -1 || msg.indexOf('limit') !== -1) {
				return strings.err_rate_limit || strings.err_generic || '';
			}
			return strings.err_generic || '';
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
			if (this._originalTitle) {
				document.title = this._originalTitle;
			}
			$('#sscribe-progress-area').fadeOut(200);
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
					console.log('[SSCRIBE] Server diagnostics for this error:', diagnosticInfo);
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
				const errorCode = (errorData && errorData.code) || '';
				guidance = this.getErrorGuidance(errorCode, message);
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
		 * Show a non-fatal warning toast.
		 *
		 * @param {string} message Warning message.
		 * @param {boolean} isHtml Whether the message is HTML.
		 */
		showWarning: function (message, isHtml) {
			const alertRegion = document.getElementById('sscribe-alert-region');
			if (alertRegion) {
				alertRegion.textContent = '';
				alertRegion.textContent = message;
			}
			const $toast = $(
				'<div class="notice notice-warning sscribe-toast" role="status">' +
					'<p></p>' +
				'</div>'
			);
			if (isHtml) {
				$toast.find('p').html(message);
			} else {
				$toast.find('p').text(message);
			}
			const $container = $('#sscribe-toast-container');
			if ($container.length) {
				$container.append($toast);
			} else {
				$('body').append($toast);
			}
			setTimeout(function () {
				$toast.fadeOut(300, function () {
					$(this).remove();
				});
			}, 8000);
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
			if (status === 429) {
				return (
					strings.net_429 ||
					strings.err_rate_limit ||
					'Too many requests. Please slow down and try again in a moment.'
				);
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

	window.SScribe = SScribe;
})(jQuery);
