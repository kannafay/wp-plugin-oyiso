(() => {
    'use strict';

    const config = window.oyisoAgent;
    const panel = document.getElementById('oyiso-agent-panel');
    const launcher = document.getElementById('oyiso-agent-launcher');
    const wrapper = document.getElementById('oyiso-agent-launcher-wrap');
    const resizers = Array.from(panel?.querySelectorAll('[data-agent-resize]') || []);
    const heading = panel?.querySelector('[data-agent-drag]');
    if (!config || !panel || !launcher || !wrapper || !resizers.length || !heading) return;

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
    let windowState = null;
    try {
        const saved = JSON.parse(read(':window'));
        if (saved && ['x', 'y', 'width', 'height'].every(name => Number.isFinite(saved[name])) && saved.width > 0 && saved.height > 0) {
            windowState = { x: clamp(saved.x, 0, 1), y: clamp(saved.y, 0, 1), width: saved.width, height: saved.height };
        }
    } catch { /* Ignore invalid or unavailable window geometry. */ }
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

    const docked = () => window.innerWidth >= 1280 && !document.body.classList.contains('oyiso-agent-floating') && !document.body.classList.contains('block-editor-page');

    function widthBounds() {
        const { w, gap } = viewport();
        const max = Math.max(1, Math.min(1000, docked() ? w - 640 : w - gap * 2));
        return { min: Math.min(360, max), max, defaultWidth: docked() ? clamp(w * 0.34, 400, 560) : 480 };
    }

    function windowBounds() {
        const { w, h, top, gap } = viewport();
        const padX = Math.min(gap, Math.max(0, (w - 1) / 2));
        const padY = Math.min(gap, Math.max(0, (h - top - 1) / 2));
        const maxWidth = w - padX * 2;
        const maxHeight = h - top - padY * 2;
        return { left: padX, right: w - padX, top: top + padY, bottom: h - padY, maxWidth, maxHeight, minWidth: Math.min(360, maxWidth), minHeight: Math.min(360, maxHeight) };
    }

    function applyGeometry() {
        const floating = !docked();
        const bounds = floating ? windowBounds() : widthBounds();
        const view = viewport();
        const value = Math.round(floating ? clamp(windowState?.width ?? width ?? 480, bounds.minWidth, bounds.maxWidth) : clamp(width ?? bounds.defaultWidth, bounds.min, bounds.max));
        const height = floating ? Math.round(clamp(windowState?.height ?? 720, bounds.minHeight, bounds.maxHeight)) : view.h - view.top;
        const rect = { width: value, height, x: floating ? Math.round(bounds.left + (windowState?.x ?? 1) * (bounds.maxWidth - value)) : view.w - value, y: floating ? Math.round(bounds.top + (windowState?.y ?? 1) * (bounds.maxHeight - height)) : view.top };
        document.body.style.setProperty('--oyiso-agent-panel-width', `${value}px`);
        panel.toggleAttribute('data-agent-window', floating);
        heading.tabIndex = floating ? 0 : -1;
        if (floating) {
            heading.setAttribute('aria-label', '移动助手窗口，也可使用方向键');
            Object.assign(panel.style, { left: `${rect.x}px`, top: `${rect.y}px`, width: `${rect.width}px`, height: `${rect.height}px`, right: 'auto', bottom: 'auto' });
        } else {
            heading.removeAttribute('aria-label');
            for (const property of ['left', 'top', 'width', 'height', 'right', 'bottom']) panel.style.removeProperty(property);
        }
        for (const handle of resizers) {
            const edge = handle.dataset.agentResize;
            if (edge.length !== 1) continue;
            const horizontal = edge === 'w' || edge === 'e';
            const max = !floating ? (horizontal ? bounds.max : height) : ({ w: rect.x + value - bounds.left, e: bounds.right - rect.x, n: rect.y + height - bounds.top, s: bounds.bottom - rect.y })[edge];
            handle.setAttribute('aria-valuemin', String(floating ? (horizontal ? bounds.minWidth : bounds.minHeight) : (horizontal ? bounds.min : height)));
            handle.setAttribute('aria-valuemax', String(max));
            handle.setAttribute('aria-valuenow', String(horizontal ? value : height));
            handle.setAttribute('aria-valuetext', `${horizontal ? value : height} 像素`);
        }
        return rect;
    }

    function setWindowRect(rect) {
        const bounds = windowBounds();
        const roomX = bounds.maxWidth - rect.width;
        const roomY = bounds.maxHeight - rect.height;
        windowState = { width: rect.width, height: rect.height, x: roomX > 0 ? clamp((rect.x - bounds.left) / roomX, 0, 1) : 0, y: roomY > 0 ? clamp((rect.y - bounds.top) / roomY, 0, 1) : 0 };
        applyGeometry();
    }

    function moveWindow(rect, dx, dy) {
        const bounds = windowBounds();
        setWindowRect({ ...rect, x: clamp(rect.x + dx, bounds.left, bounds.right - rect.width), y: clamp(rect.y + dy, bounds.top, bounds.bottom - rect.height) });
    }

    function resizeWindow(rect, edge, dx, dy) {
        const bounds = windowBounds();
        const next = { ...rect };
        if (edge.includes('w')) { next.x = clamp(rect.x + dx, bounds.left, rect.x + rect.width - bounds.minWidth); next.width = rect.x + rect.width - next.x; }
        if (edge.includes('e')) next.width = clamp(rect.width + dx, bounds.minWidth, bounds.right - rect.x);
        if (edge.includes('n')) { next.y = clamp(rect.y + dy, bounds.top, rect.y + rect.height - bounds.minHeight); next.height = rect.y + rect.height - next.y; }
        if (edge.includes('s')) next.height = clamp(rect.height + dy, bounds.minHeight, bounds.bottom - rect.y);
        setWindowRect(next);
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

    function start(event, type, edge = '') {
        if (!config.enabled || gesture || event.button !== 0 || event.isPrimary === false || (type === 'window' && docked()) || (type === 'resize' && docked() && edge !== 'w')) return;
        if (type !== 'launcher') panel.dispatchEvent(new Event('oyiso-agent-geometry-start'));
        clearTimeout(clickTimer);
        suppressClick = false;
        if (event.pointerType === 'touch') wrapper.classList.remove('is-hovered');
        const point = placeLauncher();
        gesture = { type, edge, floating: !docked(), id: event.pointerId, target: event.currentTarget, startX: event.clientX, startY: event.clientY, x: point.x, y: point.y, rect: applyGeometry(), oldWidth: width, oldPosition: position, oldWindow: windowState, moved: false };
        try { event.currentTarget.setPointerCapture?.(event.pointerId); } catch { /* Document listeners also handle browsers without pointer capture. */ }
        if (type === 'resize') {
            event.preventDefault();
            document.body.classList.add('oyiso-agent-resizing');
            document.body.style.setProperty('--oyiso-agent-resize-cursor', ['w', 'e'].includes(edge) ? 'ew-resize' : ['n', 's'].includes(edge) ? 'ns-resize' : ['nw', 'se'].includes(edge) ? 'nwse-resize' : 'nesw-resize');
            if (event.currentTarget.tabIndex === 0) event.currentTarget.focus({ preventScroll: true });
        }
    }

    function finish(event, cancelled = false) {
        if (!gesture || (event && event.pointerId !== gesture.id)) return;
        const active = gesture;
        gesture = null;
        document.body.classList.remove('oyiso-agent-resizing', 'oyiso-agent-dragging');
        document.body.style.removeProperty('--oyiso-agent-resize-cursor');
        wrapper.classList.remove('is-dragging');
        if (cancelled) {
            width = active.oldWidth;
            position = active.oldPosition;
            windowState = active.oldWindow;
            applyGeometry();
            placeLauncher();
        } else if (active.moved) {
            if (active.type === 'launcher') {
                const point = placeLauncher();
                moveLauncher(point.x, point.y, 5);
                save(':position', JSON.stringify(position));
                suppressClick = true;
                clickTimer = setTimeout(() => { suppressClick = false; }, 0);
            } else if (active.type === 'window' || active.floating) {
                save(':window', JSON.stringify(windowState));
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
        } else if (gesture.type === 'window') {
            document.body.classList.add('oyiso-agent-dragging');
            moveWindow(gesture.rect, dx, dy);
        } else if (gesture.floating) {
            resizeWindow(gesture.rect, gesture.edge, dx, dy);
        } else {
            const bounds = widthBounds();
            width = clamp(gesture.rect.width - dx, bounds.min, bounds.max);
            applyGeometry();
        }
    });
    document.addEventListener('pointerup', event => finish(event));
    document.addEventListener('pointercancel', event => finish(event, true));
    for (const [element, type] of [[launcher, 'launcher'], ...resizers.map(handle => [handle, 'resize']), [heading, 'window']]) {
        element.addEventListener('pointerdown', event => {
            if (type === 'window' && event.target.closest('button, a, input, textarea, select, [contenteditable]')) return;
            start(event, type, element.dataset.agentResize || '');
        });
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
    resizers.forEach(handle => handle.addEventListener('keydown', event => {
        const edge = handle.dataset.agentResize;
        const horizontal = edge === 'w' || edge === 'e';
        const arrows = horizontal ? ['ArrowLeft', 'ArrowRight'] : ['ArrowUp', 'ArrowDown'];
        if (edge.length !== 1 || ![...arrows, 'Home', 'End'].includes(event.key) || event.ctrlKey || event.metaKey || event.altKey) return;
        event.preventDefault();
        event.stopPropagation();
        panel.dispatchEvent(new Event('oyiso-agent-geometry-start'));
        const bounds = widthBounds();
        const step = event.shiftKey ? 40 : 20;
        const rect = applyGeometry();
        if (docked()) {
            if (edge !== 'w') return;
            width = event.key === 'Home' ? bounds.min : event.key === 'End' ? bounds.max : clamp(rect.width + (event.key === 'ArrowLeft' ? step : -step), bounds.min, bounds.max);
            applyGeometry();
            save(':width', String(width));
        } else {
            const target = event.key === 'Home' ? Number(handle.getAttribute('aria-valuemin')) : event.key === 'End' ? Number(handle.getAttribute('aria-valuemax')) : null;
            const delta = target === null ? (event.key === arrows[0] ? -step : step) : (target - (horizontal ? rect.width : rect.height)) * (edge === 'w' || edge === 'n' ? -1 : 1);
            resizeWindow(rect, edge, horizontal ? delta : 0, horizontal ? 0 : delta);
            save(':window', JSON.stringify(windowState));
        }
        window.dispatchEvent(new Event('resize'));
    }));
    heading.addEventListener('keydown', event => {
        const delta = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[event.key];
        if (!delta || event.target !== heading || docked() || event.ctrlKey || event.metaKey || event.altKey) return;
        event.preventDefault();
        panel.dispatchEvent(new Event('oyiso-agent-geometry-start'));
        const step = event.shiftKey ? 40 : 10;
        moveWindow(applyGeometry(), delta[0] * step, delta[1] * step);
        save(':window', JSON.stringify(windowState));
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
        wrapper.hidden = !config.enabled || launcher.getAttribute('aria-expanded') === 'true';
        if (wrapper.hidden) wrapper.classList.remove('is-hovered');
        applyGeometry();
        placeLauncher();
    }
    window.addEventListener('resize', sync);
    sync();
})();
