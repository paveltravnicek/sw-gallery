(function () {
	'use strict';

	var roots = [];

	/* ============================================================
	   Justified layout
	   - plné řádky zarovnané na šířku kontejneru
	   - poslední neúplný řádek zůstane v cílové výšce, zarovnaný vlevo
	     (žádné roztahování 1–2 fotek přes celou šířku)
	   ============================================================ */

	function targetRowHeight(grid) {
		var v = getComputedStyle(grid).getPropertyValue('--swg-row-h');
		var h = parseFloat(v);
		return h > 0 ? h : 260;
	}

	function gapOf(grid) {
		var cs = getComputedStyle(grid);
		var g = parseFloat(cs.columnGap || cs.gap);
		return g > 0 ? g : 12;
	}

	function ratioOf(it) {
		var r = parseFloat(it.getAttribute('data-ratio')) || 1.5;
		// Pojistka proti extrémním poměrům (ultra panorama / hodně vysoký portrét),
		// aby jedna fotka nerozbila řádek. Zbytek dořeší object-fit: cover.
		return Math.max(0.4, Math.min(2.6, r));
	}

	function layoutGrid(grid) {
		var W = grid.clientWidth;
		if (!W) { return; }
		var items = Array.prototype.slice.call(grid.querySelectorAll('.swg-item'));
		if (!items.length) { return; }

		var gap = gapOf(grid);
		var H = targetRowHeight(grid);

		var row = [];
		var rowRatio = 0;
		var lastFullH = H; // výška posledního plného řádku

		function apply(rowItems, h) {
			rowItems.forEach(function (it) {
				it.style.flex = '0 0 auto';
				it.style.width = Math.round(ratioOf(it) * h) + 'px';
				it.style.height = Math.round(h) + 'px';
			});
		}

		for (var i = 0; i < items.length; i++) {
			row.push(items[i]);
			rowRatio += ratioOf(items[i]);

			var gaps = gap * (row.length - 1);
			var fitH = (W - gaps) / rowRatio;

			if (fitH <= H) {
				apply(row, fitH);
				lastFullH = fitH;
				row = [];
				rowRatio = 0;
			}
		}

		// Neúplný poslední řádek: žádné roztahování na šířku ani zvětšování.
		// Vezme výšku předchozího plného řádku (ať to vizuálně sedí),
		// jen ho zmenší, kdyby se náhodou nevešel.
		if (row.length) {
			var gapsL = gap * (row.length - 1);
			var h = lastFullH;
			var naturalW = rowRatio * h;
			if (naturalW > (W - gapsL)) {
				h = (W - gapsL) / rowRatio;
			}
			apply(row, h);
		}
	}

	function layoutAllVisible() {
		roots.forEach(function (root) {
			root.querySelectorAll('.swg-grid').forEach(function (grid) {
				if (grid.clientWidth) { layoutGrid(grid); }
			});
		});
	}

	var rafId = null;
	function scheduleLayout() {
		if (rafId) { cancelAnimationFrame(rafId); }
		rafId = requestAnimationFrame(function () {
			rafId = null;
			layoutAllVisible();
		});
	}

	/* ============================================================
	   Tabs & panels
	   ============================================================ */

	function initGallery(root) {
		if (root.dataset.swgInit) { return; }
		root.dataset.swgInit = '1';
		roots.push(root);

		root.querySelectorAll('.swg-tab').forEach(function (tab) {
			tab.addEventListener('click', function () {
				var target = tab.getAttribute('data-cat-target');
				root.querySelectorAll('.swg-tab').forEach(function (t) {
					t.classList.toggle('is-active', t === tab);
				});
				root.querySelectorAll('.swg-cat').forEach(function (c) {
					c.classList.toggle('is-active', c.getAttribute('data-cat') === target);
				});
				scheduleLayout();
			});
		});

		root.querySelectorAll('.swg-cat').forEach(function (cat) {
			cat.querySelectorAll('.swg-sub-card').forEach(function (card) {
				card.addEventListener('click', function () {
					var target = card.getAttribute('data-sub-target');
					cat.querySelectorAll('.swg-sub-card').forEach(function (c) {
						c.classList.toggle('is-active', c === card);
					});
					cat.querySelectorAll('.swg-panel').forEach(function (p) {
						p.classList.toggle('is-active', p.getAttribute('data-sub') === target);
					});
					scheduleLayout();
				});
			});
		});

		root.querySelectorAll('.swg-item').forEach(function (item) {
			item.addEventListener('click', function () {
				openFromItem(item);
			});
		});
	}

	/* ============================================================
	   Lightbox
	   ============================================================ */

	var lb, lbImg, lbCap, lbCounter;
	var group = [];
	var index = 0;
	var touchX = null;

	function ensureLightbox() {
		if (lb) { return lb; }
		lb = document.getElementById('swg-lightbox');
		if (!lb) { return null; }
		lbImg = lb.querySelector('.swg-lb-img');
		lbCap = lb.querySelector('.swg-lb-caption');
		lbCounter = lb.querySelector('.swg-lb-counter');

		lb.querySelector('.swg-lb-close').addEventListener('click', close);
		lb.querySelector('.swg-lb-prev').addEventListener('click', function (e) { e.stopPropagation(); step(-1); });
		lb.querySelector('.swg-lb-next').addEventListener('click', function (e) { e.stopPropagation(); step(1); });

		lb.addEventListener('click', function (e) {
			if (e.target === lb || e.target.classList.contains('swg-lb-stage')) {
				close();
			}
		});

		document.addEventListener('keydown', function (e) {
			if (!lb || lb.getAttribute('aria-hidden') === 'true') { return; }
			if (e.key === 'Escape') { close(); }
			else if (e.key === 'ArrowLeft') { step(-1); }
			else if (e.key === 'ArrowRight') { step(1); }
		});

		var stage = lb.querySelector('.swg-lb-stage');
		stage.addEventListener('touchstart', function (e) {
			touchX = e.changedTouches[0].clientX;
		}, { passive: true });
		stage.addEventListener('touchend', function (e) {
			if (touchX === null) { return; }
			var dx = e.changedTouches[0].clientX - touchX;
			if (Math.abs(dx) > 45) { step(dx < 0 ? 1 : -1); }
			touchX = null;
		}, { passive: true });

		return lb;
	}

	function openFromItem(item) {
		if (!ensureLightbox()) { return; }
		var panel = item.closest('.swg-panel');
		group = Array.prototype.slice.call(panel.querySelectorAll('.swg-item'));
		index = group.indexOf(item);
		show();
		lb.setAttribute('aria-hidden', 'false');
		document.documentElement.classList.add('swg-lb-open');
	}

	function show() {
		var item = group[index];
		if (!item) { return; }
		var full = item.getAttribute('data-full');
		var cap = item.getAttribute('data-caption') || '';

		lbImg.classList.remove('is-loaded');
		var pre = new Image();
		pre.onload = function () {
			lbImg.src = full;
			lbImg.classList.add('is-loaded');
		};
		pre.src = full;
		lbImg.src = full;
		if (pre.complete) { lbImg.classList.add('is-loaded'); }

		lbCap.textContent = cap;
		lbCounter.textContent = (index + 1) + ' / ' + group.length;

		var hasNav = group.length > 1;
		lb.querySelector('.swg-lb-prev').style.display = hasNav ? '' : 'none';
		lb.querySelector('.swg-lb-next').style.display = hasNav ? '' : 'none';
	}

	function step(dir) {
		if (!group.length) { return; }
		index = (index + dir + group.length) % group.length;
		show();
	}

	function close() {
		lb.setAttribute('aria-hidden', 'true');
		document.documentElement.classList.remove('swg-lb-open');
		lbImg.src = '';
	}

	/* ============================================================
	   Init
	   ============================================================ */

	function boot() {
		document.querySelectorAll('[data-swg]').forEach(initGallery);
		layoutAllVisible();
		window.addEventListener('load', scheduleLayout);
	}

	window.addEventListener('resize', scheduleLayout);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

})();
