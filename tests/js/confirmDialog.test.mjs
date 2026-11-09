import test from 'node:test';
import assert from 'node:assert/strict';
import { confirmDialog, confirmTone, wrapTarget } from '../../resources/js/confirmDialog.js';

const a = { id: 'keep' };
const b = { id: 'go' };

test('Tab on the last button wraps to the first; Shift+Tab on the first wraps to the last', () => {
    assert.equal(wrapTarget([a, b], b, false), a);
    assert.equal(wrapTarget([a, b], a, true), b);
});

test('Tab between buttons is left to the browser', () => {
    assert.equal(wrapTarget([a, b], a, false), null);
    assert.equal(wrapTarget([a, b], b, true), null);
});

test('focus outside the dialog is pulled back in', () => {
    const outside = { id: 'page' };
    assert.equal(wrapTarget([a, b], outside, false), a);
    assert.equal(wrapTarget([a, b], outside, true), b);
    assert.equal(wrapTarget([], outside, false), null);
});

test('only an explicit danger tone is red', () => {
    assert.equal(confirmTone({ tone: 'danger' }), 'danger');
    assert.equal(confirmTone({ tone: 'DANGER' }), 'primary');
    assert.equal(confirmTone({}), 'primary');
    assert.equal(confirmTone(undefined), 'primary');
});

function fakeDialog() {
    const focused = [];
    const opener = { isConnected: true, focus: () => focused.push('opener') };
    globalThis.document = { activeElement: opener, body: {}, getElementById: () => null };
    const d = confirmDialog();
    d.$refs = { safe: { focus: () => focused.push('safe') }, dialog: {} };
    d.$nextTick = (fn) => fn();
    return { d, focused, opener };
}

test('opening focuses the safe button and closing returns focus to the opener', () => {
    const { d, focused } = fakeDialog();
    d.show({ title: 'Cancel shipment VS000006?', confirm: 'Cancel shipment', dismiss: 'Keep shipment', form: 'sd-cancel', tone: 'danger' });
    assert.equal(d.open, true);
    assert.equal(d.tone, 'danger');
    assert.equal(d.dismiss, 'Keep shipment');
    assert.deepEqual(focused, ['safe']);

    d.close();
    assert.equal(d.open, false);
    assert.equal(d.form, null);
    assert.deepEqual(focused, ['safe', 'opener']);

    d.close();
    assert.deepEqual(focused, ['safe', 'opener'], 'a second close (Esc on a closed dialog) does nothing');
});

test('a later confirm without a tone is not left red', () => {
    const { d } = fakeDialog();
    d.show({ title: 'Delete?', tone: 'danger' });
    d.close();
    d.show({ title: 'Switch to Production?' });
    assert.equal(d.tone, 'primary');
});

test('focus is not sent to an opener that left the page', () => {
    const { d, focused, opener } = fakeDialog();
    d.show({ title: 'Revoke?' });
    opener.isConnected = false;
    d.close();
    assert.deepEqual(focused, ['safe']);
});
