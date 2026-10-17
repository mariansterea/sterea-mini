/* Sterea Mini-Ședințe – admin repeatable rows */
(function () {
	'use strict';

	function renumber(table) {
		var group = table.getAttribute('data-stm-rows');
		[].forEach.call(table.querySelectorAll('tbody tr'), function (tr, i) {
			[].forEach.call(tr.querySelectorAll('[name]'), function (input) {
				input.name = input.name.replace(new RegExp('stm\\[' + group + '\\]\\[\\d+\\]'), 'stm[' + group + '][' + i + ']');
			});
		});
	}

	document.addEventListener('click', function (e) {
		var add = e.target.closest('.stm-add');
		if (add) {
			var table = document.querySelector('[data-stm-rows="' + add.getAttribute('data-stm-target') + '"]');
			var rows = table.querySelectorAll('tbody tr');
			var last = rows[rows.length - 1];
			var clone = last.cloneNode(true);
			[].forEach.call(clone.querySelectorAll('input, textarea'), function (input) {
				// New day: suggest the day after the last one, same hours.
				if (input.type === 'date' && input.value) {
					var d = new Date(input.value + 'T12:00:00');
					d.setDate(d.getDate() + 1);
					input.value = d.toISOString().slice(0, 10);
				} else if (input.type !== 'time') {
					input.value = '';
				}
			});
			last.parentNode.appendChild(clone);
			renumber(table);
			return;
		}

		var remove = e.target.closest('.stm-remove');
		if (remove) {
			var t = remove.closest('table');
			var tr = remove.closest('tr');
			if (t.querySelectorAll('tbody tr').length > 1) {
				tr.parentNode.removeChild(tr);
			} else {
				[].forEach.call(tr.querySelectorAll('input[type="text"], input[type="date"], textarea'), function (i) { i.value = ''; });
			}
			renumber(t);
		}
	});
})();
