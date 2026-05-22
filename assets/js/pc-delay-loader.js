/**
 * Prime Cache — Delay JS Loader v2
 *
 * Delays JavaScript execution until user interaction, then restores
 * scripts in correct order with jQuery/addEventListener compatibility.
 *
 * Key design: click events are NEVER prevented — links always work.
 * Only mousemove/scroll/keydown/touch trigger script loading.
 */
(function () {
	'use strict';

	var DELAY_TYPE = 'pc-delay/javascript';
	var ATTR_SRC = 'data-pc-src';
	var ATTR_TYPE = 'data-pc-type';
	var timeout = window.pcDelayTimeout || 0;

	var triggered = false;
	var loaded = false;
	var domReady = false;
	var firedFauxDom = false;
	var allJQueries = [];
	var savedListeners = [];
	var lastBreath = Date.now();

	var origAdd = EventTarget.prototype.addEventListener;
	var origRemove = EventTarget.prototype.removeEventListener;

	// Trigger events — clicks are intentionally excluded.
	// Links must always work immediately.
	var triggerEvents = [
		'keydown', 'mousedown', 'mousemove', 'touchstart',
		'touchend', 'wheel', 'scroll'
	];

	var attrEvents = [
		'onclick', 'onsubmit', 'onfocus', 'onblur',
		'onmousedown', 'onmouseenter', 'onmouseleave',
		'onmouseover', 'onmouseout', 'onscroll',
		'ondblclick', 'oncontextmenu'
	];

	function isLifecycle(type, target) {
		return (
			(target === document && type === 'DOMContentLoaded') ||
			(target === document && type === 'readystatechange') ||
			(target === window && type === 'DOMContentLoaded') ||
			(target === window && type === 'load') ||
			(target === window && type === 'pageshow')
		);
	}

	// ── addEventListener wrapping ────────────────────────────
	function wrapListeners() {
		EventTarget.prototype.addEventListener = function (type, fn, opts) {
			if (loaded) return origAdd.call(this, type, fn, opts);
			if (opts && opts._pc) return origAdd.call(this, type, fn, opts);
			if (type.indexOf('pc-') === 0) return origAdd.call(this, type, fn, opts);
			if (isLifecycle(type, this)) {
				savedListeners.push({ t: this, type: type, fn: fn, opts: opts });
				return;
			}
			origAdd.call(this, type, fn, opts);
		};
		EventTarget.prototype.removeEventListener = function (type, fn, opts) {
			origRemove.call(this, type, fn, opts);
			savedListeners = savedListeners.filter(function (e) {
				return !(e.t === this && e.type === type && e.fn === fn);
			}.bind(this));
		};
	}

	// ── jQuery patching ─────────────────────────────────────
	function patchJQuery(jq) {
		if (!jq || !jq.fn || jq.fn._pcPatched) return;
		jq.fn._pcPatched = true;
		allJQueries.push(jq);

		var readyQueue = [];

		jq.fn.ready = jq.fn.init.prototype.ready = function (fn) {
			if (typeof fn !== 'function') return this;
			if (firedFauxDom) {
				setTimeout(function () { fn.call(document, jq); });
			} else {
				readyQueue.push(fn);
			}
			return this;
		};

		origAdd.call(window, 'pc-DOMContentLoaded', function () {
			var q = readyQueue.slice();
			readyQueue = [];
			q.forEach(function (fn) {
				try { fn.call(document, jq); } catch (e) { console.error(e); }
			});
		}, { _pc: true });

		var origOn = jq.fn.on;
		var origOne = jq.fn.one;

		function rewriteLoad(events) {
			if (loaded) return events;
			return events.split(' ').map(function (e) {
				return (e === 'load' || e.indexOf('load.') === 0) ? 'pc-jquery-load' : e;
			}).join(' ');
		}

		jq.fn.on = function (events) {
			if (typeof events === 'string' && this[0] === window) {
				arguments[0] = rewriteLoad(events);
			}
			return origOn.apply(this, arguments);
		};
		jq.fn.one = function (events) {
			if (typeof events === 'string' && this[0] === window) {
				arguments[0] = rewriteLoad(events);
			}
			return origOne.apply(this, arguments);
		};
	}

	var _jq = window.jQuery;
	try {
		Object.defineProperty(window, 'jQuery', {
			get: function () { return _jq; },
			set: function (v) { _jq = v; patchJQuery(v); },
			configurable: true
		});
		Object.defineProperty(window, '$', {
			get: function () { return _jq; },
			set: function (v) { _jq = v; patchJQuery(v); },
			configurable: true
		});
	} catch (e) {}

	// ── readyState spoofing ─────────────────────────────────
	var pcReadyState = 'loading';
	try {
		Object.defineProperty(document, 'readyState', {
			get: function () { return pcReadyState; },
			set: function (v) { pcReadyState = v; },
			configurable: true
		});
	} catch (e) {}

	function wrapSetter(obj, prop) {
		var val = obj[prop];
		try {
			Object.defineProperty(obj, prop, {
				get: function () { return val; },
				set: function (v) { val = v; },
				configurable: true
			});
		} catch (e) {}
	}
	wrapSetter(document, 'onreadystatechange');
	wrapSetter(window, 'onload');
	wrapSetter(window, 'onpageshow');

	// ── Inline handler interception ─────────────────────────
	var observer;
	function watchInlineHandlers() {
		if (!window.MutationObserver) return;
		observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (m) {
				if (m.type !== 'childList') return;
				m.addedNodes.forEach(function (node) {
					if (node.nodeType !== 1) return;
					disableInline(node);
					var ch = node.querySelectorAll ? node.querySelectorAll('*') : [];
					for (var i = 0; i < ch.length; i++) disableInline(ch[i]);
				});
			});
		});
		observer.observe(document.documentElement, { childList: true, subtree: true });
	}
	function disableInline(el) {
		if (loaded) return;
		attrEvents.forEach(function (attr) {
			if (el.hasAttribute(attr)) {
				el.setAttribute('data-pc-' + attr, el.getAttribute(attr));
				el.setAttribute(attr, 'return false');
			}
		});
	}
	function restoreInline() {
		attrEvents.forEach(function (attr) {
			var els = document.querySelectorAll('[data-pc-' + attr + ']');
			for (var i = 0; i < els.length; i++) {
				els[i].setAttribute(attr, els[i].getAttribute('data-pc-' + attr));
				els[i].removeAttribute('data-pc-' + attr);
			}
		});
	}

	// ── document.write patch ────────────────────────────────
	var origWrite = document.write;
	var origWriteln = document.writeln;
	function patchDocWrite() {
		document.write = document.writeln = function (html) {
			var script = document.currentScript;
			if (!script || !script.parentElement) return;
			var frag = document.createRange().createContextualFragment(html);
			script.parentElement.insertBefore(frag, script.nextSibling);
		};
	}

	// ── Trigger mechanism ───────────────────────────────────
	var firstMouse = true;
	function onTrigger(e) {
		if (e.type === 'mousemove' && firstMouse) { firstMouse = false; return; }
		triggered = true;
		teardownTriggers();
	}
	function setupTriggers() {
		triggerEvents.forEach(function (ev) {
			origAdd.call(window, ev, onTrigger, { passive: true, _pc: true });
		});
		origAdd.call(document, 'visibilitychange', onTrigger, { _pc: true });
	}
	function teardownTriggers() {
		triggerEvents.forEach(function (ev) {
			origRemove.call(window, ev, onTrigger, true);
		});
		origRemove.call(document, 'visibilitychange', onTrigger);
	}

	// ── Breathing ───────────────────────────────────────────
	function breathe() {
		if (Date.now() - lastBreath < 45) return Promise.resolve();
		lastBreath = Date.now();
		return document.hidden
			? new Promise(function (r) { setTimeout(r); })
			: new Promise(function (r) { requestAnimationFrame(r); });
	}

	// ── Wait helpers ────────────────────────────────────────
	function waitFor(check) {
		return new Promise(function (resolve) {
			if (check()) return resolve();
			var id = setInterval(function () {
				if (check()) { clearInterval(id); resolve(); }
			}, 50);
		});
	}

	// ── Script execution ────────────────────────────────────
	function getGroups() {
		var normal = [], defer = [], async = [];
		var all = document.querySelectorAll('script[type="' + DELAY_TYPE + '"]');
		for (var i = 0; i < all.length; i++) {
			var s = all[i];
			if (s.hasAttribute(ATTR_SRC)) {
				if (s.hasAttribute('async')) async.push(s);
				else if (s.hasAttribute('defer') || s.getAttribute(ATTR_TYPE) === 'module') defer.push(s);
				else normal.push(s);
			} else {
				normal.push(s);
			}
		}
		return { normal: normal, defer: defer, async: async };
	}

	function preload(groups) {
		var all = groups.normal.concat(groups.defer, groups.async);
		all.forEach(function (s, i) {
			var src = s.getAttribute(ATTR_SRC);
			if (!src) return;
			var link = document.createElement('link');
			link.rel = 'preload';
			link.as = 'script';
			link.href = src;
			if (i === 0) link.fetchPriority = 'high';
			link.setAttribute('data-pc-preload', '1');
			document.head.appendChild(link);
		});
	}

	function exec(el) {
		return new Promise(function (resolve) {
			var neo = document.createElement('script');
			for (var i = 0; i < el.attributes.length; i++) {
				var a = el.attributes[i];
				if (a.name === 'type') continue;
				if (a.name === ATTR_SRC) { neo.src = a.value; continue; }
				if (a.name === ATTR_TYPE) { neo.type = a.value; continue; }
				if (a.name === 'data-pc-delayed') continue;
				neo.setAttribute(a.name, a.value);
			}
			if (neo.src) {
				neo.addEventListener('load', done, { _pc: true });
				neo.addEventListener('error', done, { _pc: true });
				// Safety: resolve after 5s even if load/error never fires.
				setTimeout(done, 5000);
			} else {
				neo.textContent = el.textContent;
			}
			if (el.parentNode) {
				el.parentNode.replaceChild(neo, el);
			}
			if (!neo.src) done();
			var resolved = false;
			function done() { if (!resolved) { resolved = true; resolve(); } }
		});
	}

	async function execGroup(scripts) {
		for (var i = 0; i < scripts.length; i++) {
			await breathe();
			try { await exec(scripts[i]); } catch (e) { /* continue on error */ }
		}
	}

	// ── Faux lifecycle events ───────────────────────────────
	function flushListeners(target, type) {
		var remaining = [];
		savedListeners.forEach(function (entry) {
			if (entry.t === target && entry.type === type) {
				origAdd.call(entry.t, 'pc-' + type, entry.fn, entry.opts);
			} else {
				remaining.push(entry);
			}
		});
		savedListeners = remaining;
	}

	function fireFauxDom() {
		firedFauxDom = true;
		try { document.readyState = 'interactive'; } catch (e) {}
		flushListeners(document, 'readystatechange');
		document.dispatchEvent(new Event('pc-readystatechange'));
		if (document.onreadystatechange) try { document.onreadystatechange(); } catch (e) {}
		flushListeners(document, 'DOMContentLoaded');
		document.dispatchEvent(new Event('pc-DOMContentLoaded'));
		flushListeners(window, 'DOMContentLoaded');
		window.dispatchEvent(new Event('pc-DOMContentLoaded'));
	}

	function fireFauxLoad() {
		try { document.readyState = 'complete'; } catch (e) {}
		flushListeners(document, 'readystatechange');
		document.dispatchEvent(new Event('pc-readystatechange'));
		flushListeners(window, 'load');
		window.dispatchEvent(new Event('pc-load'));
		if (window.onload) try { window.onload(); } catch (e) {}
		allJQueries.forEach(function (jq) {
			try { jq(window).trigger('pc-jquery-load'); } catch (e) {}
		});
		flushListeners(window, 'pageshow');
		window.dispatchEvent(new Event('pc-pageshow'));
		if (window.onpageshow) try { window.onpageshow({ persisted: false }); } catch (e) {}
	}

	// ── Cleanup ─────────────────────────────────────────────
	function cleanup() {
		loaded = true;
		EventTarget.prototype.addEventListener = origAdd;
		EventTarget.prototype.removeEventListener = origRemove;
		savedListeners.forEach(function (e) {
			origAdd.call(e.t, e.type, e.fn, e.opts);
		});
		savedListeners = [];
		var pls = document.querySelectorAll('link[data-pc-preload]');
		for (var i = 0; i < pls.length; i++) pls[i].remove();
		document.write = origWrite;
		document.writeln = origWriteln;
		if (observer) observer.disconnect();
		try {
			Object.defineProperty(document, 'readyState', {
				value: 'complete', writable: false, configurable: true
			});
		} catch (e) {}
		try {
			Object.defineProperty(window, 'jQuery', { value: _jq, writable: true, configurable: true });
			Object.defineProperty(window, '$', { value: _jq, writable: true, configurable: true });
		} catch (e) {}
	}

	// ── MAIN ────────────────────────────────────────────────
	async function main() {
		wrapListeners();
		watchInlineHandlers();
		setupTriggers();

		// Auto-trigger via timeout. timeout === 0 means "wait for interaction only" —
		// no fallback fires. To guarantee scripts eventually run regardless of
		// interaction, configure a non-zero "Delay Timeout (ms)" in the admin UI.
		if (timeout > 0) setTimeout(function () { triggered = true; teardownTriggers(); }, timeout);

		// Wait for DOM ready.
		if (document.readyState === 'loading' || pcReadyState === 'loading') {
			await new Promise(function (r) {
				origAdd.call(document, 'DOMContentLoaded', function () { domReady = true; r(); }, { _pc: true });
				// Fallback if DOMContentLoaded already fired.
				setTimeout(function () { domReady = true; r(); }, 100);
			});
		} else {
			domReady = true;
		}

		// Wait for user interaction.
		await waitFor(function () { return triggered; });

		patchDocWrite();

		var groups = getGroups();
		preload(groups);

		await execGroup(groups.normal);
		await execGroup(groups.defer);
		await execGroup(groups.async);

		fireFauxDom();
		await breathe();

		// Wait for real window load.
		await new Promise(function (resolve) {
			if (document.readyState === 'complete' || pcReadyState === 'complete') return resolve();
			origAdd.call(window, 'load', resolve, { once: true, _pc: true });
			setTimeout(resolve, 5000); // Safety timeout.
		});

		fireFauxLoad();
		await breathe();

		restoreInline();
		cleanup();
		window.dispatchEvent(new Event('pc-allScriptsLoaded'));
	}

	main().catch(function (e) { console.error('PC Delay:', e); });
})();
