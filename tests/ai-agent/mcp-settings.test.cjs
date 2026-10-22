const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const script = fs.readFileSync(path.join(__dirname, '../../src/ai-agent/assets/mcp-settings.js'), 'utf8');

function fixture(t, grants = []) {
    const names = ['fixture-a/create', 'fixture-a/delete', 'fixture-b/update'];
    const grantInput = name => `<input type="hidden" data-agent-mcp-grant name="oyiso[agent_write_tools][]" value="${name}"${grants.includes(name) ? '' : ' disabled'}>`;
    const group = (plugin, tools) => {
        const allowed = tools.filter(name => grants.includes(name)).length;
        const partial = allowed > 0 && allowed < tools.length;
        return `<tr data-agent-mcp-plugin="${plugin}"><td>${plugin}</td><td><details><summary>查看工具</summary><p>工具明细</p></details></td><td>
            <input type="checkbox" data-agent-mcp-write data-partial="${partial}"${tools.length && allowed === tools.length ? ' checked' : ''}${tools.length ? '' : ' disabled'}>
            ${partial ? '<span data-agent-mcp-partial>部分工具已授权</span>' : ''}${tools.map(grantInput).join('')}
        </td></tr>`;
    };
    const dom = new JSDOM(`<form><input name="oyiso[agent_write_tools][]" value="oyiso/apply-product-update" type="hidden"><table><tbody>
        ${group('fixture-a', names.slice(0, 2))}${group('fixture-b', names.slice(2))}${group('fixture-readonly', [])}
    </tbody></table></form>`, { runScripts: 'outside-only' });
    const errors = [];
    dom.window.addEventListener('error', event => errors.push(event.error));
    dom.window.eval(script);
    t.after(() => { dom.window.close(); assert.deepEqual(errors, []); });
    return {
        window: dom.window,
        toggle: plugin => dom.window.document.querySelector(`[data-agent-mcp-plugin="${plugin}"] [data-agent-mcp-write]`),
        submitted: () => Array.from(new dom.window.FormData(dom.window.document.querySelector('form')).getAll('oyiso[agent_write_tools][]')),
    };
}

test('one plugin switch selects its write tools while other plugins and built-in grants remain unchanged', t => {
    const f = fixture(t);
    assert.deepEqual(f.submitted(), ['oyiso/apply-product-update']);
    f.toggle('fixture-a').click();
    assert.deepEqual(f.submitted(), ['oyiso/apply-product-update', 'fixture-a/create', 'fixture-a/delete']);
    assert.equal(f.toggle('fixture-b').checked, false);
    assert.equal(f.window.document.querySelector('details').open, false);
});

test('turning a plugin off removes all of its submitted grants while preserving other plugins', t => {
    const f = fixture(t, ['fixture-a/create', 'fixture-a/delete', 'fixture-b/update']);
    assert.equal(f.toggle('fixture-a').checked, true);
    f.toggle('fixture-a').click();
    assert.deepEqual(f.submitted(), ['oyiso/apply-product-update', 'fixture-b/update']);
    assert.equal(f.toggle('fixture-b').checked, true);
});

test('partial legacy permissions survive an unrelated save until the plugin switch is changed', t => {
    const f = fixture(t, ['fixture-a/create']);
    const toggle = f.toggle('fixture-a');
    assert.equal(toggle.indeterminate, true);
    assert.deepEqual(f.submitted(), ['oyiso/apply-product-update', 'fixture-a/create']);
    toggle.click();
    assert.equal(toggle.indeterminate, false);
    assert.equal(f.window.document.querySelector('[data-agent-mcp-partial]').hidden, true);
    assert.deepEqual(f.submitted(), ['oyiso/apply-product-update', 'fixture-a/create', 'fixture-a/delete']);
    toggle.click();
    assert.deepEqual(f.submitted(), ['oyiso/apply-product-update']);
});

test('a plugin with only read tools cannot add write grants', t => {
    const f = fixture(t);
    const toggle = f.toggle('fixture-readonly');
    assert.equal(toggle.disabled, true);
    toggle.click();
    assert.deepEqual(f.submitted(), ['oyiso/apply-product-update']);
});
