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
			const args    = arguments;
			clearTimeout( timeout );
			timeout = setTimeout(
				function () {
					fn.apply( context, args );
				},
				wait
			);
		};
	};

	function escHtml(str) {
		if (str === null || str === undefined) {
			return '';
		}
		const div       = document.createElement( 'div' );
		div.textContent = String( str );
		return div.innerHTML;
	}

	function escAttr(str) {
		if (str === null || str === undefined) {
			return '';
		}
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

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
		currentRotatedFilename: '',
		observer: null,
		currentRequest: null,
		saveSettingsRequest: null,
		rotatedRequest: null,
		viewRotatedRequest: null,
		isRefreshing: false,
		isRefreshingSince: null,

		init: function () {
			if (this.initialized) {
				return;
			}
			if (typeof sscribe_data === 'undefined' || ! sscribe_data) {
				if (this.initRetryCount === undefined) {
					this.initRetryCount = 0;
				}
				if (this.initRetryCount < 3) {
					this.initRetryCount++;
					setTimeout(this.init.bind(this), 100);
				}
				return;
			}
			if ( ! this.hasRequiredDom()) {
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
				document.getElementById( 'sscribe-debug-root' ) !== null &&
				document.getElementById( 'sscribe-debug-entries' ) !== null &&
				document.getElementById( 'sscribe-debug-console-body' ) !== null &&
				document.getElementById( 'sscribe-debug-rotated-body' ) !== null
			);
		},

		cacheDom: function () {
			this.$container    = $( '#sscribe-debug-root' );
			this.$enabled      = $( '#sscribe-debug-enabled' );
			this.$level        = $( '#sscribe-debug-level' );
			this.$saveSettings = $( '#sscribe-debug-save-settings' );
			this.$saveFeedback = $( '#sscribe-debug-save-feedback' );
			this.$filterLevel  = $( '#sscribe-debug-filter-level' );
			this.$searchInput  = $( '#sscribe-debug-search' );
			this.$sessionInput = $( '#sscribe-debug-session-id' );
			this.$refreshMode  = $( 'input[name="sscribe_refresh_mode"]' );
			this.$refreshBtn   = $( '#sscribe-debug-refresh-btn' );
			this.$consoleBody  = $( '#sscribe-debug-console-body' );
			this.$entries      = $( '#sscribe-debug-entries' );
			this.$empty        = $( '#sscribe-debug-empty' );
			this.defaultEmptyMessage = this.$empty.find( 'p' ).text().trim();
			this.$entryCount   = $( '#sscribe-debug-entry-count' );
			this.$clearBtn     = $( '#sscribe-debug-clear-btn' );
			this.clearBtnOriginalText = this.$clearBtn.text();
			this.$exportBtn    = $( '#sscribe-debug-export-btn' );
			this.$rotatedBody  = $( '#sscribe-debug-rotated-body' );
			this.$refreshPaused = $( '#sscribe-debug-refresh-paused' );
		},

		unbindEvents: function () {
			this.$saveSettings.off( '.sscribe' );
			this.$filterLevel.off( '.sscribe' );
			this.$searchInput.off( '.sscribe' );
			this.$sessionInput.off( '.sscribe' );
			this.$refreshMode.off( '.sscribe' );
			this.$refreshBtn.off( '.sscribe' );
			this.$clearBtn.off( '.sscribe' );
			this.$exportBtn.off( '.sscribe' );
			this.$rotatedBody.off( '.sscribe' );
			this.$entries.off( '.sscribe' );
			this.$container.off( '.sscribe' );
			this.unbindVisibilityHandler();
			this.unbindToggleHandler();
		},

		unbindVisibilityHandler: function () {
			if (this._visibilityHandler) {
				$( document ).off( 'visibilitychange', this._visibilityHandler );
				this._visibilityHandler = null;
			}
			if (this._beforeUnloadHandler) {
				window.removeEventListener( 'beforeunload', this._beforeUnloadHandler );
				this._beforeUnloadHandler = null;
			}
		},

		unbindToggleHandler: function () {
			if (this._toggleHandler) {
				const rotatedEl = document.getElementById( 'sscribe-debug-rotated-details' );
				if (rotatedEl) {
					rotatedEl.removeEventListener( 'toggle', this._toggleHandler );
				}
				this._toggleHandler = null;
			}
		},

		bindEvents: function () {
			const self = this;

			this.$saveSettings.on(
				'click.sscribe',
				function () {
					self.saveSettings( self.isAutoRefresh );
				}
			);

			this.$filterLevel.on(
				'change.sscribe',
				function () {
					self.currentFilter        = $( this ).val();
					self.currentOffset        = 0;
					self.hasMoreEntries       = true;
					self.isViewingRotated     = false;
					self.currentRotatedFilename = '';
					self.hidePausedIndicator();
					self.destroyObserver();
					self.fetchLogs();
					self.updateExportButtonScope();
				}
			);

			this.$searchInput.on(
				'input.sscribe',
				debounce(
					function () {
						self.searchQuery          = self.$searchInput.val();
						self.currentOffset        = 0;
						self.hasMoreEntries       = true;
						self.isViewingRotated     = false;
						self.currentRotatedFilename = '';
						self.hidePausedIndicator();
						self.destroyObserver();
						self.fetchLogs();
						self.updateExportButtonScope();
					},
					300
				)
			);

			this.$sessionInput.on(
				'input.sscribe',
				debounce(
					function () {
						self.sessionFilter        = self.$sessionInput.val().trim();
						self.currentOffset        = 0;
						self.hasMoreEntries       = true;
						self.isViewingRotated     = false;
						self.currentRotatedFilename = '';
						self.hidePausedIndicator();
						self.destroyObserver();
						self.fetchLogs();
						self.updateExportButtonScope();
					},
					300
				)
			);

			this.$refreshMode.on(
				'change.sscribe',
				function () {
					const previousAutoRefresh = self.isAutoRefresh;
					self.isAutoRefresh        = $( this ).val() === 'auto';
					if (self.isAutoRefresh) {
						self.startAutoRefresh();
						self.hidePausedIndicator();
					} else {
						self.stopAutoRefresh();
						self.showPausedIndicator( 'Manual mode — auto-refresh off' );
					}
					self.saveSettings( previousAutoRefresh );
				}
			);

			this.$refreshBtn.on(
				'click.sscribe',
				function () {
					if (self.isAutoRefresh) {
						self.stopAutoRefresh();
						self.startAutoRefresh();
					}
					self.fetchLogs();
					self.fetchRotatedLogs();
				}
			);

			this.$clearBtn.on(
				'click.sscribe',
				function () {
					const $btn = $( this );
					if ($btn.data( 'confirming' )) {
						$btn.data( 'confirming', false ).removeClass( 'sscribe-btn-confirming' ).text( 'Clearing...' );
						$btn.prop( 'disabled', true );
						self.clearLogs();
					} else {
						if ( ! $btn.data( 'original-text' )) {
							$btn.data( 'original-text', $btn.text() );
						}
						$btn.data( 'confirming', true ).addClass( 'sscribe-btn-confirming' ).text( 'Click again to confirm' );
						setTimeout(
							function () {
								if ($btn.data( 'confirming' )) {
									const originalText = $btn.data( 'original-text' ) || self.clearBtnOriginalText;
									$btn.data( 'confirming', false ).removeClass( 'sscribe-btn-confirming' ).text( originalText );
								}
							},
							3000
						);
					}
				}
			);

			this.$container.on(
				'click.sscribe',
				'#sscribe-debug-help-btn',
				function () {
					const helpContent = document.getElementById( 'sscribe-debug-help-content' );
					if (helpContent) {
						const overlay         = document.createElement( 'div' );
						overlay.style.cssText = 'position:fixed;inset:0;background:rgb(0 0 0 / 50%);z-index:9999998;';
						overlay.setAttribute( 'aria-hidden', 'true' );
						const dialog         = document.createElement( 'div' );
						dialog.style.cssText =
						'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;color:#333;padding:24px;border-radius:8px;max-width:400px;z-index:9999999;box-shadow:0 8px 32px rgb(0 0 0 / 30%);font-size:14px;line-height:1.6;';
						dialog.setAttribute( 'role', 'dialog' );
						dialog.setAttribute( 'aria-modal', 'true' );
						dialog.setAttribute( 'aria-labelledby', 'sscribe-debug-help-title' );
						dialog.appendChild( helpContent.cloneNode( true ) );
						const priorFocus         = document.activeElement;
						const focusableSelectors =
						'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
						const closeDialog        = function () {
							if (document.body.contains( overlay )) {
								document.body.removeChild( overlay );
							}
							if (document.body.contains( dialog )) {
								document.body.removeChild( dialog );
							}
							document.removeEventListener( 'keydown', keyHandler );
							if (priorFocus && typeof priorFocus.focus === 'function') {
								priorFocus.focus();
							}
						};
						const keyHandler         = function (e) {
							if (e.key === 'Escape' || e.key === 'Esc') {
								e.preventDefault();
								closeDialog();
								return;
							}
							if (e.key === 'Tab') {
								const focusableElements = Array.from( dialog.querySelectorAll( focusableSelectors ) );
								const first = focusableElements[0];
								const last  = focusableElements[focusableElements.length - 1];
								if (e.shiftKey && document.activeElement === first) {
									e.preventDefault();
									last.focus();
								} else if ( ! e.shiftKey && document.activeElement === last) {
									e.preventDefault();
									first.focus();
								}
							}
						};

						const closeBtn       = document.createElement( 'button' );
						closeBtn.type        = 'button';
						closeBtn.textContent = '\u00D7';
						closeBtn.setAttribute( 'aria-label', 'Close dialog' );
						closeBtn.style.cssText = 'position:absolute;top:12px;right:12px;background:none;border:none;font-size:18px;cursor:pointer;';
						closeBtn.addEventListener( 'click', closeDialog );
						dialog.appendChild( closeBtn );

						overlay.addEventListener( 'click', closeDialog );
						const wpWrap = document.getElementById( 'wpwrap' ) || document.body;
						wpWrap.appendChild( overlay );
						wpWrap.appendChild( dialog );
						document.addEventListener( 'keydown', keyHandler );
						const focusableElements = Array.from( dialog.querySelectorAll( focusableSelectors ) );
						if (focusableElements.length > 0) {
							focusableElements[0].focus();
						}
					}
				}
			);

			this.$exportBtn.on(
				'click.sscribe',
				function () {
					self.exportLogs();
				}
			);

			const rotatedEl = document.getElementById( 'sscribe-debug-rotated-details' );
			if (rotatedEl) {
				this._toggleHandler = function () {
					const $hint = document.querySelector( '.sscribe-debug-rotated-hint' );
					if ( $hint ) {
						$hint.textContent = rotatedEl.open ? 'Click to collapse' : 'Click to expand';
					}
					if (rotatedEl.open) {
						self.fetchRotatedLogs();
					}
				};
				rotatedEl.addEventListener( 'toggle', this._toggleHandler );
			}

			this.$rotatedBody.on(
				'click.sscribe',
				'.sscribe-rotated-view',
				function () {
					self.viewRotatedLog( $( this ).data( 'file' ) );
				}
			);
			this.$rotatedBody.on(
				'click.sscribe',
				'.sscribe-rotated-export',
				function () {
					self.exportRotatedLog( $( this ).data( 'file' ) );
				}
			);
			this.$rotatedBody.on(
				'click.sscribe',
				'.sscribe-rotated-delete',
				function () {
					self.deleteRotatedLog( $( this ).data( 'file' ), $( this ) );
				}
			);

			this.$entries.on(
				'keydown.sscribe',
				'.sscribe-debug-entry',
				function (e) {
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						const $entry = $( this );
						const $context = $entry.find( '.sscribe-debug-entry-context' );
						if ($context.length) {
							// Toggle .expanded on the parent — the CSS rule
							// .sscribe-debug-entry.expanded .sscribe-debug-entry-context { display: block }
							// handles visibility. No need for .sscribe-hidden on the context element.
							const isExpanded = $entry.hasClass( 'expanded' );
							$entry.toggleClass( 'expanded', ! isExpanded );
							$entry.attr( 'aria-expanded', String( ! isExpanded ) );
						}
					}
				}
			);

			this.$entries.on(
				'click.sscribe',
				'.sscribe-debug-entry.has-context',
				function () {
					const $entry   = $( this );
					const $context = $entry.find( '.sscribe-debug-entry-context' );
					if ( $context.length ) {
						const isExpanded = $entry.hasClass( 'expanded' );
						$entry.toggleClass( 'expanded', ! isExpanded );
						$entry.attr( 'aria-expanded', String( ! isExpanded ) );
					}
				}
			);

			this.$entries.on(
				'click.sscribe',
				'.sscribe-debug-retry-append',
				function () {
					self.retryAppend();
				}
			);
		},

		bindVisibilityHandler: function () {
			const self              = this;
			this._visibilityHandler = function () {
				if (document.hidden) {
					self.stopAutoRefresh();
					self.showPausedIndicator( 'Paused — tab inactive' );
				} else if (self.isAutoRefresh) {
					const $debugTabBtn = $( '#sscribe-tab-btn-debug' );
					if ( !$debugTabBtn.length || $debugTabBtn.attr( 'aria-selected' ) === 'true' ) {
						self.startAutoRefresh();
						self.hidePausedIndicator();
					}
				}
			};
			$( document ).on( 'visibilitychange', this._visibilityHandler );

			this._beforeUnloadHandler = function () {
				self.stopAutoRefresh();
			};
			window.addEventListener( 'beforeunload', this._beforeUnloadHandler );
		},

		loadInitialState: function () {
			const checkedVal    = this.$refreshMode.filter( ':checked' ).val();
			this.isAutoRefresh  = checkedVal !== undefined ? checkedVal === 'auto' : true;
			this.currentOffset  = 0;
			this.hasMoreEntries = true;
			this.fetchLogs();
			this.updateExportButtonScope();

			const rotatedEl = document.getElementById( 'sscribe-debug-rotated-details' );
			if ( rotatedEl && rotatedEl.open ) {
				this.fetchRotatedLogs();
			}

			if (this.isAutoRefresh) {
				this.startAutoRefresh();
			}
		},

		startAutoRefresh: function () {
			const self = this;
			this.stopAutoRefresh();
			this.refreshInterval = setInterval(
				function () {
					if ( self.isRefreshing && self.isRefreshingSince && ( Date.now() - self.isRefreshingSince > 30000 ) ) {
						self.isRefreshing = false;
						self.isRefreshingSince = null;
					}
					if ( self.isRefreshing || self.isViewingRotated ) {
						return;
					}
					if ( self.currentOffset !== 0 ) {
						self.showPausedIndicator( 'Auto-refresh paused — scrolled into history' );
						return;
					}
					self.hidePausedIndicator();
					self.fetchLogs();
					const rotatedEl = document.getElementById( 'sscribe-debug-rotated-details' );
					if ( rotatedEl && rotatedEl.open ) {
						self.fetchRotatedLogs();
					}
				},
				10000
			);
		},

		stopAutoRefresh: function () {
			if (this.refreshInterval) {
				clearInterval( this.refreshInterval );
				this.refreshInterval = null;
			}
		},

		showPausedIndicator: function (message) {
			if (this.$refreshPaused) {
				this.$refreshPaused.text( message ).show();
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
			this.$exportBtn.find( '.sscribe-export-btn-scope' ).text( hasFilter ? ' (filtered)' : ' (all)' );
		},

		showConsoleError: function (message) {
			this.$empty.hide();
			this.$entries.html(
				'<div class="sscribe-debug-entry">' +
					'<div class="sscribe-debug-entry-header">' +
					'<span class="sscribe-debug-entry-badge error">ERROR</span>' +
					'<span class="sscribe-debug-entry-message">' +
					escHtml( message ) +
					'</span>' +
					'</div>' +
					'</div>'
			);
		},

		saveSettings: function ( previousAutoRefresh ) {
			const self = this;

			// Guard against missing DOM elements.
			if ( ! this.$enabled.length || ! this.$level.length) {
				return;
			}

			if (this.saveSettingsRequest) {
				this.saveSettingsRequest.abort();
			}
			const sentDebugEnabled = this.$enabled.is( ':checked' );
			const data             = {
				action: 'sscribe_debug_save_settings',
				nonce: sscribe_data.nonce,
				debug_enabled: sentDebugEnabled,
				log_level: this.$level.val(),
				auto_refresh: this.isAutoRefresh ? '1' : '0',
			};

			self.$saveSettings.prop( 'disabled', true );
			self.$refreshMode.prop( 'disabled', true );

			this.saveSettingsRequest = $.post(
				sscribe_data.ajaxurl,
				data,
				function (response) {
					self.saveSettingsRequest = null;
					self.$saveSettings.prop( 'disabled', false );
					self.$refreshMode.prop( 'disabled', false );
					if (response.success) {
						self.$saveFeedback.removeClass( 'success error' ).text( 'Saved!' ).addClass( 'success' );
						if ( response.data && response.data.nonce ) {
							sscribe_data.nonce = response.data.nonce;
						}
						if ( response.data && response.data.debug_enabled !== undefined && response.data.debug_enabled !== sentDebugEnabled ) {
							self.stopAutoRefresh();
							if ( self.currentRequest ) {
								self.currentRequest.abort();
								self.currentRequest = null;
							}
							self.$saveFeedback.removeClass( 'success error' ).text( 'Debug mode changed — reloading\u2026' ).addClass( 'success' );
							setTimeout(
								function () {
									window.location.reload();
								},
								1500
							);
						} else {
							setTimeout(
								function () {
									self.$saveFeedback.text( '' );
									self.$saveFeedback.removeClass( 'success' );
								},
								2500
							);
						}
					} else {
						if ( previousAutoRefresh !== undefined ) {
							self.isAutoRefresh = previousAutoRefresh;
							const targetValue  = previousAutoRefresh ? 'auto' : 'manual';
							self.$refreshMode.filter( '[value="' + targetValue + '"]' ).prop( 'checked', true );
							if (previousAutoRefresh) {
								self.startAutoRefresh();
							} else {
								self.stopAutoRefresh();
							}
						}
						self.$saveFeedback.removeClass( 'success error' ).text( self.getResponseMessage( response, 'Error' ) ).addClass( 'error' );
						setTimeout(
							function () {
								self.$saveFeedback.text( '' );
								self.$saveFeedback.removeClass( 'error' );
							},
							2000
						);
					}
				}
			).fail(
				function (xhr) {
					self.saveSettingsRequest = null;
					self.$saveSettings.prop( 'disabled', false );
					self.$refreshMode.prop( 'disabled', false );
					if (xhr.statusText === 'abort') {
						return;
					}
					if ( previousAutoRefresh !== undefined ) {
						self.isAutoRefresh = previousAutoRefresh;
						const targetValue  = previousAutoRefresh ? 'auto' : 'manual';
						self.$refreshMode.filter( '[value="' + targetValue + '"]' ).prop( 'checked', true );
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
						try {
							const parsed = JSON.parse( xhr.responseText );
							if ( parsed.data && parsed.data.message ) {
								errorMsg = parsed.data.message;
							} else {
								errorMsg += ' (Server error, see console)';
							}
						} catch (e) {
							errorMsg += ' (unparseable response)';
						}
					}
					self.$saveFeedback.text( errorMsg ).addClass( 'error' );
					setTimeout(
						function () {
							self.$saveFeedback.text( '' );
							self.$saveFeedback.removeClass( 'error' );
						},
						2000
					);
				}
			);
		},

		fetchLogs: function (append) {
			const self          = this;
			const isInitialLoad = ! append;

			if (this.isRefreshing) {
				return;
			}

			if (isInitialLoad) {
				this.currentOffset  = 0;
				this.hasMoreEntries = true;
			}

			if (this.isLoadingMore) {
				return;
			}

			if ( ! this.hasMoreEntries && append) {
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
				self.$entries.css( 'opacity', '0.5' );
				self.$entryCount.text( 'Loading...' );
				self.$consoleBody.addClass( 'is-loading' );
			} else {
				self.isLoadingMore = true;
				self.showAppendLoading();
			}

			if (this.currentRequest) {
				this.currentRequest.abort();
			}
			this.currentRequest = $.post(
				sscribe_data.ajaxurl,
				data,
				function (response) {
					self.currentRequest = null;
					self.$entries.css( 'opacity', '1' );
					self.$consoleBody.removeClass( 'is-loading' );
					self.isLoadingMore = false;
					if (isInitialLoad) {
						self.isRefreshing = false;
						self.isRefreshingSince = null;
					}

					if (response.success) {
						if ( ! response.data || ! Array.isArray( response.data.entries )) {
							self.showConsoleError( 'Invalid response from server.' );
							return;
						}
						const newEntries = response.data.entries;
						const totalCount = response.data.count;

						if ( response.data.nonce ) {
							sscribe_data.nonce = response.data.nonce;
						}

						if (isInitialLoad) {
							self.renderLogs( newEntries, false, response.data );
						} else {
							self.appendLogs( newEntries );
							self.hideAppendLoading();
						}

						self.currentOffset += newEntries.length;
						self.hasMoreEntries = self.currentOffset < totalCount;
						self.$entryCount.text( ( 1 === totalCount ? '1 entry' : totalCount + ' entries' ) );

						if ( ! self.hasMoreEntries) {
							self.destroyObserver();
						}
					} else {
						self.destroyObserver();
						self.$entryCount.text( 'Error' );
						self.showConsoleError( self.getResponseMessage( response, 'Unable to load debug logs.' ) );
					}
				}
			).fail(
				function (xhr) {
					if (xhr.statusText === 'abort') {
						self.currentRequest = null;
						self.isLoadingMore = false;
						self.isRefreshing = false;
						self.isRefreshingSince = null;
						self.$consoleBody.removeClass( 'is-loading' );
						self.hideAppendLoading();
						return;
					}
					self.currentRequest = null;
					self.$entries.css( 'opacity', '1' );
					self.$consoleBody.removeClass( 'is-loading' );
					self.isLoadingMore = false;
					self.isRefreshing = false;
					self.isRefreshingSince = null;
					self.hideAppendLoading();
					self.destroyObserver();
					if (isInitialLoad) {
						self.$entryCount.text( 'Error' );
						let errorMsg = 'Server Error';
						if (xhr.status === 0) {
							errorMsg = 'Network error. Please check your connection.';
						} else {
							errorMsg = 'HTTP ' + xhr.status;
							if (xhr.responseText) {
								try {
									const parsed = JSON.parse( xhr.responseText );
									if ( parsed.data && parsed.data.message ) {
										errorMsg = parsed.data.message;
									} else {
										errorMsg += ' - ' + xhr.responseText.substring( 0, 100 );
									}
								} catch (e) {
									errorMsg += ' (unparseable response)';
								}
							}
						}
						self.showConsoleError( errorMsg );
					} else {
						self.$entries.find( '.sscribe-debug-append-error' ).remove();
						self.$entries.append(
							'<div class="sscribe-debug-append-error">' +
							'Failed to load more entries. <button type="button" class="sscribe-button sscribe-button-sm sscribe-debug-retry-append">Retry</button>' +
							'</div>'
						);
					}
				}
			);
		},

		retryAppend: function () {
			this.$entries.find( '.sscribe-debug-append-error' ).remove();
			this.isLoadingMore = false;
			this.fetchLogs( true );
		},

		buildLogsHtml: function (entries) {
			let html = '';
			entries.forEach(
				function (entry) {
					html += buildEntryHtml( entry );
				}
			);
			return html;
		},

		renderLogs: function (entries, skipObserver, extraData) {
			if ( ! entries || entries.length === 0) {
				this.$entries.empty();
				this.$empty.show();
				this.destroyObserver();

				if (extraData) {
					if (extraData.debug_enabled === false) {
						this.$empty.find( 'p' ).text( 'Debug logging is disabled. Enable it in Settings above to capture logs.' );
					} else if (extraData.status === 'no_log_file' && extraData.debug_enabled) {
						this.$empty.find( 'p' ).text( 'Debug is enabled but no log file exists yet. Run an export to generate logs.' );
					} else if (extraData.status === 'rotated') {
						this.$empty.find( 'p' ).text( 'This rotated log file is empty.' );
					} else {
						this.$empty.find( 'p' ).text( this.defaultEmptyMessage );
					}
				} else {
					this.$empty.find( 'p' ).text( this.defaultEmptyMessage );
				}
				return;
			}

			this.$empty.hide();
			this.$empty.find( 'p' ).text( this.defaultEmptyMessage );
			this.cleanupBeforeRender();

			// Preserve scroll position during auto-refresh updates.
			const consoleBody = document.getElementById( 'sscribe-debug-console-body' );
			let scrollTop = 0;
			const wasAtBottom = consoleBody ? (consoleBody.scrollHeight - consoleBody.scrollTop - consoleBody.clientHeight < 50) : false;
			if (consoleBody) {
				scrollTop = consoleBody.scrollTop;
			}

			this.$entries.html( this.buildLogsHtml( entries ) );

			if (consoleBody) {
				if (wasAtBottom) {
					consoleBody.scrollTop = consoleBody.scrollHeight;
				} else {
					consoleBody.scrollTop = scrollTop;
				}
			}

			if ( ! skipObserver) {
				this.setupObserver();
			}
		},

		cleanupBeforeRender: function () {
			this.isLoadingMore = false;
			this.destroyObserver();
		},

		appendLogs: function (entries) {
			if ( ! entries || entries.length === 0) {
				return;
			}

			this.destroyObserver();

			const html = this.buildLogsHtml( entries );
			this.$entries.append( html );

			this.setupObserver();
		},

		showAppendLoading: function () {
			this.$entries.append( '<div class="sscribe-debug-append-loading">Loading more entries...</div>' );
		},

		hideAppendLoading: function () {
			this.$entries.find( '.sscribe-debug-append-loading' ).remove();
		},

		setupObserver: function () {
			if ( ! this.hasMoreEntries) {
				return;
			}
			if ( ! this.$consoleBody[0]) {
				return;
			}

			const self            = this;
			const sentinel        = document.createElement( 'div' );
			sentinel.id           = 'sscribe-infinite-scroll-sentinel';
			sentinel.style.height = '1px';
			sentinel.style.width  = '100%';
			this.$entries.find( '#sscribe-infinite-scroll-sentinel' ).remove();
			this.$entries.append( sentinel );

			this.observer = new IntersectionObserver(
				function (entries) {
					if (entries[0].isIntersecting && ! self.isLoadingMore && self.hasMoreEntries) {
						self.fetchLogs( true );
					}
				},
				{ root: this.$consoleBody[0], rootMargin: '50px', threshold: 0 }
			);

			this.observer.observe( sentinel );
		},

		destroyObserver: function () {
			if (this.observer) {
				this.observer.disconnect();
				this.observer = null;
			}
			this.$entries.find( '#sscribe-infinite-scroll-sentinel' ).remove();
		},

		clearLogs: function () {
			const self = this;
			const data = {
				action: 'sscribe_debug_clear_logs',
				nonce: sscribe_data.nonce,
			};

			self.$clearBtn.prop( 'disabled', true );

			$.post(
				sscribe_data.ajaxurl,
				data,
				function (response) {
					self.$clearBtn.prop( 'disabled', false );
					self.$clearBtn.siblings( '.sscribe-feedback' ).remove();
					if (response.success) {
						if ( response.data && response.data.nonce ) {
							sscribe_data.nonce = response.data.nonce;
						}
						const originalText = self.$clearBtn.data('original-text') || self.clearBtnOriginalText;
						self.$clearBtn.text( originalText );
						self.$clearBtn.after( '<span class="sscribe-feedback sscribe-feedback-success">Cleared!</span>' );
						setTimeout(
							function () {
								self.$clearBtn.siblings( '.sscribe-feedback' ).remove();
							},
							2000
						);
						self.destroyObserver();
						self.fetchLogs();
					} else {
						const originalText = self.$clearBtn.data('original-text') || self.clearBtnOriginalText;
						self.$clearBtn.text( originalText );
						self.$clearBtn.after( '<span class="sscribe-feedback sscribe-feedback-error">' + escHtml( self.getResponseMessage( response, 'Error' ) ) + '</span>' );
						setTimeout(
							function () {
								self.$clearBtn.siblings( '.sscribe-feedback' ).remove();
							},
							2000
						);
					}
				}
			).fail(
				function () {
					const originalText = self.$clearBtn.data('original-text') || self.clearBtnOriginalText;
					self.$clearBtn.prop( 'disabled', false ).text( originalText );
					self.$clearBtn.data( 'confirming', false ).removeClass( 'sscribe-btn-confirming' );
					self.$clearBtn.after( '<span class="sscribe-feedback sscribe-feedback-error">Error</span>' );
					setTimeout(
						function () {
							self.$clearBtn.siblings( '.sscribe-feedback' ).remove();
						},
						2000
					);
				}
			);
		},

		downloadViaForm: function (url, data) {
			const form         = document.createElement( 'form' );
			form.method        = 'POST';
			form.action        = url;
			form.target        = '_blank';
			form.style.display = 'none';
			Object.keys( data ).forEach(
				function (key) {
					const input = document.createElement( 'input' );
					input.type  = 'hidden';
					input.name  = key;
					input.value = data[key];
					form.appendChild( input );
				}
			);
			document.body.appendChild( form );
			form.submit();
			setTimeout(
				function () {
					if (form.parentNode) {
						form.remove();
					}
				},
				100
			);
		},

		exportLogs: function () {
			const self = this;
			if ( ! this.hasRequiredDom() || ! sscribe_data || ! sscribe_data.nonce ) {
				return;
			}
			const data = {
				action: 'sscribe_debug_export_logs',
				nonce: sscribe_data.nonce,
				filter_level: this.currentFilter,
				search: this.searchQuery,
				session_id: this.sessionFilter,
			};

			self.$exportBtn.prop( 'disabled', true );
			self.$exportBtn.find( '.sscribe-export-btn-scope' ).text( ' (downloading...)' );
			this.downloadViaForm( sscribe_data.ajaxurl, data );
			setTimeout(
				function () {
					self.$exportBtn.prop( 'disabled', false );
					self.updateExportButtonScope();
				},
				5000
			);

			$.get(
				sscribe_data.ajaxurl,
				{ action: 'sscribe_debug_refresh_nonce', nonce: sscribe_data.nonce },
				function (response) {
					if ( response && response.success && response.data && response.data.nonce ) {
						sscribe_data.nonce = response.data.nonce;
					}
				}
			);
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

			this.rotatedRequest = $.post(
				sscribe_data.ajaxurl,
				data,
				function (response) {
					self.rotatedRequest = null;
					if (response.success && response.data && Array.isArray( response.data.files )) {
						self.renderRotatedLogs( response.data.files );
					} else {
						self.$rotatedBody.html(
							'<div class="sscribe-debug-rotated-empty">' +
							escHtml( self.getResponseMessage( response, 'Unable to load rotated logs.' ) ) +
							'</div>'
						);
					}
				}
			).fail(
				function (xhr) {
					if (xhr.statusText === 'abort') {
						return;
					}
					self.rotatedRequest = null;
					let errMsg = 'Unable to load rotated logs.';
					if (xhr.status === 0) {
						errMsg = 'Network error — could not load rotated logs.';
					}
					self.$rotatedBody.html(
						'<div class="sscribe-debug-rotated-empty">' + escHtml( errMsg ) + '</div>'
					);
				}
			);
		},

		renderRotatedLogs: function (files) {
			if ( ! files || files.length === 0) {
				this.$rotatedBody.html( '<div class="sscribe-debug-rotated-empty">No rotated log files.</div>' );
				return;
			}

			let html = '';

			files.forEach(
				function (file) {
					html += '<div class="sscribe-debug-rotated-file">';
					html += '<div class="sscribe-debug-rotated-file-info">';
					html += '<span class="sscribe-debug-rotated-file-name">' + escHtml( file.name ) + '</span>';
					html +=
					'<span class="sscribe-debug-rotated-file-meta">' +
					escHtml( file.size ) +
					' - ' +
					escHtml( file.date ) +
					'</span>';
					html += '</div>';
					html += '<div class="sscribe-debug-rotated-file-actions">';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-outline sscribe-rotated-view" data-file="' +
					escAttr( file.name ) +
					'" aria-label="View rotated log ' + escAttr( file.name ) + '">View</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-secondary sscribe-rotated-export" data-file="' +
					escAttr( file.name ) +
					'" aria-label="Export rotated log ' + escAttr( file.name ) + '">Export</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-sm sscribe-button-danger sscribe-rotated-delete" data-file="' +
					escAttr( file.name ) +
					'" aria-label="Delete rotated log ' + escAttr( file.name ) + '">Delete</button>';
					html += '</div></div>';
				}
			);

			this.$rotatedBody.html( html );
		},

		viewRotatedLog: function (filename) {
			const self = this;

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

			this.viewRotatedRequest = $.post(
				sscribe_data.ajaxurl,
				data,
				function (response) {
					self.viewRotatedRequest = null;
					if (response.success) {
						self.isViewingRotated       = true;
						self.currentRotatedFilename = filename;
						self.$entries.find( '.sscribe-debug-rotated-banner' ).remove();
						const entries = Array.isArray( response.data.entries ) ? response.data.entries : [];
						const rotatedCount = parseInt( response.data.count, 10 ) || 0;
						self.renderLogs( entries, true, { status: 'rotated', count: rotatedCount } );
						self.$entryCount.text( ( 1 === rotatedCount ? '1 entry' : rotatedCount + ' entries' ) + ' (rotated)' );
						self.$entries.prepend(
							'<div class="sscribe-debug-rotated-banner">' +
							'<span>Viewing archived log: ' +
							escHtml( filename ) +
							'</span>' +
							'<button type="button" class="sscribe-button sscribe-button-primary" id="sscribe-back-to-current">Back to current log</button>' +
							'</div>'
						);
						self.$entries.find( '#sscribe-back-to-current' ).off( 'click' ).on(
							'click',
							function () {
								self.backToCurrentLog();
							}
						);
					} else {
						self.$entryCount.text( 'Error' );
						self.showConsoleError( self.getResponseMessage( response, 'Unable to open rotated log.' ) );
					}
				}
			).fail(
				function (xhr) {
					if (xhr.statusText === 'abort') {
						return;
					}
					self.viewRotatedRequest = null;
					self.isViewingRotated = false;
					self.$entryCount.text( 'Error' );
					self.showConsoleError( 'Unable to open rotated log.' );
					setTimeout(
						function () {
							self.fetchLogs();
						},
						2000
					);
				}
			);
		},

		backToCurrentLog: function () {
			this.isViewingRotated       = false;
			this.currentRotatedFilename = '';
			this.hasMoreEntries         = true;
			this.currentOffset          = 0;
			this.isLoadingMore          = false;
			this.isRefreshing           = false;
			this.isRefreshingSince      = null;
			this.$entries.empty();
			this.$entryCount.text( 'Loading...' );
			this.$consoleBody.addClass( 'is-loading' );
			this.fetchLogs();
			if ( this.isAutoRefresh ) {
				this.startAutoRefresh();
			}
		},

		exportRotatedLog: function (filename) {
			const data = {
				action: 'sscribe_debug_export_logs',
				nonce: sscribe_data.nonce,
				filename: filename,
			};

			this.downloadViaForm( sscribe_data.ajaxurl, data );
		},

		deleteRotatedLog: function (filename, $btn) {
			const self = this;

			if ( ! $btn) {
				return;
			}

			if ($btn.data( 'confirming' )) {
				$btn.data( 'confirming', false ).removeClass( 'sscribe-btn-confirming' ).text( 'Delete' );
				$btn.prop( 'disabled', true );
				self._executeDeleteRotatedLog( filename, $btn );
				return;
			}

			$btn.data( 'confirming', true ).addClass( 'sscribe-btn-confirming' ).text( 'Click to confirm' );
			setTimeout(
				function () {
					if ($btn.data( 'confirming' )) {
						$btn.data( 'confirming', false ).removeClass( 'sscribe-btn-confirming' ).text( 'Delete' );
					}
				},
				3000
			);
		},

		_executeDeleteRotatedLog: function (filename, $btn) {
			const self = this;
			const data = {
				action: 'sscribe_debug_delete_rotated',
				nonce: sscribe_data.nonce,
				filename: filename,
			};

			if ($btn) {
				$btn.prop( 'disabled', true ).text( 'Deleting...' );
			}

			$.post(
				sscribe_data.ajaxurl,
				data,
				function (response) {
					if ($btn) {
						$btn.prop( 'disabled', false ).text( 'Delete' );
					}
					if (response.success) {
						if ( response.data && response.data.nonce ) {
							sscribe_data.nonce = response.data.nonce;
						}
						self.fetchRotatedLogs();
					} else {
						if ($btn) {
							const $row = $btn.closest( '.sscribe-debug-rotated-file' );
							if ($row.length) {
								$row.find( '.sscribe-debug-rotated-file-actions' ).after(
									'<div class="sscribe-rotated-error">' +
									escHtml( self.getResponseMessage( response, 'Error' ) ) +
									'</div>'
								);
								setTimeout(
									function () {
										$row.find( '.sscribe-rotated-error' ).remove();
									},
									3000
								);
							}
						}
					}
				}
			).fail(
				function () {
					if ($btn) {
						$btn.prop( 'disabled', false ).text( 'Delete' );
						const $row = $btn.closest( '.sscribe-debug-rotated-file' );
						if ($row.length) {
							$row.find( '.sscribe-debug-rotated-file-actions' ).after(
								'<div class="sscribe-rotated-error">Error</div>'
							);
							setTimeout(
								function () {
									$row.find( '.sscribe-rotated-error' ).remove();
								},
								3000
							);
						}
					}
				}
			);
		},
	};

	function buildEntryHtml(entry) {
		const allowedLevels = ['all', 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'raw'];
		const entryLevel    = (entry.level && typeof entry.level === 'string') ? entry.level.toLowerCase() : 'info';
		const badgeClass    = allowedLevels.includes( entryLevel ) ? entryLevel : 'info';
		let contextHtml = '';

		if (entry.context && Object.keys( entry.context ).length > 0) {
			let contextRows = '';
			Object.keys( entry.context ).forEach(
				function (key) {
					let value = entry.context[key];
					if (typeof value === 'object') {
						try {
							value = JSON.stringify( value, null, 2 );
						} catch (e) {
							value = '[unserializable object]';
						}
					}
					contextRows += '<div class="sscribe-debug-context-row">' +
					'<span class="sscribe-debug-context-key">' + escHtml( String( key ) ) + '</span>' +
					'<div class="sscribe-debug-context-val"><pre>' + escHtml( String( value ) ) + '</pre></div>' +
					'</div>';
				}
			);
			contextHtml = '<div class="sscribe-debug-entry-context">' + contextRows + '</div>';
		}

		const hasContext = contextHtml !== '';

		return '<div class="sscribe-debug-entry' + (hasContext ? ' has-context' : '') + '"' +
			(hasContext ? ' tabindex="0" role="button" aria-expanded="false" aria-label="Toggle context for: ' + escAttr( String( entry.message || '' ).substring( 0, 50 ) ) + '"' : '') + '>' +
			'<div class="sscribe-debug-entry-header">' +
			'<span class="sscribe-debug-entry-badge ' + escAttr( badgeClass ) + '">' + escHtml( entry.level || 'INFO' ) + '</span>' +
			'<span class="sscribe-debug-entry-time">' + escHtml( entry.timestamp || '' ) + '</span>' +
			'<span class="sscribe-debug-entry-message">' + escHtml( entry.message || '' ) + '</span>' +
			(hasContext ? '<span class="sscribe-debug-entry-toggle" aria-hidden="true">\u25B6</span>' : '') +
			'</div>' +
			contextHtml +
			'</div>';
	}

	window.SScribeDebugConsole = SScribeDebugConsole;

	jQuery( document ).ready(
		function () {
			if ( window.SScribeDebugConsole ) {
				window.SScribeDebugConsole.init();
			}
		}
	);

})( jQuery );
