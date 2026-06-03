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
		$editor.find('input, textarea, button').prop('disabled', true);
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
				'<div class="swg-subs-list"></div>' +
			'</div>'
		);

		$cat.find('.swg-cat-title').val(cat.title);

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

})(jQuery);
