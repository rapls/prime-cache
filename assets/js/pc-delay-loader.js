/**
 * Prime Cache — Delay JS Loader
 *
 * Delays all JavaScript execution until user interaction, then
 * restores scripts in correct order with jQuery compatibility.
 *
 * @since 1.8.0
 */
(function () {
	'use strict';

	// ── Config ──────────────────────────────────────────────
	var DELAY_TYPE = 'pc-delay/javascript';
	var ATTR_SRC = 'data-pc-src';
	var ATTR_TYPE = 'data-pc-type';
	var timeout = window.pcDelayTimeout || 0;

	// ── State ───────────────────────────────────────────────
	var triggered = false;
	var loaded = false;
	var domReady = false;
	var firedFauxDom = false;
	var firedFauxLoad = false;
	var allJQueries = [];
	var savedListeners = [];
	var capturedEvents = [];
	var lastBreath = Date.now();

	// Original prototypes.
	var origAdd = EventTarget.prototype.addEventListener;
	var origRemove = EventTarget.prototype.removeEventListener;

	// ── User interaction events ─────────────────────────────
	var userEvents = [
		'keydown', 'mousedown', 'mousemove', 'touchstart',
		'touchend', 'wheel', 'click', 'scroll'
	];
	var attrEvents = [
		'onclick', 'onsubmit', 'onfocus', 'onblur',
		'onmousedown', 'onmouseenter', 'onmouseleave',
		'onmouseover', 'onmouseout', 'onscroll',
		'ondblclick', 'oncontextmenu'
	];

	// ── Lifecycle event detection ───────────────────────────
	function isLifecycle(type, target) {
		return (
			(target === document && type === 'DOMContentLoaded') ||
			(target === document && type === 'readystatechange') ||
			(target === window && type === 'DOMContentLoaded') ||
			(target === window && type === 'load') ||
			(target === window && type === 'pageshow')
		);
	}

	// ── 1. Wrap addEventListener / removeEventListener ──────
	function wrapListeners() {
		EventTarget.prototype.addEventListener = function (type, fn, opts) {
			if (opts && opts._pc) return origAdd.call(this, type, fn, opts);
			if (loaded) return origAdd.call(this, type, fn, opts);
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

	// ── 2. Patch jQuery ─────────────────────────────────────
	function patchJQuery(jq) {
		if (!jq || !jq.fn || jq.fn._pcPatched) return;
		jq.fn._pcPatched = true;
		allJQueries.push(jq);

		var origReady = jq.fn.ready;
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

		// Flush ready queue on faux DOMContentLoaded.
		window.addEventListener('pc-DOMContentLoaded', function () {
			var q = readyQueue.slice();
			readyQueue = [];
			q.forEach(function (fn) {
				try { fn.call(document, jq); } catch (e) { console.error(e); }
			});
		}, { _pc: true });

		// Patch .on/.one — rewrite 'load' to 'pc-jquery-load' on window.
		var origOn = jq.fn.on;
		var origOne = jq.fn.one;

		function rewriteLoadEvent(events) {
			if (loaded) return events;
			return events.split(' ').map(function (e) {
				return (e === 'load' || e.indexOf('load.') === 0) ? 'pc-jquery-load' : e;
			}).join(' ');
		}

		jq.fn.on = function (events) {
			if (typeof events === 'string' && this[0] === window) {
				arguments[0] = rewriteLoadEvent(events);
			}
			return origOn.apply(this, arguments);
		};
		jq.fn.one = function (events) {
			if (typeof events === 'string' && this[0] === window) {
				arguments[0] = rewriteLoadEvent(events);
			}
			return origOne.apply(this, arguments);
		};
	}

	// Intercept window.jQuery setter.
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

	// ── 3. Spoof document.readyState ────────────────────────
	var pcReadyState = 'loading';
	try {
		Object.defineProperty(document, 'readyState', {
			get: function () { return pcReadyState; },
			set: function (v) { pcReadyState = v; },
			configurable: true
		});
	} catch (e) {}

	// Wrap onreadystatechange / onload / onpageshow setters.
	function wrapSetter(obj, prop) {
		var val = obj[prop];
		obj[prop] = null;
		Object.defineProperty(obj, prop, {
			get: function () { return val; },
			set: function (v) {
				if (loaded) { val = v; }
				else { obj['_pc_' + prop] = val = v; }
			},
			configurable: true
		});
	}
	try { wrapSetter(document, 'onreadystatechange'); } catch (e) {}
	try { wrapSetter(window, 'onload'); } catch (e) {}
	try { wrapSetter(window, 'onpageshow'); } catch (e) {}

	// ── 4. MutationObserver for inline event handlers ───────
	var observer;
	function watchInlineHandlers() {
		observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (m) {
				if (m.type !== 'childList') return;
				m.addedNodes.forEach(function (node) {
					if (node.nodeType !== 1) return;
					disableInlineHandlers(node);
					var children = node.querySelectorAll ? node.querySelectorAll('*') : [];
					for (var i = 0; i < children.length; i++) disableInlineHandlers(children[i]);
				});
			});
		});
		observer.observe(document.documentElement, { childList: true, subtree: true });
	}
	function disableInlineHandlers(el) {
		if (loaded) return;
		attrEvents.forEach(function (attr) {
			if (el.hasAttribute(attr)) {
				el.setAttribute('data-pc-' + attr, el.getAttribute(attr));
				el.setAttribute(attr, 'return false');
			}
		});
	}
	function restoreInlineHandlers() {
		attrEvents.forEach(function (attr) {
			var els = document.querySelectorAll('[data-pc-' + attr + ']');
			for (var i = 0; i < els.length; i++) {
				els[i].setAttribute(attr, els[i].getAttribute('data-pc-' + attr));
				els[i].removeAttribute('data-pc-' + attr);
			}
		});
	}

	// ── 5. document.write patch ─────────────────────────────
	var origWrite = document.write;
	var origWriteln = document.writeln;
	function patchDocWrite() {
		document.write = document.writeln = function (html) {
			var script = document.currentScript;
			if (!script || !script.parentElement) return;
			var range = document.createRange();
			var frag = document.createDocumentFragment();
			range.setStart(frag, 0);
			frag.appendChild(range.createContextualFragment(html));
			script.parentElement.insertBefore(frag, script.nextSibling);
		};
	}

	// ── 6. User event capture ───────────────────────────────
	var firstMouse = true;
	function onUserEvent(e) {
		// Ignore first mousemove (often triggered by browser, not user).
		if (e.type === 'mousemove') {
			if (firstMouse) { firstMouse = false; return; }
		}
		triggered = true;
		capturedEvents.push(e);
		// Prevent default on click during delay.
		if (e.type === 'click') {
			e.preventDefault();
			e.stopPropagation();
		}
	}
	function setupUserListeners() {
		userEvents.forEach(function (ev) {
			window.addEventListener(ev, onUserEvent, { passive: false, capture: true, _pc: true });
		});
	}
	function teardownUserListeners() {
		userEvents.forEach(function (ev) {
			window.removeEventListener(ev, onUserEvent, true);
		});
	}

	// ── 7. Breathing (yield to browser) ─────────────────────
	function breathe() {
		if (Date.now() - lastBreath < 45) return Promise.resolve();
		lastBreath = Date.now();
		return document.hidden
			? new Promise(function (r) { setTimeout(r); })
			: new Promise(function (r) { requestAnimationFrame(r); });
	}

	// ── 8. Wait for trigger ─────────────────────────────────
	function waitForTrigger() {
		return new Promise(function (resolve) {
			if (triggered) return resolve();
			function check() {
				if (triggered) { resolve(); return; }
				requestAnimationFrame(check);
			}
			check();
		});
	}
	function waitForDom() {
		return new Promise(function (resolve) {
			if (domReady) return resolve();
			origAdd.call(document, 'DOMContentLoaded', function () {
				domReady = true;
				resolve();
			}, { _pc: true });
		});
	}

	// ── 9. Script categorization ────────────────────────────
	function getDelayedScripts() {
		var normal = [], defer = [], async = [];
		var scripts = document.querySelectorAll('script[type="' + DELAY_TYPE + '"]');
		for (var i = 0; i < scripts.length; i++) {
			var s = scripts[i];
			if (s.hasAttribute(ATTR_SRC)) {
				if (s.hasAttribute('async') && s.async !== false) async.push(s);
				else if (s.hasAttribute('defer') && s.defer !== false ||
					s.getAttribute(ATTR_TYPE) === 'module') defer.push(s);
				else normal.push(s);
			} else {
				normal.push(s);
			}
		}
		return { normal: normal, defer: defer, async: async };
	}

	// ── 10. Script preloading ───────────────────────────────
	function preloadScripts(groups) {
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

	// ── 11. Execute a single script ─────────────────────────
	function execScript(el) {
		return new Promise(function (resolve) {
			var neo = document.createElement('script');

			// Copy attributes.
			for (var i = 0; i < el.attributes.length; i++) {
				var a = el.attributes[i];
				if (a.name === 'type') continue; // Skip delay type.
				if (a.name === ATTR_SRC) { neo.setAttribute('src', a.value); continue; }
				if (a.name === ATTR_TYPE) { neo.setAttribute('type', a.value); continue; }
				neo.setAttribute(a.name, a.value);
			}

			// If no original type was saved, do NOT set type (let browser default to text/javascript).
			if (!el.hasAttribute(ATTR_TYPE) && !neo.hasAttribute('type')) {
				// Default — no type attribute.
			}

			if (neo.src) {
				neo.addEventListener('load', done, { _pc: true });
				neo.addEventListener('error', done, { _pc: true });
			} else {
				neo.textContent = el.textContent;
			}

			el.parentNode.replaceChild(neo, el);

			if (!neo.src) done();

			function done() { resolve(); }
		});
	}

	// ── 12. Execute script groups in order ───────────────────
	async function execGroup(scripts) {
		for (var i = 0; i < scripts.length; i++) {
			await breathe();
			await execScript(scripts[i]);
		}
	}

	// ── 13. Faux DOMContentLoaded ───────────────────────────
	function fireFauxDom() {
		firedFauxDom = true;
		try { document.readyState = 'interactive'; } catch (e) {}

		// Flush saved readystatechange listeners.
		flushListeners(document, 'readystatechange');
		document.dispatchEvent(new Event('pc-readystatechange'));
		if (document._pc_onreadystatechange) {
			try { document._pc_onreadystatechange(); } catch (e) {}
		}

		// Flush saved DOMContentLoaded listeners.
		flushListeners(document, 'DOMContentLoaded');
		document.dispatchEvent(new Event('pc-DOMContentLoaded'));
		flushListeners(window, 'DOMContentLoaded');
		window.dispatchEvent(new Event('pc-DOMContentLoaded'));
	}

	// ── 14. Faux window load ────────────────────────────────
	function fireFauxLoad() {
		firedFauxLoad = true;
		try { document.readyState = 'complete'; } catch (e) {}

		flushListeners(document, 'readystatechange');
		document.dispatchEvent(new Event('pc-readystatechange'));
		if (document._pc_onreadystatechange) {
			try { document._pc_onreadystatechange(); } catch (e) {}
		}

		flushListeners(window, 'load');
		window.dispatchEvent(new Event('pc-load'));
		if (window._pc_onload) {
			try { window._pc_onload(); } catch (e) {}
		}

		// jQuery load trigger.
		allJQueries.forEach(function (jq) {
			try { jq(window).trigger('pc-jquery-load'); } catch (e) {}
		});

		flushListeners(window, 'pageshow');
		var ps = new Event('pc-pageshow');
		ps.persisted = false;
		window.dispatchEvent(ps);
		if (window._pc_onpageshow) {
			try { window._pc_onpageshow({ persisted: false }); } catch (e) {}
		}
	}

	// ── 15. Flush saved listeners for a target+event ────────
	function flushListeners(target, type) {
		var remaining = [];
		savedListeners.forEach(function (entry) {
			if (entry.t === target && entry.type === type) {
				var evtName = isLifecycle(type, target) ? 'pc-' + type : type;
				origAdd.call(entry.t, evtName, entry.fn, entry.opts);
			} else {
				remaining.push(entry);
			}
		});
		savedListeners = remaining;
	}

	// ── 16. Replay captured user events ─────────────────────
	function replayEvents() {
		capturedEvents.forEach(function (e) {
			try {
				var target = e.target;
				if (target && target.dispatchEvent) {
					target.dispatchEvent(new e.constructor(e.type, e));
				}
			} catch (err) {}
		});
		capturedEvents = [];
	}

	// ── 17. Cleanup ─────────────────────────────────────────
	function cleanup() {
		// Restore real addEventListener.
		EventTarget.prototype.addEventListener = origAdd;
		EventTarget.prototype.removeEventListener = origRemove;

		// Flush any remaining saved listeners.
		savedListeners.forEach(function (entry) {
			origAdd.call(entry.t, entry.type, entry.fn, entry.opts);
		});
		savedListeners = [];

		// Remove preload links.
		var preloads = document.querySelectorAll('link[data-pc-preload]');
		for (var i = 0; i < preloads.length; i++) preloads[i].remove();

		// Restore document.write.
		document.write = origWrite;
		document.writeln = origWriteln;

		// Disconnect observer.
		if (observer) observer.disconnect();

		// Restore readyState.
		try {
			Object.defineProperty(document, 'readyState', {
				value: 'complete',
				writable: false,
				configurable: true
			});
		} catch (e) {}

		// Restore window.jQuery/$.
		try {
			Object.defineProperty(window, 'jQuery', { value: _jq, writable: true, configurable: true });
			Object.defineProperty(window, '$', { value: _jq, writable: true, configurable: true });
		} catch (e) {}
	}

	// ── MAIN ────────────────────────────────────────────────
	async function main() {
		wrapListeners();
		watchInlineHandlers();
		setupUserListeners();

		// Timeout auto-trigger.
		if (timeout > 0) {
			setTimeout(function () { triggered = true; }, timeout);
		}

		// Wait for BOTH user interaction AND real DOMContentLoaded.
		await Promise.all([waitForTrigger(), waitForDom()]);

		// Patch document.write before executing scripts.
		patchDocWrite();

		// Categorize and preload.
		var groups = getDelayedScripts();
		preloadScripts(groups);

		// Execute in order.
		await execGroup(groups.normal);
		await execGroup(groups.defer);
		await execGroup(groups.async);

		// Fire faux lifecycle events.
		fireFauxDom();
		await breathe();

		// Wait for real window load if not yet fired.
		await new Promise(function (resolve) {
			if (document.readyState === 'complete' || pcReadyState === 'complete') {
				return resolve();
			}
			origAdd.call(window, 'load', resolve, { once: true, _pc: true });
		});

		fireFauxLoad();

		// Signal completion.
		loaded = true;
		window.dispatchEvent(new Event('pc-allScriptsLoaded'));

		await breathe();

		// Restore everything.
		restoreInlineHandlers();
		teardownUserListeners();
		cleanup();

		// Replay captured events.
		setTimeout(function () { replayEvents(); }, 50);
	}

	main().catch(function (e) { console.error('PC Delay:', e); });
})();
