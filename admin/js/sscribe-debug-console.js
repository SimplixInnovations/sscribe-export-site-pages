/**
 * SScribe Debug Console JavaScript
 *
 * @package SScribe_Export_Site_Pages
 */

(function($) {
	'use strict';

	const debounce = function( wait, fn ) {
		let timeout;
		return function() {
			const context = this;
			const args = arguments;
			clearTimeout( timeout );
			timeout = setTimeout( function() {
				fn.apply( context, args );
			}, wait );
		};
	};

	const SScribeDebugConsole = {
		refreshInterval: null,
		isAutoRefresh: true,
		currentFilter: 'ALL',
		searchQuery: '',
		initialized: false,
		isViewingRotated: false,

		init: function() {
			if ( this.initialized ) {
				return;
			}
			this.initialized = true;
			this.cacheDom();
			this.bindEvents();
			this.bindVisibilityHandler();
			this.loadInitialState();
		},

		cacheDom: function() {
			this.$container = $( '#sscribe-admin-wrap' );
			this.$enabled = $( '#sscribe-debug-enabled' );
			this.$level = $( '#sscribe-debug-level' );
			this.$saveSettings = $( '#sscribe-debug-save-settings' );
			this.$saveFeedback = $( '#sscribe-debug-save-feedback' );
			this.$filterLevel = $( '#sscribe-debug-filter-level' );
			this.$searchInput = $( '#sscribe-debug-search' );
			this.$refreshMode = $( 'input[name="sscribe_refresh_mode"]' );
			this.$refreshBtn = $( '#sscribe-debug-refresh-btn' );
			this.$consoleBody = $( '#sscribe-debug-console-body' );
			this.$entries = $( '#sscribe-debug-entries' );
			this.$empty = $( '#sscribe-debug-empty' );
			this.$entryCount = $( '#sscribe-debug-entry-count' );
			this.$clearBtn = $( '#sscribe-debug-clear-btn' );
			this.$exportBtn = $( '#sscribe-debug-export-btn' );
			this.$rotatedBody = $( '#sscribe-debug-rotated-body' );
		},

		bindEvents: function() {
			const self = this;

			this.$saveSettings.on( 'click', function() {
				self.saveSettings();
			} );

			this.$filterLevel.on( 'change', function() {
				self.currentFilter = $( this ).val();
				self.fetchLogs();
			} );

			this.$searchInput.on( 'input', debounce( 300, function() {
				self.searchQuery = $( this ).val();
				self.fetchLogs();
			} ) );

			this.$refreshMode.on( 'change', function() {
				self.isAutoRefresh = $( this ).val() === 'auto';
				if ( self.isAutoRefresh ) {
					self.startAutoRefresh();
				} else {
					self.stopAutoRefresh();
				}
				self.saveSettings();
			} );

			this.$refreshBtn.on( 'click', function() {
				self.fetchLogs();
				self.fetchRotatedLogs();
			} );

			this.$clearBtn.on( 'click', function() {
				if ( confirm( 'Clear all current log entries?' ) ) {
					self.clearLogs();
				}
			} );

			this.$exportBtn.on( 'click', function() {
				self.exportLogs();
			} );

			this.$entries.on( 'click', '.sscribe-debug-entry', function() {
				$( this ).toggleClass( 'expanded' );
			} );

			this.$entries.on( 'keydown', '.sscribe-debug-entry', function( e ) {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					$( this ).toggleClass( 'expanded' );
				}
			} );

			this.$rotatedBody.on( 'click', '.sscribe-rotated-view', function() {
				self.viewRotatedLog( $( this ).data( 'file' ) );
			} );

			this.$rotatedBody.on( 'click', '.sscribe-rotated-export', function() {
				self.exportRotatedLog( $( this ).data( 'file' ) );
			} );

			this.$rotatedBody.on( 'click', '.sscribe-rotated-delete', function() {
				if ( confirm( 'Delete this log file?' ) ) {
					self.deleteRotatedLog( $( this ).data( 'file' ) );
				}
			} );
		},

		bindVisibilityHandler: function() {
			const self = this;
			$( document ).on( 'visibilitychange', function() {
				if ( document.hidden ) {
					self.stopAutoRefresh();
				} else if ( self.isAutoRefresh ) {
					self.startAutoRefresh();
				}
			} );
		},

		loadInitialState: function() {
			this.isAutoRefresh = this.$refreshMode.filter( ':checked' ).val() === 'auto';
			this.fetchLogs();
			this.fetchRotatedLogs();

			if ( this.isAutoRefresh ) {
				this.startAutoRefresh();
			}
		},

		startAutoRefresh: function() {
			const self = this;
			this.stopAutoRefresh();
			this.refreshInterval = setInterval( function() {
				self.fetchLogs();
			}, 10000 );
		},

		stopAutoRefresh: function() {
			if ( this.refreshInterval ) {
				clearInterval( this.refreshInterval );
				this.refreshInterval = null;
			}
		},

		saveSettings: function() {
			const self = this;
			const data = {
				action: 'sscribe_debug_save_settings',
				nonce: sscribe_data.nonce,
				debug_enabled: this.$enabled.is( ':checked' ),
				log_level: this.$level.val(),
				auto_refresh: this.isAutoRefresh ? '1' : '0'
			};

			$.post( sscribe_data.ajaxurl, data, function( response ) {
				if ( response.success ) {
					self.$saveFeedback.text( 'Saved!' ).addClass( 'success' );
					setTimeout( function() {
						self.$saveFeedback.text( '' );
						self.$saveFeedback.removeClass( 'success' );
					}, 2000 );
				} else {
					self.$saveFeedback.text( 'Error' ).addClass( 'error' );
					setTimeout( function() {
						self.$saveFeedback.text( '' );
						self.$saveFeedback.removeClass( 'error' );
					}, 2000 );
				}
			} ).fail( function() {
				self.$saveFeedback.text( 'Error' ).addClass( 'error' );
				setTimeout( function() {
					self.$saveFeedback.text( '' );
					self.$saveFeedback.removeClass( 'error' );
				}, 2000 );
			} );
		},

		fetchLogs: function() {
			const self = this;
			const data = {
				action: 'sscribe_debug_fetch_logs',
				nonce: sscribe_data.nonce,
				filter_level: this.currentFilter,
				search: this.searchQuery
			};

			self.$entries.css( 'opacity', '0.5' );
			self.$entryCount.text( 'Loading...' );

			$.get( sscribe_data.ajaxurl, data, function( response ) {
				self.$entries.css( 'opacity', '1' );
				if ( response.success ) {
					self.renderLogs( response.data.entries );
					self.$entryCount.text( response.data.count + ' entries' );
				}
			} ).fail( function() {
				self.$entries.css( 'opacity', '1' );
				self.$entryCount.text( 'Error loading logs' );
			} );
		},

		renderLogs: function( entries ) {
			if ( ! entries || entries.length === 0 ) {
				this.$entries.empty();
				this.$empty.show();
				return;
			}

			this.$empty.hide();
			let html = '';

			entries.forEach( function( entry ) {
				const badgeClass = entry.level.toLowerCase();
				let contextHtml = '';

				if ( entry.context ) {
					Object.keys( entry.context ).forEach( function( key ) {
						let value = entry.context[ key ];
						if ( typeof value === 'object' ) {
							value = JSON.stringify( value );
						}
						contextHtml += '<div class="sscribe-debug-context-row">';
						contextHtml += '<span class="sscribe-debug-context-key">' + escHtml( key ) + ':</span>';
						contextHtml += '<span class="sscribe-debug-context-value">' + escHtml( String( value ) ) + '</span>';
						contextHtml += '</div>';
					} );
				}

				html += '<div class="sscribe-debug-entry" tabindex="0" role="button" aria-label="Toggle log entry details">';
				html += '<div class="sscribe-debug-entry-header">';
				html += '<span class="sscribe-debug-entry-time">' + escHtml( entry.timestamp ) + '</span>';
				html += '<span class="sscribe-debug-entry-badge ' + badgeClass + '">' + escHtml( entry.level ) + '</span>';
				html += '<span class="sscribe-debug-entry-message">' + escHtml( entry.message ) + '</span>';
				html += '</div>';
				if ( contextHtml ) {
					html += '<div class="sscribe-debug-entry-context" aria-hidden="true">' + contextHtml + '</div>';
				}
				html += '</div>';
			} );

			this.$entries.html( html );
		},

		clearLogs: function() {
			const self = this;
			const data = {
				action: 'sscribe_debug_clear_logs',
				nonce: sscribe_data.nonce
			};

			$.post( sscribe_data.ajaxurl, data, function( response ) {
				if ( response.success ) {
					self.$saveFeedback.text( 'Logs cleared' ).addClass( 'success' );
					setTimeout( function() {
						self.$saveFeedback.text( '' );
						self.$saveFeedback.removeClass( 'success' );
					}, 2000 );
					self.fetchLogs();
					self.fetchRotatedLogs();
				} else {
					self.$saveFeedback.text( 'Error' ).addClass( 'error' );
					setTimeout( function() {
						self.$saveFeedback.text( '' );
						self.$saveFeedback.removeClass( 'error' );
					}, 2000 );
				}
			} ).fail( function() {
				self.$saveFeedback.text( 'Error' ).addClass( 'error' );
				setTimeout( function() {
					self.$saveFeedback.text( '' );
					self.$saveFeedback.removeClass( 'error' );
				}, 2000 );
			} );
		},

		exportLogs: function() {
			const data = {
				action: 'sscribe_debug_export_logs',
				nonce: sscribe_data.nonce,
				filter_level: this.currentFilter,
				search: this.searchQuery
			};

			window.location.href = sscribe_data.ajaxurl + '?' + $.param( data );
		},

		fetchRotatedLogs: function() {
			const self = this;
			const data = {
				action: 'sscribe_debug_get_files',
				nonce: sscribe_data.nonce
			};

			$.get( sscribe_data.ajaxurl, data, function( response ) {
				if ( response.success ) {
					self.renderRotatedLogs( response.data.files );
				}
			} );
		},

		renderRotatedLogs: function( files ) {
			if ( ! files || files.length === 0 ) {
				this.$rotatedBody.html( '<div class="sscribe-debug-rotated-empty">No rotated log files.</div>' );
				return;
			}

			let html = '';

			files.forEach( function( file ) {
				html += '<div class="sscribe-debug-rotated-file">';
				html += '<div class="sscribe-debug-rotated-file-info">';
				html += '<span class="sscribe-debug-rotated-file-name">' + escHtml( file.name ) + '</span>';
				html += '<span class="sscribe-debug-rotated-file-meta">' + escHtml( file.size ) + ' - ' + escHtml( file.date ) + '</span>';
				html += '</div>';
				html += '<div class="sscribe-debug-rotated-file-actions">';
				html += '<button type="button" class="sscribe-button sscribe-rotated-view" data-file="' + escHtml( file.name ) + '">View</button>';
				html += '<button type="button" class="sscribe-button sscribe-rotated-export" data-file="' + escHtml( file.name ) + '">Export</button>';
				html += '<button type="button" class="sscribe-button sscribe-button-danger sscribe-rotated-delete" data-file="' + escHtml( file.name ) + '">Delete</button>';
				html += '</div></div>';
			} );

			this.$rotatedBody.html( html );
		},

		viewRotatedLog: function( filename ) {
			const data = {
				action: 'sscribe_debug_fetch_rotated',
				nonce: sscribe_data.nonce,
				filename: filename
			};

			const self = this;
			$.get( sscribe_data.ajaxurl, data, function( response ) {
				if ( response.success ) {
					self.isViewingRotated = true;
					self.currentRotatedFilename = filename;
					self.renderLogs( response.data.entries );
					self.$entryCount.text( response.data.count + ' entries (rotated)' );
					self.$entries.prepend(
						'<div class="sscribe-debug-rotated-banner">' +
						'<span>Viewing archived log: ' + escHtml( filename ) + '</span>' +
						'<button type="button" class="sscribe-button sscribe-button-primary" id="sscribe-back-to-current">Back to current log</button>' +
						'</div>'
					);
					self.$entries.find( '#sscribe-back-to-current' ).on( 'click', function() {
						self.backToCurrentLog();
					} );
				}
			} );
		},

		backToCurrentLog: function() {
			this.isViewingRotated = false;
			this.currentRotatedFilename = '';
			this.fetchLogs();
			this.fetchRotatedLogs();
		},

		exportRotatedLog: function( filename ) {
			const data = {
				action: 'sscribe_debug_export_logs',
				nonce: sscribe_data.nonce,
				filename: filename
			};

			window.location.href = sscribe_data.ajaxurl + '?' + $.param( data );
		},

		deleteRotatedLog: function( filename ) {
			const self = this;
			const data = {
				action: 'sscribe_debug_delete_rotated',
				nonce: sscribe_data.nonce,
				filename: filename
			};

			$.post( sscribe_data.ajaxurl, data, function( response ) {
				if ( response.success ) {
					self.fetchRotatedLogs();
				}
			} );
		}
	};

	function escHtml( str ) {
		if ( ! str ) {
			return '';
		}
		const div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	}

	$.debounce = function( wait, fn ) {
		let timeout;
		return function() {
			const context = this;
			const args = arguments;
			clearTimeout( timeout );
			timeout = setTimeout( function() {
				fn.apply( context, args );
			}, wait );
		};
	};

	$( document ).ready( function() {
		if ( $( '#sscribe-tab-debug' ).hasClass( 'sscribe-tab-active' ) ) {
			SScribeDebugConsole.init();
		}
	} );

}( jQuery ));
