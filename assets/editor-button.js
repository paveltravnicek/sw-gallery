/* global SWG_EDITOR, jQuery, tinymce, QTags */
(function ($) {
	'use strict';

	if (typeof SWG_EDITOR === 'undefined') {
		return;
	}

	var categories = SWG_EDITOR.categories || [];
	var i18n = SWG_EDITOR.i18n || {};

	function insertShortcode(shortcode) {
		var inserted = false;

		if (window.tinymce) {
			var editor = tinymce.get('content');
			if (editor && !editor.isHidden()) {
				editor.execCommand('mceInsertContent', false, shortcode);
				inserted = true;
			}
		}

		if (!inserted && window.QTags && document.getElementById('content')) {
			QTags.insertContent(shortcode);
			inserted = true;
		}

		if (!inserted) {
			var $ta = $('#content');
			if ($ta.length) {
				var val = $ta.val();
				var pos = $ta[0].selectionStart;
				if (typeof pos !== 'number') {
					pos = val.length;
				}
				$ta.val(val.slice(0, pos) + shortcode + val.slice(pos));
			}
		}

		closeModal();
	}

	function closeModal() {
		$('#swg-modal-overlay').remove();
		$(document).off('keydown.swgModal');
	}

	function openModal() {
		var $overlay = $('<div class="swg-modal-overlay" id="swg-modal-overlay"></div>');
		var $modal = $('<div class="swg-modal" role="dialog" aria-modal="true"></div>');

		$modal.append($('<h2></h2>').text(i18n.modalTitle || 'Vložit fotogalerii'));

		var $list = $('<ul class="swg-modal-list"></ul>');

		$list.append(
			$('<li class="swg-modal-item swg-modal-item--all"></li>')
				.text(i18n.allGalleries || 'Celá fotogalerie')
				.on('click', function () {
					insertShortcode('[sw_gallery]');
				})
		);

		categories.forEach(function (cat) {
			$list.append(
				$('<li class="swg-modal-item"></li>')
					.text(cat.title)
					.on('click', function () {
						insertShortcode('[sw_gallery category="' + cat.slug + '"]');
					})
			);
		});

		$modal.append($list);

		$modal.append(
			$('<button type="button" class="button swg-modal-cancel"></button>')
				.text(i18n.cancel || 'Zrušit')
				.on('click', closeModal)
		);

		$overlay.on('click', function (e) {
			if (e.target === this) {
				closeModal();
			}
		});

		$overlay.append($modal);
		$('body').append($overlay);

		$(document).on('keydown.swgModal', function (e) {
			if (e.key === 'Escape') {
				closeModal();
			}
		});
	}

	$(document).on('click', '#swg-insert-gallery-btn', function (e) {
		e.preventDefault();
		openModal();
	});

})(jQuery);
