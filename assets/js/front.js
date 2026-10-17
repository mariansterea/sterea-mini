/* Sterea Mini-Ședințe – booking app */
(function () {
	'use strict';

	function h(tag, attrs, children) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'class') el.className = v;
			else if (k === 'text') el.textContent = v;
			else if (k === 'html') el.innerHTML = v; // Only server-filtered HTML (wp_kses_post).
			else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), v);
			else el.setAttribute(k, v === true ? '' : v);
		});
		[].concat(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) return;
			el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return el;
	}

	function priceLabel(p) {
		p = String(p || '').trim();
		if (!p) return '';
		return /^\d+([.,]\d+)?$/.test(p) ? p + ' lei' : p;
	}

	function api(app, path, opts) {
		var base = app.getAttribute('data-api');
		return fetch(base + path, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, opts || {}))
			.then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (data) {
					if (!r.ok) {
						var err = new Error((data && data.message) || 'A apărut o eroare. Încearcă din nou.');
						err.code = data && data.code;
						throw err;
					}
					return data;
				});
			});
	}

	function Theme(app, theme) {
		var state = { pkg: theme.packages.length === 1 ? theme.packages[0].id : null, day: null, time: null, form: {} };
		var root = h('section', { class: 'stm-theme' });

		function freeCount(day) {
			return day.slots.filter(function (s) { return s.state === 'free'; }).length;
		}

		function render() {
			root.innerHTML = '';

			// Header.
			var head = h('div', { class: 'stm-head' + (theme.image ? ' stm-head--img' : '') }, [
				theme.image ? h('img', { class: 'stm-cover', src: theme.image, alt: '', loading: 'lazy' }) : null,
				h('div', { class: 'stm-head-text' }, [
					h('h3', { class: 'stm-title', text: theme.title }),
					theme.location ? h('p', { class: 'stm-location' }, [
						'📍 ' + theme.location + (theme.address ? ', ' + theme.address : ''),
						theme.map_url ? h('a', { href: theme.map_url, target: '_blank', rel: 'noopener', text: ' Hartă' }) : null
					]) : null,
					theme.content ? h('div', { class: 'stm-desc', html: theme.content }) : null
				])
			]);
			root.appendChild(head);

			var bookable = theme.days.some(function (d) { return freeCount(d) > 0; });
			if (!theme.open || !theme.days.length || !bookable) {
				root.appendChild(h('p', { class: 'stm-note', text: !theme.days.length ? 'Nu mai sunt date disponibile pentru acest eveniment.' : 'Toate locurile sunt rezervate sau rezervările s-au închis. Scrie-ne și te anunțăm dacă se eliberează un loc.' }));
				return;
			}

			// 1. Package.
			if (theme.packages.length) {
				var pk = h('div', { class: 'stm-step' }, [h('h4', { text: '1. Alege pachetul' })]);
				var grid = h('div', { class: 'stm-packages', role: 'radiogroup' });
				theme.packages.forEach(function (p) {
					var on = state.pkg === p.id;
					grid.appendChild(h('button', {
						type: 'button',
						class: 'stm-package' + (on ? ' is-on' : ''),
						role: 'radio',
						'aria-checked': on ? 'true' : 'false',
						onclick: function () { state.pkg = p.id; render(); }
					}, [
						h('span', { class: 'stm-package-name', text: p.name }),
						p.price ? h('span', { class: 'stm-package-price', text: priceLabel(p.price) }) : null,
						p.desc ? h('span', { class: 'stm-package-desc', text: p.desc }) : null
					]));
				});
				pk.appendChild(grid);
				root.appendChild(pk);
			}

			// 2. Day.
			var dayStep = h('div', { class: 'stm-step' }, [h('h4', { text: '2. Alege ziua' })]);
			var days = h('div', { class: 'stm-days' });
			theme.days.forEach(function (d) {
				var n = freeCount(d);
				days.appendChild(h('button', {
					type: 'button',
					class: 'stm-day' + (state.day === d.date ? ' is-on' : ''),
					disabled: n === 0,
					'aria-pressed': state.day === d.date ? 'true' : 'false',
					onclick: function () { state.day = d.date; state.time = null; render(); }
				}, [
					h('span', { class: 'stm-day-label', text: d.label }),
					h('span', { class: 'stm-day-free', text: n === 0 ? 'complet' : n === 1 ? '1 loc liber' : n + ' locuri libere' })
				]));
			});
			dayStep.appendChild(days);
			root.appendChild(dayStep);

			// 3. Time.
			var day = theme.days.filter(function (d) { return d.date === state.day; })[0];
			if (day) {
				var timeStep = h('div', { class: 'stm-step' }, [h('h4', { text: '3. Alege ora' })]);
				var slots = h('div', { class: 'stm-slots' });
				day.slots.forEach(function (s) {
					var free = s.state === 'free';
					slots.appendChild(h('button', {
						type: 'button',
						class: 'stm-slot' + (state.time === s.time ? ' is-on' : '') + (free ? '' : ' is-off'),
						disabled: !free,
						title: free ? '' : (s.state === 'taken' ? 'Ocupat' : 'Închis'),
						'aria-pressed': state.time === s.time ? 'true' : 'false',
						onclick: function () { state.time = s.time; render(); focusForm(); }
					}, [s.time]));
				});
				timeStep.appendChild(slots);
				timeStep.appendChild(h('p', { class: 'stm-hint', text: 'Ședința durează ' + theme.duration + ' de minute. Orele tăiate sunt deja rezervate.' }));
				root.appendChild(timeStep);
			}

			// 4. Details.
			if (day && state.time) {
				root.appendChild(form(day));
			}
		}

		function focusForm() {
			setTimeout(function () {
				var f = root.querySelector('.stm-form input[name="name"]');
				if (f) {
					f.scrollIntoView({ behavior: 'smooth', block: 'center' });
					f.focus({ preventScroll: true });
				}
			}, 50);
		}

		function field(label, input) {
			return h('label', { class: 'stm-field' }, [h('span', { text: label }), input]);
		}

		function form(day) {
			var pkg = theme.packages.filter(function (p) { return p.id === state.pkg; })[0];
			var people = h('select', { name: 'people' });
			for (var i = 1; i <= Math.max(1, theme.max_people); i++) {
				people.appendChild(h('option', { value: i, text: i === 1 ? '1 persoană' : i + ' persoane' }));
			}
			if (theme.max_people >= 2) people.value = '2';

			var err = h('p', { class: 'stm-error', role: 'alert', hidden: true });
			var submit = h('button', { type: 'submit', class: 'stm-submit', text: 'Rezervă ' + day.label.split(',')[0] + ', ora ' + state.time });

			var f = h('form', { class: 'stm-step stm-form', novalidate: true }, [
				h('h4', { text: '4. Datele tale' }),
				h('p', { class: 'stm-choice' }, [
					h('strong', { text: day.label + ', ora ' + state.time }),
					pkg ? ' · ' + pkg.name + (pkg.price ? ' · ' + priceLabel(pkg.price) : '') : ''
				]),
				h('div', { class: 'stm-grid' }, [
					field('Nume și prenume *', h('input', { name: 'name', required: true, autocomplete: 'name' })),
					field('Telefon *', h('input', { name: 'phone', type: 'tel', required: true, autocomplete: 'tel', inputmode: 'tel' })),
					field('Email *', h('input', { name: 'email', type: 'email', required: true, autocomplete: 'email' })),
					field('Câte persoane apar în poze?', people)
				]),
				field('Observații (opțional)', h('textarea', { name: 'notes', rows: 3, placeholder: 'Ex: venim cu un bebeluș de 8 luni și cu câinele' })),
				h('label', { class: 'stm-hp', 'aria-hidden': 'true' }, ['Website', h('input', { name: 'website', tabindex: '-1', autocomplete: 'off' })]),
				h('label', { class: 'stm-consent' }, [h('input', { type: 'checkbox', name: 'consent', value: '1', required: true }), h('span', { html: theme.gdpr_text })]),
				err,
				submit
			]);

			// Păstrează ce a completat clientul la orice re-randare (schimbă ora, eroare etc.).
			var saved = state.form || {};
			['name', 'phone', 'email', 'notes'].forEach(function (n) {
				if (saved[n] !== undefined) f.elements.namedItem(n).value = saved[n];
			});
			if (saved.people) people.value = saved.people;
			if (saved.consent) f.elements.namedItem('consent').checked = true;
			function remember() {
				var el2 = f.elements;
				state.form = {
					name: el2.namedItem('name').value,
					phone: el2.namedItem('phone').value,
					email: el2.namedItem('email').value,
					notes: el2.namedItem('notes').value,
					people: el2.namedItem('people').value,
					consent: el2.namedItem('consent').checked
				};
			}
			f.addEventListener('input', remember);
			f.addEventListener('change', remember);
			function mark(n, ok, msg) {
				var input = f.elements.namedItem(n);
				var wrap = input.closest('.stm-field, .stm-consent');
				input.setAttribute('aria-invalid', ok ? 'false' : 'true');
				if (!wrap) return;
				wrap.classList.toggle('is-invalid', !ok);
				var hint = wrap.querySelector('.stm-field-error');
				if (!ok) {
					if (!hint) { hint = h('em', { class: 'stm-field-error' }); wrap.appendChild(hint); }
					hint.textContent = msg;
				} else if (hint) {
					hint.remove();
				}
			}
			f.addEventListener('input', function (ev) {
				var w = ev.target.closest && ev.target.closest('.is-invalid');
				if (w) { w.classList.remove('is-invalid'); var hh = w.querySelector('.stm-field-error'); if (hh) hh.remove(); }
			});

			f.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!pkg) {
					showError('Alege mai întâi pachetul.');
					return;
				}
				// f.elements, not f.name: a form's own "name" property shadows the input.
				var el = f.elements;
				var data = {
					theme: theme.id,
					date: state.day,
					time: state.time,
					package: pkg.id,
					name: el.namedItem('name').value.trim(),
					phone: el.namedItem('phone').value.trim(),
					email: el.namedItem('email').value.trim(),
					people: el.namedItem('people').value,
					notes: el.namedItem('notes').value.trim(),
					consent: el.namedItem('consent').checked ? '1' : '',
					website: el.namedItem('website').value
				};
				var okName = data.name.length >= 3;
				var okPhone = data.phone.replace(/\D/g, '').length >= 9;
				var okEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email);
				var okConsent = !!data.consent;
				mark('name', okName, 'Scrie numele complet.');
				mark('phone', okPhone, 'Numărul de telefon nu pare corect.');
				mark('email', okEmail, 'Adresa de email nu pare corectă.');
				mark('consent', okConsent, 'Bifează acordul pentru prelucrarea datelor.');
				if (!okName || !okPhone || !okEmail || !okConsent) {
					var first = f.querySelector('.is-invalid input, .is-invalid textarea');
					showError('Verifică ce este marcat cu roșu. Restul datelor au rămas completate.');
					if (first) first.focus({ preventScroll: true });
					return;
				}

				submit.disabled = true;
				submit.textContent = 'Se trimite…';
				api(app, '/book', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(data) })
					.then(function (res) { success(res); })
					.catch(function (e2) {
						submit.disabled = false;
						submit.textContent = 'Încearcă din nou';
						showError(e2.message);
						if (e2.code === 'stm_taken' || e2.code === 'stm_closed') reload();
					});
			});

			function showError(msg) {
				err.textContent = msg;
				err.hidden = false;
				err.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
			return f;
		}

		function success(res) {
			var s = res.summary || {};
			root.innerHTML = '';
			root.appendChild(h('div', { class: 'stm-success' }, [
				h('div', { class: 'stm-success-icon', text: '✓' }),
				h('h3', { text: res.status === 'pending' ? 'Cererea a fost trimisă!' : 'Rezervare confirmată!' }),
				h('p', { text: res.message || '' }),
				h('ul', {}, [
					h('li', {}, [h('strong', { text: s.theme || '' })]),
					h('li', { text: (s.date || '') + ', ora ' + (s.time || '') }),
					h('li', { text: (s.package || '') + (s.price ? ' · ' + s.price : '') }),
					s.location ? h('li', { text: '📍 ' + s.location }) : null
				])
			]));
			root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}

		function reload() {
			api(app, '/themes&id=' + theme.id).then(function (data) {
				var fresh = (data.themes || [])[0];
				if (fresh) {
					theme = fresh;
					state.time = null;
					render();
				}
			});
		}

		render();
		return root;
	}

	function mount(app) {
		var id = parseInt(app.getAttribute('data-theme'), 10) || 0;
		api(app, '/themes' + (id ? '&id=' + id : ''))
			.then(function (data) {
				app.innerHTML = '';
				var themes = data.themes || [];
				if (!themes.length) {
					app.appendChild(h('p', { class: 'stm-note', text: 'Momentan nu sunt mini-ședințe programate. Revino curând!' }));
					return;
				}
				themes.forEach(function (t) { app.appendChild(Theme(app, t)); });
			})
			.catch(function (e) {
				app.innerHTML = '';
				app.appendChild(h('p', { class: 'stm-note', text: e.message }));
			});
	}

	function ready() {
		[].forEach.call(document.querySelectorAll('.stm-app'), mount);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
	else ready();
})();
