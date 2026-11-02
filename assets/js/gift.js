/* Sterea Mini-Ședințe – formular de comandă card cadou (fără dependențe). */
(function () {
	'use strict';

	function h(tag, attrs, children) {
		var el = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'class') el.className = v;
			else if (k === 'text') el.textContent = v;
			else if (k === 'html') el.innerHTML = v; // Doar HTML filtrat pe server (wp_kses_post).
			else if (k.indexOf('on') === 0) el.addEventListener(k.slice(2), v);
			else if (k === 'disabled' || k === 'checked' || k === 'hidden' || k === 'required') el[k] = !!v;
			else el.setAttribute(k, v === true ? '' : v);
		});
		[].concat(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) return;
			el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return el;
	}

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

	function money(p) {
		p = String(p || '').trim();
		return /^\d+([.,]\d+)?$/.test(p) ? p + ' lei' : p;
	}

	function Gift(root, cfg, themes) {
		this.root = root;
		this.cfg = cfg;
		this.themes = themes.filter(function (t) { return t.packages.some(function (p) { return /^\d/.test(String(p.price || '').trim()); }); });
		var first = this.themes[0];
		this.v = {
			kind: first ? 'package' : 'amount',
			theme: first ? first.id : 0,
			pkg: first && first.packages.length ? first.packages[0].id : null,
			amount: '',
			occasion: cfg.occasions && cfg.occasions.length ? cfg.occasions[0] : 'Cu drag',
			to: '', from: '', message: '',
			buyer_name: '', buyer_email: '', buyer_phone: '',
			recipient_email: '', send_at: '', consent: false, website: ''
		};
		this.errors = {};
		this.alert = '';
		this.busy = false;
		this.done = null;
		this.render();
	}

	Gift.prototype.theme = function () {
		var id = this.v.theme;
		return this.themes.filter(function (t) { return t.id === id; })[0] || null;
	};

	Gift.prototype.field = function (key, label, input, hint) {
		var err = this.errors[key];
		return h('div', { class: 'stm-field' + (err ? ' has-error' : '') }, [
			h('label', { class: 'stm-label', text: label }),
			input,
			hint ? h('div', { class: 'stm-hint', text: hint }) : null,
			err ? h('div', { class: 'stm-error', text: err }) : null
		]);
	};

	Gift.prototype.input = function (key, attrs) {
		var self = this, v = this.v;
		var el = h('input', Object.assign({ class: 'stm-input', type: 'text', oninput: function (ev) { v[key] = ev.target.value; } }, attrs || {}));
		el.value = v[key] || '';
		return el;
	};

	Gift.prototype.render = function () {
		var self = this, v = this.v, root = this.root;
		root.innerHTML = '';
		root.appendChild(h('div', { class: 'stm-gift-form' }, [this.done ? this.renderDone() : this.renderForm()]));
	};

	Gift.prototype.renderForm = function () {
		var self = this, v = this.v, t = this.theme(), cfg = this.cfg;
		var total = v.kind === 'package' && t ? (t.packages.filter(function (p) { return p.id === v.pkg; })[0] || {}).price : v.amount;

		var kindRow = h('div', { class: 'stm-gift-kind' }, [
			this.themes.length ? h('label', { class: 'stm-gift-choice' + (v.kind === 'package' ? ' is-on' : '') }, [
				h('input', { type: 'radio', name: 'stm-kind', checked: v.kind === 'package', onchange: function () { v.kind = 'package'; self.render(); } }), ' O ședință dintr-un eveniment']) : null,
			h('label', { class: 'stm-gift-choice' + (v.kind === 'amount' ? ' is-on' : '') }, [
				h('input', { type: 'radio', name: 'stm-kind', checked: v.kind === 'amount', onchange: function () { v.kind = 'amount'; self.render(); } }), ' O sumă, pentru orice ședință'])
		]);

		var what;
		if (v.kind === 'package' && t) {
			var themeSel = h('select', { class: 'stm-input', onchange: function (ev) { v.theme = +ev.target.value; var nt = self.theme(); v.pkg = nt && nt.packages.length ? nt.packages[0].id : null; self.render(); } },
				this.themes.map(function (th) { var o = h('option', { value: th.id, text: th.title }); o.selected = th.id === v.theme; return o; }));
			var pkgs = h('div', { class: 'stm-packages' }, t.packages.filter(function (p) { return /^\d/.test(String(p.price || '').trim()); }).map(function (p) {
				return h('button', { type: 'button', class: 'stm-package' + (p.id === v.pkg ? ' is-selected' : ''), onclick: function () { v.pkg = p.id; self.render(); } }, [
					h('span', { class: 'stm-radio' }),
					h('span', {}, [h('span', { class: 'stm-package-name', text: p.name }), p.desc ? h('span', { class: 'stm-package-desc', text: p.desc }) : null]),
					h('span', { class: 'stm-package-price', text: money(p.price) })
				]);
			}));
			what = [this.field('theme', 'Evenimentul', themeSel), this.field('package', 'Pachetul', pkgs)];
		} else {
			what = [this.field('amount', 'Suma (lei)', this.input('amount', { type: 'number', min: cfg.min || 1, step: 1, placeholder: String(cfg.min || 300) }), 'Minimum ' + money(cfg.min || 300) + '. Valabil ' + (cfg.months || 12) + ' luni, pentru orice ședință foto.')];
		}

		var occ = h('select', { class: 'stm-input', onchange: function (ev) { v.occasion = ev.target.value; } }, (cfg.occasions || []).map(function (o) { var e = h('option', { value: o, text: o }); e.selected = o === v.occasion; return e; }));
		var msg = h('textarea', { class: 'stm-input', rows: 2, maxlength: 150, placeholder: 'Un gând scurt, apare pe card (opțional)', oninput: function (ev) { v.message = ev.target.value; } });
		msg.value = v.message;
		var consent = h('input', { type: 'checkbox', checked: v.consent, onchange: function (ev) { v.consent = ev.target.checked; } });

		return h('div', {}, [
			h('div', { class: 'stm-main' }, [
				h('h3', { class: 'stm-gift-title', text: 'Ce oferi' }),
				kindRow,
				h('div', { class: 'stm-grid' }, what),
				h('h3', { class: 'stm-gift-title', text: 'Cardul' }),
				h('div', { class: 'stm-grid' }, [
					this.field('occasion', 'Ocazia', occ),
					this.field('to', 'Pentru (numele de pe card)', this.input('to', { maxlength: 40, placeholder: 'ex. Familia Ionescu' })),
					this.field('from', 'Din partea', this.input('from', { maxlength: 40, placeholder: 'ex. Andrei și Maria' })),
					this.field('message', 'Mesaj', msg, (150 - v.message.length) + ' caractere rămase')
				]),
				h('h3', { class: 'stm-gift-title', text: 'Datele tale' }),
				h('div', { class: 'stm-grid' }, [
					this.field('buyer_name', 'Numele tău', this.input('buyer_name', { autocomplete: 'name' })),
					this.field('buyer_phone', 'Telefon', this.input('buyer_phone', { type: 'tel', autocomplete: 'tel' })),
					this.field('buyer_email', 'Emailul tău (aici primești cardul)', this.input('buyer_email', { type: 'email', autocomplete: 'email' })),
					this.field('recipient_email', 'Emailul celui care primește (opțional)', this.input('recipient_email', { type: 'email' }), 'Dacă îl completezi, trimitem noi cardul, imediat sau la data aleasă.'),
					v.recipient_email ? this.field('send_at', 'Trimite-l pe data de', this.input('send_at', { type: 'date' }), 'Gol = imediat după confirmarea plății.') : null
				]),
				h('input', { type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', class: 'stm-hp', 'aria-hidden': 'true', oninput: function (ev) { v.website = ev.target.value; } }),
				h('div', { class: 'stm-field is-wide stm-consent' + (this.errors.consent ? ' has-error' : '') }, [
					h('label', {}, [consent, ' ', h('span', { html: cfg.gdpr || 'Sunt de acord cu prelucrarea datelor.' })]),
					this.errors.consent ? h('div', { class: 'stm-error', text: this.errors.consent }) : null
				]),
				this.alert ? h('div', { class: 'stm-alert', role: 'alert', text: this.alert }) : null,
				h('div', { class: 'stm-gift-total' }, [
					h('span', { text: 'Valoarea cardului: ' }),
					h('strong', { text: total ? money(total) : '–' })
				]),
				h('div', { class: 'stm-actions' }, [
					h('button', { type: 'button', class: 'stm-btn', disabled: this.busy, text: this.busy ? 'Se trimite…' : 'Comandă cardul cadou', onclick: function () { self.submit(); } })
				]),
				h('p', { class: 'stm-hint', text: 'Plata se face prin transfer bancar sau pe WhatsApp; primești instrucțiunile pe email. Cardul (imagine + PDF) pleacă după confirmarea plății.' })
			])
		]);
	};

	Gift.prototype.submit = function () {
		var self = this, v = this.v, t = this.theme();
		this.errors = {};
		this.alert = '';
		if (v.kind === 'package' && !(t && v.pkg !== null)) this.errors.package = 'Alege pachetul.';
		if (v.kind === 'amount' && !(+v.amount >= (this.cfg.min || 1))) this.errors.amount = 'Suma minimă este ' + money(this.cfg.min || 1) + '.';
		if (v.to.trim().length < 2) this.errors.to = 'Scrie numele celui care primește.';
		if (v.from.trim().length < 2) this.errors.from = 'Scrie din partea cui este cadoul.';
		if (v.buyer_name.trim().length < 3) this.errors.buyer_name = 'Scrie numele complet.';
		if (v.buyer_phone.replace(/\D/g, '').length < 9) this.errors.buyer_phone = 'Numărul nu pare corect.';
		if (!/^\S+@\S+\.\S+$/.test(v.buyer_email.trim())) this.errors.buyer_email = 'Adresa de email nu pare corectă.';
		if (v.recipient_email && !/^\S+@\S+\.\S+$/.test(v.recipient_email.trim())) this.errors.recipient_email = 'Adresa nu pare corectă.';
		if (!v.consent) this.errors.consent = 'Bifează acordul.';
		if (Object.keys(this.errors).length) { this.render(); return; }

		this.busy = true;
		this.render();
		api(this.root, '/giftcard', '', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({
			kind: v.kind, theme: v.theme, package: v.pkg, amount: v.amount, occasion: v.occasion, to: v.to.trim(), from: v.from.trim(), message: v.message.trim(),
			buyer_name: v.buyer_name.trim(), buyer_email: v.buyer_email.trim(), buyer_phone: v.buyer_phone.trim(), recipient_email: v.recipient_email.trim(), send_at: v.send_at, consent: v.consent ? 1 : 0, website: v.website
		}) })
			.then(function (res) { self.busy = false; self.done = res; self.render(); window.scrollTo({ top: self.root.getBoundingClientRect().top + window.pageYOffset - 80, behavior: 'smooth' }); })
			.catch(function (err) {
				self.busy = false;
				var map = { stm_gift_theme: 'theme', stm_gift_package: 'package', stm_gift_amount: 'amount', stm_gift_to: 'to', stm_gift_from: 'from', stm_gift_name: 'buyer_name', stm_gift_phone: 'buyer_phone', stm_gift_email: 'buyer_email', stm_gift_remail: 'recipient_email', stm_gift_consent: 'consent' };
				if (map[err.code]) self.errors[map[err.code]] = err.message; else self.alert = err.message;
				self.render();
			});
	};

	Gift.prototype.renderDone = function () {
		var d = this.done;
		return h('div', { class: 'stm-main stm-done' }, [
			h('h3', { class: 'stm-gift-title', text: 'Comanda a fost înregistrată' }),
			h('p', { text: d.message || '' }),
			h('p', {}, ['Codul cardului: ', h('strong', { text: d.code }), ' · ', h('strong', { text: d.value })]),
			d.payment ? h('div', { class: 'stm-gift-pay' }, [h('strong', { text: 'Cum plătești' }), h('p', { html: d.payment })]) : null,
			h('p', { class: 'stm-hint', text: 'Scrie codul cardului la plată. Imediat ce confirmăm plata, cardul ajunge pe email.' })
		]);
	};

	function mount(root) {
		var cfg = {};
		try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
		api(root, '/themes', '')
			.then(function (data) { new Gift(root, cfg, (data && data.themes) || []); })
			.catch(function (err) { root.innerHTML = ''; root.appendChild(h('div', { class: 'stm-alert', role: 'alert', text: err.message })); });
	}

	function init() { [].forEach.call(document.querySelectorAll('.stm-gift[data-api]'), mount); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
