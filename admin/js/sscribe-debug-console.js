/**
 * SScribe Debug Console JavaScript
 *
 * @package SScribe_Export_Site_Pages
 * @version 1.1.2
 */

(function ($) {
	'use strict';

	const debounce = function (fn, wait) {
		let timeout;
		return function () {
			const context = this;
			const args = arguments;
			clearTimeout(timeout);
			timeout = setTimeout(function () {
				fn.apply(context, args);
			}, wait);
		};
	};

	function escHtml(str) {
		if (str === null || str === undefined) {
			return '';
		}
		const div = document.createElement('div');
		div.textContent = String(str);
		return div.innerHTML;
	}

	function escAttr(str) {
		if (str === null || str === undefined) {
			return '';
		}
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	// Format a Y-m-d H:i:s string (already converted to the site's
	// timezone on the server side) using the browser's locale. Parses
	// explicitly with a regex + Date constructor to avoid the
	// implementation-defined behaviour of `new Date('Y-m-d H:i:s')`
	// (some browsers treat it as UTC, others as local). Falls back to
	// the raw string on any parse failure.
	function formatLocalTimestamp( raw ) {
		if ( ! raw || typeof raw !== 'string' ) {
			return '';
		}
		const m = raw.match( /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/ );
		if ( ! m ) {
			return raw;
		}
		const d = new Date( +m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6] );
		if ( isNaN( d.getTime() ) ) {
			return raw;
		}
		try {
			return d.toLocaleString();
		} catch ( _e ) {
			return raw;
		}
	}

	// Truncate a string by visible code points, not UTF-16 code units.
	// `String.prototype.substring(0, n)` splits surrogate pairs in emoji
	// and supplementary-plane characters, which produces malformed
	// UTF-16 in aria-labels. Iterating with the spread operator exposes
	// code points, so a slice of 50 yields at most 50 user-perceived
	// characters and never lands mid-pair.
	function truncateForAriaLabel(str, maxChars) {
		const text = String(str || '');
		const codePoints = Array.from(text);
		if (codePoints.length <= maxChars) {
			return text;
		}
		return codePoints.slice(0, maxChars).join('') + '\u2026';
	}

	const SScribeDebugConsole = {
		refreshInterval: null,
		// Timeout ID for the exportRotatedLog() paused-indicator auto-hide.
		// Stored so viewRotatedLog() / backToCurrentLog() can cancel it
		// when the user navigates to a rotated view before the 5s window
		// expires.
		exportHintTimeout: null,
		isAutoRefresh: true,
		currentFilter: 'ALL',
		searchQuery: '',
		sessionFilter: '',
		initialized: false,
		isViewingRotated: false,
		currentOffset: 0,
		isLoadingMore: false,
		hasMoreEntries: true,
		currentRotatedFilename: '',
		observer: null,
		currentRequest: null,
		saveSettingsRequest: null,
		rotatedRequest: null,
		viewRotatedRequest: null,
		isRefreshing: false,
		isRefreshingSince: null,
		clearBtnTimeout: null,
		// Set when a refresh has been pending for >5s so we can show a
		// "refresh taking longer than expected..." indicator. Cleared on
		// fetch success/failure.
		slowRefreshNoticeSince: null,
		// True while a filter/search/session change is in flight to fetchLogs.
		// renderLogs() consults this so a filter-triggered re-render scrolls
		// to the top of the (now-shorter) entry list, instead of carrying
		// the old "wasAtBottom" heuristic over and jumping to the bottom
		// of a list the user never scrolled into.
		_filterChangeInProgress: false,

		init: function () {
			if (this.initialized) {
				return;
			}
			if (typeof sscribe_data === 'undefined' || !sscribe_data || !sscribe_data.ajaxurl || !sscribe_data.nonce) {
				if (this.initRetryCount === undefined) {
					this.initRetryCount = 0;
				}
				if (this.initRetryCount < 3) {
					this.initRetryCount++;
					setTimeout(this.init.bind(this), 100);
				}
				return;
			}
			if (!this.hasRequiredDom()) {
				return;
			}
			this.cacheDom();
			this.unbindEvents();
			this.bindEvents();
			this.bindVisibilityHandler();
			this.loadInitialState();
			this.initialized = true;
		},

		hasRequiredDom: function () {
			return (
				document.getElementById('sscribe-debug-root') !== null &&
				document.getElementById('sscribe-debug-entries') !== null &&
				document.getElementById('sscribe-debug-console-body') !== null &&
				document.getElementById('sscribe-debug-rotated-body') !== null
			);
		},

		cacheDom: function () {
			this.$container = $('#sscribe-debug-root');
			this.$enabled = $('#sscribe-debug-enabled');
			this.$level = $('#sscribe-debug-level');
			this.$saveSettings = $('#sscribe-debug-save-settings');
			this.$saveFeedback = $('#sscribe-debug-save-feedback');
			this.$filterLevel = $('#sscribe-debug-filter-level');
			this.$searchInput = $('#sscribe-debug-search');
			this.$sessionInput = $('#sscribe-debug-session-id');
			this.$refreshMode = $('input[name="sscribe_refresh_mode"]');
			this.$refreshBtn = $('#sscribe-debug-refresh-btn');
			this.$consoleBody = $('#sscribe-debug-console-body');
			this.$entries = $('#sscribe-debug-entries');
			this.$empty = $('#sscribe-debug-empty');
			// Hardcoded fallback for the case where the PHP template's
			// <p> renders empty (e.g. a translation override or a custom
			// child theme) — without this, renderLogs() would blank the
			// empty-state copy on every refresh.
			this.defaultEmptyMessage = this.$empty.find('p').text().trim() || 'No log entries found.';
			this.$entryCount = $('#sscribe-debug-entry-count');
			this.$clearBtn = $('#sscribe-debug-clear-btn');
			this.clearBtnOriginalText = this.$clearBtn.text();
			this.$exportBtn = $('#sscribe-debug-export-btn');
			this.$rotatedBody = $('#sscribe-debug-rotated-body');
			this.$rotatedDetails = $('#sscribe-debug-rotated-details');
			this.$rotatedHint = $('.sscribe-debug-rotated-hint');
			this.$helpContent = $('#sscribe-debug-help-content');
			this.$refreshPaused = $('#sscribe-debug-refresh-paused');
		},

		unbindEvents: function () {
			this.$saveSettings.off('.sscribe');
			this.$filterLevel.off('.sscribe');
			this.$searchInput.off('.sscribe');
			this.$sessionInput.off('.sscribe');
			this.$refreshMode.off('.sscribe');
			this.$refreshBtn.off('.sscribe');
			this.$clearBtn.off('.sscribe');
			this.$exportBtn.off('.sscribe');
			this.$rotatedBody.off('.sscribe');
			this.$entries.off('.sscribe');
			this.$container.off('.sscribe');
			this.unbindVisibilityHandler();
			this.unbindToggleHandler();
		},

		unbindVisibilityHandler: function () {
			if (this._visibilityHandler) {
				$(document).off('visibilitychange', this._visibilityHandler);
				this._visibilityHandler = null;
			}
			if (this._beforeUnloadHandler) {
				window.removeEventListener('beforeunload', this._beforeUnloadHandler);
				this._beforeUnloadHandler = null;
			}
			if (this._popStateHandler) {
				window.removeEventListener('popstate', this._popStateHandler);
				this._popStateHandler = null;
			}
		},

		unbindToggleHandler: function () {
			if (this._toggleHandler) {
				const rotatedEl = this.$rotatedDetails && this.$rotatedDetails[0];
				if (rotatedEl) {
					rotatedEl.removeEventListener('toggle', this._toggleHandler);
				}
				this._toggleHandler = null;
			}
		},

		bindEvents: function () {
			const self = this;

			this.$saveSettings.on('click.sscribe', function () {
				self.saveSettings(self.isAutoRefresh);
			});

			this.$filterLevel.on('change.sscribe', function () {
				self.currentFilter = $(this).val();
				self.currentOffset = 0;
				self.hasMoreEntries = true;
				self.isViewingRotated = false;
				self.currentRotatedFilename = '';
				self._filterChangeInProgress = true;
				self.hidePausedIndicator();
				self.destroyObserver();
				self.fetchLogs();
				self.updateExportButtonScope();
			});

			this.$searchInput.on(
				'input.sscribe',
				debounce(function () {
					self.searchQuery = self.$searchInput.val();
					self.currentOffset = 0;
					self.hasMoreEntries = true;
					self.isViewingRotated = false;
					self.currentRotatedFilename = '';
					self._filterChangeInProgress = true;
					self.hidePausedIndicator();
					self.destroyObserver();
					self.fetchLogs();
					self.updateExportButtonScope();
				}, 300)
			);

			this.$sessionInput.on(
				'input.sscribe',
				debounce(function () {
					self.sessionFilter = self.$sessionInput.val().trim();
					self.currentOffset = 0;
					self.hasMoreEntries = true;
					self.isViewingRotated = false;
					self.currentRotatedFilename = '';
					self._filterChangeInProgress = true;
					self.hidePausedIndicator();
					self.destroyObserver();
					self.fetchLogs();
					self.updateExportButtonScope();
				}, 300)
			);

			this.$refreshMode.on('change.sscribe', function () {
				const previousAutoRefresh = self.isAutoRefresh;
				self.isAutoRefresh = $(this).val() === 'auto';
				if (self.isAutoRefresh) {
					self.startAutoRefresh();
					self.hidePausedIndicator();
				} else {
					self.stopAutoRefresh();
					self.showPausedIndicator('Manual mode — auto-refresh off');
				}
				self.saveSettings(previousAutoRefresh);
			});

			this.$refreshBtn.on('click.sscribe', function () {
				if (self.isAutoRefresh) {
					self.stopAutoRefresh();
					self.startAutoRefresh();
				}
				if (!self.isViewingRotated) {
					self.fetchLogs();
				}
				const rotatedEl = self.$rotatedDetails && self.$rotatedDetails[0];
				if (rotatedEl && rotatedEl.open) {
					self.fetchRotatedLogs();
				}
			});

			this.$clearBtn.on('click.sscribe', function () {
				const $btn = $(this);
				if ($btn.data('confirming')) {
					if (self.clearBtnTimeout) {
						clearTimeout(self.clearBtnTimeout);
						self.clearBtnTimeout = null;
					}
					$btn.data('confirming', false).removeClass('sscribe-btn-confirming').text('Clearing...');
					$btn.prop('disabled', true);
					self.clearLogs();
				} else {
					// First click: prompt for confirmation. Do NOT disable the button —
					// the user MUST be able to click it again to confirm within 3s,
					// otherwise the confirm flow is dead on arrival.
					if (!$btn.data('original-text')) {
						$btn.data('original-text', $btn.text());
					}
					$btn.data('confirming', true).addClass('sscribe-btn-confirming').text('Click again to confirm');
					self.clearBtnTimeout = setTimeout(function () {
						if (self.$clearBtn) {
							self.$clearBtn
								.data('confirming', false)
								.removeClass('sscribe-btn-confirming')
								.text(self.$clearBtn.data('original-text') || self.clearBtnOriginalText);
							self.$clearBtn.removeData('original-text');
						}
						// Null the timeout ID so the next first-click correctly
						// detects "no pending timeout" without clearing a stale ID.
						self.clearBtnTimeout = null;
					}, 3000);
				}
			});

			this.$container.on('click.sscribe', '#sscribe-debug-help-btn', function () {
				const helpContent = self.$helpContent && self.$helpContent[0];
				if (helpContent) {
					const overlay = document.createElement('div');
					overlay.style.cssText = 'position:fixed;inset:0;background:rgb(0 0 0 / 50%);z-index:9999998;';
					overlay.setAttribute('aria-hidden', 'true');
					const dialog = document.createElement('div');
					dialog.style.cssText =
						'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;color:#333;padding:24px;border-radius:8px;max-width:400px;z-index:9999999;box-shadow:0 8px 32px rgb(0 0 0 / 30%);font-size:14px;line-height:1.6;';
					dialog.setAttribute('role', 'dialog');
					dialog.setAttribute('aria-modal', 'true');
					dialog.setAttribute('aria-labelledby', 'sscribe-debug-help-title');
					// tabindex="-1" makes the dialog focusable as a fallback when
					// its body has no focusable children (e.g. pure text help).
					// Without it, screen readers won't announce the dialog opened
					// because focus stayed on the button that launched it.
					dialog.setAttribute('tabindex', '-1');
					// The source element is `hidden` in the template (so it
					// doesn't render inline). Strip `hidden` from the clone so the
					// dialog body isn't caught by the UA `[hidden]` stylesheet.
					const helpClone = helpContent.cloneNode(true);
					if (helpClone && helpClone.removeAttribute) {
						helpClone.removeAttribute('hidden');
					}
					dialog.appendChild(helpClone);
					const priorFocus = document.activeElement;
					const focusableSelectors =
						'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
					const closeDialog = function () {
						if (document.body.contains(overlay)) {
							document.body.removeChild(overlay);
						}
						if (document.body.contains(dialog)) {
							document.body.removeChild(dialog);
						}
						document.removeEventListener('keydown', keyHandler);
						if (priorFocus && typeof priorFocus.focus === 'function') {
							priorFocus.focus();
						}
					};
					const keyHandler = function (e) {
						if (e.key === 'Escape' || e.key === 'Esc') {
							e.preventDefault();
							closeDialog();
							return;
						}
						if (e.key === 'Tab') {
							const focusableElements = Array.from(dialog.querySelectorAll(focusableSelectors));
							const first = focusableElements[0];
							const last = focusableElements[focusableElements.length - 1];
							if (e.shiftKey && document.activeElement === first) {
								e.preventDefault();
								last.focus();
							} else if (!e.shiftKey && document.activeElement === last) {
								e.preventDefault();
								first.focus();
							}
						}
					};

					const closeBtn = document.createElement('button');
					closeBtn.type = 'button';
					closeBtn.textContent = '\u00D7';
					closeBtn.setAttribute('aria-label', 'Close dialog');
					closeBtn.style.cssText =
						'position:absolute;top:12px;right:12px;background:none;border:none;font-size:18px;cursor:pointer;';
					closeBtn.addEventListener('click', closeDialog);
					dialog.appendChild(closeBtn);

					overlay.addEventListener('click', closeDialog);
					document.body.appendChild(overlay);
					document.body.appendChild(dialog);
					document.addEventListener('keydown', keyHandler);
					// Move focus to the first focusable element. If the dialog has
					// none (pure-text help), fall back to focusing the dialog
					// itself so screen readers still announce the dialog opened.
					const focusableElements = Array.from(dialog.querySelectorAll(focusableSelectors));
					if (focusableElements.length > 0) {
						focusableElements[0].focus();
					} else {
						dialog.focus();
					}
				}
			});

			this.$exportBtn.on('click.sscribe', function () {
				self.exportLogs();
			});

			const rotatedEl = this.$rotatedDetails && this.$rotatedDetails[0];
			if (rotatedEl) {
				this._toggleHandler = function () {
					const $hint = self.$rotatedHint && self.$rotatedHint[0];
					if ($hint) {
						$hint.textContent = rotatedEl.open ? 'Click to collapse' : 'Click to expand';
					}
					if (rotatedEl.open) {
						self.fetchRotatedLogs();
					}
				};
				rotatedEl.addEventListener('toggle', this._toggleHandler);
			}

			this.$rotatedBody.on('click.sscribe', '.sscribe-rotated-view', function () {
				self.viewRotatedLog($(this).data('file'));
			});
			this.$rotatedBody.on('click.sscribe', '.sscribe-rotated-export', function () {
				self.exportRotatedLog($(this).data('file'), $(this));
			});
			this.$rotatedBody.on('click.sscribe', '.sscribe-rotated-delete', function () {
				self.deleteRotatedLog($(this).data('file'), $(this));
			});

			this.$entries.on('keydown.sscribe', '.sscribe-debug-entry', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					const $entry = $(this);
					const $context = $entry.find('.sscribe-debug-entry-context');
					if ($context.length) {
						// Toggle .expanded on the parent — the CSS rule
						// .sscribe-debug-entry.expanded .sscribe-debug-entry-context { display: block }
						// handles visibility. No need for .sscribe-hidden on the context element.
						const isExpanded = $entry.hasClass('expanded');
						$entry.toggleClass('expanded', !isExpanded);
						$entry.attr('aria-expanded', String(!isExpanded));
					}
				}
			});

			this.$entries.on('click.sscribe', '.sscribe-debug-entry.has-context', function () {
				const $entry = $(this);
				const $context = $entry.find('.sscribe-debug-entry-context');
				if ($context.length) {
					const isExpanded = $entry.hasClass('expanded');
					$entry.toggleClass('expanded', !isExpanded);
					$entry.attr('aria-expanded', String(!isExpanded));
				}
			});

			this.$entries.on('click.sscribe', '.sscribe-debug-retry-append', function () {
				self.retryAppend();
			});
		},

		bindVisibilityHandler: function () {
			const self = this;
			this._visibilityHandler = function () {
				if (document.hidden) {
					self.stopAutoRefresh();
					self.showPausedIndicator('Paused — tab inactive');
				} else if (self.isAutoRefresh) {
					const $debugTabBtn = $('#sscribe-tab-btn-debug');
					const isOnDebugTab =
						!$debugTabBtn.length || $debugTabBtn.attr('aria-selected') === 'true';
					if (isOnDebugTab) {
						self.fetchLogs();
					}
					// Resume polling regardless of which plugin tab is active —
					// startAutoRefresh() polls in the background and the paused
					// indicator must clear when the tab becomes visible again.
					self.startAutoRefresh();
					self.hidePausedIndicator();
				} else {
					self.hidePausedIndicator();
				}
			};
			$(document).on('visibilitychange', this._visibilityHandler);

			this._beforeUnloadHandler = function () {
				self.stopAutoRefresh();
			};
			window.addEventListener('beforeunload', this._beforeUnloadHandler);

			this._popStateHandler = function (event) {
				if (event.state && event.state.view === 'rotated' && event.state.file) {
					self.viewRotatedLog(event.state.file);
				} else if (self.isViewingRotated) {
					self.backToCurrentLog();
				}
			};
			window.addEventListener('popstate', this._popStateHandler);
		},

		loadInitialState: function () {
			if (!this.$refreshMode || !this.$refreshMode.length) {
				this.isAutoRefresh = true;
				this.fetchLogs();
				return;
			}
			const checkedVal = this.$refreshMode.filter(':checked').val();
			this.isAutoRefresh = checkedVal !== undefined ? checkedVal === 'auto' : true;
			this.currentOffset = 0;
			this.hasMoreEntries = true;
			this.fetchLogs();
			this.updateExportButtonScope();

			const rotatedEl = this.$rotatedDetails && this.$rotatedDetails[0];
			if (rotatedEl && rotatedEl.open) {
				this.fetchRotatedLogs();
			}

			if (this.isAutoRefresh) {
				this.startAutoRefresh();
			}
		},

		startAutoRefresh: function () {
			const self = this;
			this.stopAutoRefresh();
			// Honor a PHP-provided override; fall back to 10000ms to keep
			// behaviour identical if the key is missing (e.g. an older
			// sscribe_data shape from a cached page).
			const refreshMs = Number( sscribe_data && sscribe_data.refresh_interval ) || 10000;
			this.refreshInterval = setInterval(function () {
				// Stale-lock recovery: if a fetch has been pending for >10s
				// (down from 30s — 30s left the user staring at a frozen
				// console for too long), assume the request hung and reset
				// the lock so the next tick can retry. Surface a "slow
				// refresh" notice after 5s so the user knows the console
				// hasn't actually stopped working.
				if (self.isRefreshing && self.isRefreshingSince) {
					const pendingMs = Date.now() - self.isRefreshingSince;
					if (pendingMs > 5000 && !self.slowRefreshNoticeSince) {
						self.slowRefreshNoticeSince = self.isRefreshingSince + 5000;
						self.showPausedIndicator('Refresh taking longer than expected…');
					}
					if (pendingMs > 10000) {
						self.isRefreshing = false;
						self.isRefreshingSince = null;
						self.slowRefreshNoticeSince = null;
					}
				}
				if (self.isRefreshing || self.isLoadingMore || self.isViewingRotated) {
					return;
				}
				if (self.currentOffset !== 0) {
					self.showPausedIndicator('Auto-refresh paused — scrolled into history');
					return;
				}
				self.hidePausedIndicator();
				self.slowRefreshNoticeSince = null;
				self.fetchLogs();
			}, refreshMs);
		},

		stopAutoRefresh: function () {
			if (this.refreshInterval) {
				clearInterval(this.refreshInterval);
				this.refreshInterval = null;
			}
		},

		showPausedIndicator: function (message) {
			if (this.$refreshPaused) {
				this.$refreshPaused.text(message).show();
			}
		},

		hidePausedIndicator: function () {
			if (this.$refreshPaused) {
				this.$refreshPaused.hide();
			}
		},

		getResponseMessage: function (response, fallback) {
			if (response && response.data) {
				if (typeof response.data === 'string' && response.data) {
					return response.data;
				}
				if (response.data.message) {
					return response.data.message;
				}
			}
			return fallback;
		},

		updateExportButtonScope: function () {
			const hasFilter = this.currentFilter !== 'ALL' || this.searchQuery !== '' || this.sessionFilter !== '';
			this.$exportBtn.find('.sscribe-export-btn-scope').text(hasFilter ? ' (filtered)' : ' (all)');
		},

		showConsoleError: function (message) {
			this.$empty.hide();
			this.$entries.html(
				'<div class="sscribe-debug-entry">' +
					'<div class="sscribe-debug-entry-header">' +
					'<span class="sscribe-debug-entry-badge error">ERROR</span>' +
					'<span class="sscribe-debug-entry-message">' +
					escHtml(message) +
					'</span>' +
					'</div>' +
					'</div>'
			);
		},

		saveSettings: function (previousAutoRefresh) {
			const self = this;

			// Guard against missing DOM elements.
			if (!this.$enabled.length || !this.$level.length) {
				return;
			}

			if (this.saveFeedbackTimeout) {
				clearTimeout(this.saveFeedbackTimeout);
				this.saveFeedbackTimeout = null;
			}

			if (this.saveSettingsRequest) {
				this.saveSettingsRequest.abort();
			}
			const sentDebugEnabled = this.$enabled.is(':checked');
			const data = {
				action: 'sscribe_debug_save_settings',
				nonce: sscribe_data.nonce,
				debug_enabled: sentDebugEnabled,
				log_level: this.$level.val(),
				auto_refresh: this.isAutoRefresh ? '1' : '0',
			};

			self.$saveSettings.prop('disabled', true);
			self.$refreshMode.prop('disabled', true);

			this.saveSettingsRequest = $.post(sscribe_data.ajaxurl, data, function (response) {
				self.saveSettingsRequest = null;
				self.$saveSettings.prop('disabled', false);
				self.$refreshMode.prop('disabled', false);
				if (response.success) {
					self.$saveFeedback.removeClass('success error').text('Saved!').addClass('success');
					if (response.data && response.data.nonce) {
						sscribe_data.nonce = response.data.nonce;
					}
					if (
						response.data &&
						response.data.debug_enabled !== undefined &&
						response.data.debug_enabled !== sentDebugEnabled
					) {
						self.stopAutoRefresh();
						if (self.currentRequest) {
							self.currentRequest.abort();
							self.currentRequest = null;
						}
						self.$saveFeedback
							.removeClass('success error')
							.text('Debug mode changed — reloading\u2026')
							.addClass('success');
						self.saveFeedbackTimeout = setTimeout(function () {
							self.saveFeedbackTimeout = null;
							window.location.reload();
						}, 1500);
					} else {
						self.saveFeedbackTimeout = setTimeout(function () {
							self.saveFeedbackTimeout = null;
							self.$saveFeedback.text('');
							self.$saveFeedback.removeClass('success');
						}, 2500);
					}
				} else {
					if (previousAutoRefresh !== undefined) {
						self.isAutoRefresh = previousAutoRefresh;
						const targetValue = previousAutoRefresh ? 'auto' : 'manual';
						self.$refreshMode.filter('[value="' + targetValue + '"]').prop('checked', true);
						if (previousAutoRefresh) {
							self.startAutoRefresh();
						} else {
							self.stopAutoRefresh();
						}
					}
					self.$saveFeedback
						.removeClass('success error')
						.text(self.getResponseMessage(response, 'Error'))
						.addClass('error');
					self.saveFeedbackTimeout = setTimeout(function () {
						self.saveFeedbackTimeout = null;
						self.$saveFeedback.text('');
						self.$saveFeedback.removeClass('error');
					}, 2000);
				}
			}).fail(function (xhr) {
				self.saveSettingsRequest = null;
				self.$saveSettings.prop('disabled', false);
				self.$refreshMode.prop('disabled', false);
				if (xhr.statusText === 'abort') {
					return;
				}
				if (previousAutoRefresh !== undefined) {
					self.isAutoRefresh = previousAutoRefresh;
					const targetValue = previousAutoRefresh ? 'auto' : 'manual';
					self.$refreshMode.filter('[value="' + targetValue + '"]').prop('checked', true);
					if (previousAutoRefresh) {
						self.startAutoRefresh();
					} else {
						self.stopAutoRefresh();
					}
				}
				let errorMsg = 'Error ' + xhr.status;
				if (xhr.status === 0) {
					errorMsg = 'Network error. Please check your connection.';
				} else if (xhr.responseText) {
					let parsed;
					try {
						parsed = JSON.parse(xhr.responseText);
					} catch (_e) {
						parsed = null;
					}
					if (parsed && parsed.data && parsed.data.message) {
						errorMsg = parsed.data.message;
					} else {
						errorMsg += ' (Server error, see console)';
					}
				}
				self.$saveFeedback.text(errorMsg).addClass('error');
				self.saveFeedbackTimeout = setTimeout(function () {
					self.saveFeedbackTimeout = null;
					self.$saveFeedback.text('');
					self.$saveFeedback.removeClass('error');
				}, 2000);
			});
		},

		fetchLogs: function (append) {
			const self = this;
			const isInitialLoad = !append;

			if (this.isRefreshing) {
				return;
			}

			if (isInitialLoad) {
				this.currentOffset = 0;
				this.hasMoreEntries = true;
			}

			if (append && this.isLoadingMore) {
				return;
			}

			if (!this.hasMoreEntries && append) {
				return;
			}

			const data = {
				action: 'sscribe_debug_fetch_logs',
				nonce: sscribe_data.nonce,
				filter_level: this.currentFilter,
				search: this.searchQuery,
				session_id: this.sessionFilter,
				offset: this.currentOffset,
				limit: 200,
			};

			if (isInitialLoad) {
				this.isRefreshing = true;
				this.isRefreshingSince = Date.now();
				self.$entries.css('opacity', '0.5');
				self.$entryCount.text('Loading...');
				self.$consoleBody.addClass('is-loading');
			} else {
				self.isLoadingMore = true;
				self.showAppendLoading();
			}

			if (this.currentRequest) {
				this.currentRequest.abort();
				this.currentRequest = null;
			}
			if (isInitialLoad) {
				self.hideAppendLoading();
			}
			this.currentRequest = $.post(sscribe_data.ajaxurl, data, function (response) {
				self.currentRequest = null;
				self.$entries.css('opacity', '1');
				self.$consoleBody.removeClass('is-loading');
				self.isLoadingMore = false;
				if (isInitialLoad) {
					self.isRefreshing = false;
					self.isRefreshingSince = null;
					self.slowRefreshNoticeSince = null;
				}

				if (response.success) {
					if (!response.data || !Array.isArray(response.data.entries)) {
						self.showConsoleError('Invalid response from server.');
						return;
					}
					const newEntries = response.data.entries;
					const totalCount = response.data.count;

					if (response.data.nonce) {
						sscribe_data.nonce = response.data.nonce;
					}

					if (isInitialLoad) {
						self.renderLogs(newEntries, false, response.data);
					} else {
						self.appendLogs(newEntries);
						self.hideAppendLoading();
						if (newEntries.length === 0) {
							self.hasMoreEntries = false;
							self.destroyObserver();
							return;
						}
					}

					self.currentOffset += newEntries.length;
					self.hasMoreEntries = self.currentOffset < totalCount;
					self.$entryCount.text(1 === totalCount ? '1 entry' : totalCount + ' entries');

					if (!self.hasMoreEntries) {
						self.destroyObserver();
					}
				} else {
					self.destroyObserver();
					self.$entryCount.text('Error');
					self.showConsoleError(self.getResponseMessage(response, 'Unable to load debug logs.'));
				}
			}).fail(function (xhr) {
				if (xhr.statusText === 'abort') {
					self.currentRequest = null;
					self.isLoadingMore = false;
					self.isRefreshing = false;
					self.isRefreshingSince = null;
					self.slowRefreshNoticeSince = null;
					self.$consoleBody.removeClass('is-loading');
					self.hideAppendLoading();
					return;
				}
				self.currentRequest = null;
				self.$entries.css('opacity', '1');
				self.$consoleBody.removeClass('is-loading');
				self.isLoadingMore = false;
				self.isRefreshing = false;
				self.isRefreshingSince = null;
				self.slowRefreshNoticeSince = null;
				self.hideAppendLoading();
				self.destroyObserver();
				if (isInitialLoad) {
					self.$entryCount.text('Error');
					let errorMsg;
					if (xhr.status === 0) {
						errorMsg = 'Network error. Please check your connection.';
					} else if (xhr.status === 403) {
						// Nonce/session expired — the most common cause on long admin
						// sessions. Tell the user clearly so they know to reload.
						errorMsg = 'Session expired. Please reload the page to continue.';
						self.refreshNonce(function () {
							if (isInitialLoad) {
								self.fetchLogs();
							}
						});
					} else {
						errorMsg = 'HTTP ' + xhr.status;
						if (xhr.responseText) {
							try {
								const parsed = JSON.parse(xhr.responseText);
								if (parsed.data && parsed.data.message) {
									errorMsg = parsed.data.message;
								} else {
									errorMsg += ' - ' + xhr.responseText.substring(0, 100);
								}
							} catch (_e) {
								errorMsg += ' (unparseable response)';
							}
						}
					}
					self.showConsoleError(errorMsg);
				} else {
					self.$entries.find('.sscribe-debug-append-error').remove();
					// Audit N-6: on 403 (nonce expired) we must refresh the
					// nonce first, otherwise the Retry button would 403 again
					// for the same reason. The isInitialLoad path above already
					// does this; mirror the same behavior for the append path.
					if (xhr.status === 403) {
						self.refreshNonce(function () {
							self.fetchLogs(true);
						});
						return;
					}
					self.$entries.append(
						'<div class="sscribe-debug-append-error">' +
							'Failed to load more entries. <button type="button" class="sscribe-button sscribe-button-sm sscribe-debug-retry-append">Retry</button>' +
							'</div>'
					);
				}
			});
		},

		retryAppend: function () {
			this.$entries.find('.sscribe-debug-append-error').remove();
			this.isLoadingMore = false;
			this.fetchLogs(true);
		},

		buildLogsHtml: function (entries) {
			let html = '';
			entries.forEach(function (entry) {
				html += buildEntryHtml(entry);
			});
			return html;
		},

		renderLogs: function (entries, skipObserver, extraData, scrollToTop) {
			// Consume the filter-change flag at the very top so an early return
			// for empty entries still clears the pending state — otherwise the
			// next non-filter render would also force-scroll to the top.
			const forceScrollTop = scrollToTop === true || this._filterChangeInProgress === true;
			this._filterChangeInProgress = false;

			if (!entries || entries.length === 0) {
				this.$entries.empty();
				this.$empty.find('p').text(this.defaultEmptyMessage);
				this.$empty.show();
				this.destroyObserver();

				if (extraData) {
					if (extraData.debug_enabled === false) {
						this.$empty
							.find('p')
							.text('Debug logging is disabled. Enable it in Settings above to capture logs.');
					} else if (extraData.status === 'no_log_file' && extraData.debug_enabled) {
						this.$empty
							.find('p')
							.text('Debug is enabled but no log file exists yet. Run an export to generate logs.');
					} else if (extraData.status === 'rotated') {
						this.$empty.find('p').text('This rotated log file is empty.');
					} else {
						this.$empty.find('p').text(this.defaultEmptyMessage);
					}
				} else {
					this.$empty.find('p').text(this.defaultEmptyMessage);
				}
				return;
			}

			this.$empty.hide();
			this.$empty.find('p').text(this.defaultEmptyMessage);
			this.cleanupBeforeRender();

			// Preserve scroll position during auto-refresh updates. The
			// filter change path (forceScrollTop) scrolls to the top of the
			// (now-shorter) entry list instead of carrying the old
			// "wasAtBottom" heuristic over — a smaller filtered list would
			// otherwise jump to the bottom of content the user never scrolled
			// into.
			const consoleBody = this.$consoleBody && this.$consoleBody[0];

			let scrollTop = 0;
			let wasAtBottom = false;
			if (consoleBody) {
				scrollTop = consoleBody.scrollTop;
				wasAtBottom = consoleBody.scrollHeight - consoleBody.scrollTop - consoleBody.clientHeight < 50;
			}

			this.$entries.html(this.buildLogsHtml(entries));

			if (consoleBody) {
				if (forceScrollTop) {
					consoleBody.scrollTop = 0;
				} else if (wasAtBottom) {
					consoleBody.scrollTop = consoleBody.scrollHeight;
				} else {
					consoleBody.scrollTop = scrollTop;
				}
			}

			if (!skipObserver) {
				this.setupObserver();
			}
		},

		cleanupBeforeRender: function () {
			this.isLoadingMore = false;
			this.hideAppendLoading();
			this.destroyObserver();
		},

		appendLogs: function (entries) {
			if (!entries || entries.length === 0) {
				return;
			}

			this.destroyObserver();

			const html = this.buildLogsHtml(entries);
			this.$entries.append(html);

			this.setupObserver();
		},

		showAppendLoading: function () {
			this.$entries.append('<div class="sscribe-debug-append-loading">Loading more entries...</div>');
		},

		hideAppendLoading: function () {
			this.$entries.find('.sscribe-debug-append-loading').remove();
		},

		setupObserver: function () {
			if (!this.hasMoreEntries) {
				return;
			}
			if (!this.$consoleBody || !this.$consoleBody[0]) {
				return;
			}

			const self = this;
			const sentinel = document.createElement('div');
			sentinel.id = 'sscribe-infinite-scroll-sentinel';
			sentinel.style.height = '1px';
			sentinel.style.width = '100%';
			this.$entries.find('#sscribe-infinite-scroll-sentinel').remove();
			this.$entries.append(sentinel);

			this.observer = new IntersectionObserver(
				function (entries) {
					if (entries[0].isIntersecting && !self.isLoadingMore && self.hasMoreEntries) {
						self.fetchLogs(true);
					}
				},
				{ root: this.$consoleBody[0], rootMargin: '50px', threshold: 0 }
			);

			this.observer.observe(sentinel);
		},

		destroyObserver: function () {
			if (this.observer) {
				this.observer.disconnect();
				this.observer = null;
			}
			this.$entries.find('#sscribe-infinite-scroll-sentinel').remove();
		},

		clearLogs: function () {
			const self = this;
			const data = {
				action: 'sscribe_debug_clear_logs',
				nonce: sscribe_data.nonce,
			};

			self.$clearBtn.prop('disabled', true);

			$.post(sscribe_data.ajaxurl, data, function (response) {
				self.$clearBtn.prop('disabled', false);
				self.$clearBtn.siblings('.sscribe-feedback').remove();
				self.$clearBtn.data('confirming', false).removeClass('sscribe-btn-confirming');
				if (response.success) {
					if (response.data && response.data.nonce) {
						sscribe_data.nonce = response.data.nonce;
					}
					const originalText = self.$clearBtn.data('original-text') || self.clearBtnOriginalText;
					self.$clearBtn.text(originalText);
					self.$clearBtn.after('<span class="sscribe-feedback sscribe-feedback-success">Cleared!</span>');
					setTimeout(function () {
						self.$clearBtn.siblings('.sscribe-feedback').remove();
					}, 2000);
					self.destroyObserver();
					self.fetchLogs();
				} else {
					const originalText = self.$clearBtn.data('original-text') || self.clearBtnOriginalText;
					self.$clearBtn.text(originalText);
					self.$clearBtn.data('confirming', false).removeClass('sscribe-btn-confirming');
					self.$clearBtn.after(
						'<span class="sscribe-feedback sscribe-feedback-error">' +
							escHtml(self.getResponseMessage(response, 'Error')) +
							'</span>'
					);
					setTimeout(function () {
						self.$clearBtn.siblings('.sscribe-feedback').remove();
					}, 2000);
				}
			}).fail(function (xhr) {
				const originalText = self.$clearBtn.data('original-text') || self.clearBtnOriginalText;
				self.$clearBtn.prop('disabled', false).text(originalText);
				self.$clearBtn.data('confirming', false).removeClass('sscribe-btn-confirming');
				let errorMsg = 'Error';
				if (xhr && xhr.status === 0) {
					errorMsg = 'Network error. Please check your connection.';
				} else if (xhr && xhr.status === 403) {
					errorMsg = 'Session expired. Please reload the page to continue.';
				} else if (xhr && xhr.status) {
					errorMsg = 'HTTP ' + xhr.status;
				}
				self.$clearBtn.after('<span class="sscribe-feedback sscribe-feedback-error">' + escHtml(errorMsg) + '</span>');
				setTimeout(function () {
					self.$clearBtn.siblings('.sscribe-feedback').remove();
				}, 2000);
				// Attempt to refresh nonce on failure.
				self.refreshNonce();
			});
		},

		refreshNonce: function (retryAction) {
			const self = this;
			$.post(
				sscribe_data.ajaxurl,
				{ action: 'sscribe_debug_refresh_nonce', nonce: sscribe_data.nonce },
				function (response) {
					if (response && response.success && response.data && response.data.nonce) {
						sscribe_data.nonce = response.data.nonce;
						if (typeof retryAction === 'function') {
							retryAction();
						}
					}
				}
			).fail(function () {
				self.showPausedIndicator('Nonce refresh failed — you may need to reload the page.');
			});
		},

		downloadViaForm: function (url, data) {
			const form = document.createElement('form');
			form.method = 'POST';
			form.action = url;
			form.target = '_blank';
			form.style.display = 'none';
			Object.keys(data).forEach(function (key) {
				const input = document.createElement('input');
				input.type = 'hidden';
				input.name = key;
				input.value = data[key];
				form.appendChild(input);
			});
			document.body.appendChild(form);
			form.submit();
			setTimeout(function () {
				if (form.parentNode) {
					form.remove();
				}
			}, 100);
		},

		/**
		 * Trigger a download using fetch + blob.
		 *
		 * Unlike downloadViaForm() (which uses a hidden <form> with target="_blank"),
		 * this approach lets us detect server-side failures (HTTP 4xx/5xx, fatal
		 * errors, nonces) and surface them to the user instead of silently opening
		 * a blank tab. We rely on response.ok to distinguish success from error.
		 *
		 * Filename is extracted from the Content-Disposition response header when
		 * present, falling back to the caller-supplied default.
		 *
		 * @param {string} url         Endpoint URL (e.g. admin-ajax.php).
		 * @param {Object} data        POST payload (form-urlencoded).
		 * @param {Object} callbacks   onSuccess(filename), onError(message).
		 */
		downloadViaFetch: function (url, data, callbacks) {
			const self = this;
			callbacks = callbacks || {};
			const onSuccess = typeof callbacks.onSuccess === 'function' ? callbacks.onSuccess : function () {};
			const onError = typeof callbacks.onError === 'function' ? callbacks.onError : function () {};

			const body = new URLSearchParams();
			Object.keys(data).forEach(function (key) {
				body.append(key, data[key]);
			});

			fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
					'X-Requested-With': 'XMLHttpRequest',
				},
				body: body.toString(),
			})
				.then(function (response) {
					// Extract filename from Content-Disposition before consuming body.
					let filename = '';
					const disposition = response.headers.get('Content-Disposition') || '';
					const match = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
					if (match && match[1]) {
						try {
							filename = decodeURIComponent(match[1]);
						} catch (_e) {
							filename = match[1];
						}
					}

					if (!response.ok) {
						// Try to parse the error JSON so we can show a useful message.
						return response.text().then(function (text) {
							let message = 'HTTP ' + response.status;
							try {
								const parsed = JSON.parse(text);
								if (parsed && parsed.data && parsed.data.message) {
									message = parsed.data.message;
								} else if (text) {
									message += ' - ' + text.substring(0, 200);
								}
							} catch (_e) {
								if (text) {
									message += ' - ' + text.substring(0, 200);
								}
							}
							throw new Error(message);
						});
					}

					return response.blob().then(function (blob) {
						if (!blob || blob.size === 0) {
							throw new Error('Empty response from server.');
						}
						const blobUrl = URL.createObjectURL(blob);
						const a = document.createElement('a');
						a.href = blobUrl;
						a.download = filename || 'export.json';
						a.style.display = 'none';
						document.body.appendChild(a);
						a.click();
						// Defer cleanup so the browser has time to start the download.
						setTimeout(function () {
							if (a.parentNode) {
								a.remove();
							}
							URL.revokeObjectURL(blobUrl);
						}, 100);
						onSuccess(filename);
					});
				})
				.catch(function (err) {
					const message = err && err.message ? err.message : 'Unknown error';
					if (self && typeof self.showPausedIndicator === 'function') {
						self.showPausedIndicator('Export failed: ' + message);
					}
					onError(message);
				});
		},

		exportLogs: function () {
			const self = this;
			if (!this.hasRequiredDom() || !sscribe_data || !sscribe_data.nonce) {
				return;
			}

			// Stop auto-refresh during the nonce refresh + form POST so a
			// concurrent fetchLogs() tick cannot overwrite sscribe_data.nonce
			// between this refresh response and the export form consuming it.
			const wasAutoRefresh = this.isAutoRefresh;
			if (wasAutoRefresh) {
				this.stopAutoRefresh();
			}

			const resumeAutoRefresh = function () {
				if (wasAutoRefresh) {
					setTimeout(function () {
						self.startAutoRefresh();
					}, 0);
				}
			};

			$.post(
				sscribe_data.ajaxurl,
				{ action: 'sscribe_debug_refresh_nonce', nonce: sscribe_data.nonce },
				function (response) {
					if (response && response.success && response.data && response.data.nonce) {
						sscribe_data.nonce = response.data.nonce;
					}
					self.doExportLogs();
					resumeAutoRefresh();
				}
			).fail(function () {
				self.showPausedIndicator('Nonce refresh failed — attempting export anyway.');
				self.doExportLogs();
				resumeAutoRefresh();
			});
		},

		doExportLogs: function () {
			const self = this;
			const data = {
				action: 'sscribe_debug_export_logs',
				nonce: sscribe_data.nonce,
				filter_level: this.currentFilter,
				search: this.searchQuery,
				session_id: this.sessionFilter,
			};
			// When the user is viewing a rotated log, the export POST must
			// include the filename so the server returns THAT file, not
			// a fresh export built from the current filter state.
			if (this.isViewingRotated && this.currentRotatedFilename) {
				data.filename = this.currentRotatedFilename;
			}

			self.$exportBtn.prop('disabled', true);
			self.$exportBtn.find('.sscribe-export-btn-scope').text(' — exporting…');
			this.showPausedIndicator('Export in progress — download should begin shortly');
			// fetch+blob (not form POST) so server errors surface to the user.
			// Audit N-5: form POST opened a blank tab on every failure mode.
			this.downloadViaFetch(sscribe_data.ajaxurl, data, {
				onSuccess: function () {
					self.$exportBtn.prop('disabled', false);
					self.updateExportButtonScope();
					self.hidePausedIndicator();
				},
				onError: function () {
					// downloadViaFetch already shows the error; just restore the
					// button so the user can retry without waiting for a timer.
					self.$exportBtn.prop('disabled', false);
					self.updateExportButtonScope();
					self.hidePausedIndicator();
				},
			});
		},

		fetchRotatedLogs: function () {
			const self = this;
			const data = {
				action: 'sscribe_debug_get_files',
				nonce: sscribe_data.nonce,
			};

			if (this.rotatedRequest) {
				this.rotatedRequest.abort();
			}

			// Show a loading hint immediately so the panel isn't blank
			// while the AJAX is in flight; the success / fail handlers
			// below replace this with the actual file list.
			this.$rotatedBody.html(
				'<div class="sscribe-debug-rotated-empty sscribe-debug-rotated-loading">' +
					escHtml('Loading rotated logs...') +
					'</div>'
			);

			this.rotatedRequest = $.post(sscribe_data.ajaxurl, data, function (response) {
				self.rotatedRequest = null;
				if (response.success && response.data && Array.isArray(response.data.files)) {
					self.renderRotatedLogs(response.data.files);
				} else {
					self.$rotatedBody.html(
						'<div class="sscribe-debug-rotated-empty">' +
							escHtml(self.getResponseMessage(response, 'Unable to load rotated logs.')) +
							'</div>'
					);
				}
			}).fail(function (xhr) {
				if (xhr.statusText === 'abort') {
					return;
				}
				self.rotatedRequest = null;
				let errMsg = 'Unable to load rotated logs.';
				if (xhr.status === 0) {
					errMsg = 'Network error — could not load rotated logs.';
				}
				self.$rotatedBody.html('<div class="sscribe-debug-rotated-empty">' + escHtml(errMsg) + '</div>');
			});
		},

		renderRotatedLogs: function (files) {
			if (!files || files.length === 0) {
				this.$rotatedBody.html('<div class="sscribe-debug-rotated-empty">No rotated log files.</div>');
				return;
			}

			let html = '';

			files.forEach(function (file) {
				html += '<div class="sscribe-debug-rotated-file">';
				html += '<div class="sscribe-debug-rotated-file-info">';
				html += '<span class="sscribe-debug-rotated-file-name">' + escHtml(file.name) + '</span>';
				html +=
					'<span class="sscribe-debug-rotated-file-meta">' +
					escHtml(file.size) +
					' - ' +
					escHtml(file.date) +
					'</span>';
				html += '</div>';
				html += '<div class="sscribe-debug-rotated-file-actions">';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-outline sscribe-rotated-view" data-file="' +
					escAttr(file.name) +
					'" aria-label="View rotated log ' +
					escAttr(file.name) +
					'">View</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-secondary sscribe-rotated-export" data-file="' +
					escAttr(file.name) +
					'" aria-label="Export rotated log ' +
					escAttr(file.name) +
					'">Export</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-danger sscribe-rotated-delete" data-file="' +
					escAttr(file.name) +
					'" aria-label="Delete rotated log ' +
					escAttr(file.name) +
					'">Delete</button>';
				html += '</div></div>';
			});

			this.$rotatedBody.html(html);
		},

		viewRotatedLog: function (filename) {
			const self = this;

			// Cancel any pending "Exporting rotated log..." auto-hide so the
			// indicator from a just-completed export doesn't get pulled out
			// from under the rotated view the user is now looking at.
			if (this.exportHintTimeout) {
				clearTimeout(this.exportHintTimeout);
				this.exportHintTimeout = null;
			}

			if (this.viewRotatedRequest) {
				this.viewRotatedRequest.abort();
			}

			const data = {
				action: 'sscribe_debug_fetch_rotated',
				nonce: sscribe_data.nonce,
				filename: filename,
			};

			this.hasMoreEntries = false;
			this.destroyObserver();
			// Reset isRefreshing so that auto-refresh can resume when the user
			// goes back to the current log (isRefreshing blocks startAutoRefresh).
			this.isRefreshing = false;
			this.isRefreshingSince = null;

			this.viewRotatedRequest = $.post(sscribe_data.ajaxurl, data, function (response) {
				self.viewRotatedRequest = null;
				if (response.success) {
					self.isViewingRotated = true;
					self.currentRotatedFilename = filename;
					const url = new URL(window.location.href);
					url.searchParams.set('view', 'rotated');
					url.searchParams.set('file', filename);
					const newState = { view: 'rotated', file: filename };
					// Rapid clicks on the same file would otherwise stack duplicate
					// history entries (the second click aborts the first request,
					// but the first's success handler never runs so only the
					// second pushState lands — repeated n times = n entries that
					// all point at the same view). If we're already on this exact
					// state, replace the current entry instead of stacking a new
					// one.
					if (
						history.state &&
						history.state.view === newState.view &&
						history.state.file === newState.file
					) {
						history.replaceState(newState, '', url.toString());
					} else {
						history.pushState(newState, '', url.toString());
					}
					self.$entries.find('.sscribe-debug-rotated-banner').remove();
					const entries = Array.isArray(response.data.entries) ? response.data.entries : [];
					const parsedCount = parseInt(response.data.count, 10);
					const rotatedCount = !isNaN(parsedCount) && parsedCount > 0 ? parsedCount : entries.length;
					self.renderLogs(entries, true, { status: 'rotated', count: rotatedCount }, true);
					self.$entryCount.text((1 === rotatedCount ? '1 entry' : rotatedCount + ' entries') + ' (rotated)');
					self.$entries.prepend(
						'<div class="sscribe-debug-rotated-banner">' +
							'<span>Viewing archived log: ' +
							escHtml(filename) +
							'</span>' +
							'<button type="button" class="sscribe-button sscribe-button-primary" id="sscribe-back-to-current">Back to current log</button>' +
							'</div>'
					);
					self.$entries
						.find('#sscribe-back-to-current')
						.off('click.sscribe')
						.on('click.sscribe', function () {
							self.backToCurrentLog();
						});
				} else {
					self.$entryCount.text('Error');
					self.showConsoleError(self.getResponseMessage(response, 'Unable to open rotated log.'));
				}
			}).fail(function (xhr) {
				if (xhr.statusText === 'abort') {
					return;
				}
				self.viewRotatedRequest = null;
				self.isViewingRotated = false;
				self.$entryCount.text('Error');
				self.showConsoleError('Unable to open rotated log.');
				// Clean URL state on failure.
				const cleanUrl = new URL(window.location.href);
				cleanUrl.searchParams.delete('view');
				cleanUrl.searchParams.delete('file');
				history.replaceState({}, '', cleanUrl.toString());
				setTimeout(function () {
					self.fetchLogs();
				}, 2000);
			});
		},

		backToCurrentLog: function () {
			// Abort any in-flight rotated log request.
			if (this.viewRotatedRequest) {
				this.viewRotatedRequest.abort();
				this.viewRotatedRequest = null;
			}
			// Cancel any pending export hint auto-hide from a previous
			// exportRotatedLog() call so the indicator doesn't get yanked
			// out mid-navigation.
			if (this.exportHintTimeout) {
				clearTimeout(this.exportHintTimeout);
				this.exportHintTimeout = null;
			}
			this.isViewingRotated = false;
			this.currentRotatedFilename = '';
			this.hasMoreEntries = true;
			this.currentOffset = 0;
			this.isLoadingMore = false;
			this.isRefreshing = false;
			this.isRefreshingSince = null;
			this.slowRefreshNoticeSince = null;
			this.$entries.empty();
			this.$entryCount.text('Loading...');
			// fetchLogs() will add the is-loading class on the initial-load
			// path and remove it in its success/fail handlers — adding it
			// here as well is redundant and would survive a fetch abort.
			const url = new URL(window.location.href);
			url.searchParams.delete('view');
			url.searchParams.delete('file');
			history.replaceState({}, '', url.toString());
			this.fetchLogs();
			if (this.isAutoRefresh) {
				this.startAutoRefresh();
				this.hidePausedIndicator();
			}
		},

		exportRotatedLog: function (filename, $btn) {
			const self = this;
			const data = {
				action: 'sscribe_debug_export_logs',
				nonce: sscribe_data.nonce,
				filename: filename,
			};

			// Bump the export button out of the way and surface a status hint.
			// Both timeouts (button re-enable + indicator hide) are aligned to
			// 5s — the previous 3s/5s split left a 2-second window with no
			// status message while the button was still disabled, which
			// confused users (audit #2). Use a single constant so the two
			// values cannot drift apart again.
			const ROTATED_EXPORT_TIMEOUT_MS = 5000;

			const restoreBtn = function () {
				if ($btn) {
					$btn.prop('disabled', false).text('Export');
				}
			};
			if ($btn) {
				$btn.prop('disabled', true).text('Downloading...');
			}

			self.showPausedIndicator('Exporting rotated log...');
			// fetch+blob so server errors (file not found, nonce expiry,
			// permission denied) are reported instead of opening a blank tab.
			this.downloadViaFetch(sscribe_data.ajaxurl, data, {
				onSuccess: function () {
					restoreBtn();
					self.hidePausedIndicator();
					if (self.exportHintTimeout) {
						clearTimeout(self.exportHintTimeout);
						self.exportHintTimeout = null;
					}
				},
				onError: function () {
					restoreBtn();
					// downloadViaFetch already surfaces the error to the
					// paused indicator; just make sure the hint hides.
					if (self.exportHintTimeout) {
						clearTimeout(self.exportHintTimeout);
					}
					self.exportHintTimeout = setTimeout(function () {
						self.hidePausedIndicator();
						self.exportHintTimeout = null;
					}, ROTATED_EXPORT_TIMEOUT_MS);
				},
			});
		},

		deleteRotatedLog: function (filename, $btn) {
			const self = this;

			if (!$btn) {
				return;
			}

			if ($btn.data('confirming')) {
				const pendingTimeout = $btn.data('delete-timeout');
				if (pendingTimeout) {
					clearTimeout(pendingTimeout);
					$btn.removeData('delete-timeout');
				}
				const originalText = $btn.data('original-text') || 'Delete';
				$btn.data('confirming', false).removeClass('sscribe-btn-confirming').text(originalText);
				$btn.prop('disabled', true);
				self._executeDeleteRotatedLog(filename, $btn);
				return;
			}

			if (!$btn.data('original-text')) {
				$btn.data('original-text', $btn.text());
			}
			$btn.data('confirming', true).addClass('sscribe-btn-confirming').text('Click to confirm');
			const revertTimeout = setTimeout(function () {
				$btn.removeData('delete-timeout');
				if ($btn.data('confirming')) {
					const originalText = $btn.data('original-text') || 'Delete';
					$btn.data('confirming', false).removeClass('sscribe-btn-confirming').text(originalText);
					$btn.prop('disabled', false);
				}
			}, 3000);
			$btn.data('delete-timeout', revertTimeout);
		},

		_executeDeleteRotatedLog: function (filename, $btn) {
			const self = this;
			const data = {
				action: 'sscribe_debug_delete_rotated',
				nonce: sscribe_data.nonce,
				filename: filename,
			};

			if ($btn) {
				$btn.prop('disabled', true).text('Deleting...');
			}

			$.post(sscribe_data.ajaxurl, data, function (response) {
				if ($btn) {
					const originalText = $btn.data('original-text') || 'Delete';
					$btn.prop('disabled', false).text(originalText);
				}
				if (response.success) {
					if (response.data && response.data.nonce) {
						sscribe_data.nonce = response.data.nonce;
					}
					self.fetchRotatedLogs();
				} else {
					if ($btn) {
						const $row = $btn.closest('.sscribe-debug-rotated-file');
						if ($row.length) {
							$row.find('.sscribe-debug-rotated-file-actions').after(
								'<div class="sscribe-rotated-error">' +
									escHtml(self.getResponseMessage(response, 'Error')) +
									'</div>'
							);
							setTimeout(function () {
								$row.find('.sscribe-rotated-error').remove();
							}, 3000);
						}
					}
				}
			}).fail(function (xhr) {
				if (xhr && xhr.status === 403) {
					self.refreshNonce();
				}
				if ($btn) {
					const originalText = $btn.data('original-text') || 'Delete';
					$btn.prop('disabled', false).text(originalText);
					const $row = $btn.closest('.sscribe-debug-rotated-file');
					if ($row.length) {
						$row.find('.sscribe-debug-rotated-file-actions').after(
							'<div class="sscribe-rotated-error">Error</div>'
						);
						setTimeout(function () {
							$row.find('.sscribe-rotated-error').remove();
						}, 3000);
					}
				}
			});
		},
	};

	function buildEntryHtml(entry) {
		const allowedLevels = [
			'all',
			'debug',
			'info',
			'notice',
			'warning',
			'error',
			'critical',
			'alert',
			'emergency',
			'raw',
		];
		const entryLevel = entry.level && typeof entry.level === 'string' ? entry.level.toLowerCase() : 'info';
		const badgeClass = allowedLevels.includes(entryLevel) ? entryLevel : 'info';
		let contextHtml = '';

		if (entry.context && Object.keys(entry.context).length > 0) {
			let contextRows = '';
			Object.keys(entry.context).forEach(function (key) {
				let value = entry.context[key];
				if (typeof value === 'object') {
					try {
						value = JSON.stringify(value, null, 2);
						if (typeof value === 'string' && value.length > 5000) {
							value = value.substring(0, 5000) + '\n... [truncated]';
						}
					} catch (e) {
						const detail = e && e.message ? e.message : (typeof value);
						value = '[unserializable: ' + detail + ']';
					}
				}
				contextRows +=
					'<div class="sscribe-debug-context-row">' +
					'<span class="sscribe-debug-context-key">' +
					escHtml(String(key)) +
					'</span>' +
					'<div class="sscribe-debug-context-val"><pre>' +
					escHtml(String(value)) +
					'</pre></div>' +
					'</div>';
			});
			contextHtml = '<div class="sscribe-debug-entry-context">' + contextRows + '</div>';
		}

		const hasContext = contextHtml !== '';

		return (
			'<div class="sscribe-debug-entry' +
			(hasContext ? ' has-context' : '') +
			'"' +
			(hasContext
				? ' tabindex="0" role="button" aria-expanded="false" aria-label="Toggle context for: ' +
					escAttr(truncateForAriaLabel(String(entry.message || ''), 50)) +
					'"'
				: '') +
			'>' +
			'<div class="sscribe-debug-entry-header">' +
			'<span class="sscribe-debug-entry-badge ' +
			escAttr(badgeClass) +
			'">' +
			// Normalize display to uppercase to match the class. The
			// server can return 'warning' / 'Warning' / 'WARNING' depending
			// on source; the badge class is already lowercased, so the
			// text needs explicit normalization to stay consistent.
			escHtml((entry.level || 'INFO').toUpperCase()) +
			'</span>' +
			'<span class="sscribe-debug-entry-time">' +
			escHtml(formatLocalTimestamp(entry.timestamp)) +
			'</span>' +
			'<span class="sscribe-debug-entry-message">' +
			escHtml(entry.message || '') +
			'</span>' +
			(hasContext ? '<span class="sscribe-debug-entry-toggle" aria-hidden="true">\u25B6</span>' : '') +
			'</div>' +
			contextHtml +
			'</div>'
		);
	}

	window.SScribeDebugConsole = SScribeDebugConsole;

	jQuery(document).ready(function () {
		if (window.SScribeDebugConsole) {
			window.SScribeDebugConsole.init();
		}
	});
})(jQuery);
