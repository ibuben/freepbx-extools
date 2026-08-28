(function () {
	'use strict';

	try {
		document.documentElement.classList.add('exunity-theme');
	} catch (e) {}

	function swapBrandLogo() {
		var img = document.getElementById('MENU_BRAND_IMAGE_TANGO_LEFT');
		if (!img || img.getAttribute('data-exunity-logo') === '1') {
			return;
		}
		img.setAttribute('data-exunity-logo', '1');
		img.src = 'assets/exunity/img/navbar-logo.png?v=20';
		img.alt = 'eXTools';
		img.title = 'eXTools';
	}

	function swapFavicon() {
		var ico = 'assets/exunity/img/favicon.ico?v=16';
		var png = 'assets/exunity/img/favicon-32.png?v=16';
		var head = document.head;
		if (!head) {
			return;
		}
		var links = head.querySelectorAll('link[rel="shortcut icon"], link[rel="icon"]');
		if (!links.length) {
			var icoLink = document.createElement('link');
			icoLink.rel = 'shortcut icon';
			icoLink.href = ico;
			head.appendChild(icoLink);
			links = [icoLink];
		}
		Array.prototype.forEach.call(links, function (el) {
			el.href = ico;
		});
		if (!head.querySelector('link[rel="icon"][type="image/png"]')) {
			var pngLink = document.createElement('link');
			pngLink.rel = 'icon';
			pngLink.type = 'image/png';
			pngLink.sizes = '32x32';
			pngLink.href = png;
			head.appendChild(pngLink);
		}
	}

	function injectFooterCredit() {
		var text = document.getElementById('footer_text');
		if (!text || text.getAttribute('data-exunity-footer') === '1') {
			return;
		}
		text.setAttribute('data-exunity-footer', '1');
		var line = document.createElement('div');
		line.className = 'exunity-footer-credit';
		line.innerHTML = '<a href="https://exunity.uz/?ref=xpbx" target="_blank" rel="noopener noreferrer">'
			+ '<img src="assets/exunity/img/navbar-logo.png?v=20" alt="">'
			+ '<span>eXTools 17.0.11</span></a>';
		text.appendChild(line);
	}

	function applyBrand() {
		swapBrandLogo();
		swapFavicon();
		injectFooterCredit();
	}
	swapFavicon();
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', applyBrand);
	} else {
		applyBrand();
	}

	var AXIS = {
		labelFontColor: '#9aa8b6',
		titleFontColor: '#9aa8b6',
		tickColor: '#2b3340',
		lineColor: '#2b3340',
		gridColor: '#2b3340'
	};

	function isNearWhite(c) {
		if (c == null) {
			return false;
		}
		var s = String(c).replace(/\s+/g, '').toLowerCase();
		if (s === 'white' || s === '#fff' || s === '#ffffff' || s === '#eee' || s === '#eeeeee' || s === '#f5f5f5' || s === '#fafafa') {
			return true;
		}
		var m = s.match(/^rgba?\((\d+),(\d+),(\d+)/);
		if (m) {
			return Number(m[1]) > 230 && Number(m[2]) > 230 && Number(m[3]) > 230;
		}
		return false;
	}

	function mergeAxis(axis) {
		axis = axis || {};
		Object.keys(AXIS).forEach(function (k) {
			if (axis[k] == null || isNearWhite(axis[k]) || axis[k] === '#3A3A3A' || axis[k] === '#666666') {
				axis[k] = AXIS[k];
			}
		});
		return axis;
	}

	function darkenCanvasJS(opts) {
		opts = opts || {};
		opts.backgroundColor = 'transparent';
		opts.title = opts.title || {};
		if (!opts.title.fontColor || isNearWhite(opts.title.fontColor) || opts.title.fontColor === '#3A3A3A') {
			opts.title.fontColor = '#e6edf5';
		}
		opts.axisX = mergeAxis(opts.axisX);
		opts.axisY = mergeAxis(opts.axisY);
		opts.legend = opts.legend || {};
		if (!opts.legend.fontColor || isNearWhite(opts.legend.fontColor)) {
			opts.legend.fontColor = '#e6edf5';
		}
		opts.toolTip = opts.toolTip || {};
		if (!opts.toolTip.backgroundColor || isNearWhite(opts.toolTip.backgroundColor)) {
			opts.toolTip.backgroundColor = '#222733';
		}
		if (!opts.toolTip.fontColor || isNearWhite(opts.toolTip.fontColor)) {
			opts.toolTip.fontColor = '#e6edf5';
		}
		return opts;
	}

	function copyStatics(from, to) {
		try {
			Object.getOwnPropertyNames(from).forEach(function (k) {
				if (k === 'prototype' || k === 'length' || k === 'name' || k === 'arguments' || k === 'caller') {
					return;
				}
				try {
					to[k] = from[k];
				} catch (e) {}
			});
		} catch (e) {}
		to.prototype = from.prototype;
		to.__exunityPatched = true;
	}

	function intercept(prop, wrap) {
		var current = window[prop];
		if (current) {
			window[prop] = wrap(current);
			return;
		}
		try {
			Object.defineProperty(window, prop, {
				configurable: true,
				enumerable: true,
				get: function () {
					return current;
				},
				set: function (v) {
					current = wrap(v);
				}
			});
		} catch (e) {
			var n = 0;
			(function wait() {
				if (window[prop]) {
					window[prop] = wrap(window[prop]);
					return;
				}
				if (n++ < 80) {
					setTimeout(wait, 50);
				}
			})();
		}
	}

	intercept('CanvasJS', function (ns) {
		if (!ns || typeof ns.Chart !== 'function' || ns.Chart.__exunityPatched) {
			return ns;
		}
		var Orig = ns.Chart;
		function Chart(id, options) {
			return new Orig(id, darkenCanvasJS(options));
		}
		copyStatics(Orig, Chart);
		ns.Chart = Chart;
		return ns;
	});

	function recolorList(list) {
		if (!Array.isArray(list)) {
			return list;
		}
		return list.map(function (c) {
			return isNearWhite(c) ? '#2b3340' : c;
		});
	}

	intercept('Chart', function (Chart) {
		if (!Chart || Chart.__exunityPatched) {
			return Chart;
		}
		function wrapConfig(config) {
			if (!config) {
				return config;
			}
			config.options = config.options || {};
			if (!config.options.plugins) {
				config.options.plugins = {};
			}
			if (config.options.plugins.legend && config.options.plugins.legend.labels) {
				config.options.plugins.legend.labels.color = config.options.plugins.legend.labels.color || '#e6edf5';
			}
			if (config.data && config.data.datasets) {
				config.data.datasets.forEach(function (ds) {
					ds.backgroundColor = recolorList(ds.backgroundColor);
					if (isNearWhite(ds.backgroundColor)) {
						ds.backgroundColor = '#2b3340';
					}
				});
			}
			return config;
		}
		function Wrapped(ctx, config) {
			return new Chart(ctx, wrapConfig(config));
		}
		copyStatics(Chart, Wrapped);
		if (Chart.defaults) {
			try {
				if (Chart.defaults.global) {
					Chart.defaults.global.defaultFontColor = '#e6edf5';
				}
				if (Chart.defaults.color !== undefined) {
					Chart.defaults.color = '#e6edf5';
					Chart.defaults.borderColor = '#2b3340';
				}
			} catch (e) {}
		}
		return Wrapped;
	});

	intercept('ApexCharts', function (Apex) {
		if (!Apex || Apex.__exunityPatched) {
			return Apex;
		}
		function Wrapped(el, options) {
			options = options || {};
			options.chart = options.chart || {};
			if (!options.chart.background || isNearWhite(options.chart.background)) {
				options.chart.background = 'transparent';
			}
			options.chart.foreColor = options.chart.foreColor || '#e6edf5';
			if (options.plotOptions && options.plotOptions.radialBar) {
				options.plotOptions.radialBar.track = options.plotOptions.radialBar.track || {};
				if (!options.plotOptions.radialBar.track.background || isNearWhite(options.plotOptions.radialBar.track.background)) {
					options.plotOptions.radialBar.track.background = '#2b3340';
				}
			}
			if (options.theme && options.theme.monochrome) {
				options.theme.mode = 'dark';
			}
			return new Apex(el, options);
		}
		copyStatics(Apex, Wrapped);
		return Wrapped;
	});
})();
