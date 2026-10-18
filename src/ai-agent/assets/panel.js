(() => {
    'use strict';

    const config = window.oyisoAgent;
    const panel = document.getElementById('oyiso-agent-panel');
    const launcher = document.getElementById('oyiso-agent-launcher');
    const wrapper = document.getElementById('oyiso-agent-launcher-wrap');
    const resizer = panel?.querySelector('[data-agent-resize]');
    if (!config || !panel || !launcher || !wrapper || !resizer) return;

    const key = `oyiso-agent:${config.user}:${new URL(config.url).pathname}`;
    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
    const read = suffix => { try { return localStorage.getItem(key + suffix); } catch { return null; } };
    const save = (suffix, value) => { try { localStorage.setItem(key + suffix, value); } catch { /* Storage is optional. */ } };
    let width = Number(read(':width')) || null;
    if (!Number.isFinite(width) || width < 1) width = null;
    let position = null;
    try {
        const saved = JSON.parse(read(':position'));
        if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) {
            position = { x: clamp(saved.x, 0, 1), y: clamp(saved.y, 0, 1), edge: ['left', 'right', 'top', 'bottom'].includes(saved.edge) ? saved.edge : '' };
        }
    } catch { /* Ignore invalid or unavailable browser storage. */ }
    let launcherWidth = 120;
    let launcherHeight = 44;
    let gesture = null;
    let suppressClick = false;
    let clickTimer;

    function viewport() {
        const w = document.documentElement.clientWidth || window.innerWidth;
        const h = window.innerHeight;
        return { w, h, top: clamp(parseFloat(getComputedStyle(document.body).getPropertyValue('--oyiso-agent-bar-height')) || 0, 0, h), gap: window.innerWidth <= 782 ? 12 : 24 };
    }

    function widthBounds() {
        const { w, gap } = viewport();
        const docked = window.innerWidth >= 1280 && !document.body.classList.contains('oyiso-agent-floating') && !document.body.classList.contains('block-editor-page');
        const max = Math.max(1, Math.min(1000, docked ? w - 640 : w - gap * 2));
        return { min: Math.min(360, max), max, defaultWidth: docked ? clamp(w * 0.34, 400, 560) : 480 };
    }

    function applyWidth() {
        const bounds = widthBounds();
        const value = Math.round(clamp(width ?? bounds.defaultWidth, bounds.min, bounds.max));
        document.body.style.setProperty('--oyiso-agent-panel-width', `${value}px`);
        resizer.setAttribute('aria-valuemin', String(bounds.min));
        resizer.setAttribute('aria-valuemax', String(bounds.max));
        resizer.setAttribute('aria-valuenow', String(value));
        resizer.setAttribute('aria-valuetext', `${value} 像素`);
        return value;
    }

    function launcherBounds() {
        if (launcher.offsetWidth) launcherWidth = launcher.offsetWidth;
        if (launcher.offsetHeight) launcherHeight = launcher.offsetHeight;
        const { w, h, top, gap } = viewport();
        return { maxX: Math.max(0, w - launcherWidth), minY: Math.min(top, Math.max(0, h - launcherHeight)), maxY: Math.max(0, h - launcherHeight), gap };
    }

    function placeLauncher() {
        const { maxX, minY, maxY, gap } = launcherBounds();
        let x = position ? position.x * maxX : Math.max(0, maxX - gap);
        let y = position ? minY + position.y * (maxY - minY) : Math.max(minY, maxY - gap);
        if (position?.edge === 'left') x = 0;
        if (position?.edge === 'right') x = maxX;
        if (position?.edge === 'top') y = minY;
        if (position?.edge === 'bottom') y = maxY;
        wrapper.style.left = `${Math.round(x)}px`;
        wrapper.style.top = `${Math.round(y)}px`;
        if (position?.edge) wrapper.dataset.agentEdge = position.edge;
        else delete wrapper.dataset.agentEdge;
        return { x, y };
    }

    function moveLauncher(x, y, snap = null) {
        const { maxX, minY, maxY } = launcherBounds();
        x = clamp(x, 0, maxX);
        y = clamp(y, minY, maxY);
        let edge = '';
        if (snap !== null) {
            const nearest = [['left', x], ['right', maxX - x], ['top', y - minY], ['bottom', maxY - y]].sort((a, b) => a[1] - b[1])[0];
            if (nearest[1] <= snap) edge = nearest[0];
        }
        position = { x: maxX ? x / maxX : 0, y: maxY > minY ? (y - minY) / (maxY - minY) : 0, edge };
        placeLauncher();
    }

    function start(event, type) {
        if (gesture || event.button !== 0 || event.isPrimary === false) return;
        clearTimeout(clickTimer);
        suppressClick = false;
        if (event.pointerType === 'touch') wrapper.classList.remove('is-hovered');
        const point = placeLauncher();
        gesture = { type, id: event.pointerId, target: event.currentTarget, startX: event.clientX, startY: event.clientY, x: point.x, y: point.y, width: applyWidth(), oldWidth: width, oldPosition: position, moved: false };
        try { event.currentTarget.setPointerCapture?.(event.pointerId); } catch { /* Document listeners also handle browsers without pointer capture. */ }
        if (type === 'resize') {
            event.preventDefault();
            document.body.classList.add('oyiso-agent-resizing');
            resizer.focus({ preventScroll: true });
        }
    }

    function finish(event, cancelled = false) {
        if (!gesture || (event && event.pointerId !== gesture.id)) return;
        const active = gesture;
        gesture = null;
        document.body.classList.remove('oyiso-agent-resizing', 'oyiso-agent-dragging');
        wrapper.classList.remove('is-dragging');
        if (cancelled) {
            width = active.oldWidth;
            position = active.oldPosition;
            applyWidth();
            placeLauncher();
        } else if (active.moved) {
            if (active.type === 'launcher') {
                const point = placeLauncher();
                moveLauncher(point.x, point.y, 24);
                save(':position', JSON.stringify(position));
                suppressClick = true;
                clickTimer = setTimeout(() => { suppressClick = false; }, 0);
            } else {
                save(':width', String(width));
            }
        }
        if (active.target.hasPointerCapture?.(active.id)) active.target.releasePointerCapture(active.id);
        window.dispatchEvent(new Event('resize'));
    }

    document.addEventListener('pointermove', event => {
        if (!gesture || event.pointerId !== gesture.id) return;
        const dx = event.clientX - gesture.startX;
        const dy = event.clientY - gesture.startY;
        if (!gesture.moved && Math.hypot(dx, dy) < 5) return;
        gesture.moved = true;
        event.preventDefault();
        if (gesture.type === 'launcher') {
            wrapper.classList.add('is-dragging');
            document.body.classList.add('oyiso-agent-dragging');
            moveLauncher(gesture.x + dx, gesture.y + dy);
        } else {
            const bounds = widthBounds();
            width = clamp(gesture.width - dx, bounds.min, bounds.max);
            applyWidth();
        }
    });
    document.addEventListener('pointerup', event => finish(event));
    document.addEventListener('pointercancel', event => finish(event, true));
    for (const [element, type] of [[launcher, 'launcher'], [resizer, 'resize']]) {
        element.addEventListener('pointerdown', event => start(event, type));
        element.addEventListener('lostpointercapture', event => finish(event, true));
    }
    launcher.addEventListener('click', event => {
        if (!suppressClick) return;
        suppressClick = false;
        event.preventDefault();
        event.stopImmediatePropagation();
    }, true);
    launcher.addEventListener('pointerenter', event => wrapper.classList.toggle('is-hovered', event.pointerType !== 'touch'));
    launcher.addEventListener('pointerleave', () => wrapper.classList.remove('is-hovered'));
    launcher.addEventListener('keydown', event => {
        const delta = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[event.key];
        if (!delta || event.ctrlKey || event.metaKey || event.altKey) return;
        event.preventDefault();
        const step = event.shiftKey ? 40 : 10;
        const point = placeLauncher();
        moveLauncher(point.x + delta[0] * step, point.y + delta[1] * step, 0);
        save(':position', JSON.stringify(position));
    });
    resizer.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || event.ctrlKey || event.metaKey || event.altKey) return;
        event.preventDefault();
        event.stopPropagation();
        const bounds = widthBounds();
        const step = event.shiftKey ? 40 : 20;
        width = event.key === 'Home' ? bounds.min : event.key === 'End' ? bounds.max : clamp(applyWidth() + (event.key === 'ArrowLeft' ? step : -step), bounds.min, bounds.max);
        applyWidth();
        save(':width', String(width));
        window.dispatchEvent(new Event('resize'));
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !gesture) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        finish(null, true);
    }, true);
    window.addEventListener('blur', () => finish(null, true));
    function sync() {
        if (gesture) finish(null, true);
        wrapper.hidden = launcher.getAttribute('aria-expanded') === 'true';
        if (wrapper.hidden) wrapper.classList.remove('is-hovered');
        applyWidth();
        placeLauncher();
    }
    window.addEventListener('resize', sync);
    sync();
})();
