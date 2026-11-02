/* Sterea Mini-Ședințe: formular de rezervare în 4 pași, cu varianta „card cadou” (fără dependențe). */
(function () {
	'use strict';

	var STEPS = [
		{ key: 'package', label: 'Pachet' },
		{ key: 'when', label: 'Data și ora' },
		{ key: 'info', label: 'Datele tale' },
		{ key: 'confirm', label: 'Confirmare' }
	];
	var GIFT_STEPS = [
		{ key: 'package', label: 'Pachet' },
		{ key: 'card', label: 'Cardul cadou' },
		{ key: 'info', label: 'Datele tale' },
		{ key: 'confirm', label: 'Confirmare' }
	];
	var XMAS = /cr[ăa]ciun/i;
	var uid = 0;
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
		if (/^\d{4,}$/.test(p)) p = p.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
		return /^\d{1,3}(\.\d{3})*([.,]\d+)?$|^\d+([.,]\d+)?$/.test(p) ? p + ' lei' : p;
	}
	/** La fel ca STM_Helpers::to_number() din PHP: „1.200”, „800 lei”, „1.100,50”. */
	function toNumber(price) {
		var p = String(price || '').replace(/[\s\u00a0]/g, '');
		if (/^\d{1,3}(\.\d{3})+(,\d+)?$/.test(p)) p = p.replace(/\./g, '');
		return parseFloat(p.replace(',', '.').replace(/[^0-9.]/g, '')) || 0;
	}
	/** Doar pachetele cu preț se pot oferi cadou (valoarea cardului = prețul). */
	function priced(p) { return toNumber(p.price) > 0; }
	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function today(offset) { var d = new Date(); d.setDate(d.getDate() + (offset || 0)); return ymd(d); }

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
		this.uid = 'stm' + (++uid);
		this.step = 0;
		this.sel = { pkg: theme.packages.length === 1 ? theme.packages[0].id : null, people: Math.min(2, Math.max(1, theme.max_people || 1)), date: null, time: null, first: '', last: '', email: '', phone: '', notes: '', consent: false, coupon: '' };
		// Mod „cadou”: aceeași ședință, dar se comandă un card cadou în loc de o oră (destinatarul alege ora).
		// Merge și când nu mai sunt ore libere, dar nu pentru un eveniment închis sau fără zile viitoare.
		this.giftOn = !!(cfg.gift && theme.open && theme.days.length && theme.packages.some(priced));
		this.mode = this.giftOn && (cfg.giftFirst || !this.bookable()) ? 'gift' : 'book';
		var occ = cfg.occasions || ['Cu drag'];
		var occDefault = XMAS.test(theme.title) && occ.indexOf('Crăciun') > -1 ? 'Crăciun' : (occ.indexOf('Cu drag') > -1 ? 'Cu drag' : occ[0]);
		this.gift = { occasion: occDefault, to: '', from: '', message: '', remail: '', sendAt: '' };
		this.fixPkg();
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

	App.prototype.bookable = function () {
		var t = this.theme;
		return !!(t.open && t.days.some(function (d) { return d.slots.some(function (s) { return s.state === 'free'; }); }));
	};
	App.prototype.isGift = function () { return this.mode === 'gift'; };
	App.prototype.steps = function () { return this.isGift() ? GIFT_STEPS : STEPS; };
	App.prototype.giftTitle = function () {
		return XMAS.test(this.theme.title) ? 'Oferă cadou o ședință foto de Crăciun' : 'Oferă cadou această ședință foto';
	};
	/** În modul cadou rămân doar pachetele cu preț; dacă e unul singur, e ales din oficiu. */
	App.prototype.fixPkg = function () {
		if (!this.isGift()) return;
		var list = this.theme.packages.filter(priced), p = this.pkg();
		if (p && !priced(p)) this.sel.pkg = null;
		if (!this.sel.pkg && list.length === 1) this.sel.pkg = list[0].id;
	};
	App.prototype.setMode = function (mode) {
		if (mode === this.mode) return;
		if (mode === 'book' && !this.bookable()) return;
		if (mode === 'gift' && !this.giftOn) return;
		this.mode = mode;
		this.coupon = null; this.couponMsg = '';
		this.fixPkg();
		this.errors = {}; this.alert = '';
		this.render();
	};
	/** Stilul cardului, ca pe server: Crăciun dacă evenimentul sau ocazia sunt de Crăciun. */
	App.prototype.modelStyle = function () {
		return this.gift.occasion === 'Crăciun' || XMAS.test(this.theme.title) ? 'craciun' : 'negru';
	};
	App.prototype.modelImg = function (cls, sizes) {
		var m = this.cfg.model, st = m && m.styles && m.styles[this.modelStyle()];
		if (!st) return null;
		return h('img', { class: cls, src: st.src, srcset: st.small + ' 700w, ' + st.src + ' 1400w', sizes: sizes, width: m.w, height: m.h, alt: st.alt, loading: 'lazy', decoding: 'async' });
	};
	App.prototype.lastDay = function () {
		var d = this.theme.days;
		return d.length ? d[d.length - 1].date : '';
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
		if (i === 1 && this.isGift()) return this.gift.to.trim().length >= 2 && this.gift.from.trim().length >= 2;
		if (i === 1) return !!(s.date && s.time);
		if (i === 2) return !!(s.first && s.last && s.email && s.phone);
		return false;
	};
	App.prototype.subLabel = function (i) {
		var s = this.sel, p = this.pkg();
		if (i === 0 && p) return p.name + (!this.isGift() && s.people > 1 ? ' · ' + s.people + ' pers.' : '');
		if (i === 1 && this.isGift()) return this.gift.to.trim() ? 'pentru ' + this.gift.to.trim() : '';
		if (i === 1 && s.date && s.time) return this.dayLabel(s.date) + ', ' + s.time;
		if (i === 2 && s.first) return s.first + ' ' + s.last;
		return '';
	};

	App.prototype.go = function (i) {
		this.alert = '';
		this.errors = {};
		this.step = i;
		if (i === 1 && !this.isGift()) this.reload();
		this.render();
		if (window.innerWidth < 720) {
			var top = this.el.getBoundingClientRect().top + window.pageYOffset - 16;
			window.scrollTo({ top: top, behavior: 'smooth' });
		}
	};

	App.prototype.next = function () {
		if (this.step === 1 && this.isGift() && !this.validateGift()) { this.render(); return; }
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

	App.prototype.validateGift = function () {
		var g = this.gift, e = {};
		if (g.to.trim().length < 2) e.gto = 'Scrie numele celui care primește cardul.';
		if (g.from.trim().length < 2) e.gfrom = 'Scrie din partea cui este cadoul.';
		if (g.remail.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(g.remail.trim())) e.gremail = 'Adresa nu pare corectă.';
		if (g.remail.trim() && g.sendAt) {
			var last = this.lastDay();
			if (!/^\d{4}-\d{2}-\d{2}$/.test(g.sendAt) || g.sendAt < today(1)) e.gsend = 'Alege o dată din viitor sau lasă gol (cardul pleacă imediat).';
			else if (last && g.sendAt > last) e.gsend = 'Alege o dată până la ' + this.dayLabel(last) + ', ultima zi cu ședințe.';
		}
		this.errors = e;
		return !Object.keys(e).length;
	};

	App.prototype.submitGift = function () {
		var self = this, s = this.sel, g = this.gift;
		if (this.busy) return;
		this.busy = true;
		this.alert = '';
		this.render();
		var data = {
			kind: 'package', theme: this.theme.id, package: s.pkg,
			occasion: g.occasion, to: g.to.trim(), from: g.from.trim(), message: g.message.trim(),
			buyer_name: (s.first.trim() + ' ' + s.last.trim()).trim(), buyer_email: s.email.trim(), buyer_phone: s.phone.trim(),
			recipient_email: g.remail.trim(), send_at: g.remail.trim() ? g.sendAt : '',
			consent: s.consent ? '1' : '', website: this.hp ? this.hp.value : ''
		};
		api(this.root, '/giftcard', '', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(data) })
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
				// Eroarea de la server (și de pe site-ul principal, în modul remote) ajunge lângă câmpul ei.
				var map = {
					stm_gift_theme: [0, ''], stm_gift_package: [0, ''],
					stm_gift_to: [1, 'gto'], stm_gift_from: [1, 'gfrom'], stm_gift_remail: [1, 'gremail'], stm_gift_sendat: [1, 'gsend'],
					stm_gift_name: [2, 'first'], stm_gift_phone: [2, 'phone'], stm_gift_email: [2, 'email'], stm_gift_consent: [2, 'consent']
				};
				var m = map[err.code];
				self.errors = {};
				if (m) { self.step = m[0]; if (m[1]) { self.errors[m[1]] = err.message; self.alert = ''; } }
				self.render();
			});
	};

	App.prototype.submit = function () {
		var self = this, s = this.sel;
		if (this.isGift()) { this.submitGift(); return; }
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
		var bookable = this.bookable();

		if (this.step === 4) {
			this.el.appendChild(this.renderSide());
			this.el.appendChild(h('div', { class: 'stm-main' }, [h('div', { class: 'stm-body' }, [this.isGift() ? this.renderGiftSuccess() : this.renderSuccess()])]));
			return;
		}
		if (!bookable && !this.isGift()) {
			this.el.className = 'stm-card stm-card--closed';
			this.el.appendChild(h('div', { class: 'stm-closed' }, [
				h('strong', { text: t.title }),
				h('p', { text: !t.days.length ? 'Nu mai sunt date disponibile pentru acest eveniment.' : 'Toate locurile sunt rezervate sau rezervările s-au închis. Scrie-ne și te anunțăm dacă se eliberează un loc.' }),
				this.giftOn ? h('p', { class: 'stm-closed-gift' }, [
					'Poți oferi în continuare ședința cadou: cel care primește cardul își alege ora când se eliberează un loc.',
					h('button', { type: 'button', class: 'stm-btn', text: this.giftTitle(), onclick: function () { self.step = 0; self.alert = ''; self.setMode('gift'); } })
				]) : null
			]));
			return;
		}

		var body = [this.renderPackage, this.isGift() ? this.renderGift : this.renderWhen, this.renderInfo, this.renderConfirm][this.step].call(this);
		var canNext = this.done(this.step) || (this.step === 1 && this.isGift()) || this.step === 2 || this.step === 3;
		var head = h('div', { class: 'stm-head' }, [
			this.step > 0 ? h('button', { class: 'stm-back', type: 'button', 'aria-label': 'Pasul anterior', html: '&#8249;', onclick: function () { self.go(self.step - 1); } }) : null,
			h('h3', { text: this.steps()[this.step].label })
		]);
		var foot = h('div', { class: 'stm-foot' }, [
			this.step === 3 ? h('span', { class: 'stm-foot-note', text: this.isGift() ? 'Detaliile de plată vin pe email.' : 'Plata se face la fața locului.' }) : null,
			this.step > 0 ? h('button', { class: 'stm-btn stm-btn-ghost', type: 'button', text: 'Înapoi', onclick: function () { self.go(self.step - 1); } }) : null,
			h('button', {
				class: 'stm-btn', type: 'button', disabled: !canNext || this.busy,
				text: this.step === 3 ? (this.busy ? 'Se trimite…' : (this.isGift() ? 'Comandă cardul cadou' : 'Trimite rezervarea')) : 'Continuă',
				onclick: function () { self.next(); }
			})
		]);
		this.el.className = 'stm-card' + (this.isGift() ? ' is-gift' : '');
		this.el.appendChild(this.renderSide());
		this.el.appendChild(h('div', { class: 'stm-main' }, [
			head,
			h('div', { class: 'stm-body' }, [this.alert ? h('div', { class: 'stm-alert', role: 'alert', text: this.alert }) : null, body]),
			foot
		]));
	};

	App.prototype.renderSide = function () {
		var self = this;
		var items = this.steps().map(function (st, i) {
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
			h('p', { class: 'stm-side-title', text: this.isGift() ? 'Card cadou' : 'Rezervare' }),
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
		var gift = this.isGift(), canBook = this.bookable();
		var choice = this.giftOn ? h('div', { class: 'stm-mode', role: 'radiogroup', 'aria-label': 'Pentru cine este ședința' }, [
			h('button', { type: 'button', role: 'radio', 'aria-checked': gift ? 'false' : 'true', disabled: !canBook, class: 'stm-mode-opt' + (gift ? '' : ' is-selected'), onclick: function () { self.setMode('book'); } }, [
				h('span', { class: 'stm-mode-icon', html: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>' }),
				h('span', { class: 'stm-mode-text' }, [h('strong', { text: 'Rezerv pentru noi' }), h('span', { text: canBook ? 'alegi acum ziua și ora' : 'nu mai sunt ore libere momentan' })])
			]),
			h('button', { type: 'button', role: 'radio', 'aria-checked': gift ? 'true' : 'false', class: 'stm-mode-opt' + (gift ? ' is-selected' : ''), onclick: function () { self.setMode('gift'); } }, [
				h('span', { class: 'stm-mode-icon', html: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="9.5" width="17" height="11" rx="1.5"/><path d="M2.5 6.5h19v3h-19zM12 6.5v14M12 6.5C10.5 3 6.5 3 7 5.4c.3 1.4 3 1.1 5 1.1zm0 0c1.5-3.5 5.5-3.5 5-1.1-.3 1.4-3 1.1-5 1.1z"/></svg>' }),
				h('span', { class: 'stm-mode-text' }, [h('strong', { text: this.giftTitle() }), h('span', { text: 'card personalizat, primit pe email' })])
			]),
			this.cfg.model ? h('figure', { class: 'stm-mode-model' }, [this.modelImg('', '150px'), h('figcaption', { text: 'Așa arată cardul cadou' })]) : null
		]) : null;
		var list = gift ? t.packages.filter(priced) : t.packages;
		var packages = h('div', { class: 'stm-packages', role: 'radiogroup' }, list.map(function (p) {
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
		var people = t.max_people > 1 && !gift ? h('div', null, [
			h('label', { class: 'stm-label', text: 'Câte persoane apar în poze?' }),
			h('div', { class: 'stm-stepper' }, [
				h('button', { type: 'button', text: '−', disabled: s.people <= 1, 'aria-label': 'Mai puține', onclick: function () { s.people = Math.max(1, s.people - 1); self.render(); } }),
				h('output', { text: String(s.people) }),
				h('button', { type: 'button', text: '+', disabled: s.people >= t.max_people, 'aria-label': 'Mai multe', onclick: function () { s.people = Math.min(t.max_people, s.people + 1); self.render(); } })
			]),
			h('p', { class: 'stm-hint', text: 'Maximum ' + t.max_people + ' persoane la o ședință.' })
		]) : null;
		return h('div', null, [intro, desc, choice,
			h('label', { class: 'stm-label' }, [h('span', { class: 'req', text: '*' }), gift ? 'Alege pachetul pe care îl oferi:' : 'Alege pachetul:']), packages, people,
			gift ? h('p', { class: 'stm-hint', text: 'Cel care primește cardul își alege singur ziua și ora, cu codul de pe card.' }) : null]);
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

	App.prototype.renderGift = function () {
		var self = this, g = this.gift, e = this.errors, occ = this.cfg.occasions || ['Cu drag'], id = this.uid;
		function field(key, label, input, wide, required, hint) {
			input.id = id + '-' + key;
			return h('div', { class: 'stm-field' + (wide ? ' is-wide' : '') + (e[key] ? ' has-error' : '') }, [
				h('label', { class: 'stm-label', for: input.id }, [required ? h('span', { class: 'req', text: '*' }) : null, label]),
				input,
				hint ? h('p', { class: 'stm-hint', text: hint }) : null,
				e[key] ? h('div', { class: 'stm-error', text: e[key] }) : null
			]);
		}
		function input(prop, attrs, key) {
			var area = attrs && attrs.rows;
			var el = h(area ? 'textarea' : 'input', Object.assign({
				class: 'stm-input', type: area ? null : 'text', autocomplete: 'off',
				oninput: function (ev) {
					g[prop] = ev.target.value;
					if (key && e[key]) {
						delete e[key];
						var wrap = ev.target.closest('.stm-field'), msg = wrap && wrap.querySelector('.stm-error');
						if (wrap) wrap.classList.remove('has-error');
						if (msg) msg.remove();
					}
					if (prop === 'message') { var c = self.el.querySelector('.stm-count'); if (c) c.textContent = (150 - g.message.length) + ' caractere rămase'; }
					if (prop === 'remail') { var d = self.el.querySelector('.stm-sendat'); if (d) d.hidden = !g.remail.trim(); }
					if (prop === 'to' || prop === 'from') self.updateNext();
				}
			}, attrs || {}));
			el.value = g[prop];
			return el;
		}
		var model = this.cfg.model ? h('figure', { class: 'stm-gift-model is-wide' }, [
			this.modelImg('', '(max-width: 720px) 100vw, 600px'),
			h('figcaption', { text: 'Așa arată cardul. Numele, mesajul și codul tău apar pe el; îl primești pe email ca imagine și PDF, gata de trimis sau de printat.' })
		]) : null;
		var sel = h('select', { class: 'stm-input', onchange: function (ev) {
			g.occasion = ev.target.value;
			// Cardul de Crăciun are alt șablon: modelul se schimbă odată cu ocazia.
			var img = self.el.querySelector('.stm-gift-model img'), fresh = self.modelImg('', '(max-width: 720px) 100vw, 600px');
			if (img && fresh && img.getAttribute('src') !== fresh.getAttribute('src')) img.parentNode.replaceChild(fresh, img);
		} }, occ.map(function (o) {
			var op = h('option', { value: o, text: o }); op.selected = o === g.occasion; return op;
		}));
		var send = field('gsend', 'Trimite-l pe data de', input('sendAt', { type: 'date', min: today(1), max: this.lastDay() || null }, 'gsend'), false, false, 'Gol = imediat ce e gata cardul.');
		send.classList.add('stm-sendat');
		send.hidden = !g.remail.trim();
		return h('form', { class: 'stm-grid', novalidate: true, onsubmit: function (ev) { ev.preventDefault(); self.next(); } }, [
			model,
			field('gto', 'Pentru (numele de pe card)', input('to', { maxlength: 30, placeholder: 'ex. Familia Ionescu' }, 'gto'), false, true),
			field('gfrom', 'Din partea', input('from', { maxlength: 30, placeholder: 'ex. Andrei și Maria' }, 'gfrom'), false, true),
			field('gocc', 'Ocazia', sel, true, false),
			field('gmsg', 'Mesaj pe card (opțional)', input('message', { rows: 2, maxlength: 150, placeholder: 'Un gând scurt, apare pe card' }), true, false),
			h('p', { class: 'stm-hint stm-count stm-wide-row', text: (150 - g.message.length) + ' caractere rămase' }),
			field('gremail', 'Emailul celui care primește (opțional)', input('remail', { type: 'email', placeholder: 'nume@exemplu.ro' }, 'gremail'), false, false, 'Dacă îl scrii, îi trimitem noi cardul.'),
			send,
			h('input', { type: 'submit', hidden: true })
		]);
	};
	App.prototype.updateNext = function () {
		var btn = this.el.querySelector('.stm-foot .stm-btn:last-child');
		if (btn && this.step < 3) btn.disabled = !(this.done(this.step) || (this.step === 1 && this.isGift()) || this.step === 2);
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
				id: self.uid + '-' + key,
				class: 'stm-input', type: type === 'textarea' ? null : type, placeholder: placeholder || '', rows: type === 'textarea' ? 3 : null,
				inputmode: type === 'tel' ? 'tel' : null,
				autocomplete: { first: 'given-name', last: 'family-name', email: 'email', phone: 'tel' }[key] || 'off',
				oninput: function (ev) { s[key] = ev.target.value; delete e[key]; clear(ev); }
			});
			input.value = s[key];
			return h('div', { class: 'stm-field' + (wide ? ' is-wide' : '') + (e[key] ? ' has-error' : '') }, [
				h('label', { class: 'stm-label', for: input.id }, [required ? h('span', { class: 'req', text: '*' }) : null, label]),
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
			field('email', this.isGift() ? 'Email (aici primești cardul)' : 'Email', 'email', false, true, 'nume@exemplu.ro'),
			field('phone', 'Telefon', 'tel', false, true, '07xx xxx xxx'),
			this.isGift() ? null : field('notes', 'Observații (opțional)', 'textarea', true, false, 'Ex: venim cu un bebeluș de 8 luni și cu câinele'),
			h('label', { class: 'stm-hp', 'aria-hidden': 'true' }, ['Website', this.hp]),
			consent,
			h('input', { type: 'submit', hidden: true })
		]);
	};

	App.prototype.summaryRows = function () {
		var t = this.theme, s = this.sel, p = this.pkg(), g = this.gift;
		if (this.isGift()) {
			return [
				['Cadou', t.title],
				['Pachet', p ? p.name + (p.price ? ' · ' + priceLabel(p.price) : '') : ''],
				['Pentru', g.to.trim()],
				['Din partea', g.from.trim()],
				['Ocazia', g.occasion],
				['Mesaj', g.message.trim()],
				['Trimitere', g.remail.trim() ? 'pe email la ' + g.remail.trim() + (g.sendAt ? ', pe ' + this.dayLabel(g.sendAt) : ', odată cu tine') : 'doar ție, îl dai tu mai departe'],
				['Comandat de', s.first.trim() + ' ' + s.last.trim()],
				['Contact', s.email.trim() + ' · ' + s.phone.trim()]
			].filter(function (r) { return r[1]; });
		}
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
		var couponBox = hasPrice && !this.isGift() ? h('div', { class: 'stm-coupon' }, [
			h('label', { class: 'stm-label', text: 'Ai un cod de reducere sau un card cadou?' }),
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
			h('p', { class: 'stm-hint', text: !this.isGift()
				? 'Verifică datele și apasă „Trimite rezervarea”. Primești un email cu toate detaliile.'
				: (this.cfg.giftInstant ? 'Verifică datele și apasă „Comandă cardul cadou”. Cardul ajunge pe email în câteva minute, împreună cu detaliile de plată.' : 'Verifică datele și apasă „Comandă cardul cadou”. Primești pe email detaliile de plată; cardul pleacă imediat ce confirmăm plata.') })
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

	App.prototype.renderGiftSuccess = function () {
		var r = this.result || {};
		return h('div', { class: 'stm-success' }, [
			h('div', { class: 'stm-success-icon' }),
			h('h3', { text: r.issued ? 'Cardul cadou este gata!' : 'Comanda cardului cadou a fost trimisă!' }),
			h('p', { text: r.message || '' }),
			r.image ? h('a', { class: 'stm-gift-card', href: r.preview || r.image, target: '_blank', rel: 'noopener' }, [
				h('img', { src: r.image, alt: 'Cardul cadou ' + (r.code || '') + ' pentru ' + this.gift.to.trim(), width: 2550, height: 1050, loading: 'lazy', decoding: 'async' })
			]) : null,
			h('div', { class: 'stm-summary' }, [
				h('div', { class: 'stm-summary-row' }, [h('span', { text: 'Codul cardului' }), h('strong', { text: r.code || '' })]),
				h('div', { class: 'stm-summary-row' }, [h('span', { text: 'Valoare' }), h('strong', { text: r.value || '' })]),
				h('div', { class: 'stm-summary-row' }, [h('span', { text: 'Pentru' }), h('strong', { text: this.gift.to.trim() })])
			]),
			r.payment ? h('div', { class: 'stm-gift-pay' }, [h('strong', { text: 'Cum plătești' }), h('p', { html: r.payment })]) : null,
			r.preview ? h('div', { class: 'stm-success-actions' }, [h('a', { class: 'stm-btn', href: r.preview, target: '_blank', rel: 'noopener', text: 'Descarcă cardul (PDF)' })]) : null
		]);
	};

	/* ---------- Montare ---------- */

	function mount(root) {
		var cfg = {};
		try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
		var id = parseInt(root.getAttribute('data-theme'), 10) || 0;
		// „_” ocolește orice cache (pagina poate fi servită din LiteSpeed Cache, programul nu).
		api(root, '/themes', (id ? 'id=' + id + '&' : '') + '_=' + Date.now())
			.then(function (data) {
				root.innerHTML = '';
				var themes = data.themes || [];
				// Setările de card cadou vin la zi de la server (pe un site secundar, de pe site-ul principal).
				if (data.gift) { cfg.gift = !!data.gift.on; cfg.giftInstant = !!data.gift.instant; }
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
