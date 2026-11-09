import test from 'node:test';
import assert from 'node:assert/strict';
import { copyText } from '../../resources/js/copyText.js';

// A minimal document: records the textarea's life so a test can check it was selected, copied and removed.
function fakeDocument({ execResult = true, execThrows = false } = {}) {
    const log = [];
    let attached = null;
    const doc = {
        log,
        get attached() { return attached; },
        activeElement: { focus: () => log.push('refocus') },
        createElement(tag) {
            const el = { tag, value: '', attrs: {}, className: '', setAttribute(k, v) { this.attrs[k] = v; },
                select() { log.push(`select:${this.value}`); }, remove() { attached = null; log.push('remove'); } };
            return el;
        },
        body: { appendChild(el) { attached = el; log.push('append'); } },
        execCommand(cmd) {
            log.push(`exec:${cmd}`);
            if (execThrows) {
                throw new Error('nope');
            }
            return execResult;
        },
    };

    return doc;
}

test('uses the Clipboard API when it is there', async () => {
    const written = [];
    const doc = fakeDocument();
    const ok = await copyText('sk_live_1', { clipboard: { writeText: async (v) => { written.push(v); } }, document: doc });
    assert.equal(ok, true);
    assert.deepEqual(written, ['sk_live_1']);
    assert.deepEqual(doc.log, []);
});

test('falls back to a temporary textarea when the Clipboard API is missing (plain http)', async () => {
    const doc = fakeDocument();
    const ok = await copyText('sk_live_2', { clipboard: undefined, document: doc });
    assert.equal(ok, true);
    assert.deepEqual(doc.log.slice(0, 4), ['append', 'select:sk_live_2', 'exec:copy', 'remove']);
    assert.equal(doc.attached, null);
});

test('falls back when the Clipboard API rejects', async () => {
    const doc = fakeDocument();
    const ok = await copyText('v', { clipboard: { writeText: async () => { throw new Error('denied'); } }, document: doc });
    assert.equal(ok, true);
    assert.ok(doc.log.includes('exec:copy'));
});

test('the textarea holds the value only while copying, even when execCommand throws', async () => {
    const doc = fakeDocument({ execThrows: true });
    const ok = await copyText('secret', { clipboard: undefined, document: doc });
    assert.equal(ok, false);
    assert.equal(doc.attached, null);
    assert.ok(doc.log.includes('remove'));
});

test('reports failure when both ways fail', async () => {
    const doc = fakeDocument({ execResult: false });
    const ok = await copyText('v', { clipboard: { writeText: async () => { throw new Error('denied'); } }, document: doc });
    assert.equal(ok, false);
    assert.equal(doc.attached, null);
});
