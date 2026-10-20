const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(path.resolve(__dirname, '../../src/admin/assets/modal-motion.js'), 'utf8');

function fixture(t, reduced = false) {
    const dom = new JSDOM('<dialog id="first"><button>关闭</button></dialog><dialog id="second"></dialog><div id="overlay" hidden><div role="dialog"></div></div>', { runScripts: 'outside-only' });
    const { window } = dom; const timers = new Map(); let next = 0;
    window.matchMedia = () => ({ matches: reduced });
    window.setTimeout = callback => { timers.set(++next, callback); return next; };
    window.clearTimeout = id => timers.delete(id);
    window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new window.Event('close')); };
    window.eval(source); t.after(() => window.close());
    const $ = selector => window.document.querySelector(selector);
    const ended = (element, property = 'transform', pseudo = '') => {
        const event = new window.Event('transitionend', { bubbles: true });
        Object.defineProperties(event, { propertyName: { value: property }, pseudoElement: { value: pseudo } }); element.dispatchEvent(event);
    };
    return { $, ended, motion: window.oyisoModalMotion, flush: () => { for (const [id, callback] of [...timers]) { timers.delete(id); callback(); } } };
}

test('native dialogs remain modal until their own scale transition finishes and close only once', t => {
    const f = fixture(t); const dialog = f.$('#first'); let closed = 0;
    f.motion.show(dialog); assert.equal(dialog.open, true);
    f.motion.hide(dialog, () => closed++);
    assert.equal(dialog.open, true); assert.equal(dialog.inert, true);
    f.ended(dialog.querySelector('button')); f.ended(dialog, 'opacity'); f.ended(dialog, 'transform', '::backdrop');
    assert.equal(dialog.open, true); assert.equal(closed, 0);
    f.ended(dialog); f.flush();
    assert.equal(dialog.open, false); assert.equal(dialog.inert, false); assert.equal(closed, 1);
});

test('reopening a closing dialog cancels stale cleanup and allows it to close later', t => {
    const f = fixture(t); const dialog = f.$('#first'); let closed = 0;
    f.motion.show(dialog); f.motion.hide(dialog, () => closed++); f.motion.show(dialog);
    f.flush(); f.ended(dialog);
    assert.equal(dialog.open, true); assert.equal(dialog.inert, false); assert.equal(closed, 0);
    f.motion.hide(dialog, () => closed++); f.ended(dialog);
    assert.equal(dialog.open, false); assert.equal(closed, 1);
});

test('nested native dialogs animate and close independently', t => {
    const f = fixture(t); const first = f.$('#first'); const second = f.$('#second');
    f.motion.show(first); f.motion.show(second); f.motion.hide(second); f.ended(first);
    assert.equal(second.open, true); f.ended(second);
    assert.equal(first.open, true); assert.equal(second.open, false);
});

test('overlay dialogs retain their contents through exit and hide after their panel finishes', t => {
    const f = fixture(t); const overlay = f.$('#overlay'); const panel = overlay.querySelector('[role="dialog"]'); let closed = 0;
    f.motion.show(overlay, panel);
    assert.equal(overlay.hidden, false); assert.equal(overlay.getAttribute('aria-hidden'), 'false');
    f.motion.hide(overlay, () => closed++, panel); f.motion.hide(overlay, () => closed++, panel);
    assert.equal(overlay.hidden, false); f.ended(overlay); assert.equal(closed, 0);
    f.ended(panel); f.flush();
    assert.equal(overlay.hidden, true); assert.equal(overlay.style.display, 'none'); assert.equal(overlay.getAttribute('aria-hidden'), 'true'); assert.equal(closed, 1);
});

test('reduced motion closes immediately without leaving the dialog inert', t => {
    const f = fixture(t, true); const dialog = f.$('#first'); let closed = 0;
    f.motion.show(dialog); f.motion.hide(dialog, () => closed++);
    assert.equal(dialog.open, false); assert.equal(dialog.inert, false); assert.equal(closed, 1);
    f.flush(); assert.equal(closed, 1);
});

test('missing transition events fall back to a bounded close instead of trapping the page', t => {
    const f = fixture(t); const dialog = f.$('#first'); let closed = 0;
    f.motion.show(dialog); f.motion.hide(dialog, () => closed++); f.flush(); f.ended(dialog);
    assert.equal(dialog.open, false); assert.equal(dialog.inert, false); assert.equal(closed, 1);
});

test('native dialog fallbacks preserve open and close behavior when showModal is unavailable', t => {
    const f = fixture(t, true); const dialog = f.$('#first'); dialog.showModal = dialog.close = undefined;
    f.motion.show(dialog); assert.equal(dialog.open, true);
    f.motion.hide(dialog); assert.equal(dialog.open, false);
});

test('a native close cancels an unfinished exit so its stale callback cannot move focus later', t => {
    const f = fixture(t); const dialog = f.$('#first'); let closed = 0;
    f.motion.show(dialog); f.motion.hide(dialog, () => closed++); dialog.close(); f.flush();
    assert.equal(dialog.open, false); assert.equal(dialog.inert, false); assert.equal(closed, 0);
});
