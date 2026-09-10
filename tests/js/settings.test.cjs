'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { JSDOM } = require('jsdom');
const jquery = require('jquery');
const script = readFileSync('assets/js/trp-llm-engines-settings.js', 'utf8');
async function setup(t) {
    const panels = ['openai', 'anthropic', 'openrouter', 'deepseek'].map(p => `
        <input id="trp-${p}-api-key" value="key-one">
        <select id="trp-${p}-model" name="trp_machine_translation_settings[${p}-model]">
            <option value="saved" selected>Saved model</option><option value="other">Other</option>
        </select>`).join('');
    const dom = new JSDOM(`<form>${panels}</form>`, { url: 'https://example.test/wp-admin/', runScripts: 'outside-only' });
    t.after(() => dom.window.close());
    const $ = jquery(dom.window); dom.window.jQuery = $;
    dom.window.trp_llm_engines = { ajax_url: '/wp-admin/admin-ajax.php', nonce: 'fixture' };
    const requests = [];
    $.ajax = opts => {
        const r = {
            opts, aborted: false,
            abort() { this.aborted = true; opts.error(this, 'abort'); opts.complete(this, 'abort'); },
            succeed(models) { opts.success({ success: true, data: { models } }); opts.complete(this, 'success'); },
            fail(reason = 'timeout') { opts.error(this, reason); opts.complete(this, reason); }
        };
        requests.push(r); return r;
    };
    dom.window.eval(script);
    await new Promise(resolve => $(resolve));
    const key = p => $(`#trp-${p || 'openai'}-api-key`);
    const select = p => $(`#trp-${p || 'openai'}-model`);
    const button = p => $(`.trp-llm-refresh-models[data-provider="${p || 'openai'}"]`);
    const start = p => { key(p).trigger('blur'); return requests.at(-1); };
    return { $, dom, requests, key, select, button, start };
}
test('loading retains real options and includes selected model in form submission', async t => {
    const u = await setup(t); const before = u.select().html(); u.start();
    assert.equal(u.select().html(), before); assert.equal(u.select().val(), 'saved');
    assert.equal(u.select().prop('disabled'), false);
    assert.ok(u.$('form').serializeArray().some(r => r.name === 'trp_machine_translation_settings[openai-model]' && r.value === 'saved'));
    assert.equal(u.select().attr('aria-busy'), 'true');
});
test('out-of-order stale response cannot replace catalogue or stop current spinner', async t => {
    const u = await setup(t); const old = u.start(); const latest = u.start();
    assert.ok(old.aborted);
    old.opts.success({ success: true, data: { models: { stale: 'Stale' } } }); old.opts.complete();
    assert.equal(u.select().find('option[value="stale"]').length, 0);
    assert.equal(u.select().attr('aria-busy'), 'true');
    latest.succeed({ current: 'Current' });
    assert.equal(u.select().val(), 'saved'); assert.equal(u.select().find('option[value="current"]').length, 1);
});
test('typing a new API key immediately invalidates outstanding responses', async t => {
    const u = await setup(t); const old = u.start(); u.key().val('new-key').trigger('input');
    assert.ok(old.aborted); old.succeed({ wrongAccount: 'Wrong account' });
    assert.equal(u.select().find('option[value="wrongAccount"]').length, 0);
    assert.equal(u.select().val(), 'saved'); assert.equal(u.select().attr('aria-busy'), 'false');
});
test('clearing a key preserves the selected model and sends no new request', async t => {
    const u = await setup(t); u.key().val('').trigger('input'); u.start();
    assert.equal(u.requests.length, 0); assert.equal(u.select().val(), 'saved');
});
test('latest deliberate selection made during a request survives its completion', async t => {
    const u = await setup(t); const r = u.start(); u.select().val('other').trigger('change');
    r.succeed({ saved: 'Original', newlyFetched: 'New' });
    assert.equal(u.select().val(), 'other'); assert.match(u.select().find(':selected').text(), /saved/);
});
test('missing saved model is retained instead of silently switching to first option', async t => {
    const u = await setup(t); u.start().succeed({ expensive: 'Expensive first result' });
    assert.equal(u.select().val(), 'saved'); assert.equal(u.select().find('option').length, 2);
});
for (const models of [{}, [], null, { broken: { label: 'not a string' } }, { '': 'Empty id' }]) {
    test(`empty/malformed catalogue preserves options: ${JSON.stringify(models)}`, async t => {
        const u = await setup(t); const before = u.select().html(); u.start().succeed(models);
        assert.equal(u.select().html(), before); assert.equal(u.select().val(), 'saved');
        assert.equal(u.select().attr('aria-busy'), 'false'); assert.match(u.$('#trp-openai-model-status').text(), /Error/);
    });
}
test('network failure preserves newly fetched choices, not only original server options', async t => {
    const u = await setup(t); u.start().succeed({ newModel: 'New model' });
    u.select().val('newModel').trigger('change'); const before = u.select().html();
    u.start().fail(); assert.equal(u.select().html(), before); assert.equal(u.select().val(), 'newModel');
});
test('responses and cancellations are isolated per provider', async t => {
    const u = await setup(t); const a = u.start('openai'); const b = u.start('deepseek');
    u.key('openai').val('changed').trigger('input');
    assert.ok(a.aborted); assert.equal(b.aborted, false); b.succeed({ deepseek: 'DeepSeek' });
    assert.equal(u.select('deepseek').find('option[value="deepseek"]').length, 1);
});
test('completion never enables a select disabled by TranslatePress', async t => {
    const u = await setup(t); const r = u.start(); u.select().prop('disabled', true); r.succeed({ saved: 'Saved' });
    assert.equal(u.select().prop('disabled'), true);
});
test('model labels are inserted as text, not executable HTML', async t => {
    const u = await setup(t); u.start().succeed({ injected: '<img src=x onerror="alert(1)">' });
    assert.equal(u.select().find('img').length, 0); assert.equal(u.select().find('[value="injected"]').text(), '<img src=x onerror="alert(1)">');
});
test('refresh button sends provider, nonce and explicit cache refresh', async t => {
    const u = await setup(t); u.button().trigger('click'); const r = u.requests[0];
    assert.equal(r.opts.data.force_refresh, 1); assert.equal(r.opts.data.provider, 'openai'); assert.equal(r.opts.data.nonce, 'fixture');
    assert.equal(u.button().attr('type'), 'button');
});
