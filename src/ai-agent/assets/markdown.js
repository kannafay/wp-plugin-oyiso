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
    window.oyisoAgentMarkdown = (element, text) => {
        if (element.dataset.markdownSource === text) return;
        // Raw HTML is disabled; only escaped text and markdown-it's safe renderers produce HTML.
        element.innerHTML = md.render(text).trim();
        element.dataset.markdownSource = text;
    };
})();
