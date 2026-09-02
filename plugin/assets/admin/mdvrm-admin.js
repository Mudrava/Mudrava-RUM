/* Mudrava RUM admin app */
(function () {
	'use strict';

	var cfg = window.MDVRMAdminSettings || {};
	var i18n = cfg.i18n || {};

	function t(key, fallback) {
		return i18n[key] || fallback || key;
	}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) {
			n.className = cls;
		}
		if (text !== undefined && text !== null) {
			n.textContent = String(text);
		}
		return n;
	}

	function fmt(v) {
		var n = Number(v);
		return isFinite(n) ? n.toFixed(2) : '—';
	}

	function fmtDateTime(s) {
		var d = new Date(String(s).replace(' ', 'T') + 'Z');
		if (isNaN(d.getTime())) {
			return String(s);
		}
		return d.toLocaleString();
	}

	function grade(v, good, poor) {
		v = Number(v);
		if (!isFinite(v) || v <= 0) {
			return 'na';
		}
		if (v <= good) {
			return 'good';
		}
		if (v <= poor) {
			return 'avg';
		}
		return 'poor';
	}

	var GRADES = {
		ttfb: [0.8, 1.8],
		lcp: [2.5, 4.0],
		total_load: [3.0, 5.0],
		server_time: [0.5, 1.0],
		avg_ttfb: [0.8, 1.8],
		avg_lcp: [2.5, 4.0],
		p75_lcp: [2.5, 4.0],
		avg_server: [0.5, 1.0],
		avg_load: [3.0, 5.0]
	};

	var UNKNOWN = t('unknown', 'unknown');

	function isSafeHref(url) {
		if (typeof url !== 'string' || !url) {
			return false;
		}
		return /^(https?:\/\/|\/\/)/i.test(url);
	}

	function prettyUrl(url) {
		if (!isSafeHref(url)) {
			return url || '';
		}
		try {
			var u = new URL(url, window.location.href);
			return u.pathname + u.search;
		} catch (e) {
			return url;
		}
	}

	function api(url, params) {
		var u = new URL(url, window.location.href);
		Object.keys(params || {}).forEach(function (k) {
			if (params[k] === '' || params[k] === null || typeof params[k] === 'undefined') {
				return;
			}
			u.searchParams.set(k, params[k]);
		});
		return fetch(u.toString(), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		}).then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		});
	}

	function svg(tag, attrs) {
		var n = document.createElementNS('http://www.w3.org/2000/svg', tag);
		Object.keys(attrs || {}).forEach(function (k) {
			n.setAttribute(k, attrs[k]);
		});
		return n;
	}

	function addTestEmail() {
		var btn = document.getElementById('mdvrm-send-test-email');
		if (!btn || !cfg.sendReportUrl) {
			return;
		}
		btn.addEventListener('click', function () {
			if (!window.confirm(t('confirmSend', 'Send the current report to the configured email now?'))) {
				return;
			}
			btn.disabled = true;
			var label = btn.textContent;
			btn.textContent = t('loading', 'Loading…');
			fetch(cfg.sendReportUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce }
			}).then(function (r) {
				return r.json().then(function (j) {
					return { ok: r.ok, j: j };
				});
			}).then(function (res) {
				window.alert(res.ok && res.j && res.j.status === 'sent'
					? t('sentOk', 'Email sent successfully.')
					: t('sentFail', 'Could not send email. Check mail settings and recipient.'));
			}).catch(function () {
				window.alert(t('sentFail', 'Could not send email. Check mail settings and recipient.'));
			}).finally(function () {
				btn.disabled = false;
				btn.textContent = label;
			});
		});
	}

	function initLive() {
		var periodSel = document.getElementById('mdvrm-period');
		var autoChk = document.getElementById('mdvrm-autorefresh');
		var statusEl = document.getElementById('mdvrm-status');
		var srStatus = document.getElementById('mdvrm-live-status');
		var kpis = document.getElementById('mdvrm-kpis');
		var sessionInp = document.getElementById('mdvrm-f-session');
		var urlInp = document.getElementById('mdvrm-f-url');
		var deviceSel = document.getElementById('mdvrm-f-device');
		var netSel = document.getElementById('mdvrm-f-net');
		var applyBtn = document.getElementById('mdvrm-apply');
		var clearBtn = document.getElementById('mdvrm-clear');
		var perSel = document.getElementById('mdvrm-per-page');
		var tbody = document.getElementById('mdvrm-tbody');
		var table = document.getElementById('mdvrm-table');
		var pager = document.getElementById('mdvrm-pager');
		var exportBtn = document.getElementById('mdvrm-export-btn');
		var reportBtn = document.getElementById('mdvrm-report-open');
		var toolbar = document.querySelector('.mdvrm-toolbar');

		if (!periodSel || !tbody || !kpis || !pager) {
			return;
		}

		function loadDaysPref() {
			try {
				var v = sessionStorage.getItem('mdvrm_days');
				return v === '1' || v === '30' ? v : '7';
			} catch (e) {
				return '7';
			}
		}

		var state = {
			days: loadDaysPref(),
			page: 1,
			perPage: 20,
			order: 'desc',
			orderBy: 'event_time',
			device: '',
			net: '',
			session: '',
			url: '',
			total: 0
		};

		periodSel.value = state.days;

		var auto = { on: false, timer: null };
		var lastRows = [];

		var refreshBtn = el('button', 'button button-small', 'Refresh');
		refreshBtn.type = 'button';
		if (toolbar) {
			toolbar.insertBefore(refreshBtn, statusEl || null);
		}

		function setStatus(msg) {
			if (statusEl) {
				statusEl.textContent = msg;
			}
			if (srStatus) {
				srStatus.textContent = msg;
			}
		}

		function filters() {
			var p = {
				order: state.order,
				order_by: state.orderBy,
				device: state.device,
				net: state.net,
				session_id: state.session,
				url: state.url
			};
			if (state.days && state.days !== '0') {
				p.days = state.days;
			}
			return p;
		}

		function readFilters() {
			state.session = sessionInp ? sessionInp.value.trim() : '';
			state.url = urlInp ? urlInp.value.trim() : '';
			state.device = deviceSel ? deviceSel.value : '';
			state.net = netSel ? netSel.value : '';
		}

		function metricTd(value, key) {
			return el('td', 'mdvrm-val--' + grade(value, GRADES[key][0], GRADES[key][1]), fmt(value) + 's');
		}

		function urlCell(url) {
			var td = el('td', 'mdvrm-td-url');
			if (isSafeHref(url)) {
				var a = document.createElement('a');
				a.href = url;
				a.target = '_blank';
				a.rel = 'noopener noreferrer';
				a.textContent = prettyUrl(url) || t('badUrl', 'Invalid URL');
				a.title = url;
				td.appendChild(a);
			} else {
				td.textContent = url ? url : t('badUrl', 'Invalid URL');
			}
			return td;
		}

		var DEV_ICONS = {
			desktop: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="12" rx="1.5"/><path d="M8.5 20h7M12 16.5V20"/></svg>',
			mobile: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M10.5 18.5h3"/></svg>',
			tablet: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="2.5" width="15" height="19" rx="2"/><path d="M10.5 18.5h3"/></svg>'
		};

		function devicePill(device) {
			var pill = el('span', 'mdvrm-pill');
			var key = String(device || '').toLowerCase();
			var ico = document.createElement('span');
			if (DEV_ICONS[key]) {
				ico.className = 'mdvrm-dev';
				ico.innerHTML = DEV_ICONS[key];
			} else {
				ico.className = 'mdvrm-dev mdvrm-dev--unknown';
				ico.textContent = '?';
			}
			pill.appendChild(ico);
			pill.appendChild(document.createTextNode(device ? device : UNKNOWN));
			return pill;
		}

		function renderRows(rows) {
			lastRows = rows || [];
			tbody.textContent = '';
			if (!lastRows.length) {
				var tr0 = document.createElement('tr');
				tr0.className = 'mdvrm-empty-row';
				var td0 = el('td', null, t('empty', 'No entries yet. Visit some front-end pages to collect data.'));
				td0.colSpan = 10;
				tr0.appendChild(td0);
				tbody.appendChild(tr0);
				return;
			}
			lastRows.forEach(function (row) {
				var tr = document.createElement('tr');
				tr.appendChild(el('td', 'mdvrm-td-time', fmtDateTime(row.event_time)));
				tr.appendChild(urlCell(row.url));
				tr.appendChild(metricTd(row.ttfb, 'ttfb'));
				tr.appendChild(metricTd(row.lcp, 'lcp'));
				tr.appendChild(metricTd(row.total_load, 'total_load'));
				tr.appendChild(metricTd(row.server_time, 'server_time'));
			var devTd = el('td');
			devTd.appendChild(devicePill(row.device));
			tr.appendChild(devTd);
				tr.appendChild(el('td', null, row.net ? row.net : '—'));
				tr.appendChild(el('td', null, row.country ? row.country : '—'));
				var sessTd = el('td', 'mdvrm-td-session');
				var sessSpan;
				if (row.session_id) {
					sessSpan = el('a', null, String(row.session_id).slice(0, 8) + '…');
					sessSpan.href = 'javascript:void(0)';
					sessSpan.title = row.session_id;
					sessSpan.addEventListener('click', function () {
						if (sessionInp) {
							sessionInp.value = row.session_id;
						}
						readFilters();
						state.page = 1;
						loadAll();
					});
				} else {
					sessSpan = el('span', null, '—');
				}
				sessTd.appendChild(sessSpan);
				tr.appendChild(sessTd);
				tbody.appendChild(tr);
			});
		}

		function renderKpis(stats) {
			kpis.textContent = '';
			[
				{ label: t('events', 'Events'), value: String(stats.count), cls: 'neutral', note: periodLabel() },
				{ label: 'Avg TTFB', value: fmt(stats.avg_ttfb) + 's', cls: grade(stats.avg_ttfb, GRADES.ttfb[0], GRADES.ttfb[1]), note: 'good ≤ 0.8s · poor > 1.8s' },
				{ label: 'P75 LCP', value: fmt(stats.p75_lcp) + 's', cls: grade(stats.p75_lcp, GRADES.lcp[0], GRADES.lcp[1]), note: 'good ≤ 2.5s · poor > 4s' },
				{ label: 'Avg Server', value: fmt(stats.avg_server) + 's', cls: grade(stats.avg_server, GRADES.server_time[0], GRADES.server_time[1]), note: 'PHP render · good ≤ 0.5s' },
				{ label: 'Avg Total Load', value: fmt(stats.avg_load) + 's', cls: grade(stats.avg_load, GRADES.total_load[0], GRADES.total_load[1]), note: 'full page · good ≤ 3s' }
			].forEach(function (c) {
				var card = el('div', 'mdvrm-kpi mdvrm-kpi--' + c.cls);
				card.appendChild(el('div', 'mdvrm-kpi__label', c.label));
				card.appendChild(el('div', 'mdvrm-kpi__value', c.value));
				card.appendChild(el('div', 'mdvrm-kpi__note', c.note));
				kpis.appendChild(card);
			});
		}

		function periodLabel() {
			var opt = periodSel.options[periodSel.selectedIndex];
			return opt ? opt.textContent : '';
		}

		function renderPager() {
			pager.textContent = '';
			var pages = Math.max(1, Math.ceil(state.total / state.perPage));
			pager.appendChild(el('span', 'mdvrm-pager__info', state.total + ' ' + t('items', 'events') + ' · ' + state.page + '/' + pages));
			var mk = function (glyph, title, target) {
				var b = el('button', 'button button-small', glyph);
				b.type = 'button';
				b.title = title;
				b.setAttribute('aria-label', title);
				b.addEventListener('click', function () {
					state.page = target();
					loadLogs();
				});
				return b;
			};
			pager.appendChild(mk('«', t('firstPage', 'First page'), function () { return 1; }));
			pager.appendChild(mk('‹', t('prevPage', 'Previous page'), function () { return Math.max(1, state.page - 1); }));
			pager.appendChild(mk('›', t('nextPage', 'Next page'), function () { return Math.min(pages, state.page + 1); }));
			pager.appendChild(mk('»', t('lastPage', 'Last page'), function () { return pages; }));
		}

		function enhanceHeaders() {
			var ths = table ? table.querySelectorAll('thead th[data-sort]') : [];
			Array.prototype.forEach.call(ths, function (th) {
				var key = th.getAttribute('data-sort');
				var ind = el('span', 'mdvrm-sort-ind', state.orderBy === key ? (state.order === 'asc' ? '▲' : '▼') : '↕');
				th.appendChild(ind);
				th.setAttribute('aria-sort', state.orderBy === key ? (state.order === 'asc' ? 'ascending' : 'descending') : 'none');
				th.addEventListener('click', function () {
					if (state.orderBy === key) {
						state.order = state.order === 'desc' ? 'asc' : 'desc';
					} else {
						state.orderBy = key;
						state.order = 'desc';
					}
					state.page = 1;
					enhanceHeaders();
					loadLogs();
				});
			});
		}

		function loadLogs() {
			var p = filters();
			p.page = state.page;
			p.per_page = state.perPage;
			api(cfg.restUrl, p).then(function (res) {
				state.total = res.total;
				renderRows(res.data);
				renderPager();
				setStatus(t('updated', 'Updated') + ' ' + new Date().toLocaleTimeString());
			}).catch(function () {
				setStatus(t('error', 'Failed to load data.'));
			});
		}

		function loadStats() {
			api(cfg.statsUrl, filters()).then(function (stats) {
				renderKpis(stats);
			}).catch(function () { /* status handled by loadLogs */ });
		}

		function loadAll() {
			loadLogs();
			loadStats();
		}

		enhanceHeaders();
		renderPager();

		applyBtn.addEventListener('click', function () {
			readFilters();
			state.page = 1;
			loadAll();
		});
		clearBtn.addEventListener('click', function () {
			if (sessionInp) { sessionInp.value = ''; }
			if (urlInp) { urlInp.value = ''; }
			if (deviceSel) { deviceSel.value = ''; }
			if (netSel) { netSel.value = ''; }
			readFilters();
			state.page = 1;
			loadAll();
		});
		[sessionInp, urlInp].forEach(function (inp) {
			if (!inp) {
				return;
			}
			inp.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					applyBtn.click();
				}
			});
		});
		periodSel.addEventListener('change', function () {
			state.days = periodSel.value;
			state.page = 1;
			try {
				sessionStorage.setItem('mdvrm_days', state.days);
			} catch (e) {
				/* storage unavailable */
			}
			readFilters();
			loadAll();
		});
		perSel.addEventListener('change', function () {
			state.perPage = parseInt(perSel.value, 10) || 20;
			state.page = 1;
			loadLogs();
		});
		refreshBtn.addEventListener('click', function () {
			readFilters();
			loadAll();
		});

		function setAuto(on) {
			auto.on = on;
			if (auto.timer) {
				clearInterval(auto.timer);
				auto.timer = null;
			}
			if (on) {
				auto.timer = setInterval(function () {
					if (!document.hidden) {
						readFilters();
						loadAll();
					}
				}, 10000);
			}
		}
		function loadAutoPref() {
			try {
				return localStorage.getItem('mdvrm_auto') === '1';
			} catch (e) {
				return false;
			}
		}

		function saveAutoPref(on) {
			try {
				localStorage.setItem('mdvrm_auto', on ? '1' : '0');
			} catch (e) {
				/* storage unavailable */
			}
		}

		if (autoChk) {
			autoChk.checked = loadAutoPref();
			if (autoChk.checked) {
				setAuto(true);
			}
			autoChk.addEventListener('change', function () {
				setAuto(autoChk.checked);
				saveAutoPref(autoChk.checked);
			});
			document.addEventListener('visibilitychange', function () {
				if (auto.on && autoChk.checked && !document.hidden) {
					readFilters();
					loadAll();
				}
			});
		}

		function csvCell(v) {
			var s = v === null || typeof v === 'undefined' ? '' : String(v);
			if (/^[=+\-@\t\r]/.test(s)) {
				s = "'" + s;
			}
			return '"' + s.replace(/"/g, '""') + '"';
		}

		if (exportBtn) {
			exportBtn.addEventListener('click', function () {
				if (!lastRows.length) {
					setStatus(t('empty', 'No entries yet.'));
					return;
				}
				var head = ['event_time', 'url', 'ttfb', 'lcp', 'server_time', 'total_load', 'memory_peak', 'device', 'net', 'country', 'session_id'];
				var lines = [head.join(',')];
				lastRows.forEach(function (r) {
					lines.push(head.map(function (k) { return csvCell(r[k]); }).join(','));
				});
				var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
				var url = URL.createObjectURL(blob);
				var a = document.createElement('a');
				a.href = url;
				a.download = 'mudrava-rum-' + new Date().toISOString().slice(0, 10) + '.csv';
				document.body.appendChild(a);
				a.click();
				a.remove();
				setTimeout(function () {
					URL.revokeObjectURL(url);
				}, 2000);
			});
		}

		function buildTrendChart(trend, key) {
			var wrap = el('div', 'mdvrm-chart');
			if (!trend || !trend.length) {
				return wrap;
			}
			var W = Math.min(560, (window.innerWidth || 800) - 130);
			var H = 90;
			var s = svg('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': key });
			var title = svg('title', {});
			title.textContent = key;
			s.appendChild(title);
			var max = 0;
			trend.forEach(function (r) {
				max = Math.max(max, Number(r[key]) || 0);
			});
			if (max <= 0) {
				max = 1;
			}
			var bw = Math.max(4, Math.min(28, (W - trend.length * 2) / trend.length));
			trend.forEach(function (r, i) {
				var v = Number(r[key]) || 0;
				var bh = Math.max(2, Math.round((v / max) * (H - 22)));
				var rect = svg('rect', { x: i * (bw + 2), y: H - 12 - bh, width: bw, height: bh, fill: '#021d69', rx: 2 });
				var tt = svg('title', {});
				tt.textContent = r.day + ': ' + fmt(v) + 's · ' + r.count + ' views';
				rect.appendChild(tt);
				s.appendChild(rect);
			});
			wrap.appendChild(s);
			var legend = el('div', 'mdvrm-chart__legend');
			legend.appendChild(el('span', null, trend[0].day));
			legend.appendChild(el('span', null, trend[trend.length - 1].day));
			wrap.appendChild(legend);
			return wrap;
		}

		function slowTable(rows, colLabel, valKey) {
			var wrap = el('div', 'mdvrm-table-shell mdvrm-modal-table');
			var tbl = document.createElement('table');
			tbl.className = 'mdvrm-table';
			var thead = document.createElement('thead');
			var hr = document.createElement('tr');
			['URL', colLabel, t('views', 'Views')].forEach(function (x) {
				var th = el('th', null, x);
				th.scope = 'col';
				hr.appendChild(th);
			});
			thead.appendChild(hr);
			tbl.appendChild(thead);
			var tb = document.createElement('tbody');
			(rows || []).forEach(function (r) {
				var tr = document.createElement('tr');
				tr.appendChild(urlCell(r.url));
				tr.appendChild(el('td', null, fmt(r[valKey]) + 's'));
				tr.appendChild(el('td', null, String(r.count)));
				tb.appendChild(tr);
			});
			tbl.appendChild(tb);
			wrap.appendChild(tbl);
			return wrap;
		}

		function openReport() {
			if (document.getElementById('mdvrm-report-modal')) {
				return;
			}
			readFilters();
			var overlay = el('div', 'mdvrm-modal-overlay');
			overlay.id = 'mdvrm-report-modal';
			var panel = el('div', 'mdvrm-modal-panel');
			panel.setAttribute('role', 'dialog');
			panel.setAttribute('aria-modal', 'true');
			panel.setAttribute('aria-label', 'Performance report');
			overlay.appendChild(panel);

			var closeBtn = el('button', 'mdvrm-modal-close', '×');
			closeBtn.type = 'button';
			closeBtn.setAttribute('aria-label', 'Close');
			panel.appendChild(closeBtn);
			panel.appendChild(el('h2', 'mdvrm-modal-title', 'Performance Report'));
			var subtitle = el('p', 'mdvrm-modal-sub', t('loading', 'Loading…'));
			panel.appendChild(subtitle);
			var body = el('div', 'mdvrm-modal-body');
			panel.appendChild(body);

			var escHandler = function (e) {
				if (e.key === 'Escape') {
					close();
				}
			};
			function close() {
				overlay.remove();
				document.removeEventListener('keydown', escHandler);
				reportBtn.focus();
			}
			closeBtn.addEventListener('click', close);
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					close();
				}
			});
			document.addEventListener('keydown', escHandler);
			document.body.appendChild(overlay);
			closeBtn.focus();

			api(cfg.statsUrl, filters()).then(function (stats) {
				subtitle.textContent = 'Based on ' + stats.count + ' ' + t('items', 'events') + ' · ' + periodLabel();

				var grid = el('div', 'mdvrm-kpis');
				[
					{ label: t('events', 'Events'), value: String(stats.count), cls: 'neutral' },
					{ label: 'Avg TTFB', value: fmt(stats.avg_ttfb) + 's', cls: grade(stats.avg_ttfb, GRADES.ttfb[0], GRADES.ttfb[1]) },
					{ label: 'P75 LCP', value: fmt(stats.p75_lcp) + 's', cls: grade(stats.p75_lcp, GRADES.lcp[0], GRADES.lcp[1]) },
					{ label: 'Avg load', value: fmt(stats.avg_load) + 's', cls: grade(stats.avg_load, GRADES.total_load[0], GRADES.total_load[1]) }
				].forEach(function (c) {
					var card = el('div', 'mdvrm-kpi mdvrm-kpi--' + c.cls);
					card.appendChild(el('div', 'mdvrm-kpi__label', c.label));
					card.appendChild(el('div', 'mdvrm-kpi__value', c.value));
					grid.appendChild(card);
				});
				body.appendChild(grid);

				if (stats.trend && stats.trend.length) {
					body.appendChild(el('h3', 'mdvrm-modal-h3', 'TTFB trend · daily average'));
					body.appendChild(buildTrendChart(stats.trend, 'avg_ttfb'));
					body.appendChild(el('h3', 'mdvrm-modal-h3', 'LCP trend · daily average'));
					body.appendChild(buildTrendChart(stats.trend, 'avg_lcp'));
				}

				body.appendChild(el('h3', 'mdvrm-modal-h3', 'Slowest pages by LCP'));
				body.appendChild(slowTable(stats.slowest_lcp, 'Avg LCP', 'avg_lcp'));
				body.appendChild(el('h3', 'mdvrm-modal-h3', 'Heaviest by server time'));
				body.appendChild(slowTable(stats.slowest_srv, 'Avg server', 'avg_srv'));

				if ((stats.devices || []).length) {
					body.appendChild(el('h3', 'mdvrm-modal-h3', 'Devices'));
					var devWrap = el('p', 'mdvrm-modal-devices');
					stats.devices.forEach(function (d) {
						var b = el('span', 'mdvrm-pill', (d.device ? d.device : UNKNOWN) + ' · ' + d.count);
						b.style.marginRight = '8px';
						devWrap.appendChild(b);
					});
					body.appendChild(devWrap);
				}

				var sendBtn = el('button', 'button button-primary', 'Send Report to Email');
				sendBtn.type = 'button';
				sendBtn.style.marginTop = '12px';
				body.appendChild(sendBtn);
				sendBtn.addEventListener('click', function () {
					if (!window.confirm(t('confirmSend', 'Send the current report to the configured email now?'))) {
						return;
					}
					sendBtn.disabled = true;
					sendBtn.textContent = t('loading', 'Loading…');
					fetch(cfg.sendReportUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': cfg.nonce }
					}).then(function (r) {
						return r.json().then(function (j) {
							return { ok: r.ok, j: j };
						});
					}).then(function (res) {
						sendBtn.textContent = res.ok && res.j && res.j.status === 'sent' ? t('sentOk', 'Email sent successfully.') : t('sentFail', 'Could not send email.');
					}).catch(function () {
						sendBtn.textContent = t('sentFail', 'Could not send email.');
					});
				});
			}).catch(function () {
				subtitle.textContent = t('error', 'Failed to load data.');
			});
		}

		if (reportBtn) {
			reportBtn.addEventListener('click', openReport);
		}

		loadAll();
	}

	function init() {
		addTestEmail();
		if (cfg.live) {
			initLive();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
