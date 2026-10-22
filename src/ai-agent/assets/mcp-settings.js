(() => {
    'use strict';
    document.querySelectorAll('[data-agent-mcp-write]').forEach(toggle => {
        const row = toggle.closest('[data-agent-mcp-plugin]');
        if (!row) return;
        toggle.indeterminate = toggle.dataset.partial === 'true';
        toggle.addEventListener('change', () => {
            toggle.indeterminate = false;
            row.querySelectorAll('[data-agent-mcp-grant]').forEach(input => {
                input.disabled = !toggle.checked;
            });
            const partial = row.querySelector('[data-agent-mcp-partial]');
            if (partial) partial.hidden = true;
        });
    });
})();
