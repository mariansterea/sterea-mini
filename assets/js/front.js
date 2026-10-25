/* Sterea Mini-Ședințe – formular de rezervare în 4 pași (fără dependențe). */
(function () {
	'use strict';

	var STEPS = [
		{ key: 'package', label: 'Pachet' },
		{ key: 'when', label: 'Data și ora' },
		{ key: 'info', label: 'Datele tale' },
		{ key: 'confirm', label: 'Confirmare' }
	];
	var WEEKDAYS = ['Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sâ', 'Du'];
	var MONTHS = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];

	function h(tag, attrs, children) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'class') el.className = v;
			else if (k === 'text') el.textContent = v;
			else if (k === 'html') el.innerHTML = v; // Only server-filtered HTML (wp_kses_post).
			else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), v);
			else if (k === 'disabled' || k === 'checked' || k === 'hidden') el[k] = !!v;
			else el.setAttribute(k, v === true ? '' : v);
		});
		[].concat(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) return;
			el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return el;
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function addMinutes(time, min) {
		var p = time.split(':'), t = (+p[0]) * 60 + (+p[1]) + min;
		return pad(Math.floor(t / 60) % 24) + ':' + pad(t % 60);
	}
	function priceLabel(p) {
		p = String(p || '').trim();
		if (!p) return '';
		return /^\d+([.,]\d+)?$/.test(p) ? p + ' lei' : p;
	}

	/**
	 * API-ul pluginului: data-api este „…/?rest_route=/stm/v1”, deci parametrii se lipesc cu „&”.
	 * Erorile vin ca WP_Error: { code, message } cu status 4xx.
	 */
	function api(root, path, query, opts) {
		var base = root.getAttribute('data-api');
		var url = base + path + (query ? (base.indexOf('?') > -1 ? '&' : '?') + query : '');
		return fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, opts || {}))
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

	function gcalLink(theme, date, time) {
		var p = date.split('-'), t = time.split(':');
		var start = new Date(+p[0], +p[1] - 1, +p[2], +t[0], +t[1]);
		var end = new Date(start.getTime() + (theme.duration || 30) * 60000);
		function fmt(d) { return d.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, ''); }
		var q = {
			action: 'TEMPLATE',
			text: theme.title,
			dates: fmt(start) + '/' + fmt(end),
			location: [theme.location, theme.address].filter(Boolean).join(', ')
		};
		return 'https://calendar.google.com/calendar/render?' + Object.keys(q).map(function (k) { return k + '=' + encodeURIComponent(q[k]); }).join('&');
	}

	/* ---------- O temă = un wizard ---------- */

	function App(root, cfg, theme) {
		this.root = root;
		this.cfg = cfg;
		this.el = h('section', { class: 'stm-card' });
		this.theme = theme;
		this.step = 0;
		this.sel = { pkg: theme.packages.length === 1 ? theme.packages[0].id : null, people: Math.min(2, Math.max(1, theme.max_people || 1)), date: null, time: null, first: '', last: '', email: '', phone: '', notes: '', consent: false, coupon: '' };
		this.coupon = null; // { code, label, discount, total } după validare pe server
		this.couponMsg = '';
		this.errors = {};
		this.alert = '';
		this.busy = false;
		var firstDay = theme.days.filter(function (d) { return d.slots.some(function (s) { return s.state === 'free'; }); })[0];
		var ym = (firstDay ? firstDay.date : new Date().toISOString().slice(0, 10)).split('-');
		this.view = { y: +ym[0], m: +ym[1] - 1 };
		this.render();
	}

	App.prototype.reload = function () {
		var self = this;
		return api(this.root, '/themes', 'id=' + this.theme.id + '&_=' + Date.now()).then(function (data) {
			var fresh = (data.themes || [])[0];
			if (!fresh) return;
			self.theme = fresh;
			if (self.sel.date && self.sel.time && !self.isFree(self.sel.date, self.sel.time)) {
				self.sel.time = null;
				self.alert = 'Ora aleasă nu mai este disponibilă. Alege alta.';
			}
			self.render();
		}).catch(function () { /* păstrăm programul deja încărcat */ });
	};

	App.prototype.day = function (date) {
		return this.theme.days.filter(function (d) { return d.date === date; })[0] || null;
	};
	App.prototype.isFree = function (date, time) {
		var d = this.day(date);
		return !!(d && d.slots.some(function (s) { return s.time === time && s.state === 'free'; }));
	};
	App.prototype.dayLabel = function (date) {
		var d = this.day(date);
		if (d && d.label) return d.label;
		var p = date.split('-');
		return (+p[2]) + ' ' + MONTHS[+p[1] - 1] + ' ' + p[0];
	};
	App.prototype.pkg = function () {
		var id = this.sel.pkg;
		return this.theme.packages.filter(function (p) { return p.id === id; })[0] || null;
	};
	App.prototype.done = function (i) {
		var s = this.sel;
		if (i === 0) return !!this.pkg();
		if (i === 1) return !!(s.date && s.time);
		if (i === 2) return !!(s.first && s.last && s.email && s.phone);
		return false;
	};
	App.prototype.subLabel = function (i) {
		var s = this.sel, p = this.pkg();
		if (i === 0 && p) return p.name + (s.people > 1 ? ' · ' + s.people + ' pers.' : '');
		if (i === 1 && s.date && s.time) return this.dayLabel(s.date) + ', ' + s.time;
		if (i === 2 && s.first) return s.first + ' ' + s.last;
		return '';
	};

	App.prototype.go = function (i) {
		this.alert = '';
		this.errors = {};
		this.step = i;
		if (i === 1) this.reload();
		this.render();
		if (window.innerWidth < 720) {
			var top = this.el.getBoundingClientRect().top + window.pageYOffset - 16;
			window.scrollTo({ top: top, behavior: 'smooth' });
		}
	};

	App.prototype.next = function () {
		if (this.step === 2 && !this.validate()) { this.render(); return; }
		if (this.step === 3) { this.submit(); return; }
		this.go(this.step + 1);
	};

	App.prototype.validate = function () {
		var s = this.sel, e = {};
		if (s.first.trim().length < 2) e.first = 'Scrie prenumele.';
		if (s.last.trim().length < 2) e.last = 'Scrie numele.';
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(s.email.trim())) e.email = 'Adresa de email nu pare corectă.';
		if (s.phone.replace(/\D/g, '').length < 9) e.phone = 'Numărul de telefon nu pare corect.';
		if (!s.consent) e.consent = 'Bifează acordul pentru prelucrarea datelor.';
		this.errors = e;
		return !Object.keys(e).length;
	};

	App.prototype.submit = function () {
		var self = this, s = this.sel;
		if (this.busy) return;
		this.busy = true;
		this.alert = '';
		this.render();
		var data = {
			theme: this.theme.id,
			date: s.date,
			time: s.time,
			package: s.pkg,
			name: (s.first.trim() + ' ' + s.last.trim()).trim(),
			phone: s.phone.trim(),
			email: s.email.trim(),
			people: String(s.people),
			notes: s.notes.trim(),
			consent: s.consent ? '1' : '',
			coupon: this.coupon ? this.coupon.code : '',
			website: this.hp ? this.hp.value : ''
		};
		api(this.root, '/book', '', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(data) })
			.then(function (res) {
				self.busy = false;
				self.result = res;
				self.step = 4;
				self.render();
				self.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
			})
			.catch(function (err) {
				self.busy = false;
				self.alert = err.message;
				var code = err.code || '';
				if (code === 'stm_taken' || code === 'stm_closed' || code === 'stm_slot') { self.sel.time = null; self.step = 1; self.reload(); }
				else if (code === 'stm_package') self.step = 0;
				else if (code === 'stm_coupon') { self.coupon = null; self.sel.coupon = ''; self.couponMsg = err.message; self.alert = ''; }
				else if (code === 'stm_name') { self.step = 2; self.errors.first = err.message; }
				else if (code === 'stm_phone' || code === 'stm_email' || code === 'stm_consent') { self.step = 2; self.errors[code.slice(4)] = err.message; }
				self.render();
			});
	};

	/* ---------- Randare ---------- */

	App.prototype.render = function () {
		var self = this, t = this.theme;
		this.el.innerHTML = '';
		var bookable = t.open && t.days.some(function (d) { return d.slots.some(function (s) { return s.state === 'free'; }); });

		if (this.step === 4) {
			this.el.appendChild(this.renderSide());
			this.el.appendChild(h('div', { class: 'stm-main' }, [h('div', { class: 'stm-body' }, [this.renderSuccess()])]));
			return;
		}
		if (!bookable) {
			this.el.className = 'stm-card stm-card--closed';
			this.el.appendChild(h('div', { class: 'stm-closed' }, [
				h('strong', { text: t.title }),
				h('p', { text: !t.days.length ? 'Nu mai sunt date disponibile pentru acest eveniment.' : 'Toate locurile sunt rezervate sau rezervările s-au închis. Scrie-ne și te anunțăm dacă se eliberează un loc.' })
			]));
			return;
		}

		var body = [this.renderPackage, this.renderWhen, this.renderInfo, this.renderConfirm][this.step].call(this);
		var canNext = this.done(this.step) || this.step === 2 || this.step === 3;
		var head = h('div', { class: 'stm-head' }, [
			this.step > 0 ? h('button', { class: 'stm-back', type: 'button', 'aria-label': 'Pasul anterior', html: '&#8249;', onclick: function () { self.go(self.step - 1); } }) : null,
			h('h3', { text: STEPS[this.step].label })
		]);
		var foot = h('div', { class: 'stm-foot' }, [
			this.step === 3 ? h('span', { class: 'stm-foot-note', text: 'Plata se face la fața locului.' }) : null,
			this.step > 0 ? h('button', { class: 'stm-btn stm-btn-ghost', type: 'button', text: 'Înapoi', onclick: function () { self.go(self.step - 1); } }) : null,
			h('button', {
				class: 'stm-btn', type: 'button', disabled: !canNext || this.busy,
				text: this.step === 3 ? (this.busy ? 'Se trimite…' : 'Trimite rezervarea') : 'Continuă',
				onclick: function () { self.next(); }
			})
		]);
		this.el.appendChild(this.renderSide());
		this.el.appendChild(h('div', { class: 'stm-main' }, [
			head,
			h('div', { class: 'stm-body' }, [this.alert ? h('div', { class: 'stm-alert', role: 'alert', text: this.alert }) : null, body]),
			foot
		]));
	};

	App.prototype.renderSide = function () {
		var self = this;
		var items = STEPS.map(function (st, i) {
			var done = (self.done(i) && i < self.step) || self.step === 4;
			var active = i === self.step;
			var canClick = i < self.step && self.step < 4;
			return h('li', null, [h('button', {
				type: 'button',
				class: 'stm-step' + (active ? ' is-active' : '') + (done ? ' is-done' : ''),
				disabled: !canClick,
				onclick: function () { if (canClick) self.go(i); }
			}, [
				h('span', { class: 'stm-step-num', text: String(i + 1) }),
				h('span', { class: 'stm-step-label' }, [st.label, self.subLabel(i) ? h('span', { class: 'stm-step-sub', text: self.subLabel(i) }) : null]),
				h('span', { class: 'stm-step-check' })
			])]);
		});
		var phone = (this.cfg.phone || '').trim();
		return h('aside', { class: 'stm-side' }, [
			h('p', { class: 'stm-side-title', text: 'Rezervare' }),
			h('ol', { class: 'stm-steps' }, items),
			phone ? h('div', { class: 'stm-side-foot' }, [h('a', { href: 'tel:' + phone.replace(/\s/g, ''), text: 'Întrebări? ' + phone })]) : null
		]);
	};

	App.prototype.renderPackage = function () {
		var self = this, t = this.theme, s = this.sel;
		var meta = [];
		if (t.duration) meta.push(t.duration + ' min');
		if (t.location) meta.push(t.location + (t.address ? ', ' + t.address : ''));
		var intro = h('div', { class: 'stm-intro' }, [
			t.image ? h('img', { src: t.image, alt: '', loading: 'lazy' }) : h('span'),
			h('div', null, [
				h('p', { class: 'stm-intro-title', text: t.title }),
				h('p', { class: 'stm-intro-meta' }, [meta.join(' · '), t.map_url ? ' · ' : null, t.map_url ? h('a', { href: t.map_url, target: '_blank', rel: 'noopener', text: 'hartă' }) : null])
			])
		]);
		var desc = t.content ? h('div', { class: 'stm-intro-desc', html: t.content }) : null;
		var packages = h('div', { class: 'stm-packages', role: 'radiogroup' }, t.packages.map(function (p) {
			return h('button', {
				type: 'button', role: 'radio', 'aria-checked': s.pkg === p.id ? 'true' : 'false',
				class: 'stm-package' + (s.pkg === p.id ? ' is-selected' : ''),
				onclick: function () { s.pkg = p.id; self.coupon = null; self.couponMsg = ''; self.render(); }
			}, [
				h('span', { class: 'stm-radio' }),
				h('span', null, [h('div', { class: 'stm-package-name', text: p.name }), p.desc ? h('div', { class: 'stm-package-desc', text: p.desc }) : null]),
				h('span', { class: 'stm-package-price', text: priceLabel(p.price) })
			]);
		}));
		var people = t.max_people > 1 ? h('div', null, [
			h('label', { class: 'stm-label', text: 'Câte persoane apar în poze?' }),
			h('div', { class: 'stm-stepper' }, [
				h('button', { type: 'button', text: '−', disabled: s.people <= 1, 'aria-label': 'Mai puține', onclick: function () { s.people = Math.max(1, s.people - 1); self.render(); } }),
				h('output', { text: String(s.people) }),
				h('button', { type: 'button', text: '+', disabled: s.people >= t.max_people, 'aria-label': 'Mai multe', onclick: function () { s.people = Math.min(t.max_people, s.people + 1); self.render(); } })
			]),
			h('p', { class: 'stm-hint', text: 'Maximum ' + t.max_people + ' persoane la o ședință.' })
		]) : null;
		return h('div', null, [intro, desc, h('label', { class: 'stm-label' }, [h('span', { class: 'req', text: '*' }), 'Alege pachetul:']), packages, people]);
	};

	App.prototype.renderWhen = function () {
		var self = this, t = this.theme, s = this.sel, v = this.view;
		var months = {};
		t.days.forEach(function (d) { months[d.date.slice(0, 7)] = true; });
		var keys = Object.keys(months).sort();
		var cur = v.y + '-' + pad(v.m + 1);
		var prevKey = keys.filter(function (k) { return k < cur; }).pop();
		var nextKey = keys.filter(function (k) { return k > cur; })[0];
		function setMonth(k) { var p = k.split('-'); self.view = { y: +p[0], m: +p[1] - 1 }; self.render(); }

		var head = h('div', { class: 'stm-cal-head' }, [
			h('span', { class: 'stm-cal-month', text: MONTHS[v.m] + ' ' + v.y }),
			h('div', { class: 'stm-cal-nav' }, [
				h('button', { type: 'button', html: '&#8249;', 'aria-label': 'Luna anterioară', disabled: !prevKey, onclick: function () { setMonth(prevKey); } }),
				h('button', { type: 'button', html: '&#8250;', 'aria-label': 'Luna următoare', disabled: !nextKey, onclick: function () { setMonth(nextKey); } })
			])
		]);

		var cells = WEEKDAYS.map(function (w) { return h('div', { class: 'stm-cal-wd', text: w }); });
		var first = new Date(v.y, v.m, 1), offset = (first.getDay() + 6) % 7, dim = new Date(v.y, v.m + 1, 0).getDate();
		for (var i = 0; i < offset; i++) cells.push(h('div', { class: 'stm-day is-other' }));
		for (var d = 1; d <= dim; d++) {
			(function (d) {
				var date = v.y + '-' + pad(v.m + 1) + '-' + pad(d);
				var day = self.day(date);
				var n = day ? day.slots.filter(function (x) { return x.state === 'free'; }).length : 0;
				var cls = 'stm-day' + (n ? ' is-free' : day ? ' is-full' : '') + (s.date === date ? ' is-selected' : '');
				cells.push(h('button', {
					type: 'button', class: cls, disabled: !n, text: String(d),
					'aria-label': day ? day.label + ', ' + (n ? n + ' locuri libere' : 'complet') : null,
					'aria-pressed': s.date === date ? 'true' : 'false',
					onclick: function () { s.date = date; s.time = null; self.alert = ''; self.render(); self.scrollToSlots(); }
				}));
			})(d);
		}
		var cal = h('div', { class: 'stm-cal' }, cells);

		var slots;
		if (s.date) {
			var day = this.day(s.date);
			slots = h('div', { class: 'stm-slots-wrap' }, [
				h('div', { class: 'stm-slots-title', text: this.dayLabel(s.date) }),
				h('div', { class: 'stm-slots' }, day.slots.map(function (sl) {
					var free = sl.state === 'free';
					return h('button', {
						type: 'button', class: 'stm-slot' + (s.time === sl.time ? ' is-selected' : ''), disabled: !free,
						title: free ? '' : (sl.state === 'taken' ? 'Ocupat' : 'Închis'),
						text: sl.time + ' – ' + addMinutes(sl.time, t.duration),
						onclick: function () { s.time = sl.time; self.render(); }
					});
				})),
				h('p', { class: 'stm-hint', text: 'Ședința durează ' + t.duration + ' de minute. Orele tăiate sunt deja rezervate.' })
			]);
		} else {
			slots = h('p', { class: 'stm-hint', text: 'Zilele cu punct au ore libere. Alege o zi ca să vezi orele.' });
		}
		return h('div', null, [head, cal, slots]);
	};
	App.prototype.scrollToSlots = function () {
		var el = this.el.querySelector('.stm-slots-wrap');
		if (el && window.innerWidth < 720) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
	};

	App.prototype.renderInfo = function () {
		var self = this, s = this.sel, e = this.errors;
		function clear(ev) {
			var wrap = ev.target.closest('.stm-field');
			if (wrap && wrap.classList.contains('has-error')) {
				wrap.classList.remove('has-error');
				var hint = wrap.querySelector('.stm-error');
				if (hint) hint.remove();
			}
		}
		function field(key, label, type, wide, required, placeholder) {
			var input = h(type === 'textarea' ? 'textarea' : 'input', {
				class: 'stm-input', type: type === 'textarea' ? null : type, placeholder: placeholder || '', rows: type === 'textarea' ? 3 : null,
				inputmode: type === 'tel' ? 'tel' : null,
				autocomplete: { first: 'given-name', last: 'family-name', email: 'email', phone: 'tel' }[key] || 'off',
				oninput: function (ev) { s[key] = ev.target.value; delete e[key]; clear(ev); }
			});
			input.value = s[key];
			return h('div', { class: 'stm-field' + (wide ? ' is-wide' : '') + (e[key] ? ' has-error' : '') }, [
				h('label', { class: 'stm-label' }, [required ? h('span', { class: 'req', text: '*' }) : null, label]),
				input,
				e[key] ? h('div', { class: 'stm-error', text: e[key] }) : null
			]);
		}
		this.hp = h('input', { name: 'website', tabindex: '-1', autocomplete: 'off' });
		var consent = h('div', { class: 'stm-field is-wide' + (e.consent ? ' has-error' : '') }, [
			h('label', { class: 'stm-check' }, [
				h('input', { type: 'checkbox', checked: s.consent, onchange: function (ev) { s.consent = ev.target.checked; delete e.consent; clear(ev); } }),
				h('span', { html: this.theme.gdpr_text || 'Sunt de acord ca datele mele să fie folosite pentru gestionarea rezervării.' })
			]),
			e.consent ? h('div', { class: 'stm-error', text: e.consent }) : null
		]);
		return h('form', { class: 'stm-grid', novalidate: true, onsubmit: function (ev) { ev.preventDefault(); self.next(); } }, [
			field('first', 'Prenume', 'text', false, true, 'Prenumele tău'),
			field('last', 'Nume', 'text', false, true, 'Numele de familie'),
			field('email', 'Email', 'email', false, true, 'nume@exemplu.ro'),
			field('phone', 'Telefon', 'tel', false, true, '07xx xxx xxx'),
			field('notes', 'Observații (opțional)', 'textarea', true, false, 'Ex: venim cu un bebeluș de 8 luni și cu câinele'),
			h('label', { class: 'stm-hp', 'aria-hidden': 'true' }, ['Website', this.hp]),
			consent,
			h('input', { type: 'submit', hidden: true })
		]);
	};

	App.prototype.summaryRows = function () {
		var t = this.theme, s = this.sel, p = this.pkg();
		return [
			['Ședința', t.title],
			['Pachet', p ? p.name + (p.price ? ' · ' + priceLabel(p.price) : '') : ''],
			['Data', this.dayLabel(s.date)],
			['Ora', s.time + ' – ' + addMinutes(s.time, t.duration) + ' (' + t.duration + ' min)'],
			['Locație', [t.location, t.address].filter(Boolean).join(', ')],
			['Persoane', String(s.people)],
			['Nume', s.first.trim() + ' ' + s.last.trim()],
			['Contact', s.email.trim() + ' · ' + s.phone.trim()],
			['Observații', s.notes.trim()]
		].filter(function (r) { return r[1]; });
	};

	App.prototype.applyCoupon = function () {
		var self = this, s = this.sel, code = (s.coupon || '').trim();
		if (!code) { this.coupon = null; this.couponMsg = ''; this.render(); return; }
		this.couponBusy = true;
		this.render();
		api(this.root, '/coupon', '', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ code: code, theme: this.theme.id, package: s.pkg, email: s.email.trim() }) })
			.then(function (res) { self.couponBusy = false; self.coupon = res; self.sel.coupon = res.code; self.couponMsg = ''; self.render(); })
			.catch(function (err) { self.couponBusy = false; self.coupon = null; self.couponMsg = err.message; self.render(); });
	};

	App.prototype.renderConfirm = function () {
		var self = this, s = this.sel, p = this.pkg(), c = this.coupon;
		var hasPrice = !!(p && priceLabel(p.price));
		var input = h('input', { class: 'stm-input', type: 'text', placeholder: 'Codul tău', autocomplete: 'off', disabled: !!c,
			oninput: function (ev) { s.coupon = ev.target.value.toUpperCase(); },
			onkeydown: function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); self.applyCoupon(); } } });
		input.value = s.coupon || '';
		var couponBox = hasPrice ? h('div', { class: 'stm-coupon' }, [
			h('label', { class: 'stm-label', text: 'Ai un cod de reducere?' }),
			h('div', { class: 'stm-coupon-row' }, [
				input,
				c ? h('button', { type: 'button', class: 'stm-btn stm-btn-ghost', text: 'Renunță', onclick: function () { self.coupon = null; s.coupon = ''; self.couponMsg = ''; self.render(); } })
				  : h('button', { type: 'button', class: 'stm-btn stm-btn-ghost', text: this.couponBusy ? '…' : 'Aplică', disabled: !!this.couponBusy, onclick: function () { self.applyCoupon(); } })
			]),
			this.couponMsg ? h('div', { class: 'stm-error', text: this.couponMsg }) : null,
			c ? h('div', { class: 'stm-coupon-ok', text: 'Cod ' + c.code + ' aplicat (' + c.label + ').' }) : null
		]) : null;
		var total = null;
		if (hasPrice) {
			total = c
				? h('div', { class: 'stm-total' }, [h('span', null, ['Total ', h('s', { text: priceLabel(p.price) })]), h('em', { text: priceLabel(String(c.total)) })])
				: h('div', { class: 'stm-total' }, ['Total', h('em', { text: priceLabel(p.price) })]);
		}
		return h('div', null, [
			h('div', { class: 'stm-summary' }, this.summaryRows().map(function (r) {
				return h('div', { class: 'stm-summary-row' }, [h('span', { text: r[0] }), h('strong', { text: r[1] })]);
			})),
			couponBox,
			total,
			h('p', { class: 'stm-hint', text: 'Verifică datele și apasă „Trimite rezervarea”. Primești un email cu toate detaliile.' })
		]);
	};

	App.prototype.renderSuccess = function () {
		var r = this.result || {}, sm = r.summary || {}, t = this.theme, s = this.sel;
		var rows = [
			['Ședința', sm.theme || t.title],
			['Pachet', (sm.package || '') + (sm.price ? ' · ' + sm.price : '')],
			['Data', sm.date || this.dayLabel(s.date)],
			['Ora', (sm.time || s.time) + ' – ' + addMinutes(sm.time || s.time, t.duration)],
			['Locație', sm.location || t.location],
			['Reducere', sm.coupon && sm.discount ? sm.coupon + ': −' + sm.discount : ''],
			['Total', sm.discount ? sm.total : '']
		].filter(function (x) { return x[1]; });
		var actions = [
			h('a', { class: 'stm-btn', href: gcalLink(t, s.date, s.time), target: '_blank', rel: 'noopener', text: 'Adaugă în Google Calendar' }),
			t.map_url ? h('a', { class: 'stm-btn stm-btn-ghost', href: t.map_url, target: '_blank', rel: 'noopener', text: 'Vezi locația pe hartă' }) : null
		];
		return h('div', { class: 'stm-success' }, [
			h('div', { class: 'stm-success-icon' }),
			h('h3', { text: r.status === 'pending' ? 'Cererea a fost trimisă!' : 'Rezervare confirmată!' }),
			h('p', { text: r.message || '' }),
			h('div', { class: 'stm-summary' }, rows.map(function (row) {
				return h('div', { class: 'stm-summary-row' }, [h('span', { text: row[0] }), h('strong', { text: row[1] })]);
			})),
			h('div', { class: 'stm-success-actions' }, actions)
		]);
	};

	/* ---------- Montare ---------- */

	function mount(root) {
		var cfg = {};
		try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
		var id = parseInt(root.getAttribute('data-theme'), 10) || 0;
		api(root, '/themes', id ? 'id=' + id : '')
			.then(function (data) {
				root.innerHTML = '';
				var themes = data.themes || [];
				if (!themes.length) {
					root.appendChild(h('p', { class: 'stm-note', text: 'Momentan nu sunt mini-ședințe programate. Revino curând!' }));
					return;
				}
				themes.forEach(function (t) { root.appendChild(new App(root, cfg, t).el); });
			})
			.catch(function (e) {
				root.innerHTML = '';
				root.appendChild(h('p', { class: 'stm-note', text: e.message }));
			});
	}

	function ready() {
		[].forEach.call(document.querySelectorAll('.stm-app'), function (el) {
			if (!el.__stm) { el.__stm = true; mount(el); }
		});
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
	else ready();
})();
