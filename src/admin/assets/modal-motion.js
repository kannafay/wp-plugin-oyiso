(() => {
    'use strict';
    const pending = new WeakMap();
    const observed = new WeakSet();
    const native = element => element.tagName === 'DIALOG';
    function cancel(element) {
        pending.get(element)?.(); pending.delete(element);
    }
    function prepare(element, surface) {
        element.classList.add('oyiso-modal-motion');
        if (native(element)) {
            element.classList.add('oyiso-modal-native');
            if (!observed.has(element)) {
                observed.add(element);
                element.addEventListener('close', () => {
                    if (element.open) return;
                    cancel(element); element.inert = false; element.classList.remove('is-open', 'is-closing');
                });
            }
        } else surface.classList.add('oyiso-modal-surface');
    }
    window.oyisoModalMotion = {
        show(element, surface = element) {
            cancel(element); prepare(element, surface);
            element.inert = false; element.classList.remove('is-closing');
            if (native(element)) {
                if (!element.open) { if (element.showModal) element.showModal(); else element.setAttribute('open', ''); }
            } else {
                element.hidden = false; element.style.display = 'flex'; element.setAttribute('aria-hidden', 'false');
            }
            void element.offsetHeight;
            element.classList.add('is-open');
        },
        hide(element, afterClose = () => {}, surface = element) {
            if (element.classList.contains('is-closing')) return;
            cancel(element); prepare(element, surface);
            element.classList.remove('is-open'); element.classList.add('is-closing'); element.inert = true;
            const finish = () => {
                cancel(element);
                if (native(element)) { if (element.close) element.close(); else element.removeAttribute('open'); }
                else { element.hidden = true; element.style.display = 'none'; element.setAttribute('aria-hidden', 'true'); }
                element.inert = false; element.classList.remove('is-closing');
                afterClose();
            };
            if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) { finish(); return; }
            const ended = event => { if (event.target === surface && event.propertyName === 'transform' && !event.pseudoElement) finish(); };
            surface.addEventListener('transitionend', ended);
            const timeout = setTimeout(finish, 250);
            pending.set(element, () => { clearTimeout(timeout); surface.removeEventListener('transitionend', ended); });
        },
    };
})();
