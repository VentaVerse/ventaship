import test from 'node:test';
import assert from 'node:assert/strict';
import { accountForm, accountHeader, confirmSubmitter, formSnapshot, headerView, isUnsaved, toneClasses } from '../../resources/js/accountForm.js';

test('the snapshot ignores the token, the method, the Save and test flag and empty secrets', () => {
    const base = [['_token', 'a'], ['_method', 'PUT'], ['mode', 'sandbox'], ['settings[x]', '1'], ['secrets[k]', '']];
    assert.equal(formSnapshot(base), formSnapshot([['_token', 'b'], ['mode', 'sandbox'], ['settings[x]', '1'], ['after', 'test']]));
    assert.notEqual(formSnapshot(base), formSnapshot([...base, ['secrets[j]', 'typed']]));
    assert.notEqual(formSnapshot(base), formSnapshot([['mode', 'production'], ['settings[x]', '1']]));
});

test('unsaved: an edit, or an environment other than the saved one', () => {
    assert.equal(isUnsaved({ env: 'sandbox', saved: 'sandbox', dirty: false }), false);
    assert.equal(isUnsaved({ env: 'production', saved: 'sandbox', dirty: false }), true);
    assert.equal(isUnsaved({ env: 'sandbox', saved: 'sandbox', dirty: true }), true);
    // No account yet: only an edit counts (the toggle is part of the form).
    assert.equal(isUnsaved({ env: 'sandbox', saved: null, dirty: false }), false);
    assert.equal(isUnsaved({ env: 'production', saved: null, dirty: true }), true);
});

test('the header follows the toggle: Production amber, Sandbox blue, Not checked off the saved environment', () => {
    const check = ['Connected', 'good'];
    assert.deepEqual(headerView({ env: 'sandbox', saved: 'sandbox', check }), { envLabel: 'Sandbox', envTone: 'cool', checkLabel: 'Connected', checkTone: 'good' });
    assert.deepEqual(headerView({ env: 'production', saved: 'sandbox', check }), { envLabel: 'Production', envTone: 'attn', checkLabel: 'Not checked', checkTone: 'mute' });
    assert.deepEqual(headerView({ env: 'production', saved: null, check: ['Not set up', 'mute'] }), { envLabel: 'Production', envTone: 'attn', checkLabel: 'Not set up', checkTone: 'mute' });
});

test('only the given tone class is on', () => {
    const on = Object.entries(toneClasses('attn')).filter(([, v]) => v).map(([k]) => k);
    assert.deepEqual(on, ['x-badge--attn']);
    assert.equal(toneClasses('attn')['x-badge--good'], false);
});

test('switching to Production asks once, keeping Save or Save and test', () => {
    assert.equal(confirmSubmitter({ env: 'sandbox', saved: 'sandbox', submitter: { id: '' } }), null);
    assert.equal(confirmSubmitter({ env: 'production', saved: 'production', submitter: { id: '' } }), null);
    assert.equal(confirmSubmitter({ env: 'production', saved: 'sandbox', submitter: { id: '', value: '' } }), 'account-save-confirmed');
    assert.equal(confirmSubmitter({ env: 'production', saved: 'sandbox', submitter: { id: '', value: 'test' } }), 'account-save-test-confirmed');
    assert.equal(confirmSubmitter({ env: 'production', saved: null, submitter: null }), 'account-save-confirmed');
    assert.equal(confirmSubmitter({ env: 'production', saved: 'sandbox', submitter: { id: 'account-save-confirmed' } }), null);
    assert.equal(confirmSubmitter({ env: 'production', saved: 'sandbox', submitter: { id: 'account-save-test-confirmed', value: 'test' } }), null);
});

test('the form announces the environment and unsaved state; a refused save stays unsaved', () => {
    const sent = [];
    globalThis.window = { dispatchEvent: (e) => sent.push(e.detail) };
    globalThis.CustomEvent ??= class { constructor(type, init) { this.type = type; this.detail = init?.detail; } };

    let entries = [['mode', 'sandbox'], ['settings[x]', '1']];
    const form = Object.assign(accountForm({ env: 'sandbox', saved: 'sandbox', dirty: false, confirm: {} }), { $watch() {} });
    form.snapshot = () => formSnapshot(entries);
    form.init();
    assert.deepEqual(sent.at(-1), { env: 'sandbox', unsaved: false });

    entries = [['mode', 'sandbox'], ['settings[x]', '2']];
    form.refresh();
    assert.deepEqual(sent.at(-1), { env: 'sandbox', unsaved: true });

    entries = [['mode', 'sandbox'], ['settings[x]', '1']];
    form.refresh();
    assert.deepEqual(sent.at(-1), { env: 'sandbox', unsaved: false }, 'changed back is not unsaved');

    const returned = Object.assign(accountForm({ env: 'sandbox', saved: 'sandbox', dirty: true, confirm: {} }), { $watch() {} });
    returned.snapshot = () => formSnapshot(entries);
    returned.init();
    returned.refresh();
    assert.deepEqual(sent.at(-1), { env: 'sandbox', unsaved: true });

    delete globalThis.window;
});

test('the form opens the confirm dialog instead of submitting a switch to Production', () => {
    const dispatched = [];
    const form = Object.assign(accountForm({ env: 'production', saved: 'sandbox', confirm: { title: 'T', form: 'account-form' } }), {
        $dispatch: (name, detail) => dispatched.push([name, detail]),
    });
    let prevented = false;
    form.submit({ submitter: { id: '', value: 'test' }, preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.deepEqual(dispatched, [['confirm', { title: 'T', form: 'account-form', submitter: 'account-save-test-confirmed' }]]);
});

test('the header syncs from the form event', () => {
    const header = accountHeader({ env: 'sandbox', saved: 'sandbox', check: ['Connected', 'good'], unsaved: false });
    assert.equal(header.view.checkLabel, 'Connected');
    header.sync({ env: 'production', unsaved: true });
    assert.equal(header.unsaved, true);
    assert.equal(header.view.envLabel, 'Production');
    assert.equal(header.view.checkLabel, 'Not checked');
    assert.equal(header.toneClasses(header.view.envTone)['x-badge--attn'], true);
});
