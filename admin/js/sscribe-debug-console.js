/**
 * SScribe Debug Console JavaScript
 *
 * @package SScribe_Export_Site_Pages
 * @version 2.0.0
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
	function formatLocalTimestamp(raw) {
		if (!raw || typeof raw !== 'string') {
			return '';
		}
		const m = raw.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/);
		if (!m) {
			return raw;
		}
		const d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
		if (isNaN(d.getTime())) {
			return raw;
		}
		try {
			return d.toLocaleString();
		} catch (_e) {
			return raw;
		}
	}
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
		slowRefreshNoticeSince: null,
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
			this.$staleBanner = $('#sscribe-debug-stale-banner');
			this.$staleBannerMessage = $('#sscribe-debug-stale-banner-message');
			this.$enableAndClearBtn = $('#sscribe-debug-enable-and-clear');
			this.$emptyEnableBtn = $('#sscribe-debug-empty-enable');
		},
		unbindEvents: function () {
			this.$saveSettings.off('.sscribe');
			this.$enabled.off('.sscribe');
			this.$level.off('.sscribe');
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
		markSettingsDirty: function () {
			if (!this.$saveSettings || !this.$saveSettings.length) {
				return;
			}
			this.$saveSettings.addClass('sscribe-button-dirty');
			this.$saveFeedback.removeClass('success error').text('Unsaved changes');
		},
		clearSettingsDirty: function () {
			if (!this.$saveSettings || !this.$saveSettings.length) {
				return;
			}
			this.$saveSettings.removeClass('sscribe-button-dirty');
		},
		unbindVisibilityHandler: function () {
			if (this._visibilityHandler) {
				document.removeEventListener('visibilitychange', this._visibilityHandler);
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
			this.$enabled.on('change.sscribe', function () {
				// Phase 10: capture the previous debug-enabled state at
				// the moment the user toggles, BEFORE the checkbox flips.
				// The save handler uses this on failure to roll the UI
				// back, so the user never sees a checkbox state that the
				// server has rejected.
				self._previousDebugEnabled = !self.$enabled.is(':checked');
				self.$enabled.attr('aria-checked', self.$enabled.is(':checked') ? 'true' : 'false');
				self.markSettingsDirty();
			});
			this.$level.on('change.sscribe', function () {
				// Phase 10: mirror the capture for log_level so rollback
				// on save failure is symmetric with debug_enabled.
				self._previousLogLevel = self.$level.val();
				self.markSettingsDirty();
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
					self.showPausedIndicator('Manual mode : auto-refresh off');
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
					if (!$btn.data('original-text')) {
						$btn.data('original-text', $btn.text());
					}
					const n = self.lastEntryCount || 0;
					let confirmLabel;
					if (n <= 0) {
						confirmLabel = self.clearBtnOriginalText;
					} else if (n === 1) {
						confirmLabel = 'Clear 1 log entry';
					} else {
						confirmLabel = 'Clear ' + n + ' log entries';
					}
					$btn.data('confirming', true)
						.addClass('sscribe-btn-confirming')
						.text(confirmLabel)
						.attr('aria-label', confirmLabel);
					self.clearBtnTimeout = setTimeout(function () {
						if (self.$clearBtn) {
							self.$clearBtn
								.data('confirming', false)
								.removeClass('sscribe-btn-confirming')
								.text(self.$clearBtn.data('original-text') || self.clearBtnOriginalText);
							self.$clearBtn.removeData('original-text');
						}
						self.clearBtnTimeout = null;
					}, 3000);
				}
			});
			if (this.$enableAndClearBtn && this.$enableAndClearBtn.length) {
				this.$enableAndClearBtn.on('click.sscribe', function () {
					if (self.$enabled && !self.$enabled.is(':checked')) {
						self.$enabled.prop('checked', true).attr('aria-checked', 'true').trigger('change');
					}
					if (self.$staleBanner && self.$staleBanner.length) {
						self.$staleBanner.addClass('sscribe-hidden').attr('hidden', true);
					}
					if (self.$saveFeedback && self.$saveFeedback.length) {
						self.$saveFeedback.removeClass('error').text('Saved');
					}
					self.saveSettings(self.isAutoRefresh);
				});
			}
			if (this.$emptyEnableBtn && this.$emptyEnableBtn.length) {
				this.$emptyEnableBtn.on('click.sscribe', function () {
					if (self.$enabled && !self.$enabled.is(':checked')) {
						self.$enabled.prop('checked', true).attr('aria-checked', 'true').trigger('change');
					}
					self.saveSettings(self.isAutoRefresh);
				});
			}
			this.$container.on('click.sscribe', '#sscribe-debug-help-btn', function () {
				const helpContent = self.$helpContent && self.$helpContent[0];
				const helpBtn = document.getElementById('sscribe-debug-help-btn');
				if (helpContent) {
					const overlay = document.createElement('div');
					overlay.className = 'sscribe-modal';
					overlay.setAttribute('role', 'dialog');
					overlay.setAttribute('aria-modal', 'true');
					overlay.setAttribute('aria-labelledby', 'sscribe-debug-help-title');
					overlay.setAttribute('aria-hidden', 'false');
					const dialog = document.createElement('div');
					dialog.className = 'sscribe-modal-content';
					dialog.setAttribute('role', 'document');
					dialog.setAttribute('tabindex', '-1');
					const helpClone = helpContent.cloneNode(true);
					if (helpClone && helpClone.removeAttribute) {
						helpClone.removeAttribute('hidden');
					}
					const priorFocus = document.activeElement;
					const focusableSelectors =
						'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
					if (helpBtn && helpBtn.setAttribute) {
						helpBtn.setAttribute('aria-expanded', 'true');
					}
					const closeDialog = function () {
						if (document.body.contains(overlay)) {
							document.body.removeChild(overlay);
						}
						document.removeEventListener('keydown', keyHandler);
						if (helpBtn && helpBtn.setAttribute) {
							helpBtn.setAttribute('aria-expanded', 'false');
						}
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
							const focusableElements = Array.from(overlay.querySelectorAll(focusableSelectors));
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
					closeBtn.className = 'sscribe-modal-close';
					closeBtn.innerHTML = '<span aria-hidden="true">&times;</span>';
					closeBtn.setAttribute(
						'aria-label',
						(sscribe_data.strings && sscribe_data.strings.close) || 'Close dialog'
					);
					closeBtn.addEventListener('click', closeDialog);
					const header = document.createElement('div');
					header.className = 'sscribe-modal-header';
					const clonedTitle = helpClone.querySelector('#sscribe-debug-help-title');
					const titleText = clonedTitle ? clonedTitle.textContent : 'Help';
					const headerTitle = document.createElement('h3');
					headerTitle.id = 'sscribe-debug-help-title';
					headerTitle.textContent = titleText;
					header.appendChild(headerTitle);
					header.appendChild(closeBtn);
					const body = document.createElement('div');
					body.className = 'sscribe-modal-body';
					const clonedHelpBody = helpClone.querySelectorAll(':scope > h3, :scope > h4, :scope > p');
					clonedHelpBody.forEach(function (node) {
						body.appendChild(node.cloneNode(true));
					});
					dialog.appendChild(header);
					dialog.appendChild(body);
					overlay.appendChild(dialog);
					overlay.addEventListener('click', function (e) {
						if (e.target === overlay) {
							closeDialog();
						}
					});
					document.body.appendChild(overlay);
					document.addEventListener('keydown', keyHandler);
					const focusableElements = Array.from(overlay.querySelectorAll(focusableSelectors));
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
			this.$entries.on('click.sscribe', '.sscribe-debug-retry-append', function () {
				self.retryAppend();
			});
		},
		bindVisibilityHandler: function () {
			const self = this;
			this.unbindVisibilityHandler();
			this._visibilityHandler = function () {
				if (document.hidden) {
					self.stopAutoRefresh();
					self.showPausedIndicator('Paused : tab inactive');
				} else if (self.isAutoRefresh) {
					const $debugTabBtn = $('#sscribe-tab-btn-debug');
					const isOnDebugTab = !$debugTabBtn.length || $debugTabBtn.attr('aria-selected') === 'true';
					if (isOnDebugTab) {
						self.fetchLogs();
					}
					self.startAutoRefresh();
					self.hidePausedIndicator();
				} else {
					self.hidePausedIndicator();
				}
			};
			document.addEventListener('visibilitychange', this._visibilityHandler);
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
			this.lastEntryCount = 0;
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
			const refreshMs = Number(sscribe_data && sscribe_data.refresh_interval) || 10000;
			const hiddenMultiplier = 6;
			let effectiveRefreshMs = refreshMs;
			if (document.visibilityState === 'hidden') {
				effectiveRefreshMs = refreshMs * hiddenMultiplier;
			}
			this.consecutiveNoChange = 0;
			this.noChangeStopThreshold = 5;
			this.visibilityHandler = function () {
				if (document.visibilityState !== 'hidden') {
					self.consecutiveNoChange = 0;
					self.stopAutoRefresh();
					self.startAutoRefresh();
				}
				// When hidden, _visibilityHandler owns pausing and shows the
				// "Paused: tab inactive" banner; restarting here would fight
				// it and silently keep polling in the background.
			};
			document.addEventListener('visibilitychange', this.visibilityHandler);
			this.refreshInterval = setInterval(function () {
				if (self.isRefreshing && self.isRefreshingSince) {
					const pendingMs = Date.now() - self.isRefreshingSince;
					if (pendingMs > 5000 && !self.slowRefreshNoticeSince) {
						self.slowRefreshNoticeSince = self.isRefreshingSince + 5000;
						self.showPausedIndicator('Refresh taking longer than expected...');
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
					self.showPausedIndicator('Auto-refresh paused : scrolled into history');
					return;
				}
				if (self.consecutiveNoChange >= self.noChangeStopThreshold) {
					self.showPausedIndicator('Auto-refresh paused : no new log entries');
					return;
				}
				self.hidePausedIndicator();
				self.slowRefreshNoticeSince = null;
				self.fetchLogs();
			}, effectiveRefreshMs);
		},
		stopAutoRefresh: function () {
			if (this.refreshInterval) {
				clearInterval(this.refreshInterval);
				this.refreshInterval = null;
			}
			if (this.visibilityHandler) {
				document.removeEventListener('visibilitychange', this.visibilityHandler);
				this.visibilityHandler = null;
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
		/**
		 * Phase 11 helper: single source of truth for whether any
		 * debug-log filter is active. Used by renderLogs to distinguish
		 * the no_entries (no filter, log file empty) state from the
		 * no_filter_matches (filter active, log file has entries but
		 * none match) state.
		 */
		computeHasFilter: function () {
			return (
				this.currentFilter !== 'ALL' ||
				(this.searchQuery && this.searchQuery !== '') ||
				(this.sessionFilter && this.sessionFilter !== '')
			);
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
		/**
		 * Phase 10 helper: roll back the debug_enabled checkbox and the
		 * log_level select to the state captured at the moment the user
		 * toggled them. Called from saveSettings on every terminal
		 * outcome (server-side rejection or hard HTTP failure) so the
		 * UI never shows a state the server did not accept.
		 *
		 * @param {boolean} sentDebugEnabled The value that was sent in
		 *   the save request (== current checkbox state at save time).
		 *   Used as the gate: if no change was made, no rollback needed.
		 */
		rollbackDebugControls: function (sentDebugEnabled) {
			const self = this;
			// If the user never toggled, the captured previous values are
			// undefined and there is nothing to roll back.
			if (self._previousDebugEnabled === undefined) {
				return;
			}
			const currentEnabled = self.$enabled.is(':checked');
			if (currentEnabled !== self._previousDebugEnabled) {
				self.$enabled
					.prop('checked', self._previousDebugEnabled)
					.attr('aria-checked', self._previousDebugEnabled ? 'true' : 'false');
			}
			if (self._previousLogLevel !== undefined && self.$level.val() !== self._previousLogLevel) {
				self.$level.val(self._previousLogLevel);
			}
			// Clear the captured values so a subsequent successful save
			// does not roll back to a stale state.
			self._previousDebugEnabled = undefined;
			self._previousLogLevel = undefined;
			// Recompute dirty flag so the Save button reflects the
			// rollback (no dirty state if user reverted to pre-toggle).
			if (self.updateDirtyState) {
				self.updateDirtyState();
			} else if (self.markSettingsDirty || self.clearSettingsDirty) {
				// Heuristic: if current state matches the last-saved
				// state, clear dirty; otherwise leave dirty so user can
				// retry. We compare against `sentDebugEnabled` which
				// was the captured-at-save-time state; if rollback
				// restored it to the same value, no dirty.
				if (self._previousDebugEnabled === sentDebugEnabled) {
					if (self.clearSettingsDirty) {
						self.clearSettingsDirty();
					}
				} else if (self.markSettingsDirty) {
					self.markSettingsDirty();
				}
			}
		},
		saveSettings: function (previousAutoRefresh) {
			const self = this;
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
			// A save can be triggered by refresh-mode/settings controls without
			// toggling Debug. In that case there is no captured previous toggle
			// state, so the current checked value is the pre-save baseline.
			const previousDebugEnabled =
				this._previousDebugEnabled === undefined ? sentDebugEnabled : this._previousDebugEnabled;
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
					self.clearSettingsDirty();
					self.$saveFeedback.removeClass('success error').text('Saved!').addClass('success');
					if (response.data && response.data.nonce) {
						sscribe_data.nonce = response.data.nonce;
					}
					const debugStateChanged =
						response.data &&
						response.data.debug_enabled !== undefined &&
						response.data.debug_enabled !== previousDebugEnabled;
					self._previousDebugEnabled = undefined;
					self._previousLogLevel = undefined;
					if (debugStateChanged) {
						self.stopAutoRefresh();
						if (self.currentRequest) {
							self.currentRequest.abort();
							self.currentRequest = null;
						}
						self.$saveFeedback
							.removeClass('success error')
							.text('Debug mode changed : reloading\u2026')
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
					// Phase 10: roll back debug_enabled checkbox + log_level
					// to the state captured at the moment the user toggled
					// them. Without this, a server rejection left the
					// checkbox visually checked while the server kept
					// debug disabled.
					self.rollbackDebugControls(sentDebugEnabled);
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
				// Phase 10: symmetric rollback for debug_enabled +
				// log_level on hard HTTP failure (.fail branch). Captured
				// at toggle time so we never show a state the server
				// rejected.
				self.rollbackDebugControls(self.$enabled.is(':checked'));
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
						if (newEntries.length === 0) {
							self.consecutiveNoChange = (self.consecutiveNoChange || 0) + 1;
						} else {
							self.consecutiveNoChange = 0;
						}
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
					self.lastEntryCount = totalCount;
					self.$clearBtn.prop('disabled', totalCount <= 0);
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
			entries.forEach(function (entry, index) {
				const baseId =
					entry && (entry.id || entry.timestamp) ? String(entry.id || entry.timestamp) : 'idx-' + index;
				const entryId = baseId + '-' + index;
				html += buildEntryHtml(entry, entryId);
			});
			return html;
		},
		renderLogs: function (entries, skipObserver, extraData, scrollToTop) {
			const forceScrollTop = scrollToTop === true || this._filterChangeInProgress === true;
			this._filterChangeInProgress = false;
			if (!entries || entries.length === 0) {
				this.$entries.empty();
				this.$empty.find('p').text(this.defaultEmptyMessage);
				// Explicitly remove the hidden utility class - the
				// element ships with sscribe-hidden (display:none
				// !important) so we have to strip both that class AND
				// the hidden attribute before jQuery's .show() can
				// take effect.
				this.$empty.removeClass('sscribe-hidden').attr('hidden', false).show();
				if (this.$emptyEnableBtn && this.$emptyEnableBtn.length) {
					this.$emptyEnableBtn.addClass('sscribe-hidden').attr('hidden', true);
				}
				this.destroyObserver();
				if (this.$staleBanner && this.$staleBanner.length) {
					this.$staleBanner.addClass('sscribe-hidden').attr('hidden', true);
				}
				if (extraData) {
					if (extraData.debug_enabled === false) {
						// State 1 of 5: debug_disabled.
						this.$empty
							.find('p')
							.text('Debug logging is disabled. Enable it in Settings above to capture logs.');
						if (this.$emptyEnableBtn && this.$emptyEnableBtn.length) {
							this.$emptyEnableBtn.removeClass('sscribe-hidden').attr('hidden', false);
						}
					} else if (extraData.status === 'no_log_file' && extraData.debug_enabled) {
						// State 2 of 5: no_log_file.
						this.$empty
							.find('p')
							.text('Debug is enabled but no log file exists yet. Run an export to generate logs.');
					} else if (extraData.status === 'rotated') {
						// State 3 of 5: rotated-view empty (sub-case of no_entries).
						this.$empty.find('p').text('This rotated log file is empty.');
					} else if (this.computeHasFilter()) {
						// State 4 of 5: no_filter_matches. Debug is on,
						// log file exists, but the current level / search /
						// session filter excluded everything.
						this.$empty
							.find('p')
							.text('No entries match the current filters. Adjust level, search, or session above.');
					} else {
						// State 5 of 5: no_entries (default — no filter
						// active, log file exists but is genuinely empty).
						this.$empty.find('p').text(this.defaultEmptyMessage);
					}
				} else {
					// No extraData envelope — treat as the legacy
					// no_entries default.
					this.$empty.find('p').text(this.defaultEmptyMessage);
				}
				return;
			}
			if (extraData && extraData.debug_enabled === false && this.$staleBanner && this.$staleBanner.length) {
				const count = entries.length;
				if (this.$staleBannerMessage && this.$staleBannerMessage.length) {
					this.$staleBannerMessage.text(
						'Debug mode is OFF. Showing ' +
							count +
							(1 === count ? ' entry' : ' entries') +
							' from previous runs.'
					);
				}
				this.$staleBanner.removeClass('sscribe-hidden').attr('hidden', false);
			} else if (this.$staleBanner && this.$staleBanner.length) {
				this.$staleBanner.addClass('sscribe-hidden').attr('hidden', true);
			}
			this.$empty.hide();
			this.$empty.find('p').text(this.defaultEmptyMessage);
			if (this.$emptyEnableBtn && this.$emptyEnableBtn.length) {
				this.$emptyEnableBtn.addClass('sscribe-hidden').attr('hidden', true);
			}
			this.cleanupBeforeRender();
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
				self.$clearBtn.after(
					'<span class="sscribe-feedback sscribe-feedback-error">' + escHtml(errorMsg) + '</span>'
				);
				setTimeout(function () {
					self.$clearBtn.siblings('.sscribe-feedback').remove();
				}, 2000);
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
				self.showPausedIndicator('Nonce refresh failed : you may need to reload the page.');
			});
		},
		/**
		 * @param {string} url Endpoint URL (e.g. admin-ajax.php).
		 * @param {Object} data POST payload (form-urlencoded).
		 * @param {Object} callbacks onSuccess(filename), onError(message).
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
				self.showPausedIndicator('Nonce refresh failed : attempting export anyway.');
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
			if (this.isViewingRotated && this.currentRotatedFilename) {
				data.filename = this.currentRotatedFilename;
			}
			self.$exportBtn.prop('disabled', true);
			self.$exportBtn.find('.sscribe-export-btn-scope').text(' : exporting...');
			this.showPausedIndicator('Export in progress : download should begin shortly');
			this.downloadViaFetch(sscribe_data.ajaxurl, data, {
				onSuccess: function () {
					self.$exportBtn.prop('disabled', false);
					self.updateExportButtonScope();
					self.hidePausedIndicator();
				},
				onError: function () {
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
					errMsg = 'Network error : could not load rotated logs.';
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
			const strings = (typeof sscribe_data !== 'undefined' && sscribe_data.strings) || {};
			const rotatedViewLabel = strings.rotated_view_label || 'View';
			const rotatedExportLabel = strings.rotated_export_label || 'Export';
			const rotatedDeleteLabel = strings.rotated_delete_label || 'Delete';
			const rotatedView = strings.rotated_view || 'View rotated log';
			const rotatedExport = strings.rotated_export || 'Export rotated log';
			const rotatedDelete = strings.rotated_delete || 'Delete rotated log';
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
					'" aria-label="' +
					escAttr(rotatedView + ' ' + file.name) +
					'">' +
					escHtml(rotatedViewLabel) +
					'</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-secondary sscribe-rotated-export" data-file="' +
					escAttr(file.name) +
					'" aria-label="' +
					escAttr(rotatedExport + ' ' + file.name) +
					'">' +
					escHtml(rotatedExportLabel) +
					'</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-danger sscribe-rotated-delete" data-file="' +
					escAttr(file.name) +
					'" aria-label="' +
					escAttr(rotatedDelete + ' ' + file.name) +
					'">' +
					escHtml(rotatedDeleteLabel) +
					'</button>';
				html += '</div></div>';
			});
			this.$rotatedBody.html(html);
		},
		viewRotatedLog: function (filename) {
			const self = this;
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
					if (history.state && history.state.view === newState.view && history.state.file === newState.file) {
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
			if (this.viewRotatedRequest) {
				this.viewRotatedRequest.abort();
				this.viewRotatedRequest = null;
			}
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
	function buildEntryHtml(entry, entryId) {
		const allowedLevels = [
			'all',
			'audit',
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
		const entryMessage = entry && typeof entry.message === 'string' ? entry.message : '';
		const isAudit = /^\[AUDIT\]/i.test(entryMessage);
		const rawLevel = entry.level && typeof entry.level === 'string' ? entry.level.toLowerCase() : 'info';
		const mappedLevel = isAudit ? 'audit' : rawLevel;
		const badgeClass = allowedLevels.includes(mappedLevel) ? mappedLevel : 'info';
		const dataLevel = (
			isAudit ? 'AUDIT' : entry.level && typeof entry.level === 'string' ? entry.level : 'INFO'
		).toUpperCase();
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
						const detail = e && e.message ? e.message : typeof value;
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
			const ctxId = 'sscribe-debug-ctx-' + entryId;
			contextHtml =
				'<div class="sscribe-debug-entry-context" id="' +
				escAttr(ctxId) +
				'" aria-hidden="true">' +
				contextRows +
				'</div>';
		}
		const hasContext = contextHtml !== '';
		const msgText = String(entry.message || '').trim();
		const fallbackLabel = String(entry.level || 'log') + ' entry at ' + String(entry.timestamp || '');
		const ariaLabelText = msgText ? truncateForAriaLabel(msgText, 50) : truncateForAriaLabel(fallbackLabel, 50);
		if (!hasContext) {
			return (
				'<div class="sscribe-debug-entry sscribe-debug-entry-level-' +
				escAttr(badgeClass) +
				'" data-level="' +
				escAttr(dataLevel) +
				'">' +
				'<div class="sscribe-debug-entry-header">' +
				'<span class="sscribe-debug-entry-badge ' +
				escAttr(badgeClass) +
				'">' +
				escHtml(isAudit ? 'AUDIT' : (entry.level || 'INFO').toUpperCase()) +
				'</span>' +
				'<span class="sscribe-debug-entry-time">' +
				escHtml(formatLocalTimestamp(entry.timestamp)) +
				'</span>' +
				'<span class="sscribe-debug-entry-message">' +
				escHtml(entry.message || '') +
				'</span>' +
				'</div>' +
				'</div>'
			);
		}
		return (
			'<details class="sscribe-debug-entry has-context sscribe-debug-entry-level-' +
			escAttr(badgeClass) +
			'" data-level="' +
			escAttr(dataLevel) +
			'" aria-label="' +
			escAttr('Toggle context for: ' + ariaLabelText) +
			'">' +
			'<summary class="sscribe-debug-entry-header">' +
			'<span class="sscribe-debug-entry-badge ' +
			escAttr(badgeClass) +
			'">' +
			escHtml(isAudit ? 'AUDIT' : (entry.level || 'INFO').toUpperCase()) +
			'</span>' +
			'<span class="sscribe-debug-entry-time">' +
			escHtml(formatLocalTimestamp(entry.timestamp)) +
			'</span>' +
			'<span class="sscribe-debug-entry-message">' +
			escHtml(entry.message || '') +
			'</span>' +
			'<span class="sscribe-debug-entry-toggle" aria-hidden="true">\u25B6</span>' +
			'</summary>' +
			contextHtml +
			'</details>'
		);
	}
	window.SScribeDebugConsole = SScribeDebugConsole;
	jQuery(document).ready(function () {
		if (window.SScribeDebugConsole) {
			window.SScribeDebugConsole.init();
		}
	});
})(jQuery);
