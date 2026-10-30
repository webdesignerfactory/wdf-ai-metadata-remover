/* WDF AI Metadata Remover, admin script. Config arrives via wdfaimrCfg (wp_add_inline_script). */
(function () {
	'use strict';
	var cfg = window.wdfaimrCfg || {};

	/* Activation notice: remember dismissal. */
	document.addEventListener('click', function (e) {
		if (!e.target.closest('#wdfaimr-notice .notice-dismiss')) { return; }
		var f = new FormData();
		f.append('action', 'wdfaimr_dismiss');
		f.append('_wpnonce', cfg.dismissNonce || '');
		fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: f });
	});

	/* Scan / clean page. */
	var btnScan = document.getElementById('wdfaimr-scan');
	if (!btnScan) { return; }
	var btnFlag = document.getElementById('wdfaimr-clean-flagged'),
		btnAll = document.getElementById('wdfaimr-clean-all'),
		stop = document.getElementById('wdfaimr-stop'),
		wrap = document.getElementById('wdfaimr-progress'),
		bar = document.getElementById('wdfaimr-bar'),
		status = document.getElementById('wdfaimr-status'),
		log = document.getElementById('wdfaimr-log'),
		total = parseInt(cfg.total, 10) || 0,
		flaggedTotal = parseInt(cfg.flagged, 10) || 0,
		running = false, mode = '', offset = 0, processed = 0, changed = 0, goal = 0;

	function buttons(dis) { [btnScan, btnFlag, btnAll].forEach(function (b) { b.disabled = dis; }); stop.disabled = !dis; }
	function post(data) {
		var fd = new FormData();
		fd.append('action', 'wdfaimr_bulk');
		fd.append('_wpnonce', cfg.bulkNonce || '');
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}
	function finish(msg) { running = false; buttons(false); status.textContent = msg; }
	function step() {
		if (!running) { finish('Stopped at ' + processed + ' of ' + goal + '. Reload the page to see the current report.'); return; }
		post({ mode: mode, offset: offset }).then(function (res) {
			if (!res.success) { finish('Error: ' + (res.data || 'unknown')); return; }
			var d = res.data;
			offset = d.next; processed += d.processed; changed += d.changed;
			if (d.log.length) { log.style.display = 'block'; d.log.forEach(function (l) { log.textContent += l + '\n'; }); log.scrollTop = log.scrollHeight; }
			bar.style.width = (goal ? Math.min(100, Math.round(processed / goal * 100)) : 100) + '%';
			status.textContent = mode === 'scan' ? (processed + ' of ' + goal + ' scanned, ' + changed + ' flagged so far.') : (processed + ' of ' + goal + ' processed, ' + changed + ' images cleaned.');
			if (d.done) { bar.style.width = '100%'; status.textContent += ' Done. Loading report...'; setTimeout(function () { location.reload(); }, 800); } else { step(); }
		}).catch(function (e) { finish('Request failed: ' + e); });
	}
	function run(m, g) { mode = m; goal = g; running = true; offset = 0; processed = 0; changed = 0; log.textContent = ''; log.style.display = 'none'; wrap.style.display = 'block'; bar.style.width = '0'; buttons(true); status.textContent = 'Starting...'; step(); }

	btnScan.addEventListener('click', function () { run('scan', total); });
	btnAll.addEventListener('click', function () { if (window.confirm(cfg.i18n.confirmAll)) { run('clean', total); } });
	btnFlag.addEventListener('click', function () { if (window.confirm(cfg.i18n.confirmFlagged.replace('%d', flaggedTotal))) { run('clean_flagged', flaggedTotal); } });
	stop.addEventListener('click', function () { running = false; });

	document.addEventListener('click', function (e) {
		var b = e.target.closest('.wdfaimr-row-clean');
		if (!b) { return; }
		var tr = b.closest('tr'), id = tr.getAttribute('data-id');
		b.disabled = true; b.textContent = 'Cleaning...';
		post({ mode: 'clean_ids', ids: id }).then(function (res) {
			if (!res.success) { b.textContent = 'Error'; return; }
			b.textContent = res.data.changed ? 'Cleaned' : 'Already clean'; b.style.color = '#00a32a';
			setTimeout(function () { tr.style.transition = 'opacity .4s'; tr.style.opacity = '0'; setTimeout(function () { tr.remove(); }, 400); }, 900);
			flaggedTotal = Math.max(0, flaggedTotal - 1);
			btnFlag.textContent = 'Clean all flagged (' + flaggedTotal + ')';
			if (!flaggedTotal) { btnFlag.disabled = true; }
		}).catch(function () { b.textContent = 'Error'; });
	});
})();
