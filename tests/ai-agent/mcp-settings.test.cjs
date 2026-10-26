const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const script = fs.readFileSync(path.join(__dirname, '../../src/ai-agent/assets/mcp-settings.js'), 'utf8');

const productWrites = ['oyiso/apply-product-update', 'oyiso/revert-product-update', 'oyiso/apply-product-trash', 'oyiso/revert-product-trash'];
const wooWrites = [...productWrites, 'woocommerce/product-update', 'woocommerce/product-delete'];
const wordpressWrites = ['oyiso/apply-post-update', 'oyiso/revert-post-update', 'oyiso/create-post-draft', 'oyiso/activate-plugin', 'oyiso/deactivate-plugin'];

function fixture(t, grants = [], productGrants = [productWrites[0]], enabled = ['oyiso-woocommerce', 'fixture-b']) {
    const selected = [...productGrants, ...grants];
    const names = ['fixture-a/create', 'fixture-a/delete', 'fixture-b/update'];
    const grantInput = name => `<input type="hidden" data-agent-write-grant name="oyiso[agent_write_tools][]" value="${name}"${selected.includes(name) ? '' : ' disabled'}>`;
    const group = (plugin, tools) => {
        const members = plugin === 'woocommerce' ? ['oyiso-woocommerce', 'woocommerce'] : plugin === 'wordpress' ? ['oyiso-wordpress'] : [plugin];
        const active = members.filter(name => enabled.includes(name)).length;
        const partialEnabled = active > 0 && active < members.length;
        const allowed = tools.filter(name => selected.includes(name)).length;
        const partial = allowed > 0 && allowed < tools.length;
        return `<tr data-agent-write-group="${plugin}"><td>${plugin}</td><td><details><summary>查看工具</summary><p>工具明细</p></details></td><td>
            <input type="checkbox" data-agent-write-toggle data-partial="${partial}"${tools.length && allowed === tools.length ? ' checked' : ''}${tools.length ? '' : ' disabled'}>
            ${partial ? '<span data-agent-write-partial>部分工具已授权</span>' : ''}${tools.map(grantInput).join('')}
        </td><td><input type="checkbox" data-agent-plugin-toggle data-partial="${partialEnabled}"${active === members.length ? ' checked' : ''}>
            ${partialEnabled ? '<span data-agent-plugin-partial>部分工具已启用</span>' : ''}
            ${members.map(name => `<input type="hidden" data-agent-plugin-member name="oyiso[agent_mcp_namespaces][]" value="${name}"${enabled.includes(name) ? '' : ' disabled'}>`).join('')}
        </td></tr>`;
    };
    const dom = new JSDOM(`<form><table><tbody>
        ${group('wordpress', wordpressWrites)}${group('woocommerce', wooWrites)}${group('fixture-a', names.slice(0, 2))}${group('fixture-b', names.slice(2))}${group('fixture-readonly', [])}
    </tbody></table></form>`, { runScripts: 'outside-only' });
    const errors = [];
    dom.window.addEventListener('error', event => errors.push(event.error));
    dom.window.eval(script);
    t.after(() => { dom.window.close(); assert.deepEqual(errors, []); });
    return {
        window: dom.window,
        toggle: plugin => dom.window.document.querySelector(`[data-agent-write-group="${plugin}"] [data-agent-write-toggle]`),
        enabled: plugin => dom.window.document.querySelector(`[data-agent-write-group="${plugin}"] [data-agent-plugin-toggle]`),
        plugins: () => Array.from(new dom.window.FormData(dom.window.document.querySelector('form')).getAll('oyiso[agent_mcp_namespaces][]')),
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
    assert.equal(toggle.closest('tr').querySelector('[data-agent-write-partial]').hidden, true);
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

test('one WooCommerce write switch controls built-in and native writes together while preserving other plugins', t => {
    const f = fixture(t, ['fixture-b/update']);
    const toggle = f.toggle('woocommerce');
    assert.equal(toggle.indeterminate, true);
    assert.deepEqual(f.submitted(), [productWrites[0], 'fixture-b/update']);
    toggle.click();
    assert.equal(toggle.indeterminate, false);
    assert.deepEqual(f.submitted(), [...wooWrites, 'fixture-b/update']);
    toggle.click();
    assert.deepEqual(f.submitted(), ['fixture-b/update']);
    assert.equal(f.toggle('fixture-b').checked, true);
});

test('the WooCommerce enable switch preserves legacy partial state until changed and then controls both sources', t => {
    const f = fixture(t);
    const toggle = f.enabled('woocommerce');
    assert.equal(toggle.indeterminate, true);
    assert.deepEqual(f.plugins(), ['oyiso-woocommerce', 'fixture-b']);
    toggle.click();
    assert.equal(toggle.indeterminate, false);
    assert.deepEqual(f.plugins(), ['oyiso-woocommerce', 'woocommerce', 'fixture-b']);
    assert.deepEqual(f.submitted(), [productWrites[0]], 'enabling tools does not grant write permissions');
    toggle.click();
    assert.deepEqual(f.plugins(), ['fixture-b']);
    assert.equal(f.enabled('fixture-b').checked, true);
    assert.deepEqual(f.submitted(), [productWrites[0]], 'disabling a plugin keeps its configured grants inactive for later reuse');
});

test('one WordPress switch controls content and plugin management while preserving existing partial grants', t => {
    const f = fixture(t, [wordpressWrites[0]]);
    assert.equal(f.toggle('wordpress').indeterminate, true);
    assert.deepEqual(f.submitted(), [wordpressWrites[0], productWrites[0]]);
    f.enabled('wordpress').click();
    assert.deepEqual(f.plugins(), ['oyiso-wordpress', 'oyiso-woocommerce', 'fixture-b']);
    assert.deepEqual(f.submitted(), [wordpressWrites[0], productWrites[0]], 'enabling WordPress does not grant new writes');
    f.toggle('wordpress').click();
    assert.deepEqual(f.submitted(), [...wordpressWrites, productWrites[0]]);
    f.toggle('wordpress').click();
    assert.deepEqual(f.submitted(), [productWrites[0]]);
});
