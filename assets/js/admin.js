/**
 * Slice Backup admin screen.
 *
 * Plain JavaScript, no build step. Strings are translatable through wp.i18n.
 * Long operations run as server-side jobs; this script only starts them and
 * shows their progress, so they keep going if the page is closed.
 */
(function () {
	'use strict';

	var cfg = window.sliceBackupConfig || {};
	var i18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	var __ = i18n ? i18n.__ : function (s) { return s; };
	var _n = i18n ? i18n._n : function (s, p, n) { return n === 1 ? s : p; };
	var sprintf = i18n ? i18n.sprintf : function (s) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return s.replace(/%(\d+\$)?[sd]/g, function () { return String(args[i++]); });
	};
	var D = 'slice-backup';

	var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
	var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

	var state = {
		busy: false,
		bundles: null,
		cloud: null,
		sizes: null,
		selectedBundle: null,
		manifest: null,
		passphrase: '',
		uploadController: null,
		loadedTabs: {}
	};

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (value === null || value === undefined || value === false) {
				return;
			}
			if (key === 'text') {
				node.textContent = value;
			} else if (key === 'className') {
				node.className = value;
			} else if (key.indexOf('on') === 0 && typeof value === 'function') {
				node.addEventListener(key.slice(2).toLowerCase(), value);
			} else {
				node.setAttribute(key, value === true ? '' : value);
			}
		});
		(children || []).forEach(function (child) {
			if (child === null || child === undefined || child === false) {
				return;
			}
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function icon(name) {
		return el('span', { className: 'dashicons ' + name, 'aria-hidden': 'true' });
	}

	function formatBytes(bytes) {
		bytes = Number(bytes) || 0;
		var units = ['B', 'KB', 'MB', 'GB', 'TB'];
		var i = 0;
		while (bytes >= 1024 && i < units.length - 1) {
			bytes /= 1024;
			i++;
		}
		return (i === 0 ? bytes : bytes.toFixed(bytes < 10 ? 1 : 0)) + ' ' + units[i];
	}

	function formatDuration(seconds) {
		seconds = Math.max(0, Math.floor(seconds));
		var h = Math.floor(seconds / 3600);
		var m = Math.floor((seconds % 3600) / 60);
		var s = seconds % 60;
		return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
	}

	function componentLabel(key) {
		return cfg.components && cfg.components[key] ? cfg.components[key].label : key;
	}

	function delay(ms) {
		return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
	}

	window.addEventListener('beforeunload', function (event) {
		if (state.uploadController) {
			event.preventDefault();
			event.returnValue = '';
		}
	});

	/* ------------------------------------------------------------------ */
	/* Server requests                                                     */
	/* ------------------------------------------------------------------ */

	function httpMessage(status, text) {
		if (status === 413) {
			return __('The server rejected the upload because it is too large. Try again; if it keeps failing, copy the bundle into the backup folder by FTP instead.', D);
		}
		if (status === 502 || status === 503 || status === 504 || status === 524) {
			return sprintf(__('The server did not respond in time (HTTP %d).', D), status);
		}
		if (text) {
			var plain = text.replace(/<style[\s\S]*?<\/style>|<script[\s\S]*?<\/script>/gi, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
			if (plain) {
				return plain.length > 300 ? plain.slice(0, 300) + '…' : plain;
			}
		}
		return status ? sprintf(__('The server returned an error (HTTP %d).', D), status) : __('Unexpected server response.', D);
	}

	function request(action, data, options) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');
		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			if (value instanceof Blob) {
				body.append(key, value, 'chunk.bin');
			} else if (typeof value === 'boolean') {
				body.append(key, value ? '1' : '0');
			} else if (value !== undefined && value !== null) {
				body.append(key, String(value));
			}
		});

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
			signal: options && options.signal
		}).catch(function (error) {
			if (error && error.name === 'AbortError') {
				throw error;
			}
			var e = new Error(__('Could not reach the server. Check your connection and try again.', D));
			e.network = true;
			throw e;
		}).then(function (response) {
			return response.text().then(function (text) {
				var json = null;
				try {
					json = JSON.parse(text);
				} catch (e) {
					var start = text.indexOf('{"success"');
					if (start !== -1) {
						try { json = JSON.parse(text.slice(start)); } catch (e2) { json = null; }
					}
				}

				if (json && typeof json === 'object' && 'success' in json) {
					if (json.success) {
						return json.data;
					}
					var err = new Error((json.data && json.data.message) || httpMessage(response.status));
					err.status = response.status;
					err.data = json.data || {};
					throw err;
				}

				var trimmed = text.trim();
				if (trimmed === '-1' || trimmed === '0') {
					throw new Error(__('Your session has expired. Reload the page and try again.', D));
				}
				var httpErr = new Error(httpMessage(response.status, text));
				httpErr.status = response.status;
				throw httpErr;
			});
		});
	}

	function formData(form) {
		var data = {};
		$$('input, select, textarea', form).forEach(function (field) {
			if (!field.name || field.disabled) {
				return;
			}
			if (field.type === 'checkbox') {
				data[field.name] = field.checked;
			} else if (field.type === 'radio') {
				if (field.checked) {
					data[field.name] = field.value;
				}
			} else {
				data[field.name] = field.value;
			}
		});
		return data;
	}

	/* ------------------------------------------------------------------ */
	/* Toasts & dialog                                                     */
	/* ------------------------------------------------------------------ */

	function toast(message, type) {
		type = type || 'success';
		var region = $('#slicebk-toasts');
		if (!region) {
			return;
		}
		var node = el('div', { className: 'slicebk-toast slicebk-toast--' + type, role: type === 'error' ? 'alert' : 'status' }, [
			icon(type === 'error' ? 'dashicons-warning' : (type === 'info' ? 'dashicons-info' : 'dashicons-yes-alt')),
			el('p', { text: message }),
			el('button', {
				type: 'button',
				className: 'slicebk-toast__close',
				'aria-label': __('Dismiss', D),
				onClick: function () { node.remove(); }
			}, [icon('dashicons-no-alt')])
		]);
		region.appendChild(node);
		if (type !== 'error') {
			window.setTimeout(function () { node.remove(); }, 6000);
		}
	}

	function confirmDialog(options) {
		var dialog = $('#slicebk-dialog');
		if (!dialog || typeof dialog.showModal !== 'function') {
			return Promise.resolve(window.confirm(options.message));
		}
		$('#slicebk-dialog-title').textContent = options.title;
		$('#slicebk-dialog-message').textContent = options.message;
		var confirmBtn = $('#slicebk-dialog-confirm');
		confirmBtn.textContent = options.confirmLabel || __('Continue', D);
		confirmBtn.classList.toggle('slicebk-button-danger', !!options.danger);

		return new Promise(function (resolve) {
			dialog.returnValue = 'cancel';
			dialog.addEventListener('close', function onClose() {
				dialog.removeEventListener('close', onClose);
				resolve(dialog.returnValue === 'confirm');
			});
			dialog.showModal();
			confirmBtn.focus();
		});
	}

	/* ------------------------------------------------------------------ */
	/* Tabs                                                                */
	/* ------------------------------------------------------------------ */

	var tabs = $$('.slicebk-tab');

	function activateTab(name, focus) {
		if (!tabs.some(function (t) { return t.dataset.tab === name; })) {
			name = 'export';
		}
		tabs.forEach(function (tab) {
			var selected = tab.dataset.tab === name;
			tab.setAttribute('aria-selected', selected ? 'true' : 'false');
			tab.tabIndex = selected ? 0 : -1;
			tab.classList.toggle('is-active', selected);
			var panel = document.getElementById(tab.getAttribute('aria-controls'));
			if (panel) {
				panel.hidden = !selected;
			}
			if (selected && focus) {
				tab.focus();
			}
		});
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', '#' + name);
		}
		onTabShown(name);
	}

	function onTabShown(name) {
		var first = !state.loadedTabs[name];
		state.loadedTabs[name] = true;
		if (name === 'export' && first) {
			loadEstimate();
		}
		if (name === 'backups' || (name === 'import' && state.bundles === null)) {
			loadBackups();
		}
		if (name === 'system' && first) {
			runProbe();
		}
	}

	tabs.forEach(function (tab, index) {
		tab.addEventListener('click', function () { activateTab(tab.dataset.tab); });
		tab.addEventListener('keydown', function (event) {
			var next = null;
			if (event.key === 'ArrowRight') { next = tabs[(index + 1) % tabs.length]; }
			if (event.key === 'ArrowLeft') { next = tabs[(index - 1 + tabs.length) % tabs.length]; }
			if (event.key === 'Home') { next = tabs[0]; }
			if (event.key === 'End') { next = tabs[tabs.length - 1]; }
			if (next) {
				event.preventDefault();
				activateTab(next.dataset.tab, true);
			}
		});
	});

	document.addEventListener('click', function (event) {
		var go = event.target.closest('[data-go-tab]');
		if (go) {
			event.preventDefault();
			activateTab(go.getAttribute('data-go-tab'), true);
		}
	});

	/* ------------------------------------------------------------------ */
	/* Jobs                                                                */
	/* ------------------------------------------------------------------ */

	var jobTitles = {
		export: __('Creating backup', D),
		restore: __('Restoring', D),
		upload: __('Uploading to cloud storage', D),
		download: __('Downloading from cloud storage', D),
		pull: __('Fetching from the other site', D)
	};

	var Jobs = {
		current: null,
		token: '',
		handlers: {},
		timer: null,

		/**
		 * Starts a job on the server and follows it until it ends.
		 * onDone receives the finished job; onEnd always runs.
		 */
		start: function (type, data, handlers) {
			if (Jobs.current) {
				toast(__('Another job is running. Wait for it to finish.', D), 'error');
				return Promise.resolve();
			}
			var payload = Object.assign({ type: type }, data || {});
			return request('slice_backup_job_start', payload).then(function (job) {
				Jobs.follow(job, job.token, handlers || {});
			});
		},

		follow: function (job, token, handlers) {
			Jobs.current = job;
			Jobs.token = token;
			Jobs.handlers = handlers;
			state.busy = true;
			Jobs.render(job);
			$('#slicebk-job').hidden = false;
			Jobs.startClock(job.created);
			setStartButtons();
			Jobs.loop();
		},

		loop: function () {
			var errors = 0;
			var step = function () {
				if (!Jobs.current) {
					return Promise.resolve();
				}
				return request('slice_backup_job_tick', { job: Jobs.current.id, token: Jobs.token }).then(function (job) {
					errors = 0;
					Jobs.current = job;
					Jobs.render(job);
					if (job.status === 'running') {
						return delay(500).then(step);
					}
					return Jobs.end(job);
				}).catch(function (error) {
					errors++;
					if (error.status === 403 && !error.network) {
						return Jobs.end({ status: 'failed', error: error.message, type: Jobs.current.type });
					}
					// The job keeps running on the server; keep trying, more slowly.
					$('#slicebk-job-detail').textContent = sprintf(__('Connection problem, retrying (%d)…', D), errors);
					return delay(Math.min(15000, 1000 * errors)).then(step);
				});
			};
			step();
		},

		render: function (job) {
			var p = job.progress || {};
			var percent = Math.max(0, Math.min(100, Number(p.percent) || 0));
			$('#slicebk-job-title').textContent = jobTitles[job.type] || '';
			$('#slicebk-job-label').textContent = p.label || '';
			$('#slicebk-job-detail').textContent = p.detail || '';
			$('#slicebk-job-percent').textContent = Math.floor(percent) + '%';
			var bar = $('#slicebk-job-bar');
			bar.setAttribute('aria-valuenow', String(Math.floor(percent)));
			bar.firstElementChild.style.width = percent + '%';
		},

		startClock: function (created) {
			window.clearInterval(Jobs.timer);
			var start = created ? created * 1000 : Date.now();
			var target = $('#slicebk-job-elapsed');
			var tick = function () { target.textContent = formatDuration((Date.now() - start) / 1000); };
			tick();
			Jobs.timer = window.setInterval(tick, 1000);
		},

		end: function (job) {
			window.clearInterval(Jobs.timer);
			var handlers = Jobs.handlers;
			Jobs.current = null;
			Jobs.token = '';
			state.busy = false;
			$('#slicebk-job').hidden = true;
			setStartButtons();

			if (job.status === 'done') {
				if (handlers.onDone) {
					handlers.onDone(job);
				} else {
					toast(__('Finished.', D));
				}
				(job.warnings || []).forEach(function (w) { toast(w, 'info'); });
			} else if (job.status === 'cancelled') {
				toast(__('Cancelled.', D), 'info');
			} else {
				toast(job.error || __('The job failed.', D), 'error');
			}
			if (handlers.onEnd) {
				handlers.onEnd(job);
			}
			state.bundles = null;
			if (!$('#slicebk-panel-backups').hidden) {
				loadBackups();
			}
		},

		resume: function () {
			request('slice_backup_job_status').then(function (data) {
				var job = data.job;
				if (job && job.status === 'running' && job.token) {
					Jobs.follow(job, job.token, handlersFor(job.type));
					if (job.stalled) {
						toast(__('A job had stopped and is being resumed.', D), 'info');
					}
				}
			}).catch(function () { /* Nothing running. */ });
		}
	};

	var cancelBtn = $('#slicebk-job-cancel');
	if (cancelBtn) {
		cancelBtn.addEventListener('click', function () {
			confirmDialog({
				title: __('Cancel this job?', D),
				message: __('Partial files are removed. A restore that is already writing to the database cannot be undone.', D),
				confirmLabel: __('Cancel job', D),
				danger: true
			}).then(function (ok) {
				if (ok) {
					request('slice_backup_job_cancel').catch(function (e) { toast(e.message, 'error'); });
				}
			});
		});
	}

	function handlersFor(type) {
		if (type === 'export') {
			return { onDone: showExportResult };
		}
		if (type === 'restore') {
			return { onDone: showRestoreResult };
		}
		if (type === 'download' || type === 'pull') {
			return { onDone: afterCloudDownload };
		}
		return {
			onDone: function () { toast(__('Uploaded to cloud storage.', D)); state.cloud = null; }
		};
	}

	function setStartButtons() {
		['#slicebk-export-start', '#slicebk-cloud-fetch', '#slicebk-schedule-now'].forEach(function (sel) {
			var b = $(sel);
			if (b) {
				b.classList.toggle('is-busy', state.busy);
			}
		});
		updateExportSummary();
		updateRestoreButton();
	}

	/* ------------------------------------------------------------------ */
	/* Back up                                                             */
	/* ------------------------------------------------------------------ */

	var exportForm = $('#slicebk-export-form');
	var componentKeys = Object.keys(cfg.components || {});

	function componentBox(key) {
		return exportForm ? exportForm.querySelector('input[name="include_' + key + '"]') : null;
	}

	function selectedComponents() {
		return componentKeys.filter(function (key) {
			var box = componentBox(key);
			return box && box.checked;
		});
	}

	function applyPreset(preset) {
		var list = (cfg.presets || {})[preset];
		if (!list) {
			return;
		}
		componentKeys.forEach(function (key) {
			var box = componentBox(key);
			if (box) {
				box.checked = list.indexOf(key) !== -1;
			}
		});
	}

	function detectPreset() {
		var chosen = selectedComponents().slice().sort().join(',');
		var presets = cfg.presets || {};
		var match = 'custom';
		Object.keys(presets).forEach(function (name) {
			if (presets[name].slice().sort().join(',') === chosen) {
				match = name;
			}
		});
		return match;
	}

	function updateExportSummary() {
		if (!exportForm) {
			return;
		}
		var chosen = selectedComponents();
		var list = $('#slicebk-export-summary');
		list.textContent = '';

		var total = 0;
		var partial = false;
		var known = !!state.sizes;

		chosen.forEach(function (key) {
			var size = state.sizes && state.sizes[key];
			if (size) {
				total += Number(size.bytes) || 0;
				partial = partial || size.partial;
			}
			list.appendChild(el('li', {}, [
				icon(cfg.components[key].icon),
				el('span', { text: componentLabel(key) }),
				el('span', { className: 'slicebk-muted', text: size ? (size.partial ? '≥ ' : '') + formatBytes(size.bytes) : '' })
			]));
		});

		var encrypt = $('#slicebk-export-encrypt');
		var upload = $('#slicebk-export-upload');
		if (encrypt && encrypt.checked) {
			list.appendChild(el('li', {}, [icon('dashicons-lock'), el('span', { text: __('Encrypted (AES-256)', D) }), el('span')]));
		}
		if (upload && upload.checked) {
			list.appendChild(el('li', {}, [icon('dashicons-cloud-upload'), el('span', { text: __('Copy in cloud storage', D) }), el('span')]));
		}
		var push = $('#slicebk-export-push');
		if (push && push.checked) {
			list.appendChild(el('li', {}, [icon('dashicons-randomize'), el('span', { text: exportForm.push_restore.checked ? __('Send to the other site and restore it there', D) : __('Send to the other site', D) }), el('span')]));
		}
		$('#slicebk-export-passphrase-row').hidden = !(encrypt && encrypt.checked);
		$('#slicebk-export-delete-row').hidden = !(upload && upload.checked);
		$('#slicebk-export-push-row').hidden = !(push && push.checked);

		$('#slicebk-contents-count').textContent = sprintf(__('%1$d of %2$d selected', D), chosen.length, componentKeys.length);
		$('#slicebk-export-estimate').textContent = !chosen.length ? '—' : (known ? (partial ? '≥ ' : '') + formatBytes(total) : __('Calculating…', D));
		$('#slicebk-config-warning').hidden = chosen.indexOf('root_files') === -1 || (encrypt && encrypt.checked);
		$('#slicebk-export-empty').hidden = chosen.length > 0;
		$('#slicebk-export-start').disabled = chosen.length === 0 || state.busy;

		$$('.slicebk-component', exportForm).forEach(function (label) {
			var box = label.querySelector('input');
			label.classList.toggle('is-checked', box.checked);
		});
	}

	function loadEstimate() {
		request('slice_backup_estimate').then(function (data) {
			state.sizes = data.sizes || {};
			Object.keys(state.sizes).forEach(function (key) {
				var target = exportForm.querySelector('[data-size-for="' + key + '"]');
				if (target) {
					var size = state.sizes[key];
					target.textContent = (size.partial ? '≥ ' : '') + formatBytes(size.bytes);
				}
			});
			updateExportSummary();
		}).catch(function () {
			state.sizes = {};
			$$('[data-size-for]', exportForm).forEach(function (node) { node.textContent = ''; });
			updateExportSummary();
		});
	}

	function showExportResult(job) {
		var res = job.result || {};
		var card = $('#slicebk-export-result');
		$('#slicebk-export-result-name').textContent = res.bundleFile || '';
		$('#slicebk-export-result-size').textContent = res.formattedSize || '';
		var badges = $('#slicebk-export-result-badges');
		badges.textContent = '';
		if (res.encrypted) {
			badges.appendChild(el('span', { className: 'slicebk-chip' }, [icon('dashicons-lock'), __('Encrypted', D)]));
		}
		if (res.uploaded) {
			badges.appendChild(el('span', { className: 'slicebk-chip' }, [icon('dashicons-cloud-saved'), __('In cloud storage', D)]));
		}
		if (res.pushedTo) {
			var host = res.pushedTo.replace(/^https?:\/\//, '').replace(/\/$/, '');
			badges.appendChild(el('span', { className: 'slicebk-chip slicebk-chip--accent' }, [icon('dashicons-randomize'), res.remoteRestored ? sprintf(__('Restored on %s', D), host) : sprintf(__('Sent to %s', D), host)]));
			if (res.remoteRestored) {
				toast(sprintf(__('The site was restored on %s. Log in there with an account from this site.', D), host), 'info');
			}
		}
		var download = $('#slicebk-export-download');
		download.hidden = !res.downloadUrl;
		if (res.downloadUrl) {
			download.href = res.downloadUrl;
		}
		card.hidden = false;
		activateTab('export');
		card.focus();
		toast(res.localDeleted ? __('Backup uploaded to cloud storage; the server copy was removed.', D) : __('Backup created.', D));
		if (res.deletedOldBundles && res.deletedOldBundles.length) {
			toast(sprintf(_n('%d older backup was removed by the retention rules.', '%d older backups were removed by the retention rules.', res.deletedOldBundles.length, D), res.deletedOldBundles.length), 'info');
		}
	}

	if (exportForm) {
		applyPreset('full');

		exportForm.addEventListener('change', function (event) {
			var target = event.target;
			if (target.name === 'export_type') {
				if (target.value === 'custom') {
					$('#slicebk-export-contents').open = true;
				} else {
					applyPreset(target.value);
				}
			} else if (target.dataset.component) {
				var radio = exportForm.querySelector('input[name="export_type"][value="' + detectPreset() + '"]');
				if (radio) {
					radio.checked = true;
				}
			} else if (target.name === 'multisite_mode') {
				$('#slicebk-subsite-options').hidden = target.value !== 'subsite';
			}
			updateExportSummary();
		});

		exportForm.addEventListener('submit', function (event) {
			event.preventDefault();
			var chosen = selectedComponents();
			if (!chosen.length || state.busy) {
				return;
			}
			var f = formData(exportForm);
			if (f.encrypt && f.passphrase && f.passphrase.length < 8) {
				toast(__('The passphrase must be at least 8 characters.', D), 'error');
				return;
			}
			if (f.encrypt && !f.passphrase && !(cfg.settings && cfg.settings.encryption.has_passphrase)) {
				toast(__('Enter a passphrase to encrypt this backup.', D), 'error');
				return;
			}

			var data = {
				export_type: f.export_type || 'custom',
				exclude_spam: !!f.exclude_spam,
				exclude_revisions: !!f.exclude_revisions,
				exclude_transients: !!f.exclude_transients,
				encrypt: !!f.encrypt,
				passphrase: f.encrypt ? (f.passphrase || '') : '',
				upload: !!f.upload,
				delete_local: !!f.upload && !!f.delete_local,
				push: !!f.push,
				push_key: f.push ? (f.push_key || '').trim() : '',
				push_restore: !!f.push && !!f.push_restore
			};
			if (data.push && !data.push_key) {
				toast(__('Paste the transfer key of the other site.', D), 'error');
				return;
			}
			if (data.push && data.push_restore) {
				// Overwriting another site deserves an explicit confirmation.
				confirmDialog({
					title: __('Overwrite the other site?', D),
					message: __('After the bundle arrives, the other site is replaced with this one. This cannot be undone there.', D),
					confirmLabel: __('Send and restore', D),
					danger: true
				}).then(function (ok) {
					if (ok) {
						startExportJob(data);
					}
				});
				return;
			}
			startExportJob(data);
		});

		function startExportJob(data) {
			var f = formData(exportForm);
			var chosen = selectedComponents();
			componentKeys.forEach(function (key) {
				data['include_' + key] = chosen.indexOf(key) !== -1;
			});
			if (f.multisite_mode) {
				data.multisite_mode = f.multisite_mode;
				data.subsite_id = f.subsite_id || '';
				data.convert_subsite_standalone = !!f.convert_subsite_standalone;
			}

			$('#slicebk-export-result').hidden = true;
			Jobs.start('export', data, handlersFor('export')).catch(function (error) {
				toast(error.message, 'error');
			});
		}

		var pushCheck = $('#slicebk-push-check');
		if (pushCheck) {
			pushCheck.addEventListener('click', function () {
				checkPeerKey(exportForm.push_key.value, $('#slicebk-push-check-result'), pushCheck);
			});
		}

		updateExportSummary();
	}

	/**
	 * Verifies a transfer key against the other site and shows who it belongs to.
	 */
	function checkPeerKey(key, out, button) {
		key = (key || '').trim();
		if (!key) {
			out.className = 'slicebk-test-result is-fail';
			out.textContent = __('Paste the transfer key of the other site.', D);
			return Promise.resolve(null);
		}
		button.disabled = true;
		out.className = 'slicebk-test-result';
		out.textContent = __('Checking…', D);
		return request('slice_backup_peer_check', { key: key }).then(function (info) {
			out.className = 'slicebk-test-result ' + (info.busy ? 'is-fail' : 'is-ok');
			out.textContent = sprintf(__('Connected to %1$s (%2$s) — WordPress %3$s, PHP %4$s, plugin %5$s.', D), info.name || '', info.site, info.wp, info.php, info.version) +
				(info.busy ? ' ' + __('It is busy with another backup or restore right now.', D) : '');
			return info;
		}).catch(function (error) {
			out.className = 'slicebk-test-result is-fail';
			out.textContent = error.message;
			return null;
		}).then(function (info) {
			button.disabled = false;
			return info;
		});
	}

	/* ------------------------------------------------------------------ */
	/* Backups list                                                        */
	/* ------------------------------------------------------------------ */

	function loadBackups() {
		var loading = $('#slicebk-backups-loading');
		loading.hidden = false;
		var local = request('slice_backup_list').then(function (data) {
			state.bundles = data.bundles || [];
			state.currentSiteUrl = data.currentSiteUrl;
			state.totalSize = data.totalSize;
		});
		var cloud = cfg.cloudReady ? request('slice_backup_cloud_list').then(function (data) {
			state.cloud = data.items || [];
			state.cloudLabel = data.label || '';
		}).catch(function (error) {
			state.cloud = [];
			toast(sprintf(__('Could not list cloud storage: %s', D), error.message), 'error');
		}) : Promise.resolve();

		return Promise.all([local, cloud]).then(function () {
			renderBackups();
			fillSavedSelect();
			fillCloudSelect();
		}).catch(function (error) {
			toast(error.message, 'error');
		}).then(function () {
			loading.hidden = true;
		});
	}

	function chips(components) {
		var wrap = el('span', { className: 'slicebk-chips' });
		(components || []).forEach(function (key) {
			wrap.appendChild(el('span', { className: 'slicebk-chip', title: componentLabel(key) }, [icon(cfg.components[key] ? cfg.components[key].icon : 'dashicons-media-default'), el('span', { text: componentLabel(key) })]));
		});
		return wrap;
	}

	function mergedBackups() {
		var byName = {};
		(state.bundles || []).forEach(function (b) {
			byName[b.filename] = { name: b.filename, local: b, cloud: null };
		});
		(state.cloud || []).forEach(function (c) {
			if (byName[c.filename]) {
				byName[c.filename].cloud = c;
			} else {
				byName[c.filename] = { name: c.filename, local: null, cloud: c };
			}
		});
		return Object.keys(byName).map(function (k) { return byName[k]; }).sort(function (a, b) {
			var ta = a.local ? a.local.created : a.cloud.modified;
			var tb = b.local ? b.local.created : b.cloud.modified;
			return tb - ta;
		});
	}

	function renderBackups() {
		var body = $('#slicebk-backups-body');
		var table = $('#slicebk-backups-table');
		var empty = $('#slicebk-backups-empty');
		var query = ($('#slicebk-backups-search').value || '').toLowerCase().trim();
		body.textContent = '';

		var all = mergedBackups();
		var cloudCount = (state.cloud || []).length;
		$('#slicebk-backups-meta').textContent = all.length
			? sprintf(_n('%1$d backup on this server · %2$s', '%1$d backups on this server · %2$s', (state.bundles || []).length, D), (state.bundles || []).length, state.totalSize || '0 B') +
				(cfg.cloudReady ? ' · ' + sprintf(__('%1$d in cloud storage (%2$s)', D), cloudCount, state.cloudLabel || '') : '')
			: '';

		var list = all.filter(function (row) {
			if (!query) {
				return true;
			}
			var m = row.local && row.local.manifest;
			return (row.name + ' ' + (m ? m.siteurl : '') + ' ' + (row.local ? row.local.createdDate : row.cloud.modifiedDate)).toLowerCase().indexOf(query) !== -1;
		});

		list.forEach(function (row) {
			var b = row.local;
			var m = b && b.manifest;
			var source = m && m.siteurl ? m.siteurl.replace(/^https?:\/\//, '') : '';
			var fromHere = m && state.currentSiteUrl && m.siteurl.replace(/\/$/, '') === state.currentSiteUrl;
			var encrypted = (b && b.encrypted) || /\.slicebk$/.test(row.name);
			var scheduled = row.name.indexOf('-auto-') !== -1;

			var nameCell = el('td', {}, [
				el('strong', { className: 'slicebk-filename', text: row.name }),
				el('span', { className: 'slicebk-muted slicebk-small slicebk-block' }, [
					b ? (m ? (fromHere ? __('This site', D) : sprintf(__('From %s', D), source)) : (encrypted ? __('Encrypted', D) : __('Unknown source', D))) : __('Only in cloud storage', D)
				])
			]);
			var badges = el('span', { className: 'slicebk-chips' });
			if (encrypted) {
				badges.appendChild(el('span', { className: 'slicebk-chip slicebk-chip--accent' }, [icon('dashicons-lock'), __('Encrypted', D)]));
			}
			if (scheduled) {
				badges.appendChild(el('span', { className: 'slicebk-chip' }, [icon('dashicons-clock'), __('Scheduled', D)]));
			}
			nameCell.appendChild(badges);

			var where = el('span', { className: 'slicebk-chips' });
			if (b) {
				where.appendChild(el('span', { className: 'slicebk-chip' }, [icon('dashicons-database'), __('Server', D)]));
			}
			if (row.cloud) {
				where.appendChild(el('span', { className: 'slicebk-chip slicebk-chip--accent' }, [icon('dashicons-cloud-saved'), __('Cloud', D)]));
			}

			var actions = [];
			if (b) {
				actions.push(el('a', { className: 'button button-small', href: b.downloadUrl }, [icon('dashicons-download'), el('span', { className: 'slicebk-hide-sm', text: __('Download', D) })]));
				actions.push(el('button', { type: 'button', className: 'button button-small', onClick: function () { useSavedBundle(row.name); } }, [icon('dashicons-backup'), el('span', { className: 'slicebk-hide-sm', text: __('Restore', D) })]));
				if (cfg.cloudReady && !row.cloud) {
					actions.push(el('button', {
						type: 'button',
						className: 'button button-small',
						title: __('Upload to cloud storage', D),
						onClick: function () {
							Jobs.start('upload', { filename: row.name }, handlersFor('upload')).catch(function (e) { toast(e.message, 'error'); });
						}
					}, [icon('dashicons-cloud-upload'), el('span', { className: 'slicebk-hide-sm', text: __('To cloud', D) })]));
				}
				actions.push(el('button', {
					type: 'button',
					className: 'button button-small slicebk-button-ghost-danger',
					'aria-label': sprintf(__('Delete %s from this server', D), row.name),
					title: __('Delete from this server', D),
					onClick: function () { deleteBundle(row.name, false); }
				}, [icon('dashicons-trash')]));
			} else {
				actions.push(el('button', {
					type: 'button',
					className: 'button button-small',
					onClick: function () { fetchFromCloud(row.name); }
				}, [icon('dashicons-download'), el('span', { className: 'slicebk-hide-sm', text: __('Download to server', D) })]));
			}
			if (row.cloud) {
				actions.push(el('button', {
					type: 'button',
					className: 'button button-small slicebk-button-ghost-danger',
					'aria-label': sprintf(__('Delete %s from cloud storage', D), row.name),
					title: __('Delete from cloud storage', D),
					onClick: function () { deleteBundle(row.name, true); }
				}, [icon('dashicons-cloud'), icon('dashicons-trash')]));
			}

			body.appendChild(el('tr', {}, [
				nameCell,
				el('td', {}, [m ? chips(m.components) : el('span', { className: 'slicebk-muted', text: encrypted ? __('Unlock to see', D) : '—' })]),
				el('td', { className: 'slicebk-num', text: b ? b.formattedSize : row.cloud.formattedSize }),
				el('td', { text: b ? b.createdDate : row.cloud.modifiedDate }),
				el('td', {}, [where]),
				el('td', { className: 'slicebk-actions-col' }, actions)
			]));
		});

		table.hidden = list.length === 0;
		empty.hidden = list.length !== 0;
		$('#slicebk-backups-empty-title').textContent = all.length ? __('No backups match your search', D) : __('No backups yet', D);
	}

	function deleteBundle(filename, fromCloud) {
		confirmDialog({
			title: fromCloud ? __('Delete from cloud storage?', D) : __('Delete backup?', D),
			message: sprintf(fromCloud ? __('“%s” will be permanently deleted from your cloud storage.', D) : __('“%s” will be permanently deleted from this server.', D), filename),
			confirmLabel: __('Delete', D),
			danger: true
		}).then(function (ok) {
			if (!ok) {
				return;
			}
			request(fromCloud ? 'slice_backup_cloud_delete' : 'slice_backup_delete', { file: filename }).then(function () {
				toast(__('Deleted.', D));
				loadBackups();
			}).catch(function (error) {
				toast(error.message, 'error');
			});
		});
	}

	var search = $('#slicebk-backups-search');
	if (search) {
		search.addEventListener('input', renderBackups);
		$('#slicebk-backups-refresh').addEventListener('click', loadBackups);
	}

	/* ------------------------------------------------------------------ */
	/* Restore: choose a bundle                                            */
	/* ------------------------------------------------------------------ */

	function setSource(source) {
		$$('[data-source]').forEach(function (button) {
			var active = button.dataset.source === source;
			button.classList.toggle('is-active', active);
			button.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
		['upload', 'saved', 'cloud', 'peer'].forEach(function (s) {
			var node = $('#slicebk-source-' + s);
			if (node) {
				node.hidden = s !== source;
			}
		});
		if (source === 'cloud' && state.cloud === null && cfg.cloudReady) {
			loadBackups();
		}
	}

	$$('[data-source]').forEach(function (button) {
		button.addEventListener('click', function () { setSource(button.dataset.source); });
	});

	function fillSavedSelect() {
		var select = $('#slicebk-saved-select');
		if (!select) {
			return;
		}
		var current = select.value;
		while (select.options.length > 1) {
			select.remove(1);
		}
		(state.bundles || []).forEach(function (bundle) {
			select.appendChild(el('option', { value: bundle.filename, text: (bundle.encrypted ? '🔒 ' : '') + bundle.filename + ' (' + bundle.formattedSize + ', ' + bundle.createdDate + ')' }));
		});
		select.value = current;
	}

	function fillCloudSelect() {
		var select = $('#slicebk-cloud-select');
		if (!select) {
			return;
		}
		select.textContent = '';
		var items = state.cloud || [];
		select.appendChild(el('option', { value: '', text: items.length ? __('Select a bundle…', D) : __('No bundles in cloud storage', D) }));
		items.forEach(function (item) {
			var local = (state.bundles || []).some(function (b) { return b.filename === item.filename; });
			select.appendChild(el('option', { value: item.filename, text: item.filename + ' (' + item.formattedSize + ', ' + item.modifiedDate + ')' + (local ? ' — ' + __('already on server', D) : '') }));
		});
		$('#slicebk-cloud-fetch').disabled = true;
	}

	var cloudSelect = $('#slicebk-cloud-select');
	if (cloudSelect) {
		cloudSelect.addEventListener('change', function () {
			$('#slicebk-cloud-fetch').disabled = !cloudSelect.value;
		});
		$('#slicebk-cloud-fetch').addEventListener('click', function () {
			if (cloudSelect.value) {
				fetchFromCloud(cloudSelect.value);
			}
		});
	}

	function fetchFromCloud(filename) {
		if ((state.bundles || []).some(function (b) { return b.filename === filename; })) {
			useSavedBundle(filename);
			return;
		}
		activateTab('import');
		Jobs.start('download', { filename: filename }, handlersFor('download')).catch(function (e) { toast(e.message, 'error'); });
	}

	function afterCloudDownload(job) {
		var name = job.result && job.result.bundleFile;
		toast(__('Downloaded from cloud storage.', D));
		if (name) {
			state.bundles = null;
			loadBackups().then(function () { useSavedBundle(name); });
		}
	}

	function useSavedBundle(filename) {
		activateTab('import');
		setSource('saved');
		var select = $('#slicebk-saved-select');
		var apply = function () {
			select.value = filename;
			selectBundle(filename);
		};
		if (state.bundles === null) {
			loadBackups().then(apply);
		} else {
			fillSavedSelect();
			apply();
		}
	}

	var savedSelect = $('#slicebk-saved-select');
	if (savedSelect) {
		savedSelect.addEventListener('change', function () {
			if (savedSelect.value) {
				selectBundle(savedSelect.value);
			} else {
				resetRestore();
			}
		});
	}

	function resetRestore() {
		state.selectedBundle = null;
		state.passphrase = '';
		$('#slicebk-bundle-details').hidden = true;
		$('#slicebk-unlock').hidden = true;
		$('#slicebk-restore-form').hidden = true;
	}

	function detailRow(label, value) {
		return [el('dt', { text: label }), el('dd', { text: value || '—' })];
	}

	function selectBundle(filename, passphrase) {
		var details = $('#slicebk-bundle-details');
		details.hidden = false;
		details.textContent = '';
		details.appendChild(el('p', { className: 'slicebk-loading' }, [el('span', { className: 'spinner is-active' }), __('Reading bundle…', D)]));
		$('#slicebk-restore-result').hidden = true;
		$('#slicebk-restore-form').hidden = true;

		request('slice_backup_inspect', { filename: filename, passphrase: passphrase || '' }).then(function (data) {
			state.selectedBundle = data.filename;
			if (data.needsPassphrase) {
				details.hidden = true;
				$('#slicebk-unlock').hidden = false;
				$('#slicebk-unlock-error').textContent = data.passphraseError || __('Enter the passphrase that was used to create it.', D);
				$('#slicebk-unlock-error').classList.toggle('slicebk-error-text', !!data.passphraseError);
				$('#slicebk-unlock-pass').focus();
				return;
			}
			$('#slicebk-unlock').hidden = true;
			state.passphrase = passphrase || '';
			state.manifest = data.manifest;
			renderBundleDetails(data);
			prepareRestoreForm(data.manifest);
		}).catch(function (error) {
			details.textContent = '';
			details.appendChild(el('div', { className: 'slicebk-callout slicebk-callout--error' }, [icon('dashicons-warning'), el('p', { text: error.message })]));
		});
	}

	var unlockBtn = $('#slicebk-unlock-btn');
	if (unlockBtn) {
		var unlock = function () {
			var pass = $('#slicebk-unlock-pass').value;
			if (state.selectedBundle && pass) {
				selectBundle(state.selectedBundle, pass);
			}
		};
		unlockBtn.addEventListener('click', unlock);
		$('#slicebk-unlock-pass').addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				unlock();
			}
		});
	}

	function renderBundleDetails(data) {
		var m = data.manifest;
		var details = $('#slicebk-bundle-details');
		details.textContent = '';

		var dl = el('dl', { className: 'slicebk-dl' });
		var rows = [detailRow(__('File', D), data.filename + ' · ' + data.formattedSize + (data.encrypted ? ' · ' + __('encrypted', D) : ''))];
		if (m) {
			var created = m.createdAt ? new Date(m.createdAt) : null;
			rows.push(detailRow(__('Source site', D), m.siteurl));
			rows.push(detailRow(__('Created', D), created && !isNaN(created) ? created.toLocaleString() : m.createdAt));
			rows.push(detailRow(__('WordPress / PHP', D), (m.wpVersion || '?') + ' / ' + (m.phpVersion || '?')));
			if (m.isMultisite || m.multisiteMode === 'subsite') {
				rows.push(detailRow(__('Multisite', D), m.multisiteMode === 'subsite'
					? sprintf(__('Site #%d', D), m.subsiteId) + (m.standalone ? ' · ' + __('converted to standalone', D) : '')
					: __('Entire network', D)));
			}
		}
		rows.forEach(function (pair) { pair.forEach(function (node) { dl.appendChild(node); }); });

		details.appendChild(el('h3', { className: 'slicebk-subtitle' }, [icon(data.encrypted ? 'dashicons-unlock' : 'dashicons-media-archive'), __('Bundle details', D)]));
		details.appendChild(dl);
		if (m) {
			details.appendChild(chips(m.components));
		}
		(data.warnings || []).forEach(function (warning) {
			details.appendChild(el('div', { className: 'slicebk-callout slicebk-callout--warning' }, [icon('dashicons-info-outline'), el('p', { text: warning })]));
		});
	}

	function prepareRestoreForm(manifest) {
		var form = $('#slicebk-restore-form');
		form.hidden = false;

		var present = manifest ? manifest.components : null;
		$$('input[data-restore]', form).forEach(function (box) {
			var key = box.dataset.restore;
			var available = !present || present.indexOf(key) !== -1;
			box.disabled = !available;
			box.checked = available && key !== 'wp_core' && key !== 'root_files';
			box.closest('.slicebk-component').classList.toggle('is-missing', !available);
		});

		$('#slicebk-old-url').value = manifest && manifest.siteurl ? manifest.siteurl.replace(/\/$/, '') : '';
		$('#slicebk-new-url').value = cfg.siteUrl || '';

		var network = $('#slicebk-network-fields');
		if (network) {
			var show = !!(manifest && manifest.isMultisite);
			network.hidden = !show;
			$('#slicebk-old-domain').value = show ? (manifest.networkDomain || '') : '';
		}

		$('#slicebk-restore-confirm').checked = false;
		updateRestoreButton();
		syncRestoreChecks();
	}

	function syncRestoreChecks() {
		$$('input[data-restore]').forEach(function (box) {
			box.closest('.slicebk-component').classList.toggle('is-checked', box.checked);
		});
	}

	function updateRestoreButton() {
		var button = $('#slicebk-restore-start');
		if (!button) {
			return;
		}
		var any = $$('input[data-restore]').some(function (box) { return box.checked && !box.disabled; });
		button.disabled = state.busy || !any || !$('#slicebk-restore-confirm').checked;
	}

	/* Upload ------------------------------------------------------------- */

	function randomId() {
		var bytes = new Uint8Array(16);
		window.crypto.getRandomValues(bytes);
		return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}

	function uploadFile(file) {
		if (!/\.(zip|slicebk)$/i.test(file.name)) {
			toast(__('Choose a .zip or .slicebk file created by Slice Backup.', D), 'error');
			return;
		}
		if (state.uploadController) {
			return;
		}

		var controller = new AbortController();
		state.uploadController = controller;
		var chunkSize = Number(cfg.chunkSize) || 2097152;
		var uploadId = randomId();
		var offset = 0;
		var retries = 0;
		var startedAt = Date.now();

		var progress = $('#slicebk-upload-progress');
		var bar = $('#slicebk-upload-bar');
		var text = $('#slicebk-upload-text');
		var dropzone = $('#slicebk-dropzone');
		progress.hidden = false;
		dropzone.classList.add('is-disabled');
		resetRestore();

		function report() {
			var pct = file.size ? Math.min(100, Math.floor((offset / file.size) * 100)) : 0;
			var seconds = (Date.now() - startedAt) / 1000;
			var speed = seconds > 0 ? offset / seconds : 0;
			bar.style.width = pct + '%';
			text.textContent = sprintf(__('Uploading %1$s — %2$d%% (%3$s of %4$s, %5$s/s)', D), file.name, pct, formatBytes(offset), formatBytes(file.size), formatBytes(speed));
		}

		function finish() {
			state.uploadController = null;
			dropzone.classList.remove('is-disabled');
			$('#slicebk-file').value = '';
		}

		function next() {
			report();
			var piece = file.slice(offset, offset + chunkSize);
			return request('slice_backup_upload_chunk', {
				upload_id: uploadId,
				offset: offset,
				total_size: file.size,
				filename: file.name,
				chunk: piece
			}, { signal: controller.signal }).then(function (data) {
				retries = 0;
				if (data.complete) {
					offset = file.size;
					report();
					return data.filename;
				}
				offset = Number(data.received) || offset + piece.size;
				return next();
			}).catch(function (error) {
				if (error && error.name === 'AbortError') {
					throw error;
				}
				if (error.status === 409 && error.data && error.data.expected !== undefined) {
					offset = Number(error.data.expected);
					return next();
				}
				if (retries < 5 && (!error.status || error.status >= 500)) {
					retries++;
					text.textContent = sprintf(__('Connection problem, retrying (%d of 5)…', D), retries);
					return delay(1500 * retries).then(next);
				}
				throw error;
			});
		}

		next().then(function (filename) {
			finish();
			progress.hidden = true;
			toast(sprintf(__('Uploaded %s.', D), filename));
			state.bundles = null;
			loadBackups().then(function () {
				setSource('saved');
				$('#slicebk-saved-select').value = filename;
			});
			selectBundle(filename);
		}).catch(function (error) {
			finish();
			progress.hidden = true;
			toast(error && error.name === 'AbortError' ? __('Upload cancelled.', D) : error.message, error && error.name === 'AbortError' ? 'info' : 'error');
		});
	}

	var fileInput = $('#slicebk-file');
	var dropzone = $('#slicebk-dropzone');
	if (fileInput && dropzone) {
		fileInput.addEventListener('change', function () {
			if (fileInput.files && fileInput.files[0]) {
				uploadFile(fileInput.files[0]);
			}
		});
		['dragenter', 'dragover'].forEach(function (type) {
			dropzone.addEventListener(type, function (event) {
				event.preventDefault();
				dropzone.classList.add('is-dragover');
			});
		});
		['dragleave', 'drop'].forEach(function (type) {
			dropzone.addEventListener(type, function (event) {
				event.preventDefault();
				dropzone.classList.remove('is-dragover');
			});
		});
		dropzone.addEventListener('drop', function (event) {
			var files = event.dataTransfer && event.dataTransfer.files;
			if (files && files[0]) {
				uploadFile(files[0]);
			}
		});
		$('#slicebk-upload-cancel').addEventListener('click', function () {
			if (state.uploadController) {
				state.uploadController.abort();
			}
		});
	}

	/* Restore ------------------------------------------------------------ */

	var restoreForm = $('#slicebk-restore-form');
	if (restoreForm) {
		restoreForm.addEventListener('change', function () {
			syncRestoreChecks();
			updateRestoreButton();
		});

		restoreForm.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!state.selectedBundle || state.busy) {
				return;
			}

			var chosen = $$('input[data-restore]', restoreForm).filter(function (b) { return b.checked && !b.disabled; }).map(function (b) { return b.dataset.restore; });
			var newUrl = $('#slicebk-new-url').value.trim();
			var oldUrl = $('#slicebk-old-url').value.trim();
			if ((oldUrl && !/^https?:\/\//i.test(oldUrl)) || (newUrl && !/^https?:\/\//i.test(newUrl))) {
				toast(__('Addresses must start with http:// or https://', D), 'error');
				return;
			}

			confirmDialog({
				title: __('Restore this bundle?', D),
				message: sprintf(__('This overwrites the following on %1$s: %2$s. It cannot be undone.', D), cfg.siteUrl, chosen.map(function (k) { return componentLabel(k); }).join(', ')),
				confirmLabel: __('Restore now', D),
				danger: true
			}).then(function (ok) {
				if (!ok) {
					return;
				}
				var data = {
					filename: state.selectedBundle,
					old_url: oldUrl,
					new_url: newUrl,
					isolate_conflicts: restoreForm.isolate_conflicts.checked,
					passphrase: state.passphrase
				};
				if (restoreForm.old_network_domain) {
					data.old_network_domain = restoreForm.old_network_domain.value.trim();
					data.new_network_domain = restoreForm.new_network_domain.value.trim();
				}
				componentKeys.forEach(function (key) {
					data['restore_' + key] = chosen.indexOf(key) !== -1;
				});
				Jobs.start('restore', data, handlersFor('restore')).catch(function (error) {
					toast(error.message, 'error');
				});
			});
		});
	}

	function showRestoreResult(job) {
		var res = job.result || {};
		activateTab('import');
		$('#slicebk-restore-form').hidden = true;
		var target = $('#slicebk-restore-result');
		target.textContent = '';
		target.appendChild(renderReport(res, true));
		target.hidden = false;
		target.focus();
		toast(__('Restore finished.', D));
	}

	function statusBadge(status) {
		var labels = { compatible: __('OK', D), warning: __('Warning', D), conflict: __('Conflict', D) };
		var map = { compatible: 'ok', warning: 'warn', conflict: 'fail' };
		return el('span', { className: 'slicebk-badge slicebk-badge--' + (map[status] || 'info'), text: labels[status] || status });
	}

	function renderReport(res, fresh) {
		var wrap = el('div', { className: 'slicebk-card slicebk-report' });
		var summary = res.summary || {};
		var db = summary.database;
		var replace = summary.replace && summary.replace.from && summary.replace.from !== summary.replace.to ? summary.replace : null;
		var compat = res.compatibilityReport || { items: [], summary: {} };
		var hasErrors = (db && db.errors > 0) || (summary.skippedEntries || []).length > 0;

		wrap.appendChild(el('div', { className: 'slicebk-report__head' }, [
			el('span', { className: 'slicebk-result__icon dashicons ' + (hasErrors ? 'dashicons-warning is-warn' : 'dashicons-yes-alt'), 'aria-hidden': 'true' }),
			el('div', {}, [
				el('h2', { className: 'slicebk-card__title', text: hasErrors ? __('Restore finished with warnings', D) : __('Restore complete', D) }),
				el('p', { className: 'slicebk-muted', text: (res.filename || '') + (res.finishedAt ? ' · ' + res.finishedAt : '') })
			])
		]));

		if (fresh && res.reloginRequired) {
			wrap.appendChild(el('div', { className: 'slicebk-callout slicebk-callout--info' }, [
				icon('dashicons-admin-users'),
				el('div', {}, [
					el('p', { text: __('The database was replaced, so you are probably logged out. Log in with a user account from the restored site.', D) }),
					el('p', {}, [el('a', { className: 'button button-primary', href: cfg.loginUrl, text: __('Log in again', D) })])
				])
			]));
		}

		var stats = el('ul', { className: 'slicebk-stats' });
		if (db) {
			stats.appendChild(el('li', {}, [el('strong', { text: String(db.statements) }), el('span', { text: __('SQL statements run', D) })]));
			stats.appendChild(el('li', { className: db.errors ? 'is-warn' : '' }, [el('strong', { text: String(db.errors) }), el('span', { text: __('SQL errors', D) })]));
		}
		if (replace) {
			stats.appendChild(el('li', {}, [el('strong', { text: String(replace.rowsUpdated || 0) }), el('span', { text: __('rows with updated URLs', D) })]));
		}
		Object.keys(summary.files || {}).forEach(function (key) {
			var f = summary.files[key];
			stats.appendChild(el('li', { className: f.failed ? 'is-warn' : '' }, [
				el('strong', { text: String(f.copied) }),
				el('span', { text: sprintf(__('%s files restored', D), componentLabel(key)) + (f.failed ? ' · ' + sprintf(__('%d failed', D), f.failed) : '') })
			]));
		});
		if (stats.children.length) {
			wrap.appendChild(stats);
		}

		if (replace) {
			wrap.appendChild(el('p', { className: 'slicebk-muted' }, [sprintf(__('URLs changed from %1$s to %2$s.', D), replace.from, replace.to)]));
		}

		var notes = (summary.notes || []).slice();
		if (db && db.errorSamples && db.errorSamples.length) {
			notes.push(__('First database errors:', D) + ' ' + db.errorSamples.join(' | '));
		}
		if (replace && replace.skippedSerialized) {
			notes.push(sprintf(__('%d malformed serialized values were left unchanged.', D), replace.skippedSerialized));
		}
		if ((summary.skippedEntries || []).length) {
			notes.push(sprintf(_n('%d damaged file in the archive was skipped:', '%d damaged files in the archive were skipped:', summary.skippedEntries.length, D), summary.skippedEntries.length) + ' ' + summary.skippedEntries.slice(0, 10).join(', '));
		}
		notes.forEach(function (note) {
			wrap.appendChild(el('div', { className: 'slicebk-callout slicebk-callout--warning' }, [icon('dashicons-info-outline'), el('p', { text: note })]));
		});

		if (compat.items && compat.items.length) {
			var s = compat.summary || {};
			wrap.appendChild(el('h3', { className: 'slicebk-subtitle' }, [
				icon('dashicons-shield'),
				sprintf(__('Compatibility with PHP %1$s: %2$d OK, %3$d warnings, %4$d conflicts', D), compat.targetPhp, s.compatibleCount || 0, s.warningCount || 0, s.conflictCount || 0)
			]));
			var tbody = el('tbody');
			compat.items.forEach(function (item) {
				tbody.appendChild(el('tr', { className: 'is-' + item.status }, [
					el('td', {}, [statusBadge(item.status)]),
					el('td', {}, [el('strong', { text: item.name }), el('span', { className: 'slicebk-muted slicebk-small slicebk-block', text: item.type === 'plugin' ? __('Plugin', D) : __('Theme', D) })]),
					el('td', {}, [
						el('span', { text: item.issue }),
						item.status !== 'compatible' && item.file ? el('code', { className: 'slicebk-small slicebk-block', text: item.file }) : null
					]),
					el('td', { text: item.actionTaken + (item.recommendation ? ' ' + item.recommendation : '') })
				]));
			});
			wrap.appendChild(el('div', { className: 'slicebk-table-wrap' }, [
				el('table', { className: 'slicebk-table slicebk-table--report' }, [
					el('thead', {}, [el('tr', {}, [
						el('th', { scope: 'col', text: __('Status', D) }),
						el('th', { scope: 'col', text: __('Name', D) }),
						el('th', { scope: 'col', text: __('Finding', D) }),
						el('th', { scope: 'col', text: __('Action', D) })
					])]),
					tbody
				])
			]));
		}

		return wrap;
	}

	var lastReport = $('#slicebk-last-report');
	if (lastReport) {
		lastReport.addEventListener('toggle', function () {
			if (!lastReport.open || lastReport.dataset.loaded) {
				return;
			}
			lastReport.dataset.loaded = '1';
			var body = $('#slicebk-last-report-body');
			request('slice_backup_report').then(function (data) {
				body.textContent = '';
				body.appendChild(data.report ? renderReport(data.report, false) : el('p', { className: 'slicebk-muted', text: __('No report available.', D) }));
			}).catch(function (error) {
				body.textContent = error.message;
			});
		});
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	$$('.slicebk-settings-nav a').forEach(function (link) {
		link.addEventListener('click', function (event) {
			event.preventDefault();
			var target = document.querySelector(link.getAttribute('href'));
			if (target) {
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
	});

	$$('form[data-section]').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			var section = form.dataset.section;
			var data = formData(form);

			if (section === 'encryption') {
				if (data.passphrase !== data.passphrase_confirm) {
					toast(__('The two passphrases do not match.', D), 'error');
					return;
				}
				if (data.passphrase && data.passphrase.length < 8) {
					toast(__('The passphrase must be at least 8 characters.', D), 'error');
					return;
				}
				delete data.passphrase_confirm;
			}

			data.section = section;
			var button = form.querySelector('button[type="submit"]');
			button.disabled = true;
			request('slice_backup_save_settings', data).then(function (res) {
				cfg.settings = res.settings;
				cfg.cloudReady = res.cloudReady;
				if (section === 'schedule') {
					$('#slicebk-next-run').textContent = res.nextRun ? new Date(res.nextRun * 1000).toLocaleString() : __('Not scheduled', D);
					$('#slicebk-schedule-now').disabled = !res.settings.schedule.enabled;
				}
				if (section === 'encryption' || section === 'cloud') {
					// Labels elsewhere on the page depend on these settings.
					toast(res.message + ' ' + __('Reloading…', D));
					window.setTimeout(function () { window.location.reload(); }, 900);
					return;
				}
				toast(res.message || __('Settings saved.', D));
			}).catch(function (error) {
				toast(error.message, 'error');
			}).then(function () {
				button.disabled = false;
				$$('input[type="password"]', form).forEach(function (field) { field.value = ''; });
			});
		});
	});

	/* Cloud provider fields */
	var providerSelect = $('#slicebk-cloud-provider');
	function renderProvider() {
		var id = providerSelect.value;
		var p = (cfg.providers || {})[id];
		$('#slicebk-cloud-fields').hidden = !p;
		if (!p) {
			return;
		}
		$$('[data-cloud-kind]').forEach(function (group) {
			var active = group.dataset.cloudKind === p.kind;
			group.hidden = !active;
			// Hidden groups must not submit (or validate) their fields.
			$$('input, textarea, select', group).forEach(function (field) { field.disabled = !active; });
		});
		$('#slicebk-cloud-path-label').textContent = {
			s3: __('Folder in the bucket', D),
			sftp: __('Folder on the server', D),
			dropbox: __('Folder inside the app folder', D)
		}[p.kind] || __('Folder', D);
		$$('[data-cloud-field]').forEach(function (field) {
			field.hidden = (p.fields || []).indexOf(field.dataset.cloudField) === -1;
		});
		syncSftpAuth();
		var region = $('#slicebk-cloud-region');
		region.placeholder = p.region || '';

		var links = $('#slicebk-provider-links');
		links.textContent = '';
		var labels = { signup: __('Create an account', D), pricing: __('Pricing', D), keys: __('How to create access keys', D) };
		Object.keys(labels).forEach(function (key) {
			if (p.links && p.links[key]) {
				links.appendChild(el('a', { href: p.links[key], target: '_blank', rel: 'noopener noreferrer' }, [labels[key], el('span', { className: 'screen-reader-text', text: __('(opens in a new tab)', D) })]));
			}
		});
	}
	if (providerSelect) {
		providerSelect.addEventListener('change', renderProvider);
		renderProvider();

		$('#slicebk-cloud-test').addEventListener('click', function () {
			var form = $('#slicebk-set-cloud');
			var data = formData(form);
			var out = $('#slicebk-cloud-test-result');
			var button = this;
			button.disabled = true;
			out.className = 'slicebk-test-result';
			out.textContent = __('Testing…', D);
			request('slice_backup_cloud_test', data).then(function (res) {
				out.className = 'slicebk-test-result is-ok';
				out.textContent = res.message + (res.fingerprint ? ' ' + __('Save the settings to keep trusting this server.', D) : '');
				if (res.fingerprint) {
					$('#slicebk-sftp-fingerprint').value = res.fingerprint;
				}
			}).catch(function (error) {
				out.className = 'slicebk-test-result is-fail';
				out.textContent = error.message;
			}).then(function () {
				button.disabled = false;
			});
		});
	}

	function syncSftpAuth() {
		var chosen = $('input[name="sftp_auth"]:checked');
		var mode = chosen ? chosen.value : 'password';
		$$('[data-sftp-auth]').forEach(function (node) {
			var active = node.dataset.sftpAuth === mode && !node.closest('[data-cloud-kind]').hidden;
			node.hidden = node.dataset.sftpAuth !== mode;
			$$('input, textarea', node).forEach(function (field) { field.disabled = !active; });
		});
	}
	$$('input[name="sftp_auth"]').forEach(function (radio) {
		radio.addEventListener('change', syncSftpAuth);
	});

	/* Dropbox */
	var dropboxConnect = $('#slicebk-dropbox-connect');
	if (dropboxConnect) {
		dropboxConnect.addEventListener('click', function () {
			var key = $('#slicebk-dropbox-key').value.trim();
			if (!key) {
				toast(__('Paste your Dropbox app key first.', D), 'error');
				return;
			}
			// Open the window synchronously so pop-up blockers allow it.
			var popup = window.open('about:blank', '_blank');
			request('slice_backup_dropbox_start', { app_key: key }).then(function (res) {
				if (popup) {
					popup.opener = null;
					popup.location.href = res.url;
				} else {
					window.open(res.url, '_blank', 'noopener');
				}
				$('#slicebk-dropbox-code-row').hidden = false;
				$('#slicebk-dropbox-code').focus();
			}).catch(function (error) {
				if (popup) {
					popup.close();
				}
				toast(error.message, 'error');
			});
		});
		$('#slicebk-dropbox-finish').addEventListener('click', function () {
			var code = $('#slicebk-dropbox-code').value.trim();
			if (!code) {
				return;
			}
			var button = this;
			button.disabled = true;
			request('slice_backup_dropbox_finish', { code: code }).then(function (res) {
				toast(res.message + ' ' + __('Reloading…', D));
				window.setTimeout(function () { window.location.reload(); }, 900);
			}).catch(function (error) {
				toast(error.message, 'error');
				button.disabled = false;
			});
		});
	}

	/* Site-to-site transfer keys */
	var peerGenerate = $('#slicebk-peer-generate');
	if (peerGenerate) {
		peerGenerate.addEventListener('click', function () {
			var go = function () {
				request('slice_backup_peer_key', { hours: $('#slicebk-peer-hours').value }).then(function (res) {
					$('#slicebk-peer-key').value = res.key;
					$('#slicebk-peer-key-row').hidden = false;
					$('#slicebk-peer-revoke').disabled = false;
					var status = $('#slicebk-peer-status');
					status.textContent = '';
					status.appendChild(el('span', { className: 'slicebk-badge slicebk-badge--warn', text: __('Active', D) }));
					status.appendChild(document.createTextNode(' ' + sprintf(__('A transfer key is active until %s.', D), new Date(res.expires * 1000).toLocaleString())));
					$('#slicebk-peer-key').select();
				}).catch(function (error) { toast(error.message, 'error'); });
			};
			if ($('#slicebk-peer-revoke').disabled) {
				go();
				return;
			}
			confirmDialog({
				title: __('Replace the transfer key?', D),
				message: __('The current key stops working immediately.', D),
				confirmLabel: __('Create new key', D)
			}).then(function (ok) { if (ok) { go(); } });
		});
		$('#slicebk-peer-revoke').addEventListener('click', function () {
			request('slice_backup_peer_revoke').then(function () {
				$('#slicebk-peer-key-row').hidden = true;
				$('#slicebk-peer-key').value = '';
				$('#slicebk-peer-revoke').disabled = true;
				var status = $('#slicebk-peer-status');
				status.textContent = '';
				status.appendChild(el('span', { className: 'slicebk-badge slicebk-badge--info', text: __('Off', D) }));
				status.appendChild(document.createTextNode(' ' + __('No transfer key. Other sites cannot connect.', D)));
				toast(__('Transfer key revoked.', D));
			}).catch(function (error) { toast(error.message, 'error'); });
		});
		$('#slicebk-peer-copy').addEventListener('click', function () {
			var text = $('#slicebk-peer-key').value;
			var done = function () { toast(__('Transfer key copied.', D)); };
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text); done(); });
			} else {
				fallbackCopy(text);
				done();
			}
		});
	}

	/* Pull from another site */
	var pullStart = $('#slicebk-pull-start');
	if (pullStart) {
		pullStart.addEventListener('click', function () {
			var key = $('#slicebk-pull-key').value.trim();
			checkPeerKey(key, $('#slicebk-pull-result'), pullStart).then(function (info) {
				if (!info || info.busy) {
					return;
				}
				Jobs.start('pull', { key: key, export_type: $('#slicebk-pull-preset').value }, handlersFor('pull')).catch(function (error) {
					toast(error.message, 'error');
				});
			});
		});
	}

	/* Schedule */
	var freq = $('#slicebk-sched-frequency');
	if (freq) {
		var syncWeekday = function () { $('#slicebk-sched-weekday-field').hidden = freq.value !== 'slice_backup_weekly'; };
		freq.addEventListener('change', syncWeekday);
		syncWeekday();
	}
	var runNow = $('#slicebk-schedule-now');
	if (runNow) {
		runNow.addEventListener('click', function () {
			if (Jobs.current) {
				toast(__('Another job is running. Wait for it to finish.', D), 'error');
				return;
			}
			request('slice_backup_schedule_now').then(function (job) {
				Jobs.follow(job, job.token, handlersFor('export'));
			}).catch(function (error) { toast(error.message, 'error'); });
		});
	}

	/* ------------------------------------------------------------------ */
	/* System                                                              */
	/* ------------------------------------------------------------------ */

	var statusText = { ok: __('OK', D), warn: __('Check', D), fail: __('Problem', D), info: __('Info', D) };

	function runProbe() {
		var row = $('[data-check="probe"]');
		if (!row) {
			return;
		}
		request('slice_backup_probe').then(function (data) {
			var r = data.row;
			row.className = 'slicebk-check-row is-' + r.status;
			var badge = row.querySelector('.slicebk-badge');
			badge.className = 'slicebk-badge slicebk-badge--' + r.status;
			badge.textContent = statusText[r.status] || r.status;
			row.querySelector('.slicebk-check-row__value').textContent = r.value;
			var help = row.querySelector('.slicebk-check-row__help');
			help.textContent = r.help || '';
			help.hidden = !r.help;
		}).catch(function () {
			row.querySelector('.slicebk-check-row__value').textContent = __('Could not test', D);
		});
	}

	var copyButton = $('#slicebk-copy-report');
	if (copyButton) {
		copyButton.addEventListener('click', function () {
			var lines = ['Slice Backup system report', cfg.siteUrl, ''];
			$$('.slicebk-check-row').forEach(function (row) {
				lines.push(
					row.querySelector('.slicebk-check-row__label').textContent.trim() + ': ' +
					row.querySelector('.slicebk-check-row__value').textContent.trim() + ' [' +
					row.querySelector('.slicebk-badge').textContent.trim() + ']'
				);
			});
			var text = lines.join('\n');
			var done = function () { toast(__('System report copied.', D)); };
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text); done(); });
			} else {
				fallbackCopy(text);
				done();
			}
		});
	}

	function fallbackCopy(text) {
		var area = el('textarea', { className: 'screen-reader-text' });
		area.value = text;
		document.body.appendChild(area);
		area.select();
		try { document.execCommand('copy'); } catch (e) { /* ignore */ }
		area.remove();
	}

	/* ------------------------------------------------------------------ */
	/* Start                                                               */
	/* ------------------------------------------------------------------ */

	activateTab((window.location.hash || '#export').slice(1));
	Jobs.resume();
}());
