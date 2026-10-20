const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const { ReadableStream } = require('node:stream/web');

const script = fs.readFileSync(path.join(__dirname, '../../src/ai-agent/assets/live.js'), 'utf8');
const frame = (event, data) => `event: ${event}\r\ndata: ${JSON.stringify(data)}\r\n\r\n`;

function reader(t, onEvent) {
    const dom = new JSDOM('', { runScripts: 'outside-only' });
    dom.window.TextDecoder = TextDecoder;
    dom.window.eval(script);
    t.after(() => dom.window.close());
    let controller;
    const body = new ReadableStream({ start(value) { controller = value; } });
    return { controller, result: dom.window.oyisoAgentReadStream({ body }, onEvent), encode: text => new TextEncoder().encode(text) };
}

test('live reader publishes UTF-8 deltas before the response completes and requires a final state', async t => {
    const events = [];
    const f = reader(t, (type, data) => events.push({ type, data }));
    f.controller.enqueue(f.encode(frame('state', { id: 12 })));
    for (const byte of f.encode(frame('text', { delta: '你好🌏' }))) f.controller.enqueue(new Uint8Array([byte]));
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(JSON.parse(JSON.stringify(events)), [{ type: 'state', data: { id: 12 } }, { type: 'text', data: { delta: '你好🌏' } }]);
    f.controller.enqueue(f.encode(frame('done', { id: 12, status: 'completed' })));
    assert.equal((await f.result).status, 'completed');
});

test('live reader rejects dropped, malformed, oversized and explicit error streams', async t => {
    for (const [body, message] of [[frame('text', { delta: 'partial' }), /断开/], ['data: {broken}\n\n', /JSON|Unexpected|property/i], [`data: ${'x'.repeat(1048577)}`, /过大/], [frame('error', { message: 'Model unavailable' }), /Model unavailable/]]) {
        const f = reader(t, () => {});
        const failure = assert.rejects(f.result, message);
        f.controller.enqueue(f.encode(body)); f.controller.close();
        await failure;
    }
});
