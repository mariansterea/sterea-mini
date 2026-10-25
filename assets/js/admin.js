/* Sterea Mini-Ședințe – admin: calendar vizual de disponibilitate + rânduri repetabile (pachete). */
(function () {
	'use strict';

	/* ---------- Rânduri repetabile (pachete) ---------- */

	function renumber(table) {
		var group = table.getAttribute('data-stm-rows');
		[].forEach.call(table.querySelectorAll('tbody tr'), function (tr, i) {
			[].forEach.call(tr.querySelectorAll('[name]'), function (input) {
				input.name = input.name.replace(new RegExp('\\[' + group + '\\]\\[\\d+\\]'), '[' + group + '][' + i + ']');
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
			[].forEach.call(clone.querySelectorAll('input, textarea'), function (input) { if (input.type === 'checkbox') { input.checked = false; } else { input.value = ''; } });
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
				[].forEach.call(tr.querySelectorAll('input[type="text"], textarea'), function (i) { i.value = ''; });
			}
			renumber(t);
		}
	});

	/* ---------- Calendar de disponibilitate ---------- */

	var root = document.getElementById('stm-avail');
	if (!root) return;

	var MONTHS = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
	var WD = ['Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sâ', 'Du'];
	var days = JSON.parse(root.getAttribute('data-days') || '{}');
	var off = JSON.parse(root.getAttribute('data-off') || '[]');
	var taken = JSON.parse(root.getAttribute('data-taken') || '{}');
	var wrap = document.getElementById('stm-cal-wrap');
	var inputs = document.getElementById('stm-days-inputs');
	var summary = document.getElementById('stm-avail-summary');
	var today = new Date();
	var todayKey = key(today.getFullYear(), today.getMonth(), today.getDate());
	var editing = null; // data zilei cu popover de ore deschis

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
	function isOff(k) {
		return off.some(function (o) {
			if (o.yearly) { var md = k.slice(5); return md >= o.from.slice(5) && md <= o.to.slice(5); }
			return k >= o.from && k <= o.to;
		});
	}
	function defaults() {
		return { from: document.getElementById('stm-def-from').value || '12:00', to: document.getElementById('stm-def-to').value || '17:00' };
	}

	// Lunile afișate: de la luna curentă (sau prima zi setată, dacă e mai devreme) până la ultima zi setată + 1 lună.
	function monthRange() {
		var keys = Object.keys(days).sort();
		var start = new Date(today.getFullYear(), today.getMonth(), 1);
		if (keys.length && keys[0] < todayKey) { var p = keys[0].split('-'); start = new Date(+p[0], +p[1] - 1, 1); }
		var end = new Date(today.getFullYear(), today.getMonth() + 2, 1);
		if (keys.length) { var q = keys[keys.length - 1].split('-'); var e2 = new Date(+q[0], +q[1], 1); if (e2 > end) end = e2; }
		var out = [];
		for (var d = new Date(start); d < end; d.setMonth(d.getMonth() + 1)) out.push({ y: d.getFullYear(), m: d.getMonth() });
		return out;
	}

	function render() {
		wrap.innerHTML = '';
		monthRange().forEach(function (mo) {
			var box = document.createElement('div');
			box.className = 'stm-cal';
			var h = document.createElement('div');
			h.className = 'stm-cal-title';
			h.textContent = MONTHS[mo.m] + ' ' + mo.y;
			box.appendChild(h);
			var grid = document.createElement('div');
			grid.className = 'stm-cal-grid';
			WD.forEach(function (w) { var c = document.createElement('div'); c.className = 'stm-cal-wd'; c.textContent = w; grid.appendChild(c); });
			var first = new Date(mo.y, mo.m, 1), offset = (first.getDay() + 6) % 7, dim = new Date(mo.y, mo.m + 1, 0).getDate();
			for (var i = 0; i < offset; i++) { var e = document.createElement('div'); e.className = 'stm-cal-day is-other'; grid.appendChild(e); }
			for (var d = 1; d <= dim; d++) grid.appendChild(cell(key(mo.y, mo.m, d), d));
			box.appendChild(grid);
			wrap.appendChild(box);
		});
		writeInputs();
	}

	function cell(k, d) {
		var el = document.createElement('div');
		var on = !!days[k], past = k < todayKey, isoff = isOff(k), tk = taken[k] || 0;
		el.className = 'stm-cal-day' + (on ? ' is-on' : '') + (past ? ' is-past' : '') + (isoff ? ' is-off' : '') + (tk ? ' is-taken' : '') + (editing === k ? ' is-editing' : '');
		el.setAttribute('data-date', k);
		var num = document.createElement('span'); num.className = 'stm-cal-num'; num.textContent = d; el.appendChild(num);
		if (on) {
			var hrs = document.createElement('button');
			hrs.type = 'button'; hrs.className = 'stm-cal-hours'; hrs.textContent = days[k].from + '–' + days[k].to; hrs.title = 'Schimbă orele';
			hrs.addEventListener('click', function (ev) { ev.stopPropagation(); editing = editing === k ? null : k; render(); });
			el.appendChild(hrs);
		}
		if (tk) { var b = document.createElement('span'); b.className = 'stm-cal-badge'; b.textContent = tk + ' rez.'; b.title = tk + ' rezervări'; el.appendChild(b); }
		if (isoff && !on) { var o = document.createElement('span'); o.className = 'stm-cal-off'; o.textContent = 'liber'; el.appendChild(o); }
		if (!past) {
			el.addEventListener('click', function () {
				if (days[k]) {
					if (tk && !window.confirm('Ziua are ' + tk + ' rezervări. O scoți din program? Rezervările existente rămân în listă.')) return;
					delete days[k];
				} else {
					days[k] = defaults();
				}
				editing = null;
				render();
			});
		}
		if (editing === k) el.appendChild(popover(k));
		return el;
	}

	function popover(k) {
		var p = document.createElement('div');
		p.className = 'stm-cal-pop';
		p.addEventListener('click', function (ev) { ev.stopPropagation(); });
		var from = document.createElement('input'); from.type = 'time'; from.value = days[k].from;
		var to = document.createElement('input'); to.type = 'time'; to.value = days[k].to;
		var ok = document.createElement('button'); ok.type = 'button'; ok.className = 'button button-primary button-small'; ok.textContent = 'OK';
		ok.addEventListener('click', function () { if (from.value && to.value && from.value < to.value) { days[k] = { from: from.value, to: to.value }; } editing = null; render(); });
		var all = document.createElement('button'); all.type = 'button'; all.className = 'button button-small'; all.textContent = 'La toate zilele';
		all.title = 'Pune aceste ore la toate zilele viitoare din program';
		all.addEventListener('click', function () {
			if (!(from.value && to.value && from.value < to.value)) return;
			Object.keys(days).forEach(function (x) { if (x >= todayKey) days[x] = { from: from.value, to: to.value }; });
			editing = null; render();
		});
		p.appendChild(from); p.appendChild(document.createTextNode(' – ')); p.appendChild(to); p.appendChild(ok); p.appendChild(all);
		return p;
	}

	function writeInputs() {
		inputs.innerHTML = '';
		var keys = Object.keys(days).sort();
		keys.forEach(function (k, i) {
			['date', 'from', 'to'].forEach(function (f) {
				var inp = document.createElement('input');
				inp.type = 'hidden';
				inp.name = 'stm[days][' + i + '][' + f + ']';
				inp.value = f === 'date' ? k : days[k][f];
				inputs.appendChild(inp);
			});
		});
		var future = keys.filter(function (k) { return k >= todayKey; }).length;
		summary.textContent = keys.length ? keys.length + ' zile în program, ' + future + ' viitoare.' : 'Nicio zi în program. Click pe zile în calendar sau folosește „Adaugă interval de zile”.';
	}

	document.getElementById('stm-rg-add').addEventListener('click', function () {
		var from = document.getElementById('stm-rg-from').value, to = document.getElementById('stm-rg-to').value;
		var start = document.getElementById('stm-rg-start').value, end = document.getElementById('stm-rg-end').value;
		var wds = [].map.call(document.querySelectorAll('.stm-rg-wd:checked'), function (c) { return +c.value; });
		if (!from || !to || from > to) { window.alert('Alege intervalul de zile (de la – până la).'); return; }
		if (!start || !end || start >= end) { window.alert('Orele nu sunt valide.'); return; }
		if (!wds.length) { window.alert('Bifează cel puțin o zi din săptămână.'); return; }
		var n = 0;
		for (var d = new Date(from + 'T12:00:00'), e = new Date(to + 'T12:00:00'); d <= e; d.setDate(d.getDate() + 1)) {
			var k = key(d.getFullYear(), d.getMonth(), d.getDate());
			if (wds.indexOf(d.getDay()) === -1 || k < todayKey || isOff(k)) continue;
			days[k] = { from: start, to: end };
			n++;
		}
		render();
		summary.textContent += ' Adăugate: ' + n + '.';
	});

	document.addEventListener('click', function () { if (editing) { editing = null; render(); } });
	render();
})();
