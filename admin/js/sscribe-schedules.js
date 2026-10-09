/* global wp */
(function ($) {
	'use strict';

	const __ = wp.i18n.__;
	const sprintf = wp.i18n.sprintf;

	const SScribeSchedules = {
		meta: null,
		schedules: [],
		loaded: false,
		editing: null,
		busy: false,

		init: function () {
			const $tab = $('#sscribe-tab-btn-schedules');
			if (!$tab.length) {
				return;
			}
			$tab.on('click.sscribeSchedules', $.proxy(this.ensureLoaded, this));
			$('#sscribe-schedule-add').on('click.sscribeSchedules', $.proxy(this.openForm, this, null));
			$(document)
				.on('click.sscribeSchedules', '[data-schedule-action]', $.proxy(this.onRowAction, this))
				.on('submit.sscribeSchedules', '#sscribe-schedule-form', $.proxy(this.submitForm, this))
				.on('click.sscribeSchedules', '#sscribe-schedule-cancel', $.proxy(this.closeForm, this))
				.on('change.sscribeSchedules', '#sscribe-schedule-frequency', $.proxy(this.syncFrequencyFields, this))
				.on('click.sscribeSchedules', '#sscribe-destination-add', $.proxy(this.addDestinationRow, this, null))
				.on('click.sscribeSchedules', '.sscribe-destination-remove', $.proxy(this.removeDestinationRow, this))
				.on(
					'change.sscribeSchedules',
					'.sscribe-destination-type',
					$.proxy(this.onDestinationTypeChange, this)
				);
			const params = new URLSearchParams(window.location.search);
			if (params.get('tab') === 'schedules') {
				this.ensureLoaded();
			}
		},

		ensureLoaded: function () {
			if (this.loaded) {
				return;
			}
			this.loaded = true;
			this.refresh();
		},

		request: function (action, data) {
			const payload = $.extend({ action: action, nonce: sscribe_data.nonce }, data || {});
			return $.post(sscribe_data.ajaxurl, payload);
		},

		refresh: function () {
			const self = this;
			$('#sscribe-schedules-list').attr('aria-busy', 'true');
			this.request('sscribe_schedules_list')
				.done(function (response) {
					if (!response || !response.success) {
						self.notice(self.errorMessage(response), 'error');
						return;
					}
					self.meta = response.data.meta;
					self.schedules = response.data.schedules || [];
					self.renderList();
				})
				.fail(function (xhr) {
					self.notice(self.errorMessage(xhr.responseJSON), 'error');
				})
				.always(function () {
					$('#sscribe-schedules-list').attr('aria-busy', 'false');
				});
		},

		errorMessage: function (response) {
			if (response && response.data && response.data.message) {
				return response.data.message;
			}
			return __('The request failed. Please try again.', 'sscribe-export-site-pages');
		},

		notice: function (message, kind) {
			const $notice = $('#sscribe-schedules-notice');
			$notice
				.removeClass('is-error is-success')
				.addClass(kind === 'error' ? 'is-error' : 'is-success')
				.text(message)
				.removeAttr('hidden');
			clearTimeout(this.noticeTimer);
			this.noticeTimer = setTimeout(function () {
				$notice.attr('hidden', 'hidden');
			}, 8000);
		},

		esc: function (value) {
			return $('<div>')
				.text(value === undefined || value === null ? '' : String(value))
				.html();
		},

		frequencyLabel: function (schedule) {
			const hour = String(schedule.hour).padStart(2, '0') + ':00';
			switch (schedule.frequency) {
				case 'hourly':
					return __('Every hour', 'sscribe-export-site-pages');
				case 'daily':
					return sprintf(__('Daily at %s', 'sscribe-export-site-pages'), hour);
				case 'weekly':
					return sprintf(
						__('Weekly on %1$s at %2$s', 'sscribe-export-site-pages'),
						this.weekdayName(schedule.weekday),
						hour
					);
				case 'monthly':
					return sprintf(
						__('Monthly on day %1$d at %2$s', 'sscribe-export-site-pages'),
						schedule.day_of_month,
						hour
					);
				default:
					return schedule.frequency;
			}
		},

		weekdayName: function (index) {
			const names = [
				__('Sunday', 'sscribe-export-site-pages'),
				__('Monday', 'sscribe-export-site-pages'),
				__('Tuesday', 'sscribe-export-site-pages'),
				__('Wednesday', 'sscribe-export-site-pages'),
				__('Thursday', 'sscribe-export-site-pages'),
				__('Friday', 'sscribe-export-site-pages'),
				__('Saturday', 'sscribe-export-site-pages'),
			];
			return names[Number(index)] || names[1];
		},

		statusBadge: function (schedule) {
			if (schedule.is_running) {
				return (
					'<span class="sscribe-badge sscribe-badge-info">' +
					this.esc(__('Running', 'sscribe-export-site-pages')) +
					'</span>'
				);
			}
			if (!schedule.enabled) {
				return '<span class="sscribe-badge">' + this.esc(__('Paused', 'sscribe-export-site-pages')) + '</span>';
			}
			if (schedule.last_run_status === 'failed') {
				return (
					'<span class="sscribe-badge sscribe-badge-error">' +
					this.esc(__('Last run failed', 'sscribe-export-site-pages')) +
					'</span>'
				);
			}
			if (schedule.last_run_status === 'success') {
				return (
					'<span class="sscribe-badge sscribe-badge-retained">' +
					this.esc(__('Last run succeeded', 'sscribe-export-site-pages')) +
					'</span>'
				);
			}
			return (
				'<span class="sscribe-badge sscribe-badge-info">' +
				this.esc(__('Scheduled', 'sscribe-export-site-pages')) +
				'</span>'
			);
		},

		renderList: function () {
			const $list = $('#sscribe-schedules-list');
			const self = this;
			if (!this.schedules.length) {
				$list.html(
					'<div class="sscribe-history-empty"><em>' +
						this.esc(__('No schedules yet. Add one to export on a timer.', 'sscribe-export-site-pages')) +
						'</em></div>'
				);
				$('#sscribe-schedule-add').prop('disabled', !this.meta.has_room);
				return;
			}
			let html = '<table class="sscribe-schedules-table"><thead><tr>';
			html += '<th scope="col">' + this.esc(__('Schedule', 'sscribe-export-site-pages')) + '</th>';
			html += '<th scope="col">' + this.esc(__('When', 'sscribe-export-site-pages')) + '</th>';
			html += '<th scope="col">' + this.esc(__('Status', 'sscribe-export-site-pages')) + '</th>';
			html +=
				'<th scope="col" class="sscribe-schedules-col-actions"><span class="screen-reader-text">' +
				this.esc(__('Actions', 'sscribe-export-site-pages')) +
				'</span></th>';
			html += '</tr></thead><tbody>';
			this.schedules.forEach(function (schedule) {
				const destinations = (schedule.destinations || []).length;
				html += '<tr data-schedule-id="' + self.esc(schedule.id) + '">';
				html += '<td><strong>' + self.esc(schedule.label || schedule.id) + '</strong>';
				html += '<div class="sscribe-schedules-meta">';
				html += self.esc(
					schedule.formats
						.map(function (f) {
							return f.toUpperCase();
						})
						.join(', ')
				);
				html += ' · ' + self.esc(schedule.post_type);
				if (schedule.language) {
					html += ' · ' + self.esc(schedule.language);
				}
				if (schedule.incremental) {
					html += ' · ' + self.esc(__('changed pages only', 'sscribe-export-site-pages'));
				}
				if (destinations) {
					html +=
						' · ' +
						self.esc(
							sprintf(
								wp.i18n._n(
									'%d destination',
									'%d destinations',
									destinations,
									'sscribe-export-site-pages'
								),
								destinations
							)
						);
				}
				if (schedule.owner_login) {
					html +=
						' · ' + self.esc(sprintf(__('runs as %s', 'sscribe-export-site-pages'), schedule.owner_login));
				}
				html += '</div></td>';
				html += '<td><div>' + self.esc(self.frequencyLabel(schedule)) + '</div>';
				if (schedule.next_run && schedule.enabled) {
					html +=
						'<div class="sscribe-schedules-meta">' +
						self.esc(sprintf(__('Next: %s', 'sscribe-export-site-pages'), schedule.next_run)) +
						'</div>';
				}
				if (schedule.last_run) {
					html +=
						'<div class="sscribe-schedules-meta">' +
						self.esc(sprintf(__('Last: %s', 'sscribe-export-site-pages'), schedule.last_run)) +
						'</div>';
				}
				html += '</td>';
				html += '<td>' + self.statusBadge(schedule);
				if (schedule.last_error) {
					html += '<div class="sscribe-schedules-error">' + self.esc(schedule.last_error) + '</div>';
				}
				if (schedule.last_delivery) {
					html += '<div class="sscribe-schedules-meta">' + self.esc(schedule.last_delivery) + '</div>';
				}
				html += '</td>';
				html += '<td class="sscribe-schedules-col-actions"><div class="sscribe-history-actions">';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm" data-schedule-action="run" data-id="' +
					self.esc(schedule.id) +
					'">' +
					self.esc(__('Run now', 'sscribe-export-site-pages')) +
					'</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm" data-schedule-action="toggle" data-id="' +
					self.esc(schedule.id) +
					'">' +
					self.esc(
						schedule.enabled
							? __('Pause', 'sscribe-export-site-pages')
							: __('Enable', 'sscribe-export-site-pages')
					) +
					'</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm" data-schedule-action="edit" data-id="' +
					self.esc(schedule.id) +
					'">' +
					self.esc(__('Edit', 'sscribe-export-site-pages')) +
					'</button>';
				html +=
					'<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm sscribe-schedule-delete" data-schedule-action="delete" data-id="' +
					self.esc(schedule.id) +
					'">' +
					self.esc(__('Delete', 'sscribe-export-site-pages')) +
					'</button>';
				html += '</div></td></tr>';
			});
			html += '</tbody></table>';
			$list.html(html);
			$('#sscribe-schedule-add').prop('disabled', !this.meta.has_room);
		},

		onRowAction: function (event) {
			const $button = $(event.currentTarget);
			const id = $button.data('id');
			const action = $button.data('schedule-action');
			const schedule = this.schedules.find(function (s) {
				return s.id === id;
			});
			if (!schedule || this.busy) {
				return;
			}
			if (action === 'edit') {
				this.openForm(schedule);
				return;
			}
			if (action === 'delete') {
				if (!$button.data('confirming')) {
					$button
						.data('confirming', true)
						.addClass('sscribe-btn-confirming')
						.text(__('Click again to delete', 'sscribe-export-site-pages'));
					setTimeout(function () {
						$button
							.removeData('confirming')
							.removeClass('sscribe-btn-confirming')
							.text(__('Delete', 'sscribe-export-site-pages'));
					}, 4000);
					return;
				}
				this.perform('sscribe_schedule_delete', { id: id }, $button);
				return;
			}
			if (action === 'toggle') {
				this.perform('sscribe_schedule_toggle', { id: id, enabled: schedule.enabled ? '0' : '1' }, $button);
				return;
			}
			if (action === 'run') {
				$button.text(__('Running…', 'sscribe-export-site-pages'));
				this.perform('sscribe_schedule_run', { id: id }, $button);
			}
		},

		perform: function (action, data, $button) {
			const self = this;
			this.busy = true;
			$button.prop('disabled', true);
			this.request(action, data)
				.done(function (response) {
					if (response && response.success) {
						self.notice(response.data.message || __('Done.', 'sscribe-export-site-pages'), 'success');
					} else {
						self.notice(self.errorMessage(response), 'error');
					}
				})
				.fail(function (xhr) {
					self.notice(self.errorMessage(xhr.responseJSON), 'error');
				})
				.always(function () {
					self.busy = false;
					self.refresh();
					if (window.SScribe && typeof window.SScribe.loadRecentExports === 'function') {
						window.SScribe.loadRecentExports();
					}
				});
		},

		openForm: function (schedule) {
			this.editing = schedule || null;
			const $host = $('#sscribe-schedule-form-host');
			$host.html(this.formHtml(schedule)).removeAttr('hidden');
			this.syncFrequencyFields();
			(schedule && schedule.destinations ? schedule.destinations : []).forEach(
				$.proxy(function (destination) {
					this.addDestinationRow(destination);
				}, this)
			);
			$host.find('#sscribe-schedule-label').trigger('focus');
		},

		closeForm: function () {
			this.editing = null;
			$('#sscribe-schedule-form-host').attr('hidden', 'hidden').empty();
		},

		option: function (value, label, selected) {
			return (
				'<option value="' +
				this.esc(value) +
				'"' +
				(selected ? ' selected' : '') +
				'>' +
				this.esc(label) +
				'</option>'
			);
		},

		formHtml: function (schedule) {
			const self = this;
			const meta = this.meta;
			const s = schedule || {};
			const options = s.format_options || {};
			const formats = s.formats || meta.formats;
			let html = '<form id="sscribe-schedule-form" class="sscribe-schedule-form" novalidate>';
			html +=
				'<h3>' +
				this.esc(
					schedule
						? __('Edit schedule', 'sscribe-export-site-pages')
						: __('New schedule', 'sscribe-export-site-pages')
				) +
				'</h3>';
			html += '<div class="sscribe-format-option-grid">';
			html +=
				'<label class="sscribe-format-option-field sscribe-schedule-field-wide"><span class="sscribe-format-option-label">' +
				this.esc(__('Name', 'sscribe-export-site-pages')) +
				'</span><input type="text" id="sscribe-schedule-label" name="label" maxlength="80" required value="' +
				this.esc(s.label || '') +
				'"></label>';
			html +=
				'<label class="sscribe-format-option-field"><span class="sscribe-format-option-label">' +
				this.esc(__('Frequency', 'sscribe-export-site-pages')) +
				'</span><select id="sscribe-schedule-frequency" name="frequency">';
			meta.frequencies.forEach(function (frequency) {
				html += self.option(
					frequency,
					frequency.charAt(0).toUpperCase() + frequency.slice(1),
					(s.frequency || 'daily') === frequency
				);
			});
			html += '</select></label>';
			html +=
				'<label class="sscribe-format-option-field" data-frequency-field="hour"><span class="sscribe-format-option-label">' +
				this.esc(sprintf(__('Hour (%s)', 'sscribe-export-site-pages'), meta.timezone)) +
				'</span><select name="hour">';
			for (let hour = 0; hour < 24; hour++) {
				html += self.option(hour, String(hour).padStart(2, '0') + ':00', Number(s.hour || 0) === hour);
			}
			html += '</select></label>';
			html +=
				'<label class="sscribe-format-option-field" data-frequency-field="weekday"><span class="sscribe-format-option-label">' +
				this.esc(__('Weekday', 'sscribe-export-site-pages')) +
				'</span><select name="weekday">';
			for (let day = 0; day < 7; day++) {
				html += self.option(
					day,
					self.weekdayName(day),
					Number(s.weekday === undefined ? 1 : s.weekday) === day
				);
			}
			html += '</select></label>';
			html +=
				'<label class="sscribe-format-option-field" data-frequency-field="day_of_month"><span class="sscribe-format-option-label">' +
				this.esc(__('Day of month', 'sscribe-export-site-pages')) +
				'</span><select name="day_of_month">';
			for (let dom = 1; dom <= 28; dom++) {
				html += self.option(dom, dom, Number(s.day_of_month || 1) === dom);
			}
			html += '</select></label>';
			html +=
				'<label class="sscribe-format-option-field"><span class="sscribe-format-option-label">' +
				this.esc(__('Content type', 'sscribe-export-site-pages')) +
				'</span><select name="post_type">';
			meta.post_types.forEach(function (type) {
				html += self.option(type.slug, type.label, (s.post_type || 'page') === type.slug);
			});
			html += '</select></label>';
			html +=
				'<label class="sscribe-format-option-field"><span class="sscribe-format-option-label">' +
				this.esc(__('Status', 'sscribe-export-site-pages')) +
				'</span><select name="post_status">';
			['publish', 'private', 'draft', 'pending', 'future', 'all'].forEach(function (status) {
				html += self.option(status, status, (s.post_status || 'publish') === status);
			});
			html += '</select></label>';
			if (meta.languages.length) {
				html +=
					'<label class="sscribe-format-option-field"><span class="sscribe-format-option-label">' +
					this.esc(__('Language', 'sscribe-export-site-pages')) +
					'</span><select name="language">';
				html += self.option('', __('All languages', 'sscribe-export-site-pages'), !s.language);
				meta.languages.forEach(function (language) {
					html += self.option(language.code, language.name, s.language === language.code);
				});
				html += '</select></label>';
			}
			html +=
				'<div class="sscribe-format-option-field sscribe-schedule-field-wide"><span class="sscribe-format-option-label">' +
				this.esc(__('Formats', 'sscribe-export-site-pages')) +
				'</span><div class="sscribe-schedule-checks">';
			meta.formats.forEach(function (format) {
				html +=
					'<label class="sscribe-format-option-checkbox"><input type="checkbox" name="formats" value="' +
					self.esc(format) +
					'"' +
					(formats.indexOf(format) !== -1 ? ' checked' : '') +
					'> <span>' +
					self.esc(format.toUpperCase()) +
					'</span></label>';
			});
			html += '</div></div>';
			html +=
				'<label class="sscribe-format-option-field"><span class="sscribe-format-option-label">' +
				this.esc(__('Custom fields', 'sscribe-export-site-pages')) +
				'</span><select name="fields_mode">';
			Object.keys(meta.fields_modes).forEach(function (mode) {
				html += self.option(mode, meta.fields_modes[mode], (options.sscribe_include_fields || 'auto') === mode);
			});
			html += '</select></label>';
			html +=
				'<label class="sscribe-format-option-field"><span class="sscribe-format-option-label">' +
				this.esc(__('Keep archives for (days)', 'sscribe-export-site-pages')) +
				'</span><input type="number" name="retention_days" min="0" max="3650" value="' +
				this.esc(s.retention_days || 0) +
				'"><span class="sscribe-schedules-meta">' +
				this.esc(__('0 keeps the default 3 days', 'sscribe-export-site-pages')) +
				'</span></label>';
			html += '<div class="sscribe-format-option-field sscribe-schedule-field-wide sscribe-schedule-checks">';
			html +=
				'<label class="sscribe-format-option-checkbox"><input type="checkbox" name="incremental" value="1"' +
				(s.incremental ? ' checked' : '') +
				'> <span>' +
				this.esc(__('Only pages changed since the last run', 'sscribe-export-site-pages')) +
				'</span></label>';
			html +=
				'<label class="sscribe-format-option-checkbox"><input type="checkbox" name="compliance" value="1"' +
				(options.sscribe_compliance_mode === '1' ? ' checked' : '') +
				'> <span>' +
				this.esc(
					__('Compliance mode (provenance, signed manifest, one-year retention)', 'sscribe-export-site-pages')
				) +
				'</span></label>';
			html +=
				'<label class="sscribe-format-option-checkbox"><input type="checkbox" name="notify" value="1"' +
				(s.notify ? ' checked' : '') +
				'> <span>' +
				this.esc(__('Email me when a run fails', 'sscribe-export-site-pages')) +
				'</span></label>';
			html +=
				'<label class="sscribe-format-option-checkbox"><input type="checkbox" name="enabled" value="1"' +
				(schedule ? (s.enabled ? ' checked' : '') : ' checked') +
				'> <span>' +
				this.esc(__('Enabled', 'sscribe-export-site-pages')) +
				'</span></label>';
			html += '</div>';
			html += '</div>';
			html +=
				'<div class="sscribe-schedule-destinations"><div class="sscribe-schedule-destinations-header"><span class="sscribe-format-option-label">' +
				this.esc(__('Deliver to', 'sscribe-export-site-pages')) +
				'</span>';
			html +=
				'<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm" id="sscribe-destination-add">' +
				this.esc(__('Add destination', 'sscribe-export-site-pages')) +
				'</button></div>';
			html += '<div id="sscribe-destination-rows"></div></div>';
			html +=
				'<div class="sscribe-schedule-form-actions"><button type="submit" class="sscribe-button sscribe-button-primary">' +
				this.esc(
					schedule
						? __('Save changes', 'sscribe-export-site-pages')
						: __('Create schedule', 'sscribe-export-site-pages')
				) +
				'</button> ';
			html +=
				'<button type="button" class="sscribe-button sscribe-button-outline" id="sscribe-schedule-cancel">' +
				this.esc(__('Cancel', 'sscribe-export-site-pages')) +
				'</button></div>';
			html += '</form>';
			return html;
		},

		syncFrequencyFields: function () {
			const frequency = $('#sscribe-schedule-frequency').val();
			const show = {
				hour: frequency !== 'hourly',
				weekday: frequency === 'weekly',
				day_of_month: frequency === 'monthly',
			};
			$('[data-frequency-field]').each(function () {
				const field = $(this).data('frequency-field');
				$(this).toggle(Boolean(show[field]));
			});
		},

		addDestinationRow: function (destination) {
			const $rows = $('#sscribe-destination-rows');
			if ($rows.children().length >= this.meta.max_destinations) {
				this.notice(
					sprintf(
						__('A schedule can have at most %d destinations.', 'sscribe-export-site-pages'),
						this.meta.max_destinations
					),
					'error'
				);
				return;
			}
			const self = this;
			const current =
				destination && destination.id
					? destination.id
					: this.meta.destinations[0]
						? this.meta.destinations[0].id
						: '';
			let html = '<fieldset class="sscribe-destination-row"><div class="sscribe-destination-row-header">';
			html +=
				'<label class="sscribe-format-option-field"><span class="sscribe-format-option-label">' +
				this.esc(__('Type', 'sscribe-export-site-pages')) +
				'</span><select class="sscribe-destination-type">';
			this.meta.destinations.forEach(function (type) {
				html += self.option(type.id, type.label, type.id === current);
			});
			html += '</select></label>';
			html +=
				'<button type="button" class="sscribe-button sscribe-button-outline sscribe-button-sm sscribe-destination-remove">' +
				this.esc(__('Remove', 'sscribe-export-site-pages')) +
				'</button></div>';
			html += '<div class="sscribe-format-option-grid sscribe-destination-fields"></div></fieldset>';
			const $row = $(html);
			$rows.append($row);
			this.renderDestinationFields($row, current, destination ? destination.settings : {});
		},

		onDestinationTypeChange: function (event) {
			const $row = $(event.currentTarget).closest('.sscribe-destination-row');
			this.renderDestinationFields($row, $(event.currentTarget).val(), {});
		},

		renderDestinationFields: function ($row, typeId, settings) {
			const self = this;
			const type = this.meta.destinations.find(function (t) {
				return t.id === typeId;
			});
			const $fields = $row.find('.sscribe-destination-fields').empty();
			if (!type) {
				return;
			}
			Object.keys(type.fields).forEach(function (name) {
				const field = type.fields[name];
				const value = settings && settings[name] !== undefined ? settings[name] : '';
				if (field.type === 'checkbox') {
					$fields.append(
						'<label class="sscribe-format-option-checkbox"><input type="checkbox" data-setting="' +
							self.esc(name) +
							'" value="1"' +
							(value && value !== '0' ? ' checked' : '') +
							'> <span>' +
							self.esc(field.label) +
							'</span></label>'
					);
					return;
				}
				const placeholder =
					field.type === 'password' && value === self.meta.mask
						? self.esc(__('Stored, leave blank to keep', 'sscribe-export-site-pages'))
						: '';
				$fields.append(
					'<label class="sscribe-format-option-field"><span class="sscribe-format-option-label">' +
						self.esc(field.label) +
						(field.required ? ' *' : '') +
						'</span><input type="' +
						(field.type === 'password' ? 'password' : 'text') +
						'" data-setting="' +
						self.esc(name) +
						'" autocomplete="off" placeholder="' +
						placeholder +
						'" value="' +
						(value === self.meta.mask ? '' : self.esc(value)) +
						'"' +
						(value === self.meta.mask ? ' data-masked="1"' : '') +
						'></label>'
				);
			});
		},

		removeDestinationRow: function (event) {
			$(event.currentTarget).closest('.sscribe-destination-row').remove();
		},

		collectForm: function () {
			const $form = $('#sscribe-schedule-form');
			const data = {
				label: $form.find('[name="label"]').val(),
				frequency: $form.find('[name="frequency"]').val(),
				hour: $form.find('[name="hour"]').val(),
				weekday: $form.find('[name="weekday"]').val(),
				day_of_month: $form.find('[name="day_of_month"]').val(),
				post_type: $form.find('[name="post_type"]').val(),
				post_status: $form.find('[name="post_status"]').val(),
				language: $form.find('[name="language"]').val() || '',
				formats: $form
					.find('[name="formats"]:checked')
					.map(function () {
						return this.value;
					})
					.get(),
				fields_mode: $form.find('[name="fields_mode"]').val(),
				retention_days: $form.find('[name="retention_days"]').val(),
				incremental: $form.find('[name="incremental"]').is(':checked') ? 1 : 0,
				compliance: $form.find('[name="compliance"]').is(':checked') ? 1 : 0,
				notify: $form.find('[name="notify"]').is(':checked') ? 1 : 0,
				enabled: $form.find('[name="enabled"]').is(':checked') ? 1 : 0,
				destinations: [],
			};
			if (this.editing) {
				data.id = this.editing.id;
			}
			const mask = this.meta.mask;
			$form.find('.sscribe-destination-row').each(function () {
				const $row = $(this);
				const settings = {};
				$row.find('[data-setting]').each(function () {
					const $input = $(this);
					const name = $input.data('setting');
					if ($input.attr('type') === 'checkbox') {
						settings[name] = $input.is(':checked') ? '1' : '0';
					} else if ($input.attr('type') === 'password' && $input.data('masked') && $input.val() === '') {
						settings[name] = mask;
					} else {
						settings[name] = $input.val();
					}
				});
				data.destinations.push({ id: $row.find('.sscribe-destination-type').val(), settings: settings });
			});
			return data;
		},

		submitForm: function (event) {
			event.preventDefault();
			if (this.busy) {
				return;
			}
			const self = this;
			const data = this.collectForm();
			if (!data.label) {
				this.notice(__('Give the schedule a name.', 'sscribe-export-site-pages'), 'error');
				return;
			}
			if (!data.formats.length) {
				this.notice(__('Choose at least one format.', 'sscribe-export-site-pages'), 'error');
				return;
			}
			this.busy = true;
			const $submit = $('#sscribe-schedule-form [type="submit"]').prop('disabled', true);
			this.request('sscribe_schedule_save', { schedule: JSON.stringify(data) })
				.done(function (response) {
					if (response && response.success) {
						self.notice(response.data.message, 'success');
						self.closeForm();
						self.refresh();
					} else {
						self.notice(self.errorMessage(response), 'error');
					}
				})
				.fail(function (xhr) {
					self.notice(self.errorMessage(xhr.responseJSON), 'error');
				})
				.always(function () {
					self.busy = false;
					$submit.prop('disabled', false);
				});
		},
	};

	$(function () {
		SScribeSchedules.init();
	});

	window.SScribeSchedules = SScribeSchedules;
})(jQuery);
