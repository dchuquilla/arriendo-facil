/**
 * Arriendo Fácil — accommodation list (edit.php?post_type=accommodation).
 *
 * On narrow screens the table is re-laid out as a stack of cards and each cell
 * shows its own column name, read from a data attribute because the header row
 * is hidden. WordPress does not write that attribute, so the labels are
 * mirrored here from the real header cells: reading them back guarantees the
 * card layout can never drift from the translations or from the column order.
 *
 * @package Arriendo_Facil
 */
(function () {
	'use strict';

	function columnKey(el) {
		var match = (' ' + el.className + ' ').match(/ (column-[a-z0-9_-]+) /);
		return match ? match[1] : '';
	}

	function labelFor(head) {
		// Sortable headers wrap the name in a link, a sorting indicator and
		// screen-reader-only text ("Ordenar ascendente"). Only the visible
		// name belongs in the card label.
		var clone = head.cloneNode(true);
		var noise = clone.querySelectorAll('.screen-reader-text, .sorting-indicator');

		Array.prototype.forEach.call(noise, function (el) {
			if (el.parentNode) {
				el.parentNode.removeChild(el);
			}
		});

		return (clone.textContent || '').replace(/\s+/g, ' ').trim();
	}

	function mirrorLabels() {
		var table = document.querySelector('.af-native-inmuebles .wp-list-table');

		if (!table) {
			return;
		}

		var labels = {};
		var heads = table.querySelectorAll('thead tr:first-child > th');

		Array.prototype.forEach.call(heads, function (head) {
			var key = columnKey(head);
			if (key) {
				labels[key] = labelFor(head);
			}
		});

		if (!Object.keys(labels).length) {
			return;
		}

		Array.prototype.forEach.call(table.querySelectorAll('tbody td'), function (cell) {
			var key = columnKey(cell);

			if (key && labels[key] && !cell.getAttribute('data-colname')) {
				cell.setAttribute('data-colname', labels[key]);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mirrorLabels);
	} else {
		mirrorLabels();
	}
}());
