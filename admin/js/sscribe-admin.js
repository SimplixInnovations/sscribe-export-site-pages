/**
 * SScribe Admin JavaScript
 *
 * Handles AJAX batch processing with animated progress tracking.
 *
 * @package SScribe
 * @version 2.0.4
 */
(function ($) {
	'use strict';
	const SScribe = {
		sessionId: null,
		isProcessing: false,
		isPreparing: false,
		selectedPageCount: 0,
		/**
		 * Client-owned monotonically increasing generation counter for
		 * count/status requests. Every logical counts refresh increments
		 * this BEFORE issuing requests, and any response whose echoed
		 * `client_generation` does not EXACTLY equal the current value
		 * is discarded. The server does not generate sequence numbers —
		 * it only echoes the value the browser originated.
		 *
		 * Replaces the previous PHP-side `$GLOBALS['__sscribe_request_seq']`
		 * mechanism which broke under separate `admin-ajax.php` requests
		 * (each PHP request started fresh and returned `1`).
		 *
		 * @type {number}
		 */
		_countsRequestGeneration: 0,
		_countsRetries: 0,
		/**
		 * Single authoritative counts-state object. The DOM is a RENDERING
		 * TARGET — runtime decisions must read from this object, never from
		 * `.text()` parsing of rendered DOM.
		 *
		 * `loaded: true` means a successful authoritative response was
		 * received for exactly the currently selected (postType, language,
		 * status) tuple. Any refresh failure MUST reset loaded=false and
		 * never promote stale values from a previous selection.
		 *
		 * @type {{
		 *     generation: number,
		 *     postType: string|null,
		 *     language: string|null,
		 *     status: string|null,
		 *     statusCounts: Object<string,number>,
		 *     typeCounts: Object<string,number>,
		 *     languageCounts: Object<string,number>,
		 *     loaded: boolean,
		 *     error: string|null,
		 *     errorCode: string|null
		 * }}
		 */
		countsState: {
			// Phase 5: SINGLE AUTHORITATIVE COUNT CONTRACT.
			// This shape is locked. updateConfigSummary(), the export
			// enable gate, and the preview debounce all read from this
			// object. The DOM is a RENDERING TARGET, never the source.
			// Adding/removing keys here requires updating both
			// Phase 4's generation check (line ~621) and Phase 3's
			// statusCounts lookup (line ~1037).
			generation: 0,
			postType: null,
			language: null,
			status: null,
			statusCounts: {},
			typeCounts: {},
			languageCounts: {},
			loaded: false,
			error: null,
			errorCode: null,
		},
		_lastProgressAt: 0,
		batchRetries: 0,
		maxBatchRetries: 3,
		pollBackoff: 0,
		pollBackoffBase: 1000,
		pollBackoffMax: 30000,
		pollJitter: 200,
		finalizePollInterval: 2000,
		_originalTitle: '',
		_batchXHR: null,
		_batchTimer: null,
		_cancelTimer: null,
		_cancelRetries: 0,
		maxCancelRetries: 6,
		_finalizingXHR: null,
		_isCancelling: false,
		_langCountsXHRs: null,
		/**
		 * @type {Promise<string|null>|null}
		 */
		_nonceRefreshInFlight: null,
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
		 * @param {string|null|undefined} code Two/three-letter language code.
		 * @returns {string} Human-readable language label.
		 */
		getLanguageLabel: function (code) {
			if (code === '__all__' || code === '' || code === null || code === undefined) {
				return (sscribe_data.strings && sscribe_data.strings.all_languages) || 'All Languages';
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
		init: function () {
			if (typeof sscribe_data === 'undefined' || !sscribe_data) {
				return;
			}
			this._originalTitle = document.title;
			this._lastAnnouncedBucket = -1;
			this.bindEvents();
			this.initializeTabs();
			this.initializeRadiogroups();
			this.initAutoDownloadPreference();
			this.restoreDismissedGuidance();
			this.adjustToastContainerPosition();
			if (!SScribe._visibilityInstalled && typeof document !== 'undefined') {
				document.addEventListener('visibilitychange', $.proxy(this, 'handleVisibilityChange'));
				SScribe._visibilityInstalled = true;
			}
			$('#sscribe-export-disabled-reason').text(
				(sscribe_data.strings && sscribe_data.strings.loading_counts) || 'Loading page counts...'
			);
			this.updateConfigSummary();
			this.updateFormatOptionPanels();
			const defaultPostType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const defaultLanguage = $('input[name="sscribe_language"]:checked').val() || '';
			this.refreshStatusAndLanguageCounts(defaultPostType, defaultLanguage);
			this.checkActiveSession();
			this._installStallWatchdog();
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
		restoreDismissedGuidance: function () {
			try {
				if (localStorage.getItem('sscribe_onboarding_dismissed') === '1') {
					$('#sscribe-onboarding-banner').remove();
				}
			} catch (_localStorageError) {
				try {
					if (sessionStorage.getItem('sscribe_onboarding_dismissed') === '1') {
						$('#sscribe-onboarding-banner').remove();
					}
				} catch (_sessionStorageError) {
					// Storage can be unavailable in hardened/private browsing contexts.
				}
			}
			try {
				const dismissed = JSON.parse(sessionStorage.getItem('sscribe_preflight_dismissed') || '{}');
				$('.sscribe-preflight-warning[data-warning-code]').each(function () {
					const code = String($(this).attr('data-warning-code') || '');
					if (code && dismissed && dismissed[code] === '1') {
						$(this).attr('data-dismissed', 'true');
					}
				});
			} catch (_dismissedStorageError) {
				// Invalid/unavailable session storage leaves guidance visible.
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
			$(document).on('click.sscribe', '#sscribe-error-change-config', $.proxy(this.changeConfiguration, this));
			$(document).on('click.sscribe', '#sscribe-cancel-btn', $.proxy(this.cancelExport, this));
			$('.sscribe-lang-card-label').on('click.sscribe', function () {
				$(this).find('input[type="radio"]').prop('checked', true);
			});
			$('input[name="sscribe_post_type"]').on('change.sscribe', $.proxy(this.onPostTypeChange, this));
			$('input[name="sscribe_language"]').on('change.sscribe', $.proxy(this.onLanguageChange, this));
			$('input[name="sscribe_post_status"]').on('change.sscribe', $.proxy(this.updateConfigSummary, this));
			$('input[name="sscribe_format"]').on('change.sscribe', $.proxy(this.onFormatChange, this));
			$(document).on('change.sscribe', '#sscribe-auto-download-toggle', $.proxy(this.onAutoDownloadToggle, this));
			$(document).on('click.sscribe', '.sscribe-delete-btn', $.proxy(this.deleteExport, this));
			$(document).on(
				'click.sscribe',
				'.sscribe-preflight-warning-dismiss',
				$.proxy(this.dismissPreflightWarning, this)
			);
			$(document).on('click.sscribe', '.sscribe-log-btn', $.proxy(this.showExportLog, this));
			$(document).on('change.sscribe', '.sscribe-history-check', $.proxy(this.updateBulkBar, this));
			$(document).on('change.sscribe', '#sscribe-bulk-select-all', $.proxy(this.toggleSelectAll, this));
			$(document).on('click.sscribe', '#sscribe-bulk-delete-btn', $.proxy(this.bulkDeleteSelected, this));
			$(document).on('click.sscribe', '#sscribe-bulk-download-btn', $.proxy(this.bulkDownload, this));
			$(document).on('click.sscribe', '#sscribe-support-refresh-btn', $.proxy(this.loadSupportInfo, this));
			$(document).on('click.sscribe', '#sscribe-support-copy-btn', $.proxy(this.copySupportInfo, this));
			$(document).on('click.sscribe', '#sscribe-modal-close', $.proxy(this.closeModal, this));
			$(document).on('click.sscribe', '[data-close-modal]', $.proxy(this.closeModalByAttr, this));
			$(document).on('click.sscribe', '.sscribe-history-actions > a', $.proxy(this.downloadExport, this));
			$(document).on('click.sscribe', '#sscribe-onboarding-dismiss', $.proxy(this.dismissOnboarding, this));
			$(document).on('click.sscribe', '#sscribe-error-toggle-details', $.proxy(this.toggleErrorDetails, this));
			$(document).on('input.sscribe', '#sscribe-history-search', $.proxy(this.filterHistory, this));
			$(document).on(
				'click.sscribe',
				'#sscribe-empty-start-export-btn',
				$.proxy(this.startFirstExportFromEmpty, this)
			);
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
			try {
				const urlParams = new URL(window.location.href).searchParams;
				const requestedTab = urlParams.get('tab');
				if (requestedTab) {
					const $requested = $tabs.filter(function () {
						return $(this).data('tab') === requestedTab;
					});
					if ($requested.length) {
						activeTabId = requestedTab;
					}
				}
			} catch (_e) {
				// Silent: URL parsing failure leaves ?tab untouched; non-fatal.
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
				const direction = window.getComputedStyle(this).direction;
				const horizontalStep = direction === 'rtl' ? -1 : 1;
				if (key === 'ArrowRight') {
					nextIndex = (currentIndex + horizontalStep + $orderedTabs.length) % $orderedTabs.length;
				} else if (key === 'ArrowLeft') {
					nextIndex = (currentIndex - horizontalStep + $orderedTabs.length) % $orderedTabs.length;
				} else if (key === 'Home') {
					nextIndex = 0;
				} else if (key === 'End') {
					nextIndex = $orderedTabs.length - 1;
				}
				const $nextTab = $orderedTabs.eq(nextIndex);
				self.activateTab($nextTab.data('tab'), true);
			});
		},
		initializeRadiogroups: function () {
			const self = this;
			const groupSelectors = [
				'#sscribe-post-type-cards',
				'#sscribe-language-cards',
				'#sscribe-status-cards',
				'#sscribe-format-cards',
			];
			groupSelectors.forEach(function (sel) {
				const $container = $(sel);
				if (!$container.length) {
					return;
				}
				const $inputs = $container.find('input[type="radio"]');
				if (!$inputs.length) {
					return;
				}
				const groupName = $inputs.first().attr('name');
				if (!groupName) {
					return;
				}
				const $titleEl = $container
					.closest('.sscribe-config-section')
					.find('.sscribe-section-title, .sscribe-config-section-header')
					.first();
				let titleId = $titleEl.attr('id');
				if (!titleId) {
					titleId = 'sscribe-radiogroup-title-' + groupName;
					$titleEl.attr('id', titleId);
				}
				$container.attr({
					role: 'radiogroup',
					'aria-labelledby': titleId,
				});
				self.applyRovingTabindex(groupName);
				$container.on('change', 'input[type="radio"]', function () {
					self.applyRovingTabindex(groupName);
				});
				$container.on('focus', 'input[type="radio"]', function () {
					self.applyRovingTabindex(groupName, $(this));
				});
				$container.on('keydown', 'input[type="radio"]', function (e) {
					const key = e.key;
					if (!['ArrowRight', 'ArrowLeft', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(key)) {
						return;
					}
					e.preventDefault();
					const $siblings = $container.find('input[type="radio"][name="' + groupName + '"]').not(':disabled');
					if (!$siblings.length) {
						return;
					}
					const currentIndex = $siblings.index(this);
					let nextIndex = currentIndex;
					const direction = window.getComputedStyle(this).direction;
					if (
						key === 'ArrowDown' ||
						(key === 'ArrowRight' && direction !== 'rtl') ||
						(key === 'ArrowLeft' && direction === 'rtl')
					) {
						nextIndex = (currentIndex + 1) % $siblings.length;
					} else if (key === 'ArrowUp' || key === 'ArrowLeft' || key === 'ArrowRight') {
						nextIndex = (currentIndex - 1 + $siblings.length) % $siblings.length;
					} else if (key === 'Home') {
						nextIndex = 0;
					} else if (key === 'End') {
						nextIndex = $siblings.length - 1;
					}
					const $next = $siblings.eq(nextIndex);
					$next.prop('checked', true).trigger('change').trigger('focus');
				});
			});
		},
		applyRovingTabindex: function (groupName, $focused) {
			const $group = $('input[name="' + groupName + '"]');
			if (!$group.length) {
				return;
			}
			let $target = $focused || $group.filter(':checked');
			if (!$target || !$target.length) {
				$target = $group.filter(':not(:disabled)').first();
			}
			$group.each(function () {
				if (this === $target.get(0)) {
					$(this).attr('tabindex', '0');
				} else {
					$(this).attr('tabindex', '-1');
				}
			});
		},
		activateTab: function (tabId, moveFocus) {
			if (!tabId) {
				return;
			}
			const currentTabId = $('.sscribe-tab-btn[aria-selected="true"]').data('tab');
			if (!this.scrollPositions) {
				this.scrollPositions = {};
			}
			if (currentTabId) {
				this.scrollPositions[currentTabId] = window.scrollY;
			}
			if (currentTabId !== tabId) {
				try {
					const url = new URL(window.location.href);
					url.searchParams.set('tab', tabId);
					window.history.replaceState(null, '', url.toString());
				} catch (_e) {
					// Silent: history-api failure leaves the URL unchanged; non-fatal.
				}
			}
			if (currentTabId === tabId) {
				if (moveFocus) {
					this.focusTabButton(tabId);
				}
				return;
			}
			this.setActiveTab(tabId);
			if (moveFocus) {
				this.focusTabButton(tabId);
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
			if (tabId === 'support') {
				setTimeout(function () {
					if (!String($('#sscribe-support-copy-text').val() || '').trim()) {
						SScribe.loadSupportInfo();
					}
				}, 200);
			}
			const savedY = this.scrollPositions[tabId];
			if (typeof savedY === 'number') {
				window.scrollTo(0, savedY);
			}
		},
		focusTabButton: function (tabId) {
			const $tab = $('.sscribe-tab-btn').filter(function () {
				return $(this).data('tab') === tabId;
			});
			$tab.trigger('focus');
			const tab = $tab.get(0);
			if (tab && typeof tab.scrollIntoView === 'function') {
				tab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
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
				announce.textContent = label ? label + ' tab selected' : 'Tab changed';
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
						.attr('aria-hidden', 'false')
						.attr('tabindex', '0')
						.prop('hidden', false);
				} else {
					$panel
						.removeClass('sscribe-tab-active')
						.attr('aria-hidden', 'true')
						.attr('tabindex', '-1')
						.prop('hidden', true);
					self.releaseFocusTrap($panel[0]);
				}
			});
			$(document).trigger('sscribe:tab:activated', [tabId]);
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
			// Bump the client-owned generation BEFORE any request is issued.
			// Every response handler compares the echoed `client_generation`
			// with EXACT equality to the CURRENT generation, not the value
			// captured by its own request — a stale response must NEVER
			// overwrite newer UI state just because its closure still has
			// its own generation number.
			self._countsRequestGeneration = (self._countsRequestGeneration || 0) + 1;
			const generation = self._countsRequestGeneration;
			self.selectedPageCount = 0;
			self._countsLoaded = false;
			self.updateExportButton();
			$('.sscribe-status-card-label').addClass('sscribe-loading');
			$('[data-sscribe-count-for]').addClass('sscribe-loading-count');
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
					client_generation: generation,
				},
				success: function (response) {
					if (response.success && response.data) {
						// Phase 4 (round 2): discard any response whose generation
						// is not EXACTLY the most-recent one. Comparing against
						// `self._countsRequestGeneration` (the LIVE current) — NOT
						// against the closure-captured `generation` — means an old
						// Pages response can no longer overwrite newer Posts state.
						if (
							typeof response.data.client_generation === 'undefined' ||
							parseInt(response.data.client_generation, 10) !== self._countsRequestGeneration
						) {
							return;
						}
						// Phase 4 (round 2): server must echo the controls we sent
						// AND the live UI selection must still match — the user may
						// have switched Content Type / Language again while this
						// response was in flight.
						const livePostType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
						const liveLanguage = $('input[name="sscribe_language"]:checked').val() || '';
						if (response.data.post_type !== livePostType) {
							return;
						}
						if (typeof response.data.language !== 'undefined' && response.data.language !== liveLanguage) {
							return;
						}
						const allCounts = response.data.counts || {};
						const pageCounts = response.data.counts_page || allCounts;
						const postCounts = response.data.counts_post || {};
						const anyCounts = response.data.counts_any || {};
						self.updateStatusCounts(allCounts);
						const pageTotal = self.parseLocalizedInt(pageCounts.all) || 0;
						const postTotal = self.parseLocalizedInt(postCounts.all) || 0;
						const anyTotal = self.parseLocalizedInt(anyCounts.all) || 0;
						const selectedTypeTotal = self.parseLocalizedInt(allCounts.all) || 0;
						$('[data-sscribe-count-for="page"]')
							.text(pageTotal.toLocaleString())
							.attr('data-count', pageTotal);
						$('[data-sscribe-count-for="post"]')
							.text(postTotal.toLocaleString())
							.attr('data-count', postTotal);
						$('[data-sscribe-count-for="any"]')
							.text(anyTotal.toLocaleString())
							.attr('data-count', anyTotal);
						if (postType !== 'page' && postType !== 'post' && postType !== 'any') {
							const $selectedTypeCount = $('[data-sscribe-count-for]').filter(function () {
								return $(this).attr('data-sscribe-count-for') === postType;
							});
							$selectedTypeCount
								.text(selectedTypeTotal.toLocaleString())
								.attr('data-count', selectedTypeTotal);
						}
						// Phase 3: authoritative countsState — written ONLY
						// on a successful response whose generation, post_type,
						// and language all match the current selection.
						self.countsState = self.countsState || {};
						self.countsState.generation = generation;
						self.countsState.postType = postType;
						self.countsState.language = language;
						self.countsState.statusCounts = Object.assign({}, allCounts);
						self.countsState.typeCounts = {
							page: pageTotal,
							post: postTotal,
							any: anyTotal,
						};
						if (postType !== 'page' && postType !== 'post' && postType !== 'any') {
							self.countsState.typeCounts[postType] = selectedTypeTotal;
						}
						self.countsState.loaded = true;
						self.countsState.error = null;
						self.countsState.errorCode = null;
						self._countsLoaded = true;
						self._countsRetries = 0;
						self.updateConfigSummary();
						self.updateExportButton();
					} else if (!self._countsRetries) {
						// Phase 4 (round 2): capture the generation that caused
						// this retry. If a newer refresh has been started by the
						// time the timer fires, the retry must bail instead of
						// starting yet another request behind the live one.
						const scheduledGeneration = generation;
						self._countsRetries = 1;
						setTimeout(function () {
							if (
								typeof self._countsRequestGeneration === 'undefined' ||
								self._countsRequestGeneration !== scheduledGeneration
							) {
								return;
							}
							self.refreshStatusAndLanguageCounts(postType, language);
						}, 2500);
					} else {
						// FAIL-CLOSED: a failed authoritative refresh must
						// not promote PHP-rendered numbers (from the OLD
						// postType/language) into the new selection's
						// authoritative state. Mark countsState as not
						// loaded and disable Export / Preview until a fresh
						// response arrives. Stale numbers remain visible
						// but only as a hint, never as input to the
						// export-enable decision.
						self._countsRetries = 0;
						self.countsState = self.countsState || {};
						self.countsState.loaded = false;
						self.countsState.error = 'Counts could not be refreshed. Retry to enable export.';
						self.countsState.errorCode = 'refresh_failed';
						self.countsState.postType = postType;
						self.countsState.language = language;
						self._countsLoaded = false;
						self.selectedPageCount = 0;
						self.updateConfigSummary();
						self.updateExportButton();
					}
					$('.sscribe-status-card-label').removeClass('sscribe-loading');
					$('[data-sscribe-count-for]').removeClass('sscribe-loading-count');
				},
				error: function (xhr, textStatus) {
					// Phase 4 (round 2): a deliberately aborted previous
					// request is NOT an error requiring retry. The browser
					// already started a newer request that owns the UI;
					// let that one complete instead of racing against it.
					if (textStatus === 'abort') {
						return;
					}
					if (!self._countsRetries) {
						const scheduledGeneration = generation;
						self._countsRetries = 1;
						setTimeout(function () {
							if (
								typeof self._countsRequestGeneration === 'undefined' ||
								self._countsRequestGeneration !== scheduledGeneration
							) {
								return;
							}
							self.refreshStatusAndLanguageCounts(postType, language);
						}, 2500);
						return;
					}
					// FAIL-CLOSED on transport error after retries: same
					// invariant as the !response.success branch.
					self._countsRetries = 0;
					self.countsState = self.countsState || {};
					self.countsState.loaded = false;
					self.countsState.error = 'Counts could not be refreshed. Retry to enable export.';
					self.countsState.errorCode = 'refresh_failed';
					self.countsState.postType = postType;
					self.countsState.language = language;
					self._countsLoaded = false;
					self.selectedPageCount = 0;
					self.updateConfigSummary();
					self.updateExportButton();
					$('.sscribe-status-card-label').removeClass('sscribe-loading');
					$('[data-sscribe-count-for]').removeClass('sscribe-loading-count');
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
			const langCodes = $('input[name="sscribe_language"]')
				.map(function () {
					return $(this).val();
				})
				.get();
			if (!langCodes.length) {
				return;
			}
			if (self._allLangCountsTimer) {
				clearTimeout(self._allLangCountsTimer);
			}
			const debounceMs =
				sscribe_data.strings && sscribe_data.strings.lang_counts_debounce_ms
					? parseInt(sscribe_data.strings.lang_counts_debounce_ms, 10)
					: 200;
			self._allLangCountsTimer = setTimeout(function () {
				if (self._allLangCountsXHR && self._allLangCountsXHR.abort) {
					self._allLangCountsXHR.abort();
				}
				self._allLangCountsXHR = $.ajax({
					url: sscribe_data.ajaxurl,
					type: 'POST',
					timeout: 30000,
					data: {
						action: 'sscribe_get_all_status_counts',
						nonce: sscribe_data.nonce,
						languages: JSON.stringify(langCodes),
						post_type: postType,
						client_generation: generation,
					},
					success: function (response) {
						if (!response || !response.success || !response.data || !response.data.languages) {
							return;
						}
						// Phase 4 (round 2): compare against the LIVE current
						// generation, NOT the closure-captured one - an old
						// response must never overwrite a newer one.
						if (
							typeof response.data.client_generation === 'undefined' ||
							parseInt(response.data.client_generation, 10) !== self._countsRequestGeneration
						) {
							return;
						}
						// Phase 4 (round 2): also verify the live UI selection
						// still matches what we asked for.
						const liveAllPostType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
						if (response.data.post_type !== liveAllPostType) {
							return;
						}
						const langMap = response.data.languages;
						const currentPostType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
						$('input[name="sscribe_language"]').each(function () {
							const langCode = $(this).val();
							const entry = langMap[langCode];
							if (!entry) {
								return;
							}
							const pageCounts = entry.counts_page || entry.counts || {};
							const postCounts = entry.counts_post || {};
							const anyCounts = entry.counts_any || {};
							const pageTotal = self.parseLocalizedInt(pageCounts.all) || 0;
							const postTotal = self.parseLocalizedInt(postCounts.all) || 0;
							const anyTotal = self.parseLocalizedInt(anyCounts.all) || 0;
							const selectedCounts = entry.counts || {};
							const selectedTotal = self.parseLocalizedInt(selectedCounts.all) || 0;
							let displayTotal = selectedTotal;
							if ('page' === currentPostType) {
								displayTotal = pageTotal;
							} else if ('post' === currentPostType) {
								displayTotal = postTotal;
							} else if ('any' === currentPostType) {
								displayTotal = anyTotal;
							}
							const $langLabel = $('input[name="sscribe_language"][value="' + langCode + '"]').closest(
								'.sscribe-lang-card-label'
							);
							$langLabel.find('.sscribe-lang-count').text(displayTotal.toLocaleString());
							$langLabel.attr('data-count-page', pageTotal);
							$langLabel.attr('data-count-post', postTotal);
							$langLabel.attr('data-count-any', anyTotal);
						});
					},
					error: function () {},
				});
			}, debounceMs);
		},
		/**
		 * Safety net: an export whose progress has not advanced for several
		 * minutes is dead (server-side hang, lost session, swallowed error).
		 * Unlock the UI with the timeout guidance instead of leaving the
		 * admin locked behind isProcessing forever.
		 */
		_installStallWatchdog: function () {
			if (SScribe._stallWatchdog) {
				return;
			}
			SScribe._stallWatchdog = window.setInterval(function () {
				if (!SScribe.isProcessing) {
					return;
				}
				const last = SScribe._lastProgressAt || 0;
				if (!last) {
					SScribe._lastProgressAt = Date.now();
					return;
				}
				if (Date.now() - last > 240000) {
					if (SScribe._batchXHR && SScribe._batchXHR.abort) {
						SScribe._batchXHR.abort();
						SScribe._batchXHR = null;
					}
					if (SScribe._finalizingXHR && SScribe._finalizingXHR.abort) {
						SScribe._finalizingXHR.abort();
						SScribe._finalizingXHR = null;
					}
					SScribe._lastProgressAt = 0;
					SScribe.showError(
						(sscribe_data.strings && sscribe_data.strings.err_timeout) ||
							'The server took too long to respond.',
						false
					);
				}
			}, 30000);
		},
		checkActiveSession: function () {
			const startedAt = Date.now();
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 10000,
				data: {
					action: 'sscribe_check_active_session',
					nonce: sscribe_data.nonce,
				},
				success: function (response) {
					// A slow response must never override newer UI state: if
					// the user already started an export, or the check is so
					// old the answer can no longer be trusted, ignore it.
					if (SScribe.isProcessing || Date.now() - startedAt > 20000) {
						return;
					}
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
						// .show() cannot override the sscribe-hidden utility
						// (display:none !important), so the class must be removed
						// or the restored progress UI stays invisible while the
						// cancel button stays unreachable.
						$('#sscribe-progress-area').removeClass('sscribe-hidden').hide().fadeIn(200);
						SScribe._lastProgressAt = Date.now();
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
			// Retain the canonical counts map in instance state so downstream
			// readers (updateConfigSummary) can look up the numeric value
			// without parsing the DOM text back out. The DOM mirror stays
			// for users with JS disabled / initial server-render path.
			this._statusCountMap = {};
			$('.sscribe-status-card-label').each(function () {
				const $label = $(this);
				const $input = $label.find('input[type="radio"]');
				const status = $input.val();
				const count = self.parseLocalizedInt(counts[status]) || 0;
				self._statusCountMap[status] = count;
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
		updateFormatOptionPanels: function () {
			const format = $('input[name="sscribe_format"]:checked').val() || 'all';
			const $wrapper = $('#sscribe-format-options');
			const $panels = $wrapper.find('.sscribe-format-option-panel');
			let anyVisible = false;
			$panels.each(function () {
				const $panel = $(this);
				const matches = $panel.attr('data-format') === format;
				if (matches) {
					$panel.removeClass('sscribe-format-option-revealed');
					void $panel[0].offsetWidth;
					$panel.removeAttr('hidden').addClass('sscribe-format-option-revealed');
					anyVisible = true;
				} else {
					$panel.attr('hidden', 'hidden').removeClass('sscribe-format-option-revealed');
				}
			});
			if (anyVisible) {
				$wrapper.removeClass('sscribe-hidden');
			} else {
				$wrapper.addClass('sscribe-hidden');
			}
		},
		/**
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
			// Phase 3: read from the authoritative countsState object —
			// not from .text() parsing of the rendered DOM. The DOM is a
			// RENDERING TARGET. Stale numbers must never become input to
			// downstream decisions.
			const state = this.countsState || {};
			if ($selectedStatus.length && !$selectedStatus.prop('disabled') && state.loaded) {
				const statusKey = $selectedStatus.val();
				const cs = state.statusCounts || {};
				if (Object.prototype.hasOwnProperty.call(cs, statusKey)) {
					count = parseInt(cs[statusKey], 10) || 0;
				}
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
				any: S.post_type_any || 'All types',
			};
			const resolvePostTypeLabel = function (slug) {
				if (postTypeLabels[slug]) {
					return postTypeLabels[slug];
				}
				if (S['post_type_' + slug]) {
					return S['post_type_' + slug];
				}
				return slug;
			};
			const statusLabels = {
				publish: S.status_publish || 'Published',
				draft: S.status_draft || 'Draft',
				private: S.status_private || 'Private',
				future: S.status_future || 'Scheduled',
				pending: S.status_pending || 'Pending',
				all: S.status_all || 'All',
			};
			$('#sscribe-summary-post-type').text(resolvePostTypeLabel(postType));
			$('#sscribe-summary-status').text(statusLabels[status] || status);
			$('#sscribe-summary-language').text(this.getLanguageLabel(language));
			$('#sscribe-summary-format').text(format === 'all' ? S.status_all || 'All' : format.toUpperCase());
			const $pagesChip = $('#sscribe-summary-pages');
			const pagesText =
				(state.loaded ? '' : '~') +
				count +
				' ' +
				(count === 1 ? (S && S.log_page) || 'page' : (S && S.log_pages) || 'pages');
			$pagesChip.text(pagesText);
			$pagesChip.attr('aria-label', pagesText);
			$('#sscribe-summary-time')
				.text(sscribe_data.strings.calculating_time || 'Calculating...')
				.addClass('sscribe-summary-time-pending');
			if (this._configSummaryXHR && this._configSummaryXHR.abort) {
				this._configSummaryXHR.abort();
			}
			const self = this;
			clearTimeout(this._configSummaryDebounceTimer);
			if (!this._countsLoaded || count === 0) {
				const $timeChipEmpty = $('#sscribe-summary-time');
				const timeHintEmpty = sscribe_data.strings.summary_time_hint || 'See Preview for adaptive estimate';
				$timeChipEmpty.text(timeHintEmpty).removeClass('sscribe-summary-time-pending');
				$timeChipEmpty.attr('aria-label', timeHintEmpty);
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
						const $timeChip = $('#sscribe-summary-time');
						if (response.success && response.data && response.data.estimated_time) {
							$timeChip.text(response.data.estimated_time).removeClass('sscribe-summary-time-pending');
							$timeChip.attr('aria-label', response.data.estimated_time);
						} else {
							const fallbackHint =
								sscribe_data.strings.summary_time_hint || 'See Preview for adaptive estimate';
							$timeChip.text(fallbackHint).removeClass('sscribe-summary-time-pending');
							$timeChip.attr('aria-label', fallbackHint);
						}
					},
					error: function (xhr, status) {
						if (status === 'abort') {
							return;
						}
						const $timeChipErr = $('#sscribe-summary-time');
						const errHint = sscribe_data.strings.summary_time_hint || 'See Preview for adaptive estimate';
						$timeChipErr.text(errHint).removeClass('sscribe-summary-time-pending');
						$timeChipErr.attr('aria-label', errHint);
					},
				});
			}, 300);
			const liveRegion = document.getElementById('sscribe-live-region');
			if (liveRegion) {
				if (count > 0) {
					const tplReady =
						(sscribe_data.strings && sscribe_data.strings.live_region_ready) ||
						'%1$d %2$s ready for export';
					liveRegion.textContent = tplReady
						.replace('%1$d', count)
						.replace(
							'%2$s',
							count === 1
								? (sscribe_data.strings && sscribe_data.strings.log_page) || 'page'
								: (sscribe_data.strings && sscribe_data.strings.log_pages) || 'pages'
						);
				} else {
					liveRegion.textContent =
						(sscribe_data.strings && sscribe_data.strings.live_region_no_pages) ||
						'No pages match selected options. Export button is disabled.';
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
			// Phase 3 invariant: Export / Preview must only be enabled when
			// the authoritative countsState matches the currently selected
			// (postType, language, status). If the user changed any of those
			// after the last successful refresh, this state is stale and
			// the buttons stay disabled until a fresh response arrives.
			const state = this.countsState || {};
			const currentPostType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const currentLanguage = $('input[name="sscribe_language"]:checked').val() || '';
			const currentStatus = $('input[name="sscribe_post_status"]:checked').val() || 'publish';
			const stateMatchesSelection =
				state.loaded === true &&
				state.postType === currentPostType &&
				state.language === currentLanguage &&
				(state.status === null || state.status === currentStatus);
			const canExport =
				hasPostType &&
				hasLanguage &&
				hasStatus &&
				hasFormat &&
				hasPages &&
				stateMatchesSelection &&
				!this.isProcessing &&
				!this.isPreparing;
			$('#sscribe-export-btn').prop('disabled', !canExport);
			$('#sscribe-preview-btn').prop('disabled', !canExport);
			const $reason = $('#sscribe-export-disabled-reason');
			if (!canExport) {
				let reasonText = '';
				if (this.isProcessing) {
					reasonText =
						(sscribe_data.strings && sscribe_data.strings.export_in_progress) ||
						'An export is in progress. Cancel it to start a new one.';
				} else if (this.isPreparing) {
					reasonText =
						(sscribe_data.strings && sscribe_data.strings.preparing_export) || 'Preparing your export...';
				} else if (!stateMatchesSelection && state.error) {
					reasonText = state.error;
				} else if (!hasPages && !state.loaded) {
					reasonText =
						(sscribe_data.strings && sscribe_data.strings.loading_counts) || 'Loading page counts...';
				} else if (!hasPages) {
					reasonText =
						(sscribe_data.strings && sscribe_data.strings.err_no_pages) ||
						'No pages match selected options';
				} else if (!hasStatus) {
					reasonText = sscribe_data.strings.select_status || 'Select a post status';
				} else if (!hasFormat) {
					reasonText = sscribe_data.strings.select_format || 'Select a format';
				} else if (!hasPostType) {
					reasonText = sscribe_data.strings.select_post_type || 'Select a post type';
				} else if (!hasLanguage) {
					reasonText = sscribe_data.strings.select_language || 'Select a language';
				}
				$reason.text(reasonText);
			} else {
				$reason.text('');
			}
		},
		startExport: function (e) {
			e.preventDefault();
			if (this.isProcessing || this.isPreparing) {
				return;
			}
			this._exportCompleteFired = false;
			clearTimeout(this._configSummaryDebounceTimer);
			if (this._configSummaryXHR && this._configSummaryXHR.abort) {
				this._configSummaryXHR.abort();
			}
			this.resetUI();
			// isPreparing means "click acknowledged, preflight running" -
			// the user already pressed the button and the preflight AJAX
			// may take a few seconds (429 retries on busy servers).
			// We don't want to flash "export in progress" during this
			// preflight phase; only flip to isProcessing once the export
			// batch is actually running.
			this.isPreparing = true;
			this._lastProgressAt = Date.now();
			this.batchRetries = 0;
			const $exportBtns = $('#sscribe-export-btn, #sscribe-preview-btn');
			$exportBtns.prop('disabled', true).attr('aria-busy', 'true').addClass('sscribe-btn-busy');
			this.updateExportButton();
			const language = $('input[name="sscribe_language"]:checked').val() || '';
			const postStatus = $('input[name="sscribe_post_status"]:checked').val() || 'publish';
			const postType = $('input[name="sscribe_post_type"]:checked').val() || 'page';
			const format = $('input[name="sscribe_format"]:checked').val() || 'docx';
			const formats = format === 'all' ? ['docx', 'pdf', 'html', 'markdown'] : [format];
			// Phase 8: preflight is a named operation so retry/refresh_nonce
			// paths can safely re-enter it without recursion hazards.
			this.runPreflightCheck(language, postStatus, postType, formats);
		},
		runPreflightCheck: function (language, postStatus, postType, formats) {
			const self = this;
			// Phase 8: this is the single re-entrant preflight operation.
			// Called by startExport() and by the retry/refresh_nonce
			// branches in the error handler below. Safe to call multiple
			// times; each call owns its own request.
			if (typeof formats === 'string') {
				formats = [formats];
			}
			$.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_preflight_check',
					nonce: sscribe_data.nonce,
					page_count: self.selectedPageCount,
					formats: formats,
				},
				success: function (response) {
					if (response.success) {
						const diagnostics = response.data || {};
						const canProceed = diagnostics.can_proceed !== false;
						if (!canProceed || diagnostics.status === 'error') {
							self.isPreparing = false;
							$('#sscribe-export-btn, #sscribe-preview-btn')
								.prop('disabled', false)
								.removeAttr('aria-busy')
								.removeClass('sscribe-btn-busy');
							self.updateExportButton();
							self.showPreflightWarnings(diagnostics, null, false);
						} else if (diagnostics.status === 'warning') {
							self.showPreflightWarnings(
								diagnostics,
								function () {
									self.proceedWithExport(language, postStatus, postType, formats);
								},
								true
							);
						} else {
							self.proceedWithExport(language, postStatus, postType, formats);
						}
						return;
					}
					const failData = response && response.data ? response.data : {};
					self.finishPreparationFailure(
						failData.message || 'Preflight check could not be completed. Please try again.',
						null,
						failData
					);
				},
				error: function (xhr) {
					const data = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
					const decision = SScribe.getAjaxFailureDecision(xhr, data);
					if (decision.action === 'retry') {
						setTimeout(function () {
							self.runPreflightCheck(language, postStatus, postType, formats);
						}, decision.delayMs);
						return;
					}
					if (decision.action === 'refresh_nonce') {
						SScribe.refreshNonceAnd(function () {
							self.runPreflightCheck(language, postStatus, postType, formats);
						});
						return;
					}
					const msg =
						decision.message ||
						SScribe.parseServerError(xhr) ||
						SScribe.getNetworkErrorMessage(xhr, 'preflight_check') ||
						'Preflight check failed. Please try again.';
					self.finishPreparationFailure(msg, xhr, data);
				},
			});
		},
		/**
		 * Phase 8 helper: cleanly terminate the preflight phase on a
		 * server-side rejection or hard HTTP failure. Resets all
		 * click-handler state so the user can retry without a page
		 * reload. Safe to call from any of the preflight code paths.
		 */
		finishPreparationFailure: function (message, xhr, data) {
			const self = this;
			self.isPreparing = false;
			self.isProcessing = false;
			self.batchRetries = 0;
			self.pollBackoff = 0;
			clearTimeout(self._configSummaryDebounceTimer);
			if (self._configSummaryXHR && self._configSummaryXHR.abort) {
				self._configSummaryXHR.abort();
				self._configSummaryXHR = null;
			}
			$('#sscribe-export-btn, #sscribe-preview-btn')
				.prop('disabled', false)
				.removeAttr('aria-busy')
				.removeClass('sscribe-btn-busy');
			self.updateExportButton();
			const responseData = data || (xhr && xhr.responseJSON && xhr.responseJSON.data) || {};
			self.showError(
				message,
				false,
				SScribe.normalizeErrorData({
					code: responseData.code || 'preflight_failed',
					message: message,
					_diagnostics: {
						action: 'sscribe_preflight_check',
						http_status: xhr && typeof xhr.status === 'number' ? xhr.status : 0,
						server_code: responseData.code || null,
					},
				})
			);
		},
		showPreflightWarnings: function (diagnostics, onProceed, allowProceed) {
			const preflightReturnFocus = document.activeElement;
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
				'<h2>' + this.escapeHtml(sscribe_data.strings.preflight_title || 'Export Readiness Check') + '</h2>';
			bannerHtml +=
				'<button type="button" class="sscribe-preflight-close" aria-label="' +
				this.escapeHtml(sscribe_data.strings.close || 'Close') +
				'">&times;</button>';
			bannerHtml += '</div>';
			bannerHtml += '<div class="sscribe-preflight-body">';
			if (errors.length > 0) {
				bannerHtml += '<div class="sscribe-preflight-section sscribe-preflight-errors">';
				bannerHtml +=
					'<h3 class="sscribe-preflight-section-title">' +
					this.escapeHtml(sscribe_data.strings.preflight_errors || 'Critical Issues') +
					'</h3>';
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
					'<h3 class="sscribe-preflight-section-title">' +
					this.escapeHtml(sscribe_data.strings.preflight_warnings || 'Recommendations') +
					'</h3>';
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
			if (allowProceed === true && typeof onProceed === 'function') {
				bannerHtml +=
					'<button type="button" class="sscribe-button sscribe-button-primary sscribe-preflight-proceed">' +
					this.escapeHtml(sscribe_data.strings.preflight_continue || 'Continue Anyway') +
					'</button>';
			}
			bannerHtml +=
				'<button type="button" class="sscribe-button sscribe-button-ghost sscribe-preflight-cancel">' +
				this.escapeHtml(sscribe_data.strings.preflight_cancel || 'Cancel Export') +
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
			const restorePreflightFocus = function () {
				if (
					preflightReturnFocus &&
					typeof preflightReturnFocus.focus === 'function' &&
					document.contains(preflightReturnFocus) &&
					!preflightReturnFocus.disabled
				) {
					preflightReturnFocus.focus();
					return;
				}
				const progressArea = document.getElementById('sscribe-progress-area');
				if (progressArea && !progressArea.classList.contains('sscribe-hidden')) {
					progressArea.setAttribute('tabindex', '-1');
					progressArea.focus();
				}
			};
			if (allowProceed === true && typeof onProceed === 'function') {
				$banner.on('click.sscribe-preflight', '.sscribe-preflight-proceed', function () {
					$banner.fadeOut(200, function () {
						$banner.remove();
						restorePreflightFocus();
					});
					onProceed();
				});
			}
			$banner.on('click.sscribe-preflight', '.sscribe-preflight-cancel', function () {
				$banner.fadeOut(200, function () {
					$banner.remove();
					restorePreflightFocus();
				});
				SScribe.isPreparing = false;
				SScribe.isProcessing = false;
				$('#sscribe-export-btn, #sscribe-preview-btn')
					.prop('disabled', false)
					.removeAttr('aria-busy')
					.removeClass('sscribe-btn-busy');
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
					restorePreflightFocus();
				});
				SScribe.isPreparing = false;
				SScribe.isProcessing = false;
				$('#sscribe-export-btn, #sscribe-preview-btn')
					.prop('disabled', false)
					.removeAttr('aria-busy')
					.removeClass('sscribe-btn-busy');
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
				error: function (xhr, textStatus) {
					// Aborted requests must never trigger a retry.
					if (textStatus === 'abort') {
						return;
					}
					const responseData = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
					const decision = SScribe.getAjaxFailureDecision(xhr, responseData, {
						jitterSeed: 0.5,
					});
					// Phase 10: terminal failure decision stops immediately.
					// Previously any 4xx/5xx was retried up to maxAttempts,
					// which amplified 4xx noise (e.g. invalid_session_id).
					if (decision.action === 'fail') {
						const serverMessage = responseData.message || '';
						self.isPreparing = false;
						self.isProcessing = false;
						self.resetUI();
						self.updateExportButton();
						self.showError(
							serverMessage ||
								sscribe_data.strings.err_clear_session ||
								'Could not clear export session. Please try again.',
							false,
							{ request_id: decision.requestId || '' }
						);
						return;
					}
					if (decision.action === 'refresh_nonce') {
						SScribe.refreshNonceAnd(function () {
							self.clearSessionWithRetry(language, postStatus, postType, formats, attempt + 1);
						});
						return;
					}
					if (attempt < maxAttempts - 1) {
						// Phase 10: only retry on decision=retry or decision=conflict.
						let delayMs;
						if (decision.action === 'retry' || decision.action === 'conflict') {
							delayMs = decision.delayMs;
						} else {
							const backoff = Math.pow(2, Math.max(0, attempt));
							delayMs = Math.max(1500, backoff * 1000);
						}
						setTimeout(function () {
							self.clearSessionWithRetry(language, postStatus, postType, formats, attempt + 1);
						}, delayMs);
					} else {
						const responseData =
							xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
						const serverMessage = responseData.message || '';
						self.isPreparing = false;
						self.isProcessing = false;
						self.resetUI();
						self.updateExportButton();
						self.showError(
							serverMessage ||
								sscribe_data.strings.err_clear_session ||
								'Could not clear the previous export session. Please try again in a moment.',
							false,
							{
								code: responseData.code || null,
								message: serverMessage || null,
								_diagnostics: {
									action: 'sscribe_clear_session',
									http_status: xhr && typeof xhr.status === 'number' ? xhr.status : 0,
									request_status: textStatus || 'error',
									server_code: responseData.code || null,
									attempts: maxAttempts,
								},
							}
						);
					}
				},
			});
		},
		doStartExport: function (language, postStatus, postType, formats) {
			const self = this;
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
						self._startExportRetries = 0;
						// Export batch has actually started - flip from
						// preparing to processing so the status bar shows
						// the "export in progress" copy.
						SScribe.isPreparing = false;
						SScribe.isProcessing = true;
						// Reset transient retry/backoff counters from any
						// prior session so the new run starts clean.
						SScribe.batchRetries = 0;
						SScribe.pollBackoff = 0;
						SScribe.showProgress();
						SScribe.sessionId = response.data.session_id;
						SScribe.updateStatus(response.data.message);
						if (response.data.partial_export) {
							SScribe.showWarning(response.data.partial_export_message);
						}
						SScribe.processBatch();
						return;
					}
					// Phase 9: start-export soft-fail terminal. Previously
					// only reset isPreparing; now also clears isProcessing,
					// backoff, busy UI, and config summary timer so the
					// user can immediately retry without a reload.
					self.finishStartExportFailure(
						(response && response.data && response.data.message) ||
							'Export failed to start. Please try again.',
						null,
						(response && response.data) || {}
					);
				},
				error: function (xhr, textStatus) {
					// Phase 11: route through the centralized failure
					// decision helper. Previously any 4xx/5xx went
					// straight to terminal, which prevented legitimate
					// 429/503 backoff and 403 nonce-refresh retries.
					if (textStatus === 'abort') {
						return;
					}
					const errData = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
					const decision = SScribe.getAjaxFailureDecision(xhr, errData, {
						jitterSeed: 0.5,
					});
					if (decision.action === 'retry' || decision.action === 'conflict') {
						if (!self._startExportRetries || self._startExportRetries < 3) {
							self._startExportRetries = (self._startExportRetries || 0) + 1;
							setTimeout(function () {
								self.doStartExport(language, postStatus, postType, formats);
							}, decision.delayMs);
							return;
						}
					}
					if (decision.action === 'refresh_nonce') {
						SScribe.refreshNonceAnd(function () {
							self.doStartExport(language, postStatus, postType, formats);
						});
						return;
					}
					self._startExportRetries = 0;
					const serverMsg = SScribe.parseServerError(xhr);
					const msg = serverMsg || SScribe.getNetworkErrorMessage(xhr, 'start_export');
					self.finishStartExportFailure(msg, xhr, errData);
				},
			});
		},
		/**
		 * Phase 9 helper: cleanly terminate the start-export phase on a
		 * soft-fail or hard HTTP failure. Resets isPreparing/isProcessing,
		 * backoff counters, busy UI, config-summary timers so the user
		 * can immediately retry without a page reload.
		 */
		finishStartExportFailure: function (message, xhr, data) {
			const self = this;
			self.isPreparing = false;
			self.isProcessing = false;
			self.batchRetries = 0;
			self.pollBackoff = 0;
			clearTimeout(self._configSummaryDebounceTimer);
			if (self._configSummaryXHR && self._configSummaryXHR.abort) {
				self._configSummaryXHR.abort();
				self._configSummaryXHR = null;
			}
			$('#sscribe-export-btn, #sscribe-preview-btn')
				.prop('disabled', false)
				.removeAttr('aria-busy')
				.removeClass('sscribe-btn-busy');
			self.updateExportButton();
			const responseData = data || {};
			const httpStatus = xhr && typeof xhr.status === 'number' ? xhr.status : 0;
			self.showError(
				message,
				false,
				SScribe.normalizeErrorData({
					code: responseData.code || 'start_export_failed',
					message: message,
					_diagnostics: {
						action: 'sscribe_start_export',
						http_status: httpStatus,
						server_code: responseData.code || null,
					},
				})
			);
		},
		scheduleNextBatch: function (retryInMs, isRetry) {
			const self = this;
			let delay;
			let delayFromServer = false;
			if (typeof retryInMs === 'number' && isFinite(retryInMs) && retryInMs > 0) {
				delay = Math.max(0, Math.floor(retryInMs));
				self.pollBackoff = 0;
				delayFromServer = true;
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
			// Phase 12: when the server supplies retry_in it is an ABSOLUTE
			// minimum — only additive (positive-only) jitter may be applied.
			// When the delay is locally computed (±jitter is allowed).
			if (delayFromServer) {
				const positiveJitter = Math.floor(Math.random() * self.pollJitter);
				delay = delay + positiveJitter;
			} else {
				const jitter = Math.floor(Math.random() * (self.pollJitter * 2 + 1)) - self.pollJitter;
				delay = Math.max(0, delay + jitter);
			}
			delay = Math.max(1500, delay);
			clearTimeout(self._batchTimer);
			self._batchTimer = setTimeout(function () {
				self._batchTimer = null;
				if (self._isCancelling || !self.sessionId || !self.isProcessing) {
					return;
				}
				self.processBatch();
			}, delay);
		},
		processBatch: function () {
			if (this._isCancelling) {
				return;
			}
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
							if (isCancelled) {
								self._lastAnnouncedBucket = -1;
							}
							if (isCancelled) {
								SScribe.showCancelled(response.data.message);
							} else {
								SScribe.showError(
									response.data.message,
									false,
									SScribe.normalizeErrorData(response.data)
								);
							}
						}
					}
				},
				error: function (xhr, textStatus) {
					self._batchInProgress = false;
					self._batchXHR = null;
					if (self._isCancelling && textStatus === 'abort') {
						return;
					}
					// Phase 5: route HTTP failures through the centralized
					// failure decision helper. Server retry_in / HTTP
					// Retry-After take priority over client backoff.
					// Do NOT silently hammer the server with a 1000ms
					// generic client retry when the server said 60000.
					const responseData = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
					const decision = SScribe.getAjaxFailureDecision(xhr, responseData, {
						jitterSeed: 0.5,
					});
					if (decision.action === 'retry' || decision.action === 'conflict') {
						// Honor server-driven timing exactly. The decision
						// helper clamps the lower bound so a 1s client
						// backoff can never out-pace a 60s server hint.
						SScribe.scheduleNextBatch(decision.delayMs, true);
						SScribe.updateStatus(
							decision.message ||
								(sscribe_data.strings && sscribe_data.strings.retrying_after_delay) ||
								'Retrying…'
						);
						return;
					}
					if (decision.action === 'refresh_nonce') {
						SScribe.refreshNonceAnd(function () {
							SScribe.scheduleNextBatch(0, true);
						});
						return;
					}
					// action === 'fail' — terminal. Clear in-progress
					// flags, clear timers, clear busy UI, preserve
					// useful error details.
					self._lastAnnouncedBucket = -1;
					const msg = decision.message || SScribe.getNetworkErrorMessage(xhr, 'process_batch');
					const errData = responseData || {};
					SScribe.showError(msg, false, SScribe.normalizeErrorData(errData));
					SScribe.batchRetries = 0;
					SScribe.pollBackoff = 0;
				},
			});
		},
		cancelExport: function (e) {
			e.preventDefault();
			if (!this.sessionId) {
				return;
			}
			if (typeof this._originalTitle === 'string' && '' !== this._originalTitle) {
				document.title = this._originalTitle;
			}
			this._lastAnnouncedBucket = -1;
			const confirmTitle = (sscribe_data.strings && sscribe_data.strings.cancel_title) || 'Cancel this export?';
			const confirmDesc =
				(sscribe_data.strings && sscribe_data.strings.cancel_confirm) ||
				'Cancel the current export? Partial progress will be discarded.';
			const confirmBtn = (sscribe_data.strings && sscribe_data.strings.cancel) || 'Cancel Export';
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
		_doCancelExport: function (attempt) {
			const cancelAttempt = typeof attempt === 'number' && attempt > 0 ? Math.floor(attempt) : 0;
			if (cancelAttempt === 0) {
				this._isCancelling = true;
				this._cancelRetries = 0;
				clearTimeout(this._batchTimer);
				this._batchTimer = null;
				this._pendingFinalize = null;
				if (this._batchXHR) {
					this._batchXHR.abort();
					this._batchXHR = null;
				}
				if (this._finalizingXHR) {
					this._finalizingXHR.abort();
					this._finalizingXHR = null;
				}
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
					clearTimeout(SScribe._cancelTimer);
					SScribe._cancelTimer = null;
					SScribe.showCancelled(sscribe_data.strings.export_cancelled || 'Export cancelled.');
				},
				error: function (xhr) {
					const responseData = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
					const shouldRetry =
						xhr.status === 409 || responseData.retry === true || responseData.code === 'batch_in_progress';
					if (shouldRetry && cancelAttempt < SScribe.maxCancelRetries) {
						const serverDelay = Number(responseData.retry_in);
						const fallbackDelay = Math.min(5000, 1000 * Math.pow(2, cancelAttempt));
						const retryDelay = isFinite(serverDelay) && serverDelay > 0 ? serverDelay : fallbackDelay;
						SScribe._cancelRetries = cancelAttempt + 1;
						clearTimeout(SScribe._cancelTimer);
						SScribe._cancelTimer = setTimeout(function () {
							SScribe._cancelTimer = null;
							if (SScribe._isCancelling && SScribe.sessionId) {
								SScribe._doCancelExport(cancelAttempt + 1);
							}
						}, retryDelay);
						SScribe.updateStatus(sscribe_data.strings.cancelling || 'Cancelling...');
						return;
					}
					SScribe._isCancelling = false;
					SScribe.isProcessing = false;
					SScribe.resetUI();
					$('#sscribe-cancel-btn')
						.prop('disabled', false)
						.text(sscribe_data.strings.cancel || 'Cancel Export');
					const msg =
						SScribe.parseServerError(xhr) ||
						(sscribe_data.strings && sscribe_data.strings.err_cancel_failed) ||
						'Could not confirm cancellation : the server may still be processing. Reload the page before starting a new export.';
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
		initAutoDownloadPreference: function () {
			const $toggle = $('#sscribe-auto-download-toggle');
			if (!$toggle.length) {
				return;
			}
			// Auto-download is deliberately session-local and opt-in. A fresh
			// page load always begins OFF; only the checkbox the admin can see
			// may enable an automatic download for this page load.
			$toggle.prop('checked', false);
		},
		onAutoDownloadToggle: function () {
			// Visible checkbox is the sole source of truth. No persistent
			// client-side storage: an admin toggling "Auto-download" affects
			// only the current page load and never silently overrides the
			// site-wide default for other admins on other browsers.
		},
		shouldAutoDownload: function () {
			const $toggle = $('#sscribe-auto-download-toggle');
			return $toggle.length > 0 && $toggle.is(':checked');
		},
		exportComplete: function (data, isAutoDownload) {
			if (this._exportCompleteFired) {
				// Guard against duplicate invocations: a single batch-finalize
				// response should trigger exactly one download click. Re-entry
				// from a polling race or a tab-visibility refresh would
				// otherwise fire the same <a>.click() twice.
				return;
			}
			this._exportCompleteFired = true;
			this.isProcessing = false;
			this.sessionId = null;
			this._batchInProgress = false;
			this.pollBackoff = 0;
			$('#sscribe-export-btn, #sscribe-preview-btn').removeClass('sscribe-btn-busy').removeAttr('aria-busy');
			this.updateExportButton();
			this._lastAnnouncedBucket = -1;
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
						const safeDownloadUrl = data.download_url ? self.getSafeSameOriginUrl(data.download_url) : '';
						if (safeDownloadUrl) {
							$('#sscribe-download-btn').attr('href', safeDownloadUrl).attr('aria-disabled', 'false').removeAttr('tabindex');
							if (isAutoDownload !== false && self.shouldAutoDownload()) {
								const a = document.createElement('a');
								a.href = safeDownloadUrl;
								a.download = '';
								document.body.appendChild(a);
								a.click();
								setTimeout(function () {
									a.remove();
								}, 1000);
							}
						} else {
							$('#sscribe-download-btn').removeAttr('href').attr('aria-disabled', 'true').attr('tabindex', '-1');
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
			if (typeof document !== 'undefined' && document.hidden) {
				// Background tab: hold off until visibility returns. Catches up
				// with the smallest possible interval so the user does not see
				// stale progress when they return.
				this._pendingFinalize = { sessionId: sessionId, attempt: attempt, delay: delay };
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
							// Phase 6: soft-failures on the finalization
							// endpoint also honor the server's retry_in if
							// present, mirroring the centralized retry
							// policy used by processBatch().
							const dataRetryIn = response.data && Number(response.data.retry_in);
							const softDelay =
								isFinite(dataRetryIn) && dataRetryIn > 0
									? Math.max(1500, Math.floor(dataRetryIn))
									: self.computeFinalizeBackoff();
							self.pollFinalize(sessionId, attempt + 1, softDelay);
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
						// Phase 6: route finalization HTTP failures through the
						// centralized failure-decision helper so that the
						// server's retry_in / HTTP Retry-After takes priority
						// over the client exponential backoff. Same contract
						// as processBatch() (Phase 5).
						const responseData = response && response.data ? response.data : {};
						const decision = SScribe.getAjaxFailureDecision(xhr, responseData, {
							jitterSeed: 0.5,
						});
						if (decision.action === 'retry' || decision.action === 'conflict') {
							if (attempt < maxAttempts) {
								self.pollFinalize(sessionId, attempt + 1, decision.delayMs);
							} else {
								self.isProcessing = false;
								self.showError(decision.message || 'Export finalization timed out.', false, {});
							}
							return;
						}
						if (decision.action === 'refresh_nonce') {
							if (attempt < maxAttempts) {
								SScribe.refreshNonceAnd(function () {
									self.pollFinalize(sessionId, attempt + 1, 0);
								});
							} else {
								self.isProcessing = false;
								self.showError(decision.message || 'Export finalization timed out.', false, {});
							}
							return;
						}
						// action === 'fail' — terminal. Phase 13: do NOT poll again on
						// a terminal decision. The previous fall-through
						// re-entered pollFinalize for up to maxAttempts
						// attempts, masking the real failure and consuming
						// rate-limit quota.
						self.isProcessing = false;
						const msg = decision.message || self.getNetworkErrorMessage(xhr, 'finalize_export');
						self.showError(msg, false, { request_id: decision.requestId || '' });
					},
				});
			}, delay);
		},

		computeFinalizeBackoff: function () {
			const self = this;
			const multiplier = Math.pow(2, Math.max(0, self.pollBackoff));
			const base = self.pollBackoffBase * multiplier;
			let delay = Math.min(self.pollBackoffMax, Math.floor(base));
			if (multiplier < self.pollBackoffMax / self.pollBackoffBase) {
				self.pollBackoff++;
			}
			const jitter = Math.floor(Math.random() * (self.pollJitter * 2 + 1)) - self.pollJitter;
			delay = Math.max(1500, delay + jitter);
			return delay;
		},

		resumeFinalizeFromBackground: function () {
			const pending = this._pendingFinalize;
			if (!pending) {
				return;
			}
			this._pendingFinalize = null;
			this.pollFinalize(pending.sessionId, pending.attempt, 0);
		},

		handleVisibilityChange: function () {
			if (typeof document === 'undefined' || document.hidden) {
				return;
			}
			this.resumeFinalizeFromBackground();
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
			const visibleCount = Math.min(totalExports, maxRows);
			let html = '';
			html +=
				'<table class="sscribe-history-table-element">' +
				'<caption class="screen-reader-text">' +
				this.escapeHtml(strings.history_caption || 'Recent export packages') +
				'</caption>' +
				'<thead><tr>' +
				'<th scope="col" class="sscribe-history-col-check"><span class="screen-reader-text">' +
				this.escapeHtml(strings.history_col_select || 'Select') +
				'</span></th>' +
				'<th scope="col" class="sscribe-history-col-file">' +
				this.escapeHtml(strings.history_col_export || 'Export') +
				'</th>' +
				'<th scope="col" class="sscribe-history-col-actions"><span class="screen-reader-text">' +
				this.escapeHtml(strings.history_col_actions || 'Actions') +
				'</span></th>' +
				'</tr></thead>' +
				'<tbody>';
			for (let i = 0; i < visibleCount; i++) {
				const exp = exports[i] && typeof exports[i] === 'object' ? exports[i] : {};
				const filename = typeof exp.filename === 'string' ? exp.filename : '';
				const flagUrl = this.getSafeSameOriginUrl(exp.flag_url);
				const downloadUrl = this.getSafeSameOriginUrl(exp.url);
				if (!filename) {
					continue;
				}
				const selectExportLabel = String(strings.select_export_label || 'Select export %s').replace(
					'%s',
					filename
				);
				html +=
					'<tr class="sscribe-history-row" data-filename="' +
					this.escapeHtml(filename) +
					'" aria-rowindex="' +
					(i + 1) +
					'">';
				html += '<td class="sscribe-history-cell-check">';
				html +=
					'<label class="sscribe-history-check-label">' +
					'<input type="checkbox" class="sscribe-history-check" value="' +
					this.escapeHtml(filename) +
					'" aria-label="' +
					this.escapeHtml(selectExportLabel) +
					'">' +
					'<span class="sscribe-check-visual"></span>' +
					'</label>';
				html += '</td>';
				html += '<td class="sscribe-history-cell-file"><div class="sscribe-history-file">';
				html += '<div class="sscribe-file-icon">';
				if (flagUrl) {
					html +=
						'<img src="' +
						this.escapeHtml(flagUrl) +
						'" alt="' +
						this.escapeHtml(exp.lang_name || '') +
						'" class="sscribe-file-icon-img">';
				} else {
					html +=
						'<span class="sscribe-file-icon-text">' +
						this.escapeHtml(
							String(exp.lang_code || 'EN')
								.substring(0, 2)
								.toUpperCase()
						) +
						'</span>';
				}
				html += '</div>';
				html += '<div class="sscribe-file-details">';
				html += '<strong>' + this.escapeHtml(filename) + '</strong>';
				html +=
					'<span>' +
					this.escapeHtml(exp.date || '') +
					' : ' +
					this.escapeHtml(exp.size_formatted || exp.size || '') +
					'</span>';
				html += '</div></div></td>';
				html += '<td class="sscribe-history-cell-actions"><div class="sscribe-history-actions">';
				if (downloadUrl) {
					html +=
						'<a href="' +
						this.escapeHtml(downloadUrl) +
						'" class="sscribe-button sscribe-button-outline sscribe-button-sm" download title="' +
						this.escapeHtml(strings.download_tooltip || 'Download') +
						'" aria-label="' +
						this.escapeHtml(strings.download_tooltip || 'Download') +
						'">' +
						this.escapeHtml(strings.download_label || 'Download') +
						'</a>';
				} else {
					html +=
						'<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm sscribe-button-disabled" disabled>' +
						this.escapeHtml(strings.download_unavailable || 'Download unavailable') +
						'</button>';
				}
				html +=
					'<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm sscribe-log-btn" data-filename="' +
					this.escapeHtml(filename) +
					'" title="' +
					this.escapeHtml(strings.log_tooltip || 'View export log') +
					'" aria-label="' +
					this.escapeHtml(strings.log_tooltip || 'View export log') +
					'">' +
					this.escapeHtml(strings.log_label || 'Log') +
					'</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm sscribe-button-danger sscribe-delete-btn" data-filename="' +
					this.escapeHtml(filename) +
					'" title="' +
					this.escapeHtml(strings.delete_tooltip || 'Delete this export') +
					'" aria-label="' +
					this.escapeHtml(strings.delete_tooltip || 'Delete this export') +
					'">' +
					this.escapeHtml(strings.delete_label || 'Delete') +
					'</button>';
				html += '</div></td>';
				html += '</tr>';
			}
			html += '</tbody></table>';
			$table.html(html);
		},
		updateBulkBar: function () {
			const $checks = $('.sscribe-history-check:checked').not(
				'.sscribe-history-row-hidden .sscribe-history-check'
			);
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
			const exportWord = count === 1 ? 'export' : 'exports';
			const proceedLabel =
				sscribe_data.strings && sscribe_data.strings.bulk_delete_label_format
					? sscribe_data.strings.bulk_delete_label_format
							.replace('%d', String(count))
							.replace('%s', exportWord)
					: 'Delete ' + count + ' ' + exportWord;
			this.showConfirm({
				title:
					sscribe_data.strings && sscribe_data.strings.bulk_delete_title_format
						? sscribe_data.strings.bulk_delete_title_format
								.replace('%d', String(count))
								.replace('%s', exportWord)
						: 'Delete ' + count + ' ' + exportWord + '?',
				description:
					(sscribe_data.strings && sscribe_data.strings.bulk_delete_desc) ||
					'All selected exports will be permanently removed from the server. ZIP files in your downloads folder will not be affected.',
				items: $checks
					.map(function () {
						return String($(this).val() || '');
					})
					.get(),
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
			this._lastProgressAt = Date.now();
			percentage = Math.min(100, Math.max(0, percentage));
			const progressBar = document.getElementById('sscribe-progress-bar');
			if (progressBar) {
				const progressContainer = progressBar.closest('.sscribe-progress-bar-container');
				if (percentage >= 1) {
					progressBar.classList.remove('sscribe-progress-initializing');
				}
				if (progressContainer) {
					progressContainer.classList.toggle('sscribe-progress-complete', percentage >= 100);
				}
				progressBar.style.transform = 'scaleX(' + percentage / 100 + ')';
				progressBar.setAttribute('aria-valuenow', percentage);
				if (typeof currentPage === 'number' && typeof totalPages === 'number' && totalPages > 0) {
					const tpl =
						(sscribe_data.strings && sscribe_data.strings.progress_pages) ||
						'Processing %1$d of %2$d pages';
					progressBar.setAttribute(
						'aria-valuetext',
						tpl.replace('%1$d', currentPage).replace('%2$d', totalPages)
					);
				}
			} else {
				$('#sscribe-progress-bar')
					.removeClass('sscribe-progress-initializing')
					.css('width', percentage + '%');
			}
			$('#sscribe-progress-text').text(percentage + '%');
			const bucket = Math.floor(percentage / 10);
			if (bucket !== this._lastAnnouncedBucket) {
				this._lastAnnouncedBucket = bucket;
				const prefix =
					(sscribe_data.strings && sscribe_data.strings.export_progress_prefix) || 'Export progress:';
				this.announce(prefix + ' ' + percentage + '%');
			}
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
				$step.removeAttr('aria-current').removeAttr('data-state');
				if (stepPhase === phase) {
					$step
						.removeClass('sscribe-phase-completed sscribe-phase-active')
						.addClass('sscribe-phase-active')
						.attr('aria-current', 'step')
						.attr('data-state', 'active');
					found = true;
				} else if (!found) {
					$step.removeClass('sscribe-phase-active').addClass('sscribe-phase-completed').attr('data-state', 'completed');
				} else {
					$step.removeClass('sscribe-phase-completed sscribe-phase-active').attr('data-state', 'pending');
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
			if (typeof this._originalTitle !== 'string' || '' === this._originalTitle) {
				this._originalTitle = document.title;
			}
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
		 * Outputs a concise debug-only console group with redacted request data
		 * and structured server diagnostics. Raw response bodies and XHR objects
		 * are intentionally excluded because they can contain private site data.
		 *
		 * @param {object} requestData The data object sent in the AJAX request.
		 * @param {jqXHR}  xhr The jQuery XHR object.
		 * @param {*} exception The exception object (if any).
		 */
		logAJAXError: function (requestData, xhr, exception) {
			if (typeof window.SSCRIBE_DEBUG === 'undefined' || !window.SSCRIBE_DEBUG) {
				return;
			}
			if (!window.console || !window.console.group) {
				return;
			}
			const action = (requestData && requestData.action) || 'unknown';
			const timestamp = new Date().toISOString();
			const statusCode = xhr ? xhr.status : 0;
			const statusText = xhr ? xhr.statusText : 'N/A';
			let diagnostics = null;
			if (xhr && xhr.responseText) {
				try {
					const parsed = JSON.parse(xhr.responseText);
					if (parsed && parsed.data && parsed.data._diagnostics) {
						diagnostics = parsed.data._diagnostics;
					}
				} catch {
					// Empty optional binding: the response wasn't JSON, that's fine
					// so structured server diagnostics are unavailable.
				}
			}
			/* eslint-disable no-console */
			console.groupCollapsed('[SSCRIBE] AJAX Error : %s (HTTP %d %s)', action, statusCode, statusText);
			console.log('Timestamp:', timestamp);
			console.log('Action:', action);
			console.log('HTTP Status:', statusCode, statusText);
			console.log('Request Data:', SScribe.redactNonce(requestData) || {});
			console.log('Exception:', exception || 'None');
			if (diagnostics) {
				console.log('Server Diagnostics:', diagnostics);
			}
			console.groupEnd();
			/* eslint-enable no-console */
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
		showToast: function (message, type, duration) {
			if (!message) {
				return;
			}
			type = type || 'info';
			duration = typeof duration === 'number' && isFinite(duration) ? duration : 4000;
			const $container = $('#sscribe-toast-container');
			if (!$container.length) {
				return;
			}
			const typeClass = 'sscribe-toast-' + type;
			const $toast = $('<div>').addClass('sscribe-toast ' + typeClass);
			const $icon = $('<span>').addClass('sscribe-toast-icon').attr('aria-hidden', 'true');
			const $body = $('<span>').addClass('sscribe-toast-body').text(message);
			const $dismissButton = $('<button>')
				.addClass('sscribe-toast-dismiss')
				.attr('type', 'button')
				.attr(
					'aria-label',
					(sscribe_data.strings && sscribe_data.strings.dismiss_notification) || 'Dismiss notification'
				)
				.append($('<span>').attr('aria-hidden', 'true').text('×'));
			$toast.append($icon).append($body).append($dismissButton);
			$container.append($toast);
			// Animate in
			requestAnimationFrame(function () {
				$toast.addClass('sscribe-toast-visible');
			});
			// Auto-dismiss
			let dismissed = false;
			const dismiss = function () {
				if (dismissed) {
					return;
				}
				dismissed = true;
				$toast.removeClass('sscribe-toast-visible').addClass('sscribe-toast-removing');
				setTimeout(function () {
					$toast.remove();
				}, 220);
			};
			if (duration > 0) {
				setTimeout(dismiss, duration);
			}
			$dismissButton.on('click.sscribe', function (event) {
				event.preventDefault();
				dismiss();
			});
			return $toast;
		},
		adjustToastContainerPosition: function () {
			const $container = $('#sscribe-toast-container');
			if (!$container.length) {
				return;
			}
			const $wpadminbar = $('#wpadminbar');
			const adminBarHeight = $wpadminbar.length ? $wpadminbar.outerHeight() : 0;
			$container.css('top', Math.max(adminBarHeight, 32) + 'px');
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
			div.textContent = String(str);
			return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
		},
		/**
		 * Accept only same-origin HTTP(S) URLs before placing server data in a URL attribute.
		 *
		 * @param {*} value Candidate URL.
		 * @returns {string} Safe absolute URL or an empty string.
		 */
		getSafeSameOriginUrl: function (value) {
			if (typeof value !== 'string' || value === '') {
				return '';
			}
			try {
				const parsed = new URL(value, window.location.href);
				if (!['http:', 'https:'].includes(parsed.protocol) || parsed.origin !== window.location.origin) {
					return '';
				}
				return parsed.href;
			} catch {
				return '';
			}
		},
		/**
		 * Render a minimal stroke-only SVG icon by name.
		 *
		 * Kept inline so the log modal does not depend on the PHP-side
		 * get_icon_inline_safe() (which runs server-side). Matches the
		 * shape of the icons bundled in /assets/icons/ so the visual
		 * language stays consistent across server-rendered and JS-built UI.
		 *
		 * @param {string} name One of: check, x, file-text, alert.
		 * @param {number} size Pixel size for width and height.
		 * @returns {string} Inline SVG markup.
		 */
		getIconSvg: function (name, size) {
			const px = typeof size === 'number' && size > 0 ? size : 16;
			const icons = {
				check: '<path d="M4 11.5L8.5 16L20 4.5" />',
				x: '<path d="M6 6L18 18M18 6L6 18" />',
				'file-text':
					'<path d="M6 3h8l5 5v12a1 1 0 0 1 -1 1H6a1 1 0 0 1 -1 -1V4a1 1 0 0 1 1 -1z" />' +
					'<path d="M14 3v5h5" />' +
					'<path d="M9 13h6M9 17h4" />',
				alert: '<path d="M12 4l9 16H3z" />' + '<path d="M12 10v4M12 17.5v.5" />',
			};
			const body = icons[name] || icons.alert;
			return (
				'<svg xmlns="http://www.w3.org/2000/svg" width="' +
				px +
				'" height="' +
				px +
				'" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
				'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' +
				'aria-hidden="true" focusable="false">' +
				body +
				'</svg>'
			);
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
		renderPreview: function (data) {
			const $content = $('#sscribe-preview-content');
			const strings = sscribe_data.strings || {};
			const totalPages = this.parseLocalizedInt(data.total_pages);
			const formatLabels = { docx: 'DOCX', pdf: 'PDF', html: 'HTML', markdown: 'Markdown' };
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
			html +=
				'<span class="sscribe-preview-value">' +
				this.escapeHtml(this.getLanguageLabel(data.language)) +
				'</span>';
			html += '</div>';
			html += '<div class="sscribe-preview-stat">';
			html +=
				'<span class="sscribe-preview-label">' +
				this.escapeHtml(strings.preview_status || 'Status:') +
				'</span>';
			html +=
				'<span class="sscribe-preview-value">' +
				this.escapeHtml(this.getPostStatusLabel(data.post_status || 'publish')) +
				'</span>';
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
				html += '<h4>' + this.escapeHtml(strings.preview_sample_title || 'Sample:') + '</h3>';
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
			$panel.attr('aria-hidden', 'true').addClass('sscribe-hidden').prop('hidden', true);
			self.releaseFocusTrap(panelEl);
			const trigger = self._previewTrigger;
			if (trigger && typeof trigger.focus === 'function' && document.contains(trigger) && !trigger.disabled) {
				trigger.focus();
			}
			self._previewTrigger = null;
			$panel.fadeOut(200);
		},
		showConfirm: function (opts) {
			if (!opts || typeof opts.onProceed !== 'function') {
				return;
			}
			const $modal = $('#sscribe-confirm-modal');
			if (!$modal.length) {
				opts.onProceed(function () {
					return true;
				});
				return;
			}
			this.saveFocus();
			const $title = $('#sscribe-confirm-title');
			const $desc = $('#sscribe-confirm-desc');
			const $body = $('#sscribe-confirm-body');
			const $proceed = $('#sscribe-confirm-proceed');
			const $cancel = $('#sscribe-confirm-cancel');
			$title.text(opts.title || 'Confirm action');
			$desc.text(opts.description || 'Are you sure?');
			const items = Array.isArray(opts.items) ? opts.items.slice(0, 50) : [];
			$body.empty();
			if (items.length === 0) {
				$modal.addClass('sscribe-confirm-empty');
			} else {
				const $list = $('<ul>', { class: 'sscribe-confirm-list' });
				items.forEach(function (item) {
					$('<li>').text(String(item)).appendTo($list);
				});
				$body.append($list);
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
				self.restoreFocus();
				if (typeof opts.onCancel === 'function') {
					opts.onCancel();
				}
			};
			const proceed = function () {
				$modal.addClass('sscribe-hidden').attr('aria-hidden', 'true').prop('hidden', true);
				self.releaseFocusTrap($modal[0]);
				self.restoreFocus();
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
				if ($cancel[0]) {
					$cancel.trigger('focus');
				} else if ($proceed[0] && !$proceed.prop('disabled')) {
					$proceed.trigger('focus');
				}
			}, 0);
		},
		dismissOnboarding: function (e) {
			if (e) {
				e.preventDefault();
			}
			const $banner = $('#sscribe-onboarding-banner');
			$banner.fadeOut(160, function () {
				$banner.remove();
			});
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
		dismissPreflightWarning: function (e) {
			if (e) {
				e.preventDefault();
			}
			const $btn = $(e && e.currentTarget ? e.currentTarget : null);
			const code = $btn.attr('data-warning-code') || '';
			const $warning = $btn.closest('.sscribe-preflight-warning');
			$warning.attr('data-dismissed', 'true');
			try {
				const key = 'sscribe_preflight_dismissed';
				const existing = JSON.parse(sessionStorage.getItem(key) || '{}');
				existing[code] = '1';
				sessionStorage.setItem(key, JSON.stringify(existing));
			} catch (_err) {
				/* sessionStorage unavailable - chip hides for this view only */
			}
		},
		changeConfiguration: function (e) {
			if (e) {
				e.preventDefault();
			}
			$('#sscribe-error-area').stop(true, true).addClass('sscribe-hidden');
			this.setErrorDetails(null);
			const $config = $('.sscribe-config-panel').first();
			const offset = $config.offset();
			if (!$config.length || !offset) {
				return;
			}
			const $focusTarget = $config.find('input[type="radio"]:checked').first();
			const focusConfiguration = function () {
				if ($focusTarget.length) {
					$focusTarget.trigger('focus');
				}
			};
			const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			const scrollTop = Math.max(0, offset.top - 20);
			if (reduceMotion) {
				window.scrollTo(0, scrollTop);
				focusConfiguration();
				return;
			}
			$('html, body').stop(true).animate({ scrollTop: scrollTop }, 200);
			setTimeout(focusConfiguration, 220);
		},
		setErrorDetails: function (details) {
			const $btn = $('#sscribe-error-toggle-details');
			const $details = $('#sscribe-error-technical-details');
			const canShow =
				details &&
				typeof details === 'object' &&
				Object.keys(details).length > 0 &&
				typeof window.SSCRIBE_DEBUG !== 'undefined' &&
				window.SSCRIBE_DEBUG;
			$btn.attr('aria-expanded', 'false').toggleClass('sscribe-hidden', !canShow).prop('hidden', !canShow);
			$('#sscribe-error-toggle-details-label').text(
				(sscribe_data.strings && sscribe_data.strings.error_show_details) || 'Show technical details'
			);
			$details.addClass('sscribe-hidden').prop('hidden', true);
			$details.find('pre').text(canShow ? JSON.stringify(details, null, 2) : '');
		},
		toggleErrorDetails: function (e) {
			if (e) {
				e.preventDefault();
			}
			const $btn = $('#sscribe-error-toggle-details');
			const $details = $('#sscribe-error-technical-details');
			if (!$btn.length || !$details.length) {
				return;
			}
			const willOpen = $btn.attr('aria-expanded') !== 'true';
			$btn.attr('aria-expanded', willOpen ? 'true' : 'false');
			$('#sscribe-error-toggle-details-label').text(
				willOpen
					? (sscribe_data.strings && sscribe_data.strings.error_hide_details) || 'Hide technical details'
					: (sscribe_data.strings && sscribe_data.strings.error_show_details) || 'Show technical details'
			);
			$details.toggleClass('sscribe-hidden', !willOpen).prop('hidden', !willOpen);
		},
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
					(sscribe_data.strings && sscribe_data.strings.history_no_match) || 'No exports match your filter.'
				);
			}
		},
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
		openHistoryFromSuccess: function (e) {
			if (e) {
				e.preventDefault();
			}
			this.activateTab('history', true);
		},
		prettyFormatLabel: function (format) {
			if (!format || typeof format !== 'string') {
				return '';
			}
			const acronyms = { pdf: 'PDF', docx: 'DOCX', html: 'HTML', htm: 'HTM', md: 'MD' };
			const compoundTokens = {
				'all-formats': 'All Formats',
				'all-langs': 'All Languages',
				'pages-and-posts': 'Pages + Posts',
			};
			return format
				.split('+')
				.map(function (part) {
					const lower = part.toLowerCase();
					if (compoundTokens[lower]) {
						return compoundTokens[lower];
					}
					if (acronyms[lower]) {
						return acronyms[lower];
					}
					return lower.replace(/(^|[\s_-])[a-z]/g, function (m) {
						return m.toUpperCase();
					});
				})
				.join(' + ');
		},
		prettyFilenameStem: function (filename) {
			if (!filename || typeof filename !== 'string') {
				return '';
			}
			const stem = filename.replace(/\.zip$/i, '').replace(/^sscribe-export-/, '');
			const dateMatch = stem.match(/(?:^|-)(\d{4}-\d{2}-\d{2})(?:[-T](\d{2})-?(\d{2}))?/);
			const randomMatch = stem.match(/-([a-f0-9]{4,8})$/i);
			let core = stem;
			if (dateMatch) {
				core = core.replace(dateMatch[0], '').replace(/-+$/, '');
			}
			if (randomMatch) {
				core = core.replace(randomMatch[0], '').replace(/-+$/, '');
			}
			let label = core
				.split(/[-_]+/)
				.filter(Boolean)
				.map(function (part) {
					return part.charAt(0).toUpperCase() + part.slice(1);
				})
				.join(' ');
			if (dateMatch) {
				const yyyy = dateMatch[1].slice(0, 4);
				const mm = dateMatch[1].slice(5, 7) - 1;
				const dd = dateMatch[1].slice(8, 10);
				const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
				const friendly = months[mm] + ' ' + parseInt(dd, 10) + ', ' + yyyy;
				let stamp = friendly;
				if (dateMatch[2] && dateMatch[3]) {
					stamp += ' ' + dateMatch[2] + ':' + dateMatch[3];
				}
				label = label ? label + ' · ' + stamp : stamp;
			}
			return label || stem;
		},
		/**
		 * Format a byte count with the largest sensible unit.
		 *
		 * @param {number} bytes Byte count.
		 * @returns {string} Human-readable size.
		 */
		formatBytes: function (bytes) {
			const value = Number(bytes);
			if (!isFinite(value) || value <= 0) {
				return '0 KB';
			}
			const units = ['KB', 'MB', 'GB'];
			let size = value / 1024;
			let unit = 0;
			while (size >= 1024 && unit < units.length - 1) {
				size /= 1024;
				unit++;
			}
			return (unit === 0 ? Math.round(size) : size.toFixed(1)) + ' ' + units[unit];
		},
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
			const self = this;
			if (data.formats && data.formats.length) {
				if (data.formats.length > 1) {
					const labels = data.formats.map(function (f) {
						return self.prettyFormatLabel(f);
					});
					setVal('sscribe-success-formats', data.formats.length + ' formats: ' + labels.join(', '));
				} else {
					setVal('sscribe-success-formats', self.prettyFormatLabel(data.formats[0]));
				}
			} else if (typeof data.format !== 'undefined') {
				setVal('sscribe-success-formats', self.prettyFormatLabel(data.format));
			} else if (data.filename && /\.zip$/i.test(data.filename)) {
				setVal('sscribe-success-formats', self.prettyFilenameStem(data.filename));
			}
			if (typeof data.size !== 'undefined' && data.size) {
				const formatted =
					typeof this.formatBytes === 'function'
						? this.formatBytes(data.size)
						: Math.round(data.size / 1024) + ' KB';
				setVal('sscribe-success-size', formatted);
			} else if (typeof data.file_size !== 'undefined' && data.file_size) {
				const formatted =
					typeof this.formatBytes === 'function'
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
		startExportFromPreview: function (e) {
			e.preventDefault();
			if (this.isProcessing) {
				return;
			}
			this.closePreview();
			$('#sscribe-export-btn').trigger('click');
		},
		loadSupportInfo: function () {
			if (!sscribe_data.health_nonce || this._supportInfoRequest) {
				return;
			}
			const $grid = $('#sscribe-support-grid');
			const $textarea = $('#sscribe-support-copy-text');
			const $btn = $('#sscribe-support-copy-btn');
			const $refresh = $('#sscribe-support-refresh-btn');
			const $card = $('[data-support-card]');
			$textarea.val('');
			$btn.prop('disabled', true);
			$refresh.prop('disabled', true).attr('aria-busy', 'true').addClass('sscribe-btn-busy');
			$card.attr('aria-busy', 'true');
			$grid.attr('aria-busy', 'true');
			$grid
				.removeClass('sscribe-support-grid-empty')
				.html(
					'<div class="sscribe-support-loading">' +
						'<span class="sscribe-loading-spinner"></span>' +
						'<span>' +
						this.escapeHtml(sscribe_data.strings.support_loading || 'Loading support information...') +
						'</span>' +
						'</div>'
				);
			const self = this;
			const request = $.ajax({
				url: sscribe_data.ajaxurl,
				type: 'POST',
				timeout: 30000,
				data: {
					action: 'sscribe_get_support_info',
					nonce: sscribe_data.health_nonce,
				},
				success: function (response) {
					if (response.success && response.data) {
						self.renderSupportInfo(response.data);
					} else {
						$grid
							.removeClass('sscribe-support-grid-empty')
							.html(
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
					$grid
						.removeClass('sscribe-support-grid-empty')
						.html(
							'<div class="sscribe-support-error">' +
								self.escapeHtml(
									sscribe_data.strings.support_error ||
										'Unable to load support information right now.'
								) +
								'</div>'
						);
				},
			});
			this._supportInfoRequest = request;
			request.always(function () {
				self._supportInfoRequest = null;
				$refresh.prop('disabled', false).attr('aria-busy', 'false').removeClass('sscribe-btn-busy');
				$card.attr('aria-busy', 'false');
				$grid.attr('aria-busy', 'false');
			});
		},
		/**
		 * Copy the support snapshot text to the clipboard and announce
		 * the result via the live-region. Refuses to do anything if no
		 * snapshot has been generated yet (textarea empty) or the
		 * Clipboard API is unavailable.
		 */
		copySupportInfo: function () {
			const $textarea = $('#sscribe-support-copy-text');
			const $btn = $('#sscribe-support-copy-btn');
			if (!$textarea.length || !$textarea.val()) {
				this.showToast(
					(sscribe_data.strings && sscribe_data.strings.support_copy_error) ||
						'Copy failed. Try selecting the text manually.',
					'warning'
				);
				return;
			}
			const text = $textarea.val();
			const done = () => {
				$btn.prop('disabled', true).text(
					(sscribe_data.strings && sscribe_data.strings.support_copied) || 'Support information copied.'
				);
				setTimeout(function () {
					$btn.prop('disabled', false).text(
						(sscribe_data.strings && sscribe_data.strings.support_copy) || 'Copy support info'
					);
				}, 2000);
				SScribe.announce(
					(sscribe_data.strings && sscribe_data.strings.support_copied) || 'Support information copied.'
				);
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard
					.writeText(text)
					.then(done)
					.catch(function () {
						self.fallbackCopy(text, done);
					});
			} else {
				this.fallbackCopy(text, done);
			}
		},
		/**
		 * Fallback clipboard write for non-secure contexts. Uses a hidden
		 * textarea + document.execCommand('copy'). Deprecated API but
		 * still the only option on http:// origins (e.g. test env).
		 */
		fallbackCopy: function (text, onDone) {
			const attempt = function () {
				const $temp = $('<textarea>')
					.css({ position: 'fixed', top: '-9999px', opacity: 0 })
					.val(text)
					.appendTo('body');
				$temp[0].select();
				const result = document.execCommand('copy');
				$temp.remove();
				return result;
			};
			let ok = false;
			try {
				ok = attempt();
			} catch (_e) {
				// ok stays false
			}
			if (ok) {
				if (typeof onDone === 'function') {
					onDone();
				}
				return;
			}
			this.showToast(
				(sscribe_data.strings && sscribe_data.strings.support_copy_error) ||
					'Copy failed. Try selecting the text manually.',
				'error'
			);
		},
		/**
		 * Push a status announcement to the aria-live region and keep
		 * the last-announced bucket so duplicate messages do not spam
		 * screen readers.
		 *
		 * @param {string} message Announcement text.
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
			if (last.text === message && now - last.at < 1500) {
				return;
			}
			// Reset to empty first so identical consecutive strings still
			// re-trigger screen-reader announcements.
			$region.text('');
			setTimeout(
				function () {
					$region.text(message);
				},
				last.text === message ? 30 : 0
			);
			this._lastAnnounce = { text: message, at: now };
		},
		renderSupportInfo: function (data) {
			const $grid = $('#sscribe-support-grid');
			const $textarea = $('#sscribe-support-copy-text');
			const $btn = $('#sscribe-support-copy-btn');
			$grid.removeClass('sscribe-support-grid-empty');
			let html = '';
			if (data.sections) {
				Object.keys(data.sections).forEach(function (sectionKey) {
					const section = data.sections[sectionKey];
					if (!section || !section.items) {
						return;
					}
					const itemKeys = Object.keys(section.items);
					if (itemKeys.length === 0) {
						return;
					}
					const label = section.label || sectionKey;
					const spanClass = section.span === 'full' ? ' sscribe-support-section-full' : '';
					html +=
						'<section class="sscribe-support-section' +
						spanClass +
						'" aria-labelledby="sscribe-support-section-' +
						this.escapeHtml(sectionKey) +
						'">';
					html +=
						'<h4 class="sscribe-support-section-title" id="sscribe-support-section-' +
						this.escapeHtml(sectionKey) +
						'">' +
						this.escapeHtml(label) +
						'</h3>';
					html += '<div class="sscribe-support-grid-inner">';
					itemKeys.forEach(function (itemKey) {
						const value = section.items[itemKey];
						html += '<div class="sscribe-support-item">';
						html += '<span class="sscribe-support-label">' + this.escapeHtml(itemKey) + '</span>';
						html += '<span class="sscribe-support-value">' + this.escapeHtml(String(value)) + '</span>';
						html += '</div>';
					}, this);
					html += '</div>';
					html += '</section>';
				}, this);
			}
			if (html === '') {
				html =
					'<div class="sscribe-support-empty-state">' +
					this.escapeHtml(sscribe_data.strings.support_empty || 'No diagnostic data available.') +
					'</div>';
			}
			$grid.html(html);
			$textarea.val(data.copy_text || '');
			$btn.prop('disabled', !String(data.copy_text || '').trim());
		},
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
				$btn.removeData('sscribe-confirm-hint');
				$btn.removeClass('sscribe-btn-confirming');
				$row.removeClass('sscribe-row-deleting');
				SScribe.deleteSingleExport(filename, function (success) {
					if (success === false) {
						return;
					}
					$row.fadeOut(200, function () {
						$(this).remove();
						SScribe.refreshRecentExports();
					});
				});
				return;
			}
			// First click: enter the "click again to confirm" state with strong visual cues.
			const $original = $row.find('.sscribe-file-details > strong').first();
			const $hint = $('<span>')
				.addClass('sscribe-confirm-hint')
				.attr('role', 'status')
				.attr('aria-live', 'polite')
				.text(sscribe_data.strings.delete_confirm_hint || 'Click again within 3s to confirm');
			$original.after($hint);
			$btn.data('sscribe-confirming', true)
				.data('sscribe-confirm-original', $btn.text())
				.data('sscribe-confirm-hint', $hint)
				.addClass('sscribe-btn-confirming')
				.text(sscribe_data.strings.click_again || 'Confirm delete')
				.attr('aria-label', sscribe_data.strings.delete_confirm_hint || 'Click again within 3s to confirm')
				.prop('disabled', false);
			SScribe.announce(sscribe_data.strings.delete_confirm_hint || 'Click again within 3 seconds to confirm');
			const $originalText = $btn.data('sscribe-confirm-original');
			const tid = setTimeout(function () {
				if ($btn.data('sscribe-confirming')) {
					const $h = $btn.data('sscribe-confirm-hint');
					if ($h && $h.length) {
						$h.remove();
					}
					$btn.removeData('sscribe-confirming')
						.removeData('sscribe-confirm-hint')
						.removeClass('sscribe-btn-confirming')
						.text($originalText || '')
						.removeAttr('aria-label');
				}
			}, 3000);
			$btn.data('sscribe-confirm-timeout', tid);
		},
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
						'<p>' +
						safe +
						'</p>' +
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
				'<div class="sscribe-log-stat">' +
				'<span class="sscribe-log-stat-icon" aria-hidden="true">' +
				this.getIconSvg('file-text', 16) +
				'</span>' +
				'<span class="sscribe-log-stat-body">' +
				'<span class="sscribe-log-stat-label">' +
				this.escapeHtml(strings.log_total || 'Total') +
				'</span>' +
				'<span class="sscribe-log-stat-value">' +
				this.escapeHtml(String(log.total_pages || 0)) +
				'</span>' +
				'</span>' +
				'</div>';
			html +=
				'<div class="sscribe-log-stat sscribe-log-success">' +
				'<span class="sscribe-log-stat-icon" aria-hidden="true">' +
				this.getIconSvg('check', 16) +
				'</span>' +
				'<span class="sscribe-log-stat-body">' +
				'<span class="sscribe-log-stat-label">' +
				this.escapeHtml(strings.log_success_label || 'Success') +
				'</span>' +
				'<span class="sscribe-log-stat-value success">' +
				this.escapeHtml(String(log.success || 0)) +
				'</span>' +
				'</span>' +
				'</div>';
			html +=
				'<div class="sscribe-log-stat sscribe-log-failed">' +
				'<span class="sscribe-log-stat-icon" aria-hidden="true">' +
				this.getIconSvg('x', 16) +
				'</span>' +
				'<span class="sscribe-log-stat-body">' +
				'<span class="sscribe-log-stat-label">' +
				this.escapeHtml(strings.log_failed_label || 'Failed') +
				'</span>' +
				'<span class="sscribe-log-stat-value failed">' +
				this.escapeHtml(String(log.failed || 0)) +
				'</span>' +
				'</span>' +
				'</div>';
			html += '</div>';
			if (pages.length > 0) {
				html += '<div class="sscribe-log-pages">';
				html += '<h4>' + this.escapeHtml(strings.log_page_details || 'Page Details') + '</h3>';
				html += '<div class="sscribe-log-table-wrap"><table class="sscribe-log-table">';
				html += '<thead><tr>';
				html += '<th scope="col">' + this.escapeHtml(strings.log_col_id || 'ID') + '</th>';
				html += '<th scope="col">' + this.escapeHtml(strings.log_col_title || 'Title') + '</th>';
				html += '<th scope="col">' + this.escapeHtml(strings.log_col_status || 'Status') + '</th>';
				html += '<th scope="col">' + this.escapeHtml(strings.log_col_time || 'Time') + '</th>';
				html += '<th scope="col">' + this.escapeHtml(strings.log_col_formats || 'Formats') + '</th>';
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
						const statusKey = (status || '').toLowerCase();
						const statusClass =
							statusKey === 'success'
								? 'sscribe-log-status-success'
								: statusKey === 'failed' || statusKey === 'error'
									? 'sscribe-log-status-failed'
									: 'sscribe-log-status-neutral';
						const statusDotClass =
							statusKey === 'success'
								? 'sscribe-log-status-dot-success'
								: statusKey === 'failed' || statusKey === 'error'
									? 'sscribe-log-status-dot-failed'
									: 'sscribe-log-status-dot-neutral';
						let formatText = '-';
						let formatChips = '';
						if (page.formats && typeof page.formats === 'object') {
							const formatKeys = Object.keys(page.formats).filter(function (fmt) {
								return page.formats[fmt] && page.formats[fmt].success;
							});
							if (formatKeys.length > 0) {
								formatText = formatKeys
									.map(function (format) {
										return format.toUpperCase();
									})
									.join(', ');
								formatChips = formatKeys
									.map(
										function (format) {
											return (
												'<span class="sscribe-log-format-chip">' +
												this.escapeHtml(format.toUpperCase()) +
												'</span>'
											);
										}.bind(this)
									)
									.join('');
							}
						}
						const idCell = '<code class="sscribe-log-id">' + this.escapeHtml(String(pageId)) + '</code>';
						const titleCell = '<span class="sscribe-log-title">' + this.escapeHtml(title) + '</span>';
						const statusCell =
							'<span class="sscribe-log-status-badge ' +
							this.escapeHtml(statusClass) +
							'">' +
							'<span class="sscribe-log-status-dot ' +
							this.escapeHtml(statusDotClass) +
							'" aria-hidden="true"></span>' +
							this.escapeHtml(status) +
							'</span>';
						const timeCell = '<span class="sscribe-log-time">' + this.escapeHtml(duration) + '</span>';
						const formatCell =
							formatChips || '<span class="sscribe-log-time">' + this.escapeHtml(formatText) + '</span>';
						html += '<tr>';
						html += '<td class="sscribe-log-cell-id">' + idCell + '</td>';
						html += '<td class="sscribe-log-cell-title">' + titleCell + '</td>';
						html += '<td class="sscribe-log-cell-status">' + statusCell + '</td>';
						html += '<td class="sscribe-log-cell-time">' + timeCell + '</td>';
						html += '<td class="sscribe-log-cell-formats">' + formatCell + '</td>';
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
				html += '<h4>' + this.escapeHtml(strings.log_errors || 'Errors') + '</h3>';
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
		closeModal: function (e) {
			if (e) {
				e.preventDefault();
			}
			const $modal = $('#sscribe-log-modal');
			const modalEl = $modal[0];
			const self = this;
			$modal.attr('aria-hidden', 'true').addClass('sscribe-hidden').prop('hidden', true);
			self.releaseFocusTrap(modalEl);
			self.restoreFocus();
			$modal.fadeOut(200);
		},
		// Generic closer for any element marked with
		// [data-close-modal="<modal-id>"]. Reads the modal id from
		// the attribute so the markup can declare its own target
		// without needing a separate JS handler per modal.
		closeModalByAttr: function (e) {
			if (e) {
				e.preventDefault();
			}
			const $btn = $(e.currentTarget);
			const modalId = $btn.attr('data-close-modal');
			if (!modalId) {
				return;
			}
			const $modal = $('#' + modalId);
			if (!$modal.length) {
				return;
			}
			const modalEl = $modal[0];
			const self = this;
			$modal.attr('aria-hidden', 'true').addClass('sscribe-hidden').prop('hidden', true);
			self.releaseFocusTrap(modalEl);
			self.restoreFocus();
			$modal.fadeOut(200);
		},
		downloadExport: function (e) {
			const $link = $(e.currentTarget);
			const href = $link.attr('href') || '';
			const safeHref = this.getSafeSameOriginUrl(href);
			if (safeHref) {
				return;
			}

			// History rows are rendered only with a validated same-origin
			// download URL. If markup is stale or tampered with, fail closed
			// instead of treating a filename/data attribute as a navigation URL.
			e.preventDefault();
		},
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
		resetUI: function () {
			clearTimeout(this._batchTimer);
			this._batchTimer = null;
			clearTimeout(this._cancelTimer);
			this._cancelTimer = null;
			$('#sscribe-progress-area').addClass('sscribe-hidden');
			$('#sscribe-error-area').addClass('sscribe-hidden');
			$('#sscribe-download-area').addClass('sscribe-hidden');
			$('#sscribe-progress-bar').removeClass('sscribe-progress-initializing').css('transform', 'scaleX(0)');
			$('.sscribe-progress-bar-container').removeClass('sscribe-progress-complete');
			$('#sscribe-progress-text').text('0%');
			$('#sscribe-status-text').text('');
			$('#sscribe-current-page').text('').hide();
			$('#sscribe-time-remaining').text('').hide();
			$('#sscribe-live-region, #sscribe-alert-region').text('');
			const progressBar = document.getElementById('sscribe-progress-bar');
			if (progressBar) {
				progressBar.setAttribute('aria-valuetext', '');
				progressBar.setAttribute('aria-valuenow', '0');
			}
			$('#sscribe-export-btn, #sscribe-preview-btn').removeClass('sscribe-btn-busy').removeAttr('aria-busy');
			this.isProcessing = false;
			this.sessionId = null;
			this.batchRetries = 0;
			this._cancelRetries = 0;
			this._batchInProgress = false;
			this._isCancelling = false;
		},
		showCancelled: function (message) {
			const cancelledMessage = message || sscribe_data.strings.export_cancelled || 'Export cancelled.';
			this.resetUI();
			this.updateExportButton();
			$('#sscribe-cancel-btn')
				.prop('disabled', false)
				.text(sscribe_data.strings.cancel || 'Cancel Export');
			this.showToast(cancelledMessage, 'info');
		},
		/**
		 * @param {string} code Stable server error code.
		 * @param {string} _message Reserved for a future fallback.
		 * @returns {string} Guidance text.
		 */
		getErrorGuidance: function (code, _message) {
			const strings = (sscribe_data && sscribe_data.strings) || {};
			const knownCodes = {
				invalid_nonce: strings.err_session_expired,
				invalid_session_id: strings.err_session_expired,
				invalid_filename: strings.err_generic,
				invalid_post_type: strings.err_generic,
				invalid_language: strings.err_generic,
				permission_denied: strings.err_session_expired,
				session_expired: strings.err_session_expired,
				session_ownership: strings.err_session_expired,
				session_corrupt: strings.err_generic,
				session_cleared: strings.err_generic,
				race_detected: strings.err_generic,
				already_completing: strings.err_generic,
				batch_locked: strings.err_generic,
				batch_in_progress: strings.err_generic,
				finalize_exception: strings.err_generic,
				not_finalizing: strings.err_generic,
				export_not_found: strings.err_generic,
				delete_failed: strings.err_generic,
				workspace_init_failed: strings.err_generic,
				session_create_failed: strings.err_generic,
				page_list_failed: strings.err_generic,
				concurrent_export: strings.err_session_expired,
				no_pages_selected: strings.err_generic,
				support_info_unavailable: strings.err_generic,
				memory: strings.err_memory,
				memory_exhausted: strings.err_memory,
				zip_failed: strings.err_zip,
				archive_failed: strings.err_zip,
				rate_limit: strings.err_rate_limit,
				rate_limited: strings.err_rate_limit,
			};
			if (code && Object.prototype.hasOwnProperty.call(knownCodes, code)) {
				return knownCodes[code] || strings.err_generic || '';
			}
			return strings.err_generic || '';
		},
		formatGuidance: function (guidance) {
			if (!guidance) {
				return '';
			}
			return this.escapeHtml(guidance).replace(/\n/g, '<br>');
		},
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
		showError: function (message, isCancelled, errorData) {
			this.isProcessing = false;
			this._batchInProgress = false;
			$('#sscribe-export-btn, #sscribe-preview-btn').removeClass('sscribe-btn-busy').removeAttr('aria-busy');
			this.updateExportButton();
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
				if (diagnosticInfo && diagnosticInfo.code) {
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
			let technicalDetails = null;
			if (diagnosticInfo && diagnosticInfo._diagnostics) {
				technicalDetails = diagnosticInfo._diagnostics;
			} else if (
				diagnosticInfo &&
				Object.keys(diagnosticInfo).some(function (key) {
					return diagnosticInfo[key] !== null;
				})
			) {
				technicalDetails = diagnosticInfo;
			}
			this.setErrorDetails(technicalDetails);
			$('#sscribe-error-area').removeClass('sscribe-hidden').hide().fadeIn(300);
		},
		showWarning: function (message) {
			const alertRegion = document.getElementById('sscribe-alert-region');
			if (alertRegion) {
				alertRegion.textContent = '';
				alertRegion.textContent = message;
			}
			this.showToast(message, 'warning', 8000);
		},
		/**
		 * Centralized translator from a jqXHR failure into a single decision
		 * object the rest of the code can branch on without re-deriving the
		 * same status-code / retry_in logic at every call site.
		 *
		 * Decision contract:
		 *   {
		 *     'action':    'retry' | 'fail' | 'refresh_nonce' | 'noop'
		 *     'delayMs':   number       // recommended delay before retry (>=0)
		 *     'reason':    string       // machine-readable bucket
		 *     'messageKey':string|null  // sscribe_data.strings key, when known
		 *   }
		 *
		 * Status mapping (server-side decisions match this):
		 *   409 batch_in_progress / batch_locked : short retry with jitter
		 *   429 rate_limited / rate_limit        : server-driven retry_in (clamped)
		 *   503 rate_limiter_busy / contention   : short jittered retry
		 *   403 invalid_nonce                    : refresh-once retry of the same call
		 *   0   network / abort                  : bounded retry, no message
		 *   other                                : 'fail' (caller shows the message)
		 *
		 * @param {jqXHR|null} xhr      jQuery XHR.
		 * @param {object}     response Parsed responseJSON.data (may be empty).
		 * @param {object}     options  Optional override flags (jitter seed etc.).
		 * @returns {object} Decision object.
		 */
		getAjaxFailureDecision: function (xhr, response, options) {
			const opts = options || {};
			const data = response || {};
			const code = typeof data.code === 'string' ? data.code : '';
			const message = typeof data.message === 'string' ? data.message : null;
			const serverDelay = Number(data.retry_in);
			const hasServerDelay = isFinite(serverDelay) && serverDelay > 0;
			// Phase 4: HTTP `Retry-After` header is the second-priority
			// delay source after the server's JSON `retry_in`. We accept
			// either a numeric seconds value or an HTTP-date.
			const headerDelay = SScribe._parseRetryAfterHeader(xhr);
			const hasHeaderDelay = headerDelay !== null;

			const status = xhr && typeof xhr.status === 'number' ? xhr.status : 0;
			// `textStatus` is jQuery's textual status, e.g. 'timeout',
			// 'abort', 'parsererror', 'error'.
			const textStatus = xhr && typeof xhr.statusText === 'string' ? xhr.statusText : '';

			// 409 batch / lock conflict — use the helper `conflict` action
			// name required by Phase 4. Server retry_in honored when present.
			if (status === 409 || code === 'batch_in_progress' || code === 'batch_locked') {
				const lower = hasServerDelay ? Math.max(500, serverDelay) : 5000;
				const upper = hasServerDelay ? Math.min(30000, serverDelay + 5000) : 8000;
				return {
					action: 'conflict',
					delayMs: SScribe._jitteredDelay(lower, upper, opts.jitterSeed),
					reason: 'batch_locked',
					message: message,
					code: code || 'batch_locked',
					messageKey: 'err_batch_locked',
				};
			}

			if (status === 429 || code === 'rate_limited') {
				// Mandatory: server-provided retry_in is absolute; do not let
				// a 1000ms client backoff override a 60000ms server hint.
				const delay = hasServerDelay ? Math.max(1000, serverDelay) : hasHeaderDelay ? headerDelay : 60000;
				return {
					action: 'retry',
					delayMs: delay,
					reason: 'rate_limited',
					message: message,
					code: code || 'rate_limited',
					messageKey: 'err_rate_limit',
				};
			}

			if (status === 503 || code === 'rate_limiter_busy') {
				const delay = hasServerDelay
					? Math.max(250, serverDelay)
					: hasHeaderDelay
						? headerDelay
						: SScribe._jitteredDelay(500, 1500, opts.jitterSeed);
				return {
					action: 'retry',
					delayMs: delay,
					reason: 'limiter_contention',
					message: message,
					code: code || 'rate_limiter_busy',
					messageKey: 'err_limiter_busy',
				};
			}

			if (status === 403 && (code === 'invalid_nonce' || code === 'security_check_failed')) {
				return {
					action: 'refresh_nonce',
					delayMs: 0,
					reason: 'invalid_nonce',
					message: message,
					code: code || 'invalid_nonce',
					messageKey: 'err_session_expired',
				};
			}

			if (status === 400) {
				return {
					action: 'fail',
					delayMs: 0,
					reason: 'bad_request',
					message: message,
					code: code || 'bad_request',
					messageKey: null,
				};
			}

			if (status === 401) {
				return {
					action: 'fail',
					delayMs: 0,
					reason: 'unauthorized',
					message: message,
					code: code || 'unauthorized',
					messageKey: 'err_401',
				};
			}

			if (status === 404) {
				return {
					action: 'fail',
					delayMs: 0,
					reason: 'not_found',
					message: message,
					code: code || 'not_found',
					messageKey: 'err_404',
				};
			}

			if (status === 499) {
				// Client closed request — bounded retry with jitter.
				return {
					action: 'retry',
					delayMs: SScribe._jitteredDelay(1000, 3000, opts.jitterSeed),
					reason: 'client_closed',
					message: message,
					code: code || 'client_closed',
					messageKey: 'err_499',
				};
			}

			if (status === 500) {
				// Terminal — Phase 8 will route this through finishPreparationFailure
				// for the start-export path; here it just means "no automatic retry".
				return {
					action: 'fail',
					delayMs: 0,
					reason: 'server_error',
					message: message,
					code: code || 'server_error',
					messageKey: 'err_500',
				};
			}

			if (textStatus === 'timeout') {
				return {
					action: 'retry',
					delayMs: hasServerDelay ? serverDelay : hasHeaderDelay ? headerDelay : 5000,
					reason: 'timeout',
					message: message,
					code: code || 'timeout',
					messageKey: 'err_timeout',
				};
			}

			if (status === 0 || textStatus === 'error' || textStatus === 'abort') {
				return {
					action: 'retry',
					delayMs: hasHeaderDelay ? headerDelay : SScribe._jitteredDelay(2000, 4000, opts.jitterSeed),
					reason: 'network',
					message: message,
					code: code || 'network',
					messageKey: 'err_connection',
				};
			}

			return {
				action: 'fail',
				delayMs: 0,
				reason: code || 'unknown',
				message: message,
				code: code || 'unknown',
				messageKey: null,
			};
		},
		/**
		 * Parse the HTTP `Retry-After` header value into a millisecond delay.
		 *
		 * Accepts either a non-negative integer (seconds) or an HTTP-date.
		 * Returns null if the header is absent, malformed, or in the past.
		 *
		 * @param {jqXHR|null} xhr jQuery XHR object.
		 * @returns {number|null} Delay in ms, or null when no usable value.
		 */
		_parseRetryAfterHeader: function (xhr) {
			if (!xhr || typeof xhr.getResponseHeader !== 'function') {
				return null;
			}
			const raw = xhr.getResponseHeader('Retry-After');
			if (!raw) {
				return null;
			}
			const trimmed = String(raw).trim();
			if (/^\d+(\.\d+)?$/.test(trimmed)) {
				const seconds = parseFloat(trimmed);
				if (!isFinite(seconds) || seconds < 0) {
					return null;
				}
				return Math.floor(seconds * 1000);
			}
			const ms = Date.parse(trimmed);
			if (isNaN(ms)) {
				return null;
			}
			const diff = ms - Date.now();
			return diff > 0 ? diff : null;
		},
		/**
		 * Refresh the WordPress nonce for SScribe AJAX calls.
		 *
		 * Falls back to a page reload because the canonical way to obtain
		 * a fresh nonce is to re-render the admin page (which re-reads the
		 * session-bound nonce). The fallback path is safe to call even
		 * when the current call has already failed with invalid_nonce.
		 *
		 * @param {Function} after Optional callback to invoke after reload
		 *                        (only fires when a dedicated refresh
		 *                        endpoint succeeds; in reload mode the
		 *                        page navigation supersedes it).
		 */
		refreshNonceAnd: function (after) {
			if (typeof sscribe_data !== 'undefined' && sscribe_data && sscribe_data.refresh_nonce_url) {
				$.ajax({
					url: sscribe_data.refresh_nonce_url,
					type: 'POST',
					dataType: 'json',
					data: { action: 'sscribe_refresh_nonce' },
					success: function (response) {
						if (response && response.success && response.data && response.data.nonce) {
							sscribe_data.nonce = response.data.nonce;
							if (typeof after === 'function') {
								after();
							}
							return;
						}
						window.location.reload();
					},
					error: function () {
						window.location.reload();
					},
				});
				return;
			}
			window.location.reload();
		},
		/**
		 * Stable, deterministic jittered delay between [lowerMs, upperMs].
		 * Uses a Math.random fallback by default; pass a 0-1 number as
		 * jitterSeed to make it reproducible in tests.
		 *
		 * @param {number} lowerMs   Lower bound in ms (inclusive).
		 * @param {number} upperMs   Upper bound in ms (inclusive).
		 * @param {number} jitterSeed Optional 0-1 override.
		 * @returns {number} Delay in ms.
		 */
		_jitteredDelay: function (lowerMs, upperMs, jitterSeed) {
			const lo = Math.max(0, Math.floor(lowerMs));
			const hi = Math.max(lo, Math.floor(upperMs));
			const seed =
				typeof jitterSeed === 'number' && isFinite(jitterSeed)
					? Math.min(1, Math.max(0, jitterSeed))
					: Math.random();
			return Math.floor(lo + (hi - lo) * seed);
		},
		/**
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

	// Global JS error trap so any thrown handler surfaces a toast instead
	// of silently breaking an action (which the user perceives as "broken
	// tab switching", "delete does nothing", etc.).
	window.addEventListener('error', function (event) {
		if (!event || !event.error) {
			return;
		}
		try {
			if (typeof SScribe !== 'undefined' && typeof SScribe.showToast === 'function') {
				SScribe.showToast('A script error occurred: ' + (event.message || 'unknown'), 'error', 6000);
			}
		} catch (_e) {
			// Silent: the toast itself errored, nothing else to do.
		}
	});

	window.SScribe = SScribe;
})(jQuery);
