/**
 * SScribe Debug Console JavaScript
 *
 * @package SScribe_Export_Site_Pages
 * @version 1.1.1
 */

(function ($) {
	'use strict';

	var debounce = function (fn, wait) {
		var timeout;
		return function () {
			var context = this;
			var args = arguments;
			clearTimeout(timeout);
			timeout = setTimeout(function () {
				fn.apply(context, args);
			}, wait);
		};
	};

	var SScribeDebugConsole = {
		refreshInterval: null,
		isAutoRefresh: true,
		currentFilter: 'ALL',
		searchQuery: '',
		sessionFilter: '',
		initialized: false,
		isViewingRotated: false,
		currentOffset: 0,
		isLoadingMore: false,
		hasMoreEntries: true,
		observer: null,
		currentRequest: null,
		saveSettingsRequest: null,

		init: function () {
			if (this.initialized) {
				return;
			}
			if (typeof sscribe_data === 'undefined' || !scribe_data) {
				return;
			}
			if (!this.hasRequiredDom()) {
				return;
			}
			this.stopAutoRefresh();
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
				document.getElementById('sscribe-debug-console-body') !== null
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
			this.$entryCount = $('#sscribe-debug-entry-count');
			this.$clearBtn = $('#sscribe-debug-clear-btn');
			this.$exportBtn = $('#sscribe-debug-export-btn');
			this.$rotatedBody = $('#sscribe-debug-rotated-body');
		},

		unbindEvents: function () {
			this.$saveSettings.off();
			this.$filterLevel.off();
			this.$searchInput.off();
			this.$sessionInput.off();
			this.$refreshMode.off();
			this.$refreshBtn.off();
			this.$clearBtn.off();
			this.$exportBtn.off();
			this.$rotatedBody.off();
			this.$entries.off();
			this.$container.off();
			this.unbindVisibilityHandler();
		},

		unbindVisibilityHandler: function () {
			if (this._visibilityHandler) {
				$(document).off('visibilitychange', this._visibilityHandler);
				this._visibilityHandler = null;
			}
		},

		bindEvents: function () {
			var self = this;

			this.$saveSettings.on('click', function () {
				self.saveSettings();
			});

			this.$filterLevel.on('change', function () {
				self.currentFilter = $(this).val();
				self.currentOffset = 0;
				self.hasMoreEntries = true;
				self.destroyObserver();
				self.fetchLogs();
				self.updateExportButtonScope();
			});

			this.$searchInput.on(
				'input',
				debounce(function () {
					self.searchQuery = self.$searchInput.val();
					self.currentOffset = 0;
					self.hasMoreEntries = true;
					self.destroyObserver();
					self.fetchLogs();
					self.updateExportButtonScope();
				}, 300)
			);

			this.$sessionInput.on(
				'input',
				debounce(function () {
					self.sessionFilter = self.$sessionInput.val().trim();
					self.currentOffset = 0;
					self.hasMoreEntries = true;
					self.destroyObserver();
					self.fetchLogs();
					self.updateExportButtonScope();
				}, 300)
			);

			this.$refreshMode.on('change', function () {
				self.isAutoRefresh = $(this).val() === 'auto';
				if (self.isAutoRefresh) {
					self.startAutoRefresh();
				} else {
					self.stopAutoRefresh();
				}
				self.saveSettings();
			});

			this.$refreshBtn.on('click', function () {
				if (self.isAutoRefresh) {
					self.stopAutoRefresh();
					self.startAutoRefresh();
				}
				self.fetchLogs();
				self.fetchRotatedLogs();
			});

			this.$clearBtn.on('click', function () {
				var $btn = $(this);
				if ($btn.data('confirming')) {
					$btn.data('confirming', false).removeClass('sscribe-btn-confirming').text('Clear Logs');
					self.clearLogs();
				} else {
					$btn.data('confirming', true).addClass('sscribe-btn-confirming').text('Click again to confirm');
					setTimeout(function () {
						$btn.data('confirming', false).removeClass('sscribe-btn-confirming').text('Clear Logs');
					}, 3000);
				}
			});

			this.$container.on('click', '#sscribe-debug-help-btn', function () {
				var helpContent = document.getElementById('sscribe-debug-help-content');
				if (helpContent) {
					var overlay = document.createElement('div');
					overlay.style.cssText = 'position:fixed;inset:0;background:rgb(0 0 0 / 50%);z-index:999998;';
					overlay.setAttribute('aria-hidden', 'true');
					var dialog = document.createElement('div');
					dialog.style.cssText =
						'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;color:#333;padding:24px;border-radius:8px;max-width:400px;z-index:999999;box-shadow:0 8px 32px rgb(0 0 0 / 30%);font-size:14px;line-height:1.6;';
					dialog.setAttribute('role', 'dialog');
					dialog.setAttribute('aria-modal', 'true');
					dialog.setAttribute('aria-labelledby', 'sscribe-debug-help-title');
					dialog.innerHTML = '<h2 id="sscribe-debug-help-title" style="margin:0 0 12px;font-size:16px;">Debug Console Help</h2>' + helpContent.innerHTML;
					var priorFocus = document.activeElement;
					var focusableSelectors =
						'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
					var focusableElements = Array.from(dialog.querySelectorAll(focusableSelectors));
					var closeDialog = function () {
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
					var keyHandler = function (e) {
						if (e.key === 'Escape' || e.key === 'Esc') {
							e.preventDefault();
							closeDialog();
							return;
						}
						if (e.key === 'Tab') {
							var first = focusableElements[0];
							var last = focusableElements[focusableElements.length - 1];
							if (e.shiftKey && document.activeElement === first) {
								e.preventDefault();
								last.focus();
							} else if (!e.shiftKey && document.activeElement === last) {
								e.preventDefault();
								first.focus();
							}
						}
					};

					var closeBtn = document.createElement('button');
					closeBtn.type = 'button';
					closeBtn.textContent = '\u00D7';
					closeBtn.style.cssText = 'position:absolute;top:12px;right:12px;background:none;border:none;font-size:18px;cursor:pointer;';
					closeBtn.addEventListener('click', closeDialog);
					dialog.style.position = 'relative';
					dialog.appendChild(closeBtn);

					overlay.addEventListener('click', closeDialog);
					document.body.appendChild(overlay);
					document.body.appendChild(dialog);
					document.addEventListener('keydown', keyHandler);
					if (focusableElements.length > 0) {
						focusableElements[0].focus();
					}
				}
			});

			this.$exportBtn.on('click', function () {
				self.exportLogs();
			});

			var rotatedEl = document.getElementById('sscribe-debug-rotated-details');
			if (rotatedEl) {
				rotatedEl.addEventListener('toggle', function () {
					if (rotatedEl.open) {
						self.fetchRotatedLogs();
					}
				});
			}

			this.$rotatedBody.on('click', '.sscribe-rotated-view', function () {
				self.viewRotatedLog($(this).data('file'));
			});
			this.$rotatedBody.on('click', '.sscribe-rotated-export', function () {
				self.exportRotatedLog($(this).data('file'));
			});
			this.$rotatedBody.on('click', '.sscribe-rotated-delete', function () {
				self.deleteRotatedLog($(this).data('file'));
			});

			this.$entries.on('keydown', '.sscribe-debug-entry', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					var $entry = $(this);
					var $context = $entry.find('.sscribe-debug-entry-context');
					if ($context.length) {
						var isVisible = !$context.hasClass('sscribe-hidden');
						$context.toggleClass('sscribe-hidden', isVisible);
						$entry.attr('aria-expanded', String(!isVisible));
					}
				}
			});

			this.$entries.on('click', '.sscribe-debug-entry.has-context', function () {
				var $entry = $(this);
				var $context = $entry.find('.sscribe-debug-entry-context');
				var isVisible = !$context.hasClass('sscribe-hidden');
				$context.toggleClass('sscribe-hidden', isVisible);
				$entry.attr('aria-expanded', String(!isVisible));
			});
		},

		bindVisibilityHandler: function () {
			var self = this;
			this._visibilityHandler = function () {
				if (document.hidden) {
					self.stopAutoRefresh();
				} else if (self.isAutoRefresh) {
					self.startAutoRefresh();
				}
			};
			$(document).on('visibilitychange', this._visibilityHandler);
		},

		loadInitialState: function () {
			var checkedVal = this.$refreshMode.filter(':checked').val();
			this.isAutoRefresh = checkedVal !== undefined ? checkedVal === 'auto' : true;
			this.currentOffset = 0;
			this.hasMoreEntries = true;
			this.fetchLogs();
			this.fetchRotatedLogs();

			if (this.isAutoRefresh) {
				this.startAutoRefresh();
			}
		},

		startAutoRefresh: function () {
			var self = this;
			this.stopAutoRefresh();
			this.refreshInterval = setInterval(function () {
				if (!self.isViewingRotated) {
					self.fetchLogs();
				}
				self.fetchRotatedLogs();
			}, 10000);
		},

		stopAutoRefresh: function () {
			if (this.refreshInterval) {
				clearInterval(this.refreshInterval);
				this.refreshInterval = null;
			}
		},

		getResponseMessage: function (response, fallback) {
			if (response && response.data && response.data.message) {
				return response.data.message;
			}
			return fallback;
		},

		updateExportButtonScope: function () {
			var hasFilter = this.currentFilter !== 'ALL' || this.searchQuery !== '' || this.sessionFilter !== '';
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

		saveSettings: function () {
			var self = this;
			if (this.saveSettingsRequest) {
				this.saveSettingsRequest.abort();
			}
			var data = {
				action: 'sscribe_debug_save_settings',
				nonce: sscribe_data.nonce,
				debug_enabled: this.$enabled.is(':checked'),
				log_level: this.$level.val(),
				auto_refresh: this.isAutoRefresh ? '1' : '0',
			};

			this.saveSettingsRequest = $.post(sscribe_data.ajaxurl, data, function (response) {
				self.saveSettingsRequest = null;
				if (response.success) {
					self.$saveFeedback.text('Saved!').addClass('success');
					setTimeout(function () {
						self.$saveFeedback.text('');
						self.$saveFeedback.removeClass('success');
					}, 2000);
				} else {
					self.$saveFeedback.text(self.getResponseMessage(response, 'Error')).addClass('error');
					setTimeout(function () {
						self.$saveFeedback.text('');
						self.$saveFeedback.removeClass('error');
					}, 2000);
				}
			}).fail(function () {
				self.saveSettingsRequest = null;
				self.$saveFeedback.text('Error').addClass('error');
				setTimeout(function () {
					self.$saveFeedback.text('');
					self.$saveFeedback.removeClass('error');
				}, 2000);
			});
		},

		fetchLogs: function (append) {
			var self = this;
			var isInitialLoad = !append;

			if (isInitialLoad) {
				this.currentOffset = 0;
				this.hasMoreEntries = true;
			}

			if (this.isLoadingMore) {
				return;
			}

			if (!this.hasMoreEntries && append) {
				return;
			}

			var data = {
				action: 'sscribe_debug_fetch_logs',
				nonce: sscribe_data.nonce,
				filter_level: this.currentFilter,
				search: this.searchQuery,
				session_id: this.sessionFilter,
				offset: this.currentOffset,
				limit: 500,
			};

			if (isInitialLoad) {
				self.$entries.css('opacity', '0.5');
				self.$entryCount.text('Loading...');
				self.$consoleBody.addClass('is-loading');
			} else {
				self.isLoadingMore = true;
			}

			if (this.currentRequest) {
				this.currentRequest.abort();
			}
			this.currentRequest = $.post(sscribe_data.ajaxurl, data, function (response) {
				self.currentRequest = null;
				self.$entries.css('opacity', '1');
				self.$consoleBody.removeClass('is-loading');
				self.isLoadingMore = false;

				if (response.success) {
					var newEntries = response.data.entries;
					var totalCount = response.data.count;

					if (isInitialLoad) {
						self.renderLogs(newEntries);
					} else {
						self.appendLogs(newEntries);
					}

					self.currentOffset += newEntries.length;
					self.hasMoreEntries = self.currentOffset < totalCount;
					self.$entryCount.text(totalCount + ' entries');

					if (!self.hasMoreEntries) {
						self.destroyObserver();
					}
				} else {
					self.destroyObserver();
					self.$entryCount.text('Error');
					self.showConsoleError(self.getResponseMessage(response, 'Unable to load debug logs.'));
				}
			}).fail(function () {
				self.currentRequest = null;
				self.$entries.css('opacity', '1');
				self.isLoadingMore = false;
				if (isInitialLoad) {
					self.$entryCount.text('Error');
					self.showConsoleError('Error loading logs');
				}
			});
		},

		buildLogsHtml: function (entries) {
			var html = '';
			entries.forEach(function (entry) {
				html += buildEntryHtml(entry);
			});
			return html;
		},

		renderLogs: function (entries, skipObserver) {
			if (!entries || entries.length === 0) {
				this.$entries.empty();
				this.$empty.show();
				this.destroyObserver();
				return;
			}

			this.$empty.hide();
			this.cleanupBeforeRender();
			var html = this.buildLogsHtml(entries);
			this.$entries.html(html);
			if (!skipObserver) {
				this.setupObserver();
			}
		},

		cleanupBeforeRender: function () {
			this.destroyObserver();
		},

		appendLogs: function (entries) {
			if (!entries || entries.length === 0) {
				return;
			}

			var html = this.buildLogsHtml(entries);
			this.$entries.append(html);
		},

		setupObserver: function () {
			if (!this.hasMoreEntries) {
				return;
			}

			var self = this;
			var sentinel = document.createElement('div');
			sentinel.id = 'sscribe-infinite-scroll-sentinel';
			sentinel.style.height = '1px';
			sentinel.style.width = '100%';
			this.$entries.after(sentinel);

			this.observer = new IntersectionObserver(
				function (entries) {
					if (entries[0].isIntersecting && !self.isLoadingMore && self.hasMoreEntries) {
						self.fetchLogs(true);
					}
				},
				{ root: null, rootMargin: '100px', threshold: 0 }
			);

			this.observer.observe(sentinel);
		},

		destroyObserver: function () {
			if (this.observer) {
				this.observer.disconnect();
				this.observer = null;
			}
			var sentinel = document.getElementById('sscribe-infinite-scroll-sentinel');
			if (sentinel) {
				sentinel.remove();
			}
		},

		clearLogs: function () {
			var self = this;
			var data = {
				action: 'sscribe_debug_clear_logs',
				nonce: sscribe_data.nonce,
			};

			$.post(sscribe_data.ajaxurl, data, function (response) {
				if (response.success) {
					self.$saveFeedback.text('Logs cleared').addClass('success');
					setTimeout(function () {
						self.$saveFeedback.text('');
						self.$saveFeedback.removeClass('success');
					}, 2000);
					self.fetchLogs();
					self.fetchRotatedLogs();
				} else {
					self.$saveFeedback.text(self.getResponseMessage(response, 'Error')).addClass('error');
					setTimeout(function () {
						self.$saveFeedback.text('');
						self.$saveFeedback.removeClass('error');
					}, 2000);
				}
			}).fail(function () {
				self.$saveFeedback.text('Error').addClass('error');
				setTimeout(function () {
					self.$saveFeedback.text('');
					self.$saveFeedback.removeClass('error');
				}, 2000);
			});
		},

		downloadViaForm: function (url, data) {
			var form = document.createElement('form');
			form.method = 'POST';
			form.action = url;
			form.style.display = 'none';
			Object.keys(data).forEach(function (key) {
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = key;
				input.value = data[key];
				form.appendChild(input);
			});
			document.body.appendChild(form);
			form.submit();
			setTimeout(function () {
				form.remove();
			}, 100);
		},

		exportLogs: function () {
			var data = {
				action: 'sscribe_debug_export_logs',
				nonce: sscribe_data.nonce,
				filter_level: this.currentFilter,
				search: this.searchQuery,
				session_id: this.sessionFilter,
			};

			this.downloadViaForm(sscribe_data.ajaxurl, data);
		},

		fetchRotatedLogs: function () {
			var self = this;
			var data = {
				action: 'sscribe_debug_get_files',
				nonce: sscribe_data.nonce,
			};

			$.post(sscribe_data.ajaxurl, data, function (response) {
				if (response.success && response.data && Array.isArray(response.data.files)) {
					self.renderRotatedLogs(response.data.files);
				} else {
					self.$rotatedBody.html(
						'<div class="sscribe-debug-rotated-empty">' +
							escHtml(self.getResponseMessage(response, 'Unable to load rotated logs.')) +
							'</div>'
					);
				}
			}).fail(function () {
				self.$rotatedBody.html(
					'<div class="sscribe-debug-rotated-empty">' + escHtml('Unable to load rotated logs.') + '</div>'
				);
			});
		},

		renderRotatedLogs: function (files) {
			if (!files || files.length === 0) {
				this.$rotatedBody.html('<div class="sscribe-debug-rotated-empty">No rotated log files.</div>');
				return;
			}

			var html = '';

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
					escHtml(file.name) +
					'">View</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-secondary sscribe-rotated-export" data-file="' +
					escHtml(file.name) +
					'">Export</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-danger sscribe-rotated-delete" data-file="' +
					escHtml(file.name) +
					'">Delete</button>';
				html += '</div></div>';
			});

			this.$rotatedBody.html(html);
		},

		viewRotatedLog: function (filename) {
			var data = {
				action: 'sscribe_debug_fetch_rotated',
				nonce: sscribe_data.nonce,
				filename: filename,
			};

			var self = this;
			this.hasMoreEntries = false;
			this.destroyObserver();

			$.post(sscribe_data.ajaxurl, data, function (response) {
				if (response.success) {
					self.isViewingRotated = true;
					self.currentRotatedFilename = filename;
					self.renderLogs(response.data.entries, true);
					self.$entryCount.text(response.data.count + ' entries (rotated)');
					self.$entries.prepend(
						'<div class="sscribe-debug-rotated-banner">' +
							'<span>Viewing archived log: ' +
							escHtml(filename) +
							'</span>' +
							'<button type="button" class="sscribe-button sscribe-button-primary" id="sscribe-back-to-current">Back to current log</button>' +
							'</div>'
					);
					self.$entries.find('#sscribe-back-to-current').on('click', function () {
						self.backToCurrentLog();
					});
				} else {
					self.$entryCount.text('Error');
					self.showConsoleError(self.getResponseMessage(response, 'Unable to open rotated log.'));
				}
			}).fail(function () {
				self.isViewingRotated = false;
				self.$entryCount.text('Error');
				self.showConsoleError('Unable to open rotated log.');
			});
		},

		backToCurrentLog: function () {
			this.isViewingRotated = false;
			this.currentRotatedFilename = '';
			this.hasMoreEntries = true;
			this.currentOffset = 0;
			this.fetchLogs();
			this.fetchRotatedLogs();
		},

		exportRotatedLog: function (filename) {
			var data = {
				action: 'sscribe_debug_export_logs',
				nonce: sscribe_data.nonce,
				filename: filename,
			};

			this.downloadViaForm(sscribe_data.ajaxurl, data);
		},

		deleteRotatedLog: function (filename) {
			var self = this;
			var data = {
				action: 'sscribe_debug_delete_rotated',
				nonce: sscribe_data.nonce,
				filename: filename,
			};

			$.post(sscribe_data.ajaxurl, data, function (response) {
				if (response.success) {
					self.fetchRotatedLogs();
				} else {
					self.$saveFeedback.text(self.getResponseMessage(response, 'Error')).addClass('error');
					setTimeout(function () {
						self.$saveFeedback.text('');
						self.$saveFeedback.removeClass('error');
					}, 2000);
				}
			}).fail(function () {
				self.$saveFeedback.text('Error').addClass('error');
				setTimeout(function () {
					self.$saveFeedback.text('');
					self.$saveFeedback.removeClass('error');
				}, 2000);
			});
		},
	};

	function escHtml(str) {
		if (!str) {
			return '';
		}
		var div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	function buildEntryHtml(entry) {
		var badgeClass = (entry.level && typeof entry.level === 'string') ? entry.level.toLowerCase() : 'info';
		var contextHtml = '';

		if (entry.context && Object.keys(entry.context).length > 0) {
			var contextRows = '';
			Object.keys(entry.context).forEach(function (key) {
				var value = entry.context[key];
				if (typeof value === 'object') {
					value = JSON.stringify(value, null, 2);
				}
				contextRows += '<div class="sscribe-debug-context-row">' +
					'<span class="sscribe-debug-context-key">' + escHtml(String(key)) + '</span>' +
					'<span class="sscribe-debug-context-val"><pre>' + escHtml(String(value)) + '</pre></span>' +
					'</div>';
			});
			contextHtml = '<div class="sscribe-debug-entry-context sscribe-hidden">' + contextRows + '</div>';
		}

		var hasContext = contextHtml !== '';

		return '<div class="sscribe-debug-entry' + (hasContext ? ' has-context' : '') + '"' +
			(hasContext ? ' tabindex="0" role="button" aria-expanded="false"' : '') + '>' +
			'<div class="sscribe-debug-entry-header">' +
			'<span class="sscribe-debug-entry-badge ' + escHtml(badgeClass) + '">' + escHtml(entry.level || 'INFO') + '</span>' +
			'<span class="sscribe-debug-entry-time">' + escHtml(entry.timestamp || '') + '</span>' +
			'<span class="sscribe-debug-entry-message">' + escHtml(entry.message || '') + '</span>' +
			(hasContext ? '<span class="sscribe-debug-entry-toggle" aria-hidden="true">\u25B6</span>' : '') +
			'</div>' +
			contextHtml +
			'</div>';
	}

	window.SScribeDebugConsole = SScribeDebugConsole;

})(jQuery);
