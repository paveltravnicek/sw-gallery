/* global SWG, jQuery, wp */
(function ($) {
	'use strict';

	if (typeof SWG === 'undefined') {
		return;
	}

	var data = SWG.data && SWG.data.categories ? SWG.data : { categories: [] };
	var thumbs = SWG.thumbs || {};
	var i18n = SWG.i18n || {};
	var readonly = !!SWG.readonly;

	var $editor = $('#swg-editor');
	var $json = $('#swg-data-json');

	function uid(prefix) {
		return prefix + '_' + Math.random().toString(36).slice(2, 10);
	}

	function countLabel(n) {
		if (n === 1) { return n + ' ' + (i18n.photo || 'fotka'); }
		if (n >= 2 && n <= 4) { return n + ' ' + (i18n.photos2 || 'fotky'); }
		return n + ' ' + (i18n.photos5 || 'fotek');
	}

	function thumbUrl(id) {
		return thumbs[id] || '';
	}

	/* ---------- Slug / shortcode náhled ---------- */

	var SLUG_MAP = {
		á: 'a', ä: 'a', č: 'c', ď: 'd', é: 'e', ě: 'e', í: 'i', ľ: 'l', ň: 'n',
		ó: 'o', ô: 'o', ř: 'r', š: 's', ť: 't', ú: 'u', ů: 'u', ü: 'u', ý: 'y', ž: 'z',
		Á: 'a', Ä: 'a', Č: 'c', Ď: 'd', É: 'e', Ě: 'e', Í: 'i', Ľ: 'l', Ň: 'n',
		Ó: 'o', Ô: 'o', Ř: 'r', Š: 's', Ť: 't', Ú: 'u', Ů: 'u', Ü: 'u', Ý: 'y', Ž: 'z'
	};

	function slugify(text) {
		var s = String(text || '').replace(/[áäčďéěíľňóôřšťúůüýžÁÄČĎÉĚÍĽŇÓÔŘŠŤÚŮÜÝŽ]/g, function (ch) {
			return SLUG_MAP[ch] || ch;
		});
		s = s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
		return s || 'kategorie';
	}

	function catShortcode(title) {
		return '[sw_gallery category="' + slugify(title) + '"]';
	}

	/* ---------- Kopírování do schránky ---------- */

	function copyText(text, $btn) {
		function done(ok) {
			var original = $btn.attr('title');
			$btn.addClass(ok ? 'is-copied' : 'is-copy-error');
			$btn.attr('title', ok ? 'Zkopírováno!' : 'Nepodařilo se zkopírovat');
			setTimeout(function () {
				$btn.removeClass('is-copied is-copy-error');
				$btn.attr('title', original);
			}, 1500);
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
			return;
		}
		try {
			var $tmp = $('<textarea readonly></textarea>').val(text).css({ position: 'fixed', top: '-1000px', left: '-1000px' });
			$('body').append($tmp);
			$tmp[0].select();
			document.execCommand('copy');
			$tmp.remove();
			done(true);
		} catch (e) {
			done(false);
		}
	}

	/* ---------- Render ---------- */

	function render() {
		$editor.empty();

		if (!data.categories.length) {
			$editor.append(
				'<div class="swg-blank">Zatím žádná kategorie. Přidej první nahoře.</div>'
			);
			sync();
			return;
		}

		data.categories.forEach(function (cat) {
			$editor.append(renderCategory(cat));
		});

		if (!readonly) {
			bindSortables();
		} else {
			lockEditor();
		}
		sync();
	}

	function lockEditor() {
		// Tlačítko kopírování shortcode je jen ke čtení, i v read-only režimu má zůstat funkční.
		$editor.find('input, textarea, button').not('.swg-cat-sc-copy').prop('disabled', true);
	}

	function renderCategory(cat) {
		var $cat = $(
			'<div class="swg-cat-card" data-id="' + cat.id + '">' +
				'<div class="swg-cat-head">' +
					'<span class="swg-drag dashicons dashicons-menu" title="Přetáhni pro řazení"></span>' +
					'<input type="text" class="swg-cat-title" value="" />' +
					'<div class="swg-cat-actions">' +
						'<button type="button" class="button swg-add-sub">+ Subkategorie</button>' +
						'<button type="button" class="button swg-icon-btn swg-del-cat" title="Smazat kategorii"><span class="dashicons dashicons-trash"></span></button>' +
					'</div>' +
				'</div>' +
				'<div class="swg-cat-sc-row">' +
					'<span class="swg-cat-sc-label">Shortcode:</span>' +
					'<code class="swg-cat-sc-code"></code>' +
					'<button type="button" class="button swg-icon-btn swg-cat-sc-copy" title="Kopírovat shortcode"><span class="dashicons dashicons-admin-page"></span></button>' +
				'</div>' +
				'<div class="swg-subs-list"></div>' +
			'</div>'
		);

		$cat.find('.swg-cat-title').val(cat.title);
		$cat.find('.swg-cat-sc-code').text(catShortcode(cat.title));

		var $list = $cat.find('.swg-subs-list');
		(cat.subcategories || []).forEach(function (sub) {
			$list.append(renderSub(sub));
		});

		return $cat;
	}

	function renderSub(sub) {
		var $sub = $(
			'<div class="swg-sub-card" data-id="' + sub.id + '">' +
				'<div class="swg-sub-head">' +
					'<span class="swg-drag dashicons dashicons-menu" title="Přetáhni pro řazení"></span>' +
					'<input type="text" class="swg-sub-title" value="" />' +
					'<span class="swg-sub-count"></span>' +
					'<div class="swg-sub-actions">' +
						'<button type="button" class="button button-primary swg-add-photos">' + (i18n.selectPhotos || 'Vybrat fotky') + '</button>' +
						'<button type="button" class="button swg-icon-btn swg-del-sub" title="Smazat subkategorii"><span class="dashicons dashicons-trash"></span></button>' +
					'</div>' +
				'</div>' +
				'<div class="swg-photos"></div>' +
			'</div>'
		);

		$sub.find('.swg-sub-title').val(sub.title);

		var $photos = $sub.find('.swg-photos');
		renderPhotos($photos, sub.photos || []);
		updateSubCount($sub, (sub.photos || []).length);

		return $sub;
	}

	function renderPhotos($photos, ids) {
		$photos.empty();
		if (!ids.length) {
			$photos.append('<div class="swg-photos-empty">' + (i18n.emptyPhotos || 'Zatím žádné fotky.') + '</div>');
			return;
		}
		ids.forEach(function (id) {
			$photos.append(
				'<div class="swg-photo" data-id="' + id + '">' +
					'<img src="' + thumbUrl(id) + '" alt="" />' +
					'<button type="button" class="swg-photo-del" title="Odebrat">&times;</button>' +
				'</div>'
			);
		});
	}

	function updateSubCount($sub, n) {
		$sub.find('.swg-sub-count').text(countLabel(n));
	}

	/* ---------- Read DOM -> data ---------- */

	function readData() {
		var cats = [];
		$editor.children('.swg-cat-card').each(function () {
			var $cat = $(this);
			var subs = [];
			$cat.find('.swg-subs-list > .swg-sub-card').each(function () {
				var $sub = $(this);
				var photos = [];
				$sub.find('.swg-photos > .swg-photo').each(function () {
					photos.push(parseInt($(this).data('id'), 10));
				});
				subs.push({
					id: $sub.data('id') || uid('sub'),
					title: $sub.find('.swg-sub-title').val(),
					photos: photos
				});
			});
			cats.push({
				id: $cat.data('id') || uid('cat'),
				title: $cat.find('.swg-cat-title').val(),
				subcategories: subs
			});
		});
		return { categories: cats };
	}

	function sync() {
		data = readData();
		$json.val(JSON.stringify(data));
	}

	/* ---------- Sortable ---------- */

	function bindSortables() {
		$editor.sortable({
			items: '> .swg-cat-card',
			handle: '> .swg-cat-head > .swg-drag',
			placeholder: 'swg-sort-ph',
			forcePlaceholderSize: true,
			update: sync
		});

		$editor.find('.swg-subs-list').sortable({
			items: '> .swg-sub-card',
			handle: '> .swg-sub-head > .swg-drag',
			connectWith: '.swg-subs-list',
			placeholder: 'swg-sort-ph',
			forcePlaceholderSize: true,
			update: sync
		});

		$editor.find('.swg-photos').sortable({
			items: '> .swg-photo',
			placeholder: 'swg-photo-ph',
			forcePlaceholderSize: true,
			update: sync
		});
	}

	/* ---------- Events ---------- */

	// Add category (top toolbar).
	$('#swg-add-cat').on('click', function () {
		var title = ($('#swg-new-cat').val() || '').trim();
		if (!title) {
			$('#swg-new-cat').focus();
			return;
		}
		data = readData();
		data.categories.push({ id: uid('cat'), title: title, subcategories: [] });
		$('#swg-new-cat').val('');
		render();
	});

	$('#swg-new-cat').on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			$('#swg-add-cat').trigger('click');
		}
	});

	// Add subcategory.
	$editor.on('click', '.swg-add-sub', function () {
		if (readonly) { return; }
		var $cat = $(this).closest('.swg-cat-card');
		var title = window.prompt(i18n.newSubcategory || 'Název subkategorie', '');
		if (title === null) { return; }
		title = title.trim();
		if (!title) { return; }
		sync();
		var $sub = renderSub({ id: uid('sub'), title: title, photos: [] });
		$cat.find('.swg-subs-list').append($sub);
		bindSortables();
		sync();
	});

	// Delete category.
	$editor.on('click', '.swg-del-cat', function () {
		if (readonly) { return; }
		if (!window.confirm(i18n.confirmCat || 'Smazat kategorii?')) { return; }
		$(this).closest('.swg-cat-card').remove();
		sync();
		if (!$editor.children('.swg-cat-card').length) { render(); }
	});

	// Delete subcategory.
	$editor.on('click', '.swg-del-sub', function () {
		if (readonly) { return; }
		if (!window.confirm(i18n.confirmSub || 'Smazat subkategorii?')) { return; }
		$(this).closest('.swg-sub-card').remove();
		sync();
	});

	// Delete single photo.
	$editor.on('click', '.swg-photo-del', function () {
		if (readonly) { return; }
		var $sub = $(this).closest('.swg-sub-card');
		$(this).closest('.swg-photo').remove();
		var $photos = $sub.find('.swg-photos');
		var n = $photos.children('.swg-photo').length;
		if (!n) { renderPhotos($photos, []); }
		updateSubCount($sub, n);
		sync();
	});

	// Title edits.
	$editor.on('input', '.swg-cat-title, .swg-sub-title', sync);

	// Live náhled shortcode při psaní názvu kategorie.
	$editor.on('input', '.swg-cat-title', function () {
		var $card = $(this).closest('.swg-cat-card');
		$card.find('.swg-cat-sc-code').text(catShortcode($(this).val()));
	});

	// Kopírování shortcode kategorie.
	$editor.on('click', '.swg-cat-sc-copy', function () {
		var text = $(this).closest('.swg-cat-card').find('.swg-cat-sc-code').text();
		copyText(text, $(this));
	});

	// Media picker.
	var frame = null;
	var $activeSub = null;

	$editor.on('click', '.swg-add-photos', function () {
		if (readonly) { return; }
		$activeSub = $(this).closest('.swg-sub-card');

		frame = wp.media({
			title: i18n.selectPhotos || 'Vybrat fotky',
			button: { text: i18n.addToGallery || 'Přidat do galerie' },
			library: { type: 'image' },
			multiple: 'add'
		});

		frame.on('select', function () {
			var selection = frame.state().get('selection');
			var $photos = $activeSub.find('.swg-photos');

			// existing ids
			var existing = [];
			$photos.children('.swg-photo').each(function () {
				existing.push(parseInt($(this).data('id'), 10));
			});

			// remove empty placeholder
			$photos.find('.swg-photos-empty').remove();

			selection.each(function (att) {
				var a = att.toJSON();
				if (existing.indexOf(a.id) !== -1) { return; }
				existing.push(a.id);

				var t = '';
				if (a.sizes && a.sizes.thumbnail) {
					t = a.sizes.thumbnail.url;
				} else {
					t = a.url;
				}
				thumbs[a.id] = t;

				$photos.append(
					'<div class="swg-photo" data-id="' + a.id + '">' +
						'<img src="' + t + '" alt="" />' +
						'<button type="button" class="swg-photo-del" title="Odebrat">&times;</button>' +
					'</div>'
				);
			});

			updateSubCount($activeSub, $photos.children('.swg-photo').length);
			bindSortables();
			sync();
		});

		frame.open();
	});

	// Make sure latest DOM state is serialized right before submit.
	$('#swg-form').on('submit', sync);

	/* ---------- Init ---------- */
	render();

	/* ---------- Barevnost galerie ----------
	   Odvození barev musí odpovídat PHP třídě SWG_Color. Když se mění vzoreček
	   tam, musí se změnit i tady – jinak se živý náhled rozejde s webem. */
	(function () {

		var $card = $('#swg-color-card');
		var $enabled = $('#swg-color-enabled');
		if (!$card.length || !$enabled.length) { return; }

		var SLOTS = ['tab_on', 'tab_off', 'bg', 'bd', 'fg', 'bg_on', 'bd_on', 'fg_on'];
		var VARMAP = {
			tab_on: '--swg-tab-line',
			tab_off: '--swg-tab-line-idle',
			bg: '--swg-btn-bg',
			bd: '--swg-btn-bd',
			fg: '--swg-btn-fg',
			bg_on: '--swg-btn-bg-on',
			bd_on: '--swg-btn-bd-on',
			fg_on: '--swg-btn-fg-on'
		};
		var FALLBACK = '#5f7585';

		/* ----- barevná matematika ----- */

		function hex(v) {
			v = String(v || '').trim().toLowerCase();
			if (!v) { return ''; }
			if (v.charAt(0) !== '#') { v = '#' + v; }
			if (/^#[0-9a-f]{3}$/.test(v)) {
				return '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
			}
			return /^#[0-9a-f]{6}$/.test(v) ? v : '';
		}

		function rgb(h) {
			h = hex(h);
			if (!h) { return null; }
			return [parseInt(h.substr(1, 2), 16), parseInt(h.substr(3, 2), 16), parseInt(h.substr(5, 2), 16)];
		}

		function rgba(h, a) {
			var c = rgb(h);
			return c ? 'rgba(' + c.join(', ') + ', ' + a + ')' : 'transparent';
		}

		function mix(a, b, w) {
			var ca = rgb(a), cb = rgb(b);
			if (!ca || !cb) { return hex(a); }
			var out = '#', i, v;
			for (i = 0; i < 3; i++) {
				v = Math.round(ca[i] * w + cb[i] * (1 - w));
				out += ('0' + v.toString(16)).slice(-2);
			}
			return out;
		}

		function lum(h) {
			var c = rgb(h);
			if (!c) { return 1; }
			var ch = c.map(function (x) {
				x = x / 255;
				return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
			});
			return 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2];
		}

		function ratio(l1, l2) {
			var hi = Math.max(l1, l2), lo = Math.min(l1, l2);
			return (hi + 0.05) / (lo + 0.05);
		}

		function contrast(h) {
			var bg = lum(h);
			return ratio(bg, lum('#1e2327')) >= ratio(bg, lum('#ffffff')) ? '#1e2327' : '#ffffff';
		}

		/* ----- odvození sady proměnných (zrcadlo SWG_Color::vars) ----- */

		function derive(state) {
			var p = hex(state.primary) || FALLBACK;
			var s = hex(state.secondary) || p;
			var v = {};

			v['--swg-gold'] = p;
			v['--swg-gold-soft'] = rgba(p, 0.5);
			v['--swg-gold-tint'] = rgba(p, 0.1);
			v['--swg-gold-tint-soft'] = rgba(s, 0.05);
			v['--swg-gold-muted'] = rgba(s, 0.62);

			v['--swg-tab-line'] = p;
			v['--swg-tab-line-idle'] = 'transparent';

			if (state.style === 'outline') {
				v['--swg-btn-bg'] = 'transparent';
				v['--swg-btn-bd'] = rgba(s, 0.35);
				v['--swg-btn-fg'] = rgba(s, 0.72);
				v['--swg-btn-sub'] = 'var(--swg-muted)';
				v['--swg-btn-bg-hover'] = rgba(s, 0.05);
				v['--swg-btn-bd-hover'] = rgba(p, 0.6);
				v['--swg-btn-bg-on'] = rgba(p, 0.08);
				v['--swg-btn-bd-on'] = p;
				v['--swg-btn-fg-on'] = p;
				v['--swg-btn-sub-on'] = 'var(--swg-muted)';
			} else if (state.style === 'solid') {
				var ink = contrast(p);
				v['--swg-btn-bg'] = rgba(s, 0.08);
				v['--swg-btn-bd'] = rgba(s, 0.28);
				v['--swg-btn-fg'] = mix(s, '#1e2327', 0.55);
				v['--swg-btn-sub'] = 'var(--swg-muted)';
				v['--swg-btn-bg-hover'] = rgba(s, 0.16);
				v['--swg-btn-bd-hover'] = rgba(p, 0.5);
				v['--swg-btn-bg-on'] = p;
				v['--swg-btn-bd-on'] = p;
				v['--swg-btn-fg-on'] = ink;
				v['--swg-btn-sub-on'] = rgba(ink, 0.78);
			} else {
				v['--swg-btn-bg'] = rgba(s, 0.05);
				v['--swg-btn-bd'] = 'var(--swg-line)';
				v['--swg-btn-fg'] = rgba(s, 0.62);
				v['--swg-btn-sub'] = 'var(--swg-muted)';
				v['--swg-btn-bg-hover'] = rgba(s, 0.05);
				v['--swg-btn-bd-hover'] = rgba(p, 0.5);
				v['--swg-btn-bg-on'] = rgba(p, 0.1);
				v['--swg-btn-bd-on'] = p;
				v['--swg-btn-fg-on'] = p;
				v['--swg-btn-sub-on'] = 'var(--swg-muted)';
			}

			// Automatika si pamatujeme zvlášť, ať víme, co vzorník ukazuje u prázdných polí.
			var auto = $.extend({}, v);

			SLOTS.forEach(function (slot) {
				var val = hex(state.ovr[slot]);
				if (val) { v[VARMAP[slot]] = val; }
			});

			if (hex(state.ovr.bg_on) && !hex(state.ovr.fg_on) && state.style === 'solid') {
				var ink2 = contrast(hex(state.ovr.bg_on));
				v['--swg-btn-fg-on'] = ink2;
				v['--swg-btn-sub-on'] = rgba(ink2, 0.78);
			}
			if (hex(state.ovr.bg)) {
				v['--swg-btn-bg-hover'] = v['--swg-btn-bg'];
			}

			return { vars: v, auto: auto };
		}

		/* ----- čtení stavu z formuláře ----- */

		function fieldValue(slot) {
			return $card.find('.swg-cf[data-slot="' + slot + '"] .swg-cf-value').val() || '';
		}

		function readState() {
			var st = { primary: fieldValue('primary'), secondary: fieldValue('secondary'), style: 'soft', ovr: {} };
			var $checked = $card.find('input[name="swg_btn_style"]:checked');
			if ($checked.length) { st.style = $checked.val(); }
			SLOTS.forEach(function (slot) { st.ovr[slot] = fieldValue(slot); });
			return st;
		}

		/* ----- vykreslení ----- */

		var $previewRoot = $('#swg-preview').find('.swg');

		function apply() {
			var st = readState();
			var d = derive(st);
			var el = $previewRoot.get(0);

			if (el) {
				Object.keys(d.vars).forEach(function (name) {
					el.style.setProperty(name, d.vars[name]);
				});
			}

			// Vzorník u prázdných polí ukazuje, co vrací automatika.
			$card.find('.swg-cf').each(function () {
				var $cf = $(this);
				var slot = $cf.data('slot');
				var val = $cf.find('.swg-cf-value').val() || '';
				var shown = val;

				if (!shown) {
					if (slot === 'secondary') {
						shown = hex(st.primary) || FALLBACK;
					} else if (VARMAP[slot]) {
						shown = hex(d.auto[VARMAP[slot]]);
						if (!shown) {
							// průhledné / var() hodnoty vzorník zobrazit neumí
							shown = (slot === 'tab_off' || slot === 'bg') ? '#ffffff' : (hex(st.secondary) || hex(st.primary) || FALLBACK);
						}
					}
				}
				$cf.toggleClass('is-auto', !val);
				$cf.find('.swg-cf-swatch').val(shown || FALLBACK);
			});

			// Ukázky u výběru stylu.
			$card.find('.swg-style-opt').each(function () {
				var $opt = $(this);
				var key = $opt.find('input').val();
				var dd = derive({ primary: st.primary, secondary: st.secondary, style: key, ovr: {} }).vars;
				var $off = $opt.find('.swg-style-chip--off');
				var $on = $opt.find('.swg-style-chip--on');
				$off.css({ background: dd['--swg-btn-bg'] === 'transparent' ? 'transparent' : dd['--swg-btn-bg'], borderColor: dd['--swg-btn-bd'].indexOf('var(') === 0 ? '#dcdfe3' : dd['--swg-btn-bd'] });
				$on.css({ background: dd['--swg-btn-bg-on'], borderColor: dd['--swg-btn-bd-on'] });
				$opt.toggleClass('is-on', $opt.find('input').is(':checked'));
			});
		}

		/* ----- zapnuto / vypnuto ----- */

		function refreshEnabled() {
			var on = !readonly && $enabled.is(':checked');
			$card.toggleClass('is-off', !on);
			$card.find('.swg-cf-swatch, .swg-cf-hex, .swg-cf-reset, input[name="swg_btn_style"], #swg-color-swap, #swg-color-reset')
				.prop('disabled', !on);
			apply();
		}

		/* ----- události ----- */

		// Vzorník -> hodnota
		$card.on('input change', '.swg-cf-swatch', function () {
			var $cf = $(this).closest('.swg-cf');
			var v = hex($(this).val());
			$cf.find('.swg-cf-value').val(v);
			$cf.find('.swg-cf-hex').val(v.toUpperCase());
			apply();
		});

		// Ruční HEX -> hodnota (prázdné pole = zpět na automatiku)
		$card.on('input', '.swg-cf-hex', function () {
			var $cf = $(this).closest('.swg-cf');
			var raw = String($(this).val() || '').trim();
			var v = hex(raw);
			if (!raw && $cf.hasClass('swg-cf--auto-capable')) {
				$cf.find('.swg-cf-value').val('');
				apply();
				return;
			}
			if (v) {
				$cf.find('.swg-cf-value').val(v);
				apply();
			}
		});

		// Odchod z pole srovná zápis do platné podoby.
		$card.on('blur', '.swg-cf-hex', function () {
			var $cf = $(this).closest('.swg-cf');
			var v = $cf.find('.swg-cf-value').val() || '';
			$(this).val(v ? v.toUpperCase() : '');
			apply();
		});

		// Zpět na automatiku
		$card.on('click', '.swg-cf-reset', function () {
			var $cf = $(this).closest('.swg-cf');
			$cf.find('.swg-cf-value').val('');
			$cf.find('.swg-cf-hex').val('');
			apply();
		});

		$card.on('change', 'input[name="swg_btn_style"]', apply);

		// Prohození hlavní a vedlejší barvy – rychlá inverze bez ručního přepisování.
		$('#swg-color-swap').on('click', function () {
			var st = readState();
			var p = hex(st.primary) || FALLBACK;
			var s = hex(st.secondary) || p;
			setField('primary', s);
			setField('secondary', s === p ? '' : p);
			apply();
		});

		$('#swg-color-reset').on('click', function () {
			SLOTS.forEach(function (slot) { setField(slot, ''); });
			apply();
		});

		function setField(slot, value) {
			var $cf = $card.find('.swg-cf[data-slot="' + slot + '"]');
			$cf.find('.swg-cf-value').val(value);
			$cf.find('.swg-cf-hex').val(value ? value.toUpperCase() : '');
		}

		$enabled.on('change', refreshEnabled);

		// Přepínání náhledu klikem – ať je vidět i neaktivní stav.
		$('#swg-preview').on('click', '.swg-tab, .swg-sub-card', function () {
			var $b = $(this);
			$b.siblings().removeClass('is-active');
			$b.addClass('is-active');
			return false;
		});

		refreshEnabled();
	})();

})(jQuery);
