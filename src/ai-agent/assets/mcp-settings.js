(() => {
    'use strict';
    document.querySelectorAll('[data-agent-write-toggle]').forEach(toggle => {
        const row = toggle.closest('[data-agent-write-group]');
        if (!row) return;
        toggle.indeterminate = toggle.dataset.partial === 'true';
        toggle.addEventListener('change', () => {
            toggle.indeterminate = false;
            row.querySelectorAll('[data-agent-write-grant]').forEach(input => {
                input.disabled = !toggle.checked;
            });
            const partial = row.querySelector('[data-agent-write-partial]');
            if (partial) partial.hidden = true;
        });
    });
})();
