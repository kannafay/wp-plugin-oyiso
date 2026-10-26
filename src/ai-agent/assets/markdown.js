(() => {
    'use strict';
    if (!window.markdownit) return;
    const md = window.markdownit({ html: false, breaks: true, linkify: true, typographer: false });
    const validate = md.validateLink;
    md.validateLink = url => validate(url) && (/^(https?:|mailto:)/i.test(url) || !/^[a-z][a-z\d+.-]*:|^\/\//i.test(url));
    const linkOpen = md.renderer.rules.link_open || ((tokens, index, options, env, renderer) => renderer.renderToken(tokens, index, options));
    md.renderer.rules.link_open = (tokens, index, options, env, renderer) => {
        tokens[index].attrSet('target', '_blank');
        tokens[index].attrSet('rel', 'noopener noreferrer');
        return linkOpen(tokens, index, options, env, renderer);
    };
    // Reply images are links, so loading a conversation never contacts an image host.
    md.renderer.rules.image = (tokens, index) => {
        const token = tokens[index];
        const href = md.utils.escapeHtml(token.attrGet('src') || '');
        const label = md.utils.escapeHtml(token.content || '图片');
        return `<a href="${href}" target="_blank" rel="noopener noreferrer">${label}</a>`;
    };
    const tableOpen = md.renderer.rules.table_open || (() => '<table>');
    const tableClose = md.renderer.rules.table_close || (() => '</table>');
    md.renderer.rules.table_open = (...args) => '<div class="oyiso-agent-markdown-table" tabindex="0" role="region" aria-label="表格">' + tableOpen(...args);
    md.renderer.rules.table_close = (...args) => tableClose(...args) + '</div>';
    const replies = new WeakMap();
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    const segmenter = typeof Intl.Segmenter === 'function' ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : null;

    function reconcile(parent, incoming) {
        let current = parent.firstChild;
        for (const wanted of incoming.childNodes) {
            const next = current?.nextSibling;
            if (current?.nodeType === wanted.nodeType && current.nodeName === wanted.nodeName) {
                if (wanted.nodeType === Node.TEXT_NODE) {
                    if (current.nodeValue !== wanted.nodeValue) current.nodeValue = wanted.nodeValue;
                } else {
                    for (const attribute of [...current.attributes]) if (!wanted.hasAttribute(attribute.name)) current.removeAttribute(attribute.name);
                    for (const attribute of wanted.attributes) if (current.getAttribute(attribute.name) !== attribute.value) current.setAttribute(attribute.name, attribute.value);
                    reconcile(current, wanted);
                }
            } else if (current) parent.replaceChild(wanted.cloneNode(true), current);
            else parent.append(wanted.cloneNode(true));
            current = next;
        }
        while (current) { const next = current.nextSibling; current.remove(); current = next; }
    }

    function paint(state, text) {
        const { element, scroll } = state;
        const follow = scroll && scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 90;
        state.caret?.remove();
        if (element.dataset.markdownSource !== text) {
            // Only markdown-it's escaped output enters the DOM; keep settled nodes in place.
            const template = document.createElement('template');
            template.innerHTML = md.render(text).trim();
            reconcile(element, template.content);
            element.dataset.markdownSource = text;
        }
        state.text = text;
        if (state.streaming) {
            if (!state.caret) {
                state.caret = document.createElement('span');
                state.caret.className = 'oyiso-agent-stream-caret';
                state.caret.setAttribute('aria-hidden', 'true');
            }
            const blocks = element.querySelectorAll('p, li, h1, h2, h3, h4, h5, h6, blockquote, pre code, th, td');
            (blocks[blocks.length - 1] || element).append(state.caret);
        }
        if (follow) scroll.scrollTop = scroll.scrollHeight;
    }

    function stop(state) {
        if (state.frame) cancelAnimationFrame(state.frame);
        state.frame = 0;
        state.last = undefined;
    }

    function advance(state, time) {
        state.frame = 0;
        if (!state.element.isConnected) { state.last = undefined; return; }
        if (document.hidden || reducedMotion?.matches || (state.last !== undefined && time - state.last > 250)) {
            state.last = undefined;
            paint(state, state.target);
            return;
        }
        const elapsed = Math.max(1, time - (state.last ?? time - 16));
        state.last = time;
        const start = state.ends.findIndex(end => end > state.text.length);
        if (start === -1) { state.last = undefined; return; }
        const count = Math.max(1, Math.ceil((state.ends.length - start) * Math.min(1, elapsed / state.remaining)));
        paint(state, state.target.slice(0, state.ends[start + count - 1]));
        state.remaining = Math.max(1, state.remaining - elapsed);
        if (state.text !== state.target) state.frame = requestAnimationFrame(next => advance(state, next));
        else state.last = undefined;
    }

    window.oyisoAgentMarkdown = (element, text, options = {}) => {
        let state = replies.get(element);
        if (!state) { state = { element, text: '', target: '', frame: 0 }; replies.set(element, state); }
        state.streaming = !!options.streaming;
        state.scroll = options.scroll;
        state.target = text;
        if (!options.animate || reducedMotion?.matches || document.hidden || !text.startsWith(state.text)) {
            stop(state);
            paint(state, text);
            return;
        }
        state.ends = segmenter
            ? Array.from(segmenter.segment(text), part => part.index + part.segment.length)
            : Array.from(text).reduce((ends, part) => { ends.push((ends[ends.length - 1] || 0) + part.length); return ends; }, []);
        // Keep live and buffered replies readable, including their final chunk. Never retype history.
        if (!state.text && state.ends.length) paint(state, text.slice(0, state.ends[0]));
        const remaining = state.ends.filter(end => end > state.text.length).length;
        state.remaining = Math.min(900, Math.max(100, remaining * 18));
        if (remaining && !state.frame) state.frame = requestAnimationFrame(time => advance(state, time));
    };
})();
