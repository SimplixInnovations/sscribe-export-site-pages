/**
 * SScribe Debug Console JavaScript
 *
 * @package SScribe_Export_Site_Pages
 * @version 1.1.1
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

	const SScribeDebugConsole = {
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

		init: function () {
			if (this.initialized && this.initialized === true) {
				return;
			}
			if (!this.hasRequiredDom()) {
				return;
			}
			this.initialized = true;
			this.cacheDom();
			this.bindEvents();
			this.bindVisibilityHandler();
			this.loadInitialState();
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

		bindEvents: function () {
			const self = this;

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
				}, 300)
			);

			this.$refreshMode.on('change', function () {
				self.isAutoRefresh = $(this).val() === 'auto';
				if (self.isAutoRefresh) {
					self.startAutoRefresh();
				} else {
					self.stopAutoRefresh();
				}
			});

			this.$refreshMode.on(
				'change',
				debounce(function () {
					self.saveSettings();
				}, 500)
			);

			this.$refreshBtn.on('click', function () {
				if (self.isAutoRefresh) {
					self.stopAutoRefresh();
					self.startAutoRefresh();
				}
				self.fetchLogs();
				self.fetchRotatedLogs();
			});

			this.$clearBtn.on('click', function () {
				const $btn = $(this);
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
				const helpContent = document.getElementById('sscribe-debug-help-content');
				if (helpContent) {
					const overlay = document.createElement('div');
					overlay.style.cssText = 'position:fixed;inset:0;background:rgb(0 0 0 / 50%);z-index:999998;';
					const dialog = document.createElement('div');
					dialog.style.cssText =
						'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;color:#333;padding:24px;border-radius:8px;max-width:400px;z-index:999999;box-shadow:0 8px 32px rgb(0 0 0 / 30%);font-size:14px;line-height:1.6;';
					dialog.innerHTML = helpContent.innerHTML;
					overlay.addEventListener('click', function () {
						document.body.removeChild(overlay);
						document.body.removeChild(dialog);
					});
					document.body.appendChild(overlay);
					document.body.appendChild(dialog);
				}
			});

			this.$exportBtn.on('click', function () {
				self.exportLogs();
			});

			this.$searchInput.on(
				'input',
				debounce(function () {
					self.updateExportButtonScope();
				}, 300)
			);

			this.$sessionInput.on(
				'input',
				debounce(function () {
					self.updateExportButtonScope();
				}, 300)
			);

			document.querySelector('.sscribe-debug-rotated').addEventListener('toggle', function (e) {
				if (e.newState === 'open') {
					self.fetchRotatedLogs();
				}
			});
		},

		bindVisibilityHandler: function () {
			const self = this;
			$(document).on('visibilitychange', function () {
				if (document.hidden) {
					self.stopAutoRefresh();
				} else if (self.isAutoRefresh) {
					self.startAutoRefresh();
				}
			});
		},

		loadInitialState: function () {
			this.isAutoRefresh = this.$refreshMode.filter(':checked').val() === 'auto';
			this.currentOffset = 0;
			this.hasMoreEntries = true;
			this.fetchLogs();
			this.fetchRotatedLogs();

			if (this.isAutoRefresh) {
				this.startAutoRefresh();
			}
		},

		startAutoRefresh: function () {
			const self = this;
			this.stopAutoRefresh();
			this.refreshInterval = setInterval(function () {
				self.fetchLogs();
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

		saveSettings: function () {
			const self = this;
			const data = {
				action: 'sscribe_debug_save_settings',
				nonce: sscribe_data.nonce,
				debug_enabled: this.$enabled.is(':checked'),
				log_level: this.$level.val(),
				auto_refresh: this.isAutoRefresh ? '1' : '0',
			};

			$.post(sscribe_data.ajaxurl, data, function (response) {
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
				self.$saveFeedback.text('Error').addClass('error');
				setTimeout(function () {
					self.$saveFeedback.text('');
					self.$saveFeedback.removeClass('error');
				}, 2000);
			});
		},

		fetchLogs: function (append) {
			const self = this;
			const isInitialLoad = !append;

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

			const data = {
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

			$.get(sscribe_data.ajaxurl, data, function (response) {
				self.$entries.css('opacity', '1');
				self.$consoleBody.removeClass('is-loading');
				self.isLoadingMore = false;

				if (response.success) {
					const newEntries = response.data.entries;
					const totalCount = response.data.count;

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
				self.$entries.css('opacity', '1');
				self.isLoadingMore = false;
				if (isInitialLoad) {
					self.$entryCount.text('Error');
					self.showConsoleError('Error loading logs');
				}
			});
		},

		buildLogsHtml: function (entries) {
			let html = '';
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
			const html = this.buildLogsHtml(entries);
			this.$entries.html(html);
			if (!skipObserver) {
				this.setupObserver();
			}
		},

		appendLogs: function (entries) {
			if (!entries || entries.length === 0) {
				return;
			}

			const html = this.buildLogsHtml(entries);
			this.$entries.append(html);
		},

		setupObserver: function () {
			if (!this.hasMoreEntries) {
				return;
			}

			const self = this;
			const sentinel = document.createElement('div');
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
			const sentinel = document.getElementById('sscribe-infinite-scroll-sentinel');
			if (sentinel) {
				sentinel.remove();
			}
		},

		clearLogs: function () {
			const self = this;
			const data = {
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
			const form = document.createElement('form');
			form.method = 'POST';
			form.action = url;
			form.style.display = 'none';
			for (const key in data) {
				const input = document.createElement('input');
				input.type = 'hidden';
				input.name = key;
				input.value = data[key];
				form.appendChild(input);
			}
			document.body.appendChild(form);
			form.submit();
			document.body.removeChild(form);
		},

		exportLogs: function () {
			const data = {
				action: 'sscribe_debug_export_logs',
				nonce: sscribe_data.nonce,
				filter_level: this.currentFilter,
				search: this.searchQuery,
			};

			this.downloadViaForm(sscribe_data.ajaxurl, data);
		},

		fetchRotatedLogs: function () {
			const self = this;
			const data = {
				action: 'sscribe_debug_get_files',
				nonce: sscribe_data.nonce,
			};

			$.get(sscribe_data.ajaxurl, data, function (response) {
				if (response.success) {
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
			const data = {
				action: 'sscribe_debug_fetch_rotated',
				nonce: sscribe_data.nonce,
				filename: filename,
			};

			const self = this;
			this.hasMoreEntries = false;
			this.destroyObserver();

			$.get(sscribe_data.ajaxurl, data, function (response) {
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
				self.$entryCount.text('Error');
				self.showConsoleError('Unable to open rotated log.');
			});
		},

		backToCurrentLog: function () {
			this.isViewingRotated = false;
			this.currentRotatedFilename = '';
			this.fetchLogs();
			this.fetchRotatedLogs();
		},

		exportRotatedLog: function (filename) {
			const data = {
				action: 'sscribe_debug_export_logs',
				nonce: sscribe_data.nonce,
				filename: filename,
			};

			this.downloadViaForm(sscribe_data.ajaxurl, data);
		},

		deleteRotatedLog: function (filename) {
			const self = this;
			const data = {
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
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	/**
	 * Build HTML string for a single log entry.
	 *
	 * @param {Object} entry Log entry object.
	 * @returns {string} HTML string for the entry.
	 */
	function buildEntryHtml(entry) {
		const badgeClass = entry.level.toLowerCase();
		let contextHtml = '';

		if (entry.context) {
			Object.keys(entry.context).forEach(function (key) {
				let value = entry.context[key];
				if (typeof value === 'object') {
					value = JSON.stringify(value);
				}
				contextHtml += '<div class="sscribe-debug-context-row">';
				contextHtml += '<span class="sscribe-debug-context-key">' + escHtml(key) + ':</span>';
				contextHtml += '<span class="sscribe-debug-context-value">' + escHtml(String(value)) + '</span>';
				contextHtml += '</div>';
			});
		}

		let html = '<div class="sscribe-debug-entry" tabindex="0" role="button" aria-label="Toggle log entry details">';
		html += '<div class="sscribe-debug-entry-header">';
		html += '<span class="sscribe-debug-entry-time">' + escHtml(entry.timestamp) + '</span>';
		html += '<span class="sscribe-debug-entry-badge ' + badgeClass + '">' + escHtml(entry.level) + '</span>';
		html += '<span class="sscribe-debug-entry-message">' + escHtml(entry.message) + '</span>';
		html += '</div>';
		if (contextHtml) {
			html += '<div class="sscribe-debug-entry-context" aria-hidden="true">' + contextHtml + '</div>';
		}
		html += '</div>';

		return html;
	}

	window.SScribeDebugConsole = SScribeDebugConsole;

	$(document).ready(function () {
		if ($('#sscribe-tab-debug').hasClass('sscribe-tab-active')) {
			SScribeDebugConsole.init();
		}
	});
})(jQuery);
