/* WDF AI Metadata Remover, optional footer credit. Matches the theme footer background and picks a readable text colour. */
(function () {
	'use strict';
	var c = document.getElementById('wdfaimr-credit');
	if (!c) { return; }
	function bg(el) {
		while (el && el !== document.documentElement) {
			var b = getComputedStyle(el).backgroundColor;
			if (b && b !== 'transparent' && !/rgba\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*0\s*\)/.test(b)) { return b; }
			el = el.parentElement;
		}
		var h = getComputedStyle(document.documentElement).backgroundColor;
		return h && h !== 'transparent' && !/rgba\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*0\s*\)/.test(h) ? h : '#ffffff';
	}
	var fs = document.querySelectorAll('footer, .site-footer, #footer, .footer'),
		f = fs.length ? fs[fs.length - 1] : null,
		col = bg(f || document.body),
		m = col.match(/\d+(\.\d+)?/g) || [255, 255, 255],
		lum = (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255;
	c.style.background = col;
	c.style.color = lum < 0.5 ? 'rgba(255,255,255,.75)' : 'rgba(0,0,0,.65)';
	if (f && f.parentElement && f.parentElement !== document.body && f.nextSibling !== c) {
		try { f.parentElement.insertBefore(c, f.nextSibling); } catch (e) {}
	}
})();
