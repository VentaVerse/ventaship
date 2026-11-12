import test from 'node:test';
import assert from 'node:assert/strict';
import { bookCheck, bookingSummary, canBook, checkBody, checkResult, parcelLine, plainNumber } from '../../resources/js/bookCheck.js';
import { shipmentForm } from '../../resources/js/shipmentForm.js';

test('numbers drop trailing zeros', () => {
    assert.equal(plainNumber('35.000000'), '35');
    assert.equal(plainNumber('1.50'), '1.5');
    assert.equal(plainNumber('0.5'), '0.5');
    assert.equal(plainNumber('010'), '10');
    assert.equal(plainNumber(' 2 '), '2');
    assert.equal(plainNumber('abc'), 'abc');
});

test('the parcel line names packaging, weight and size when the courier asks for them', () => {
    const base = { packaging: 'Small', weight: '1.0', length: '25', width: '20', height: '10.0' };
    assert.equal(parcelLine({ ...base, showWeight: true, showSize: true }), 'Small · 1 kg · 25 × 20 × 10 cm');
    assert.equal(parcelLine({ ...base, showWeight: false, showSize: false }), 'Small');
    assert.equal(parcelLine({ ...base, height: '', showWeight: true, showSize: true }), 'Small · 1 kg');
});

const spx = {
    courier: 'spx',
    courierName: 'SPX',
    handover: 'pickup',
    handoverLabel: 'Pick up',
    slotLabel: 'Thu, Nov 13 · 9:00am-12:00pm',
    toName: 'Juan Dela Cruz',
    toPlace: 'Makati, Metro Manila',
    packaging: 'Small',
    weight: '1',
    length: '25',
    width: '20',
    height: '10',
    showWeight: true,
    showSize: true,
    cod: true,
    total: '₱1,250.00',
    payerLabel: 'I pay',
};

test('the summary for an SPX pickup with COD', () => {
    assert.deepEqual(bookingSummary(spx), [
        ['Courier', 'SPX · Pick up'],
        ['Pickup', 'Thu, Nov 13 · 9:00am-12:00pm'],
        ['To', 'Juan Dela Cruz · Makati, Metro Manila'],
        ['Parcel', 'Small · 1 kg · 25 × 20 × 10 cm'],
        ['COD', '₱1,250.00'],
        ['Shipping fee', 'I pay'],
    ]);
});

test('the summary leaves out what does not apply', () => {
    const rows = bookingSummary({ ...spx, handover: 'dropoff', handoverLabel: 'Drop off myself', cod: false });
    assert.deepEqual(rows.map((r) => r[0]), ['Courier', 'To', 'Parcel', 'Shipping fee']);
    assert.equal(rows[0][1], 'SPX · Drop off myself');

    const lbc = bookingSummary({ ...spx, courier: 'lbc', courierName: 'LBC', handover: 'pickup', payerLabel: '', toPlace: '', cod: false });
    assert.deepEqual(lbc, [['Courier', 'LBC'], ['To', 'Juan Dela Cruz'], ['Parcel', 'Small · 1 kg · 25 × 20 × 10 cm']]);
});

test('the check answer decides what the dialog shows', () => {
    assert.deepEqual(checkResult(200, { fee: '195.00', currency: 'PHP', label: '₱195.00' }), { state: 'ready', label: '₱195.00' });
    assert.deepEqual(checkResult(200, { error: 'Too long for SPX. Length is 35 cm. SPX takes up to 25 cm.', title: 'Too long for SPX', detail: 'Length is 35 cm. SPX takes up to 25 cm.' }),
        { state: 'refused', title: 'Too long for SPX', detail: 'Length is 35 cm. SPX takes up to 25 cm.' });
    assert.deepEqual(checkResult(409, { error: 'Only unbooked manual shipments can be changed.' }),
        { state: 'refused', title: 'Only unbooked manual shipments can be changed.', detail: '' });
    assert.deepEqual(checkResult(422, { errors: { item: ['Enter the item.'] } }), { state: 'invalid' });
    assert.deepEqual(checkResult(200, { fee: null, currency: null, label: null }), { state: 'none' });
    assert.deepEqual(checkResult(500, {}), { state: 'none' });
    assert.deepEqual(checkResult(0, null), { state: 'none' });
});

test('Book shipment in the dialog waits for the answer and stays off on a refusal', () => {
    assert.equal(canBook('loading', false), false);
    assert.equal(canBook('refused', false), false);
    assert.equal(canBook('ready', false), true);
    assert.equal(canBook('none', false), true);
    assert.equal(canBook('ready', true), false);
});

test('the check sends the form without the edit form PUT override', () => {
    class FakeFormData {
        constructor(form) { this.entries = new Map(Object.entries(form.fields)); }
        delete(key) { this.entries.delete(key); }
    }
    const body = checkBody({ fields: { _token: 't', _method: 'PUT', item: 'Tee' } }, FakeFormData);
    assert.deepEqual([...body.entries.keys()], ['_token', 'item']);
});

function event(submitter) {
    return { submitter, prevented: false, preventDefault() { this.prevented = true; } };
}

test('only a Book button opens the check, and the confirmed submit goes through once', () => {
    const c = bookCheck({});
    const opened = [];
    c.openCheck = (s) => opened.push(s);

    const draft = event({ dataset: {} });
    c.submitted(draft);
    assert.equal(draft.prevented, false);

    const book = { dataset: { book: '' } };
    const first = event(book);
    c.submitted(first);
    assert.equal(first.prevented, true);
    assert.deepEqual(opened, [book]);

    c.checkPassed = true;
    const confirmed = event(book);
    c.submitted(confirmed);
    assert.equal(confirmed.prevented, false);
    assert.equal(c.checkPassed, false);

    const again = event(book);
    c.submitted(again);
    assert.equal(again.prevented, true);
});

test('confirming books with the button that opened the check, once', () => {
    const c = bookCheck({});
    const submits = [];
    const book = { isConnected: true, dataset: { book: '' } };
    c.$root = { requestSubmit: (s) => submits.push(s) };
    c.checkOpener = book;
    c.check = { open: true, state: 'loading', busy: false };

    c.confirmBook();
    assert.deepEqual(submits, [], 'not while the check runs');

    c.check.state = 'refused';
    c.confirmBook();
    assert.deepEqual(submits, [], 'not on a refusal');

    c.check.state = 'ready';
    c.confirmBook();
    c.confirmBook();
    assert.deepEqual(submits, [book]);
    assert.equal(c.checkPassed, true);
});

test('Back to edit closes the dialog, drops a late answer and returns focus to the button', async () => {
    const c = bookCheck({});
    const focused = [];
    const book = { isConnected: true, focus: () => focused.push('book') };
    c.$nextTick = (fn) => fn();
    c.checkOpener = book;
    c.check = { open: true, state: 'loading', busy: false };
    const seq = c.checkSeq;

    c.closeCheck();
    assert.equal(c.check.open, false);
    assert.notEqual(c.checkSeq, seq);
    assert.deepEqual(focused, ['book']);
});

test('the form keeps the check getters live when merged', () => {
    const form = shipmentForm({ services: {}, names: {}, couriers: {}, defaultParcels: {}, parcelSizes: {}, courier: {} });
    form.check = { open: true, state: 'loading', busy: false };
    assert.equal(form.canBook, false);
    form.check.state = 'ready';
    assert.equal(form.canBook, true);
});

test('a stored draft uses the server rows instead of the form fields', () => {
    const rows = [['Courier', 'QuadX'], ['To', 'Juan Dela Cruz']];
    const c = bookCheck({ rows, checkUrl: '/x' });
    c.$nextTick = () => {};
    c.$refs = {};
    c.runCheck = () => {};
    c.openCheck({ dataset: { book: '' } });
    assert.deepEqual(c.checkRows, rows);
    assert.equal(c.check.state, 'loading');
    assert.equal(c.check.open, false, 'the dialog stays shut while the check runs');
    assert.equal(c.checking, true);
});

test('the dialog opens only with the answer, and never for a form with errors', async () => {
    const realFetch = globalThis.fetch;
    const realFormData = globalThis.FormData;
    globalThis.FormData = class { delete() {} };
    const submits = [];
    const book = { isConnected: true, dataset: { book: '' }, setAttribute() {}, removeAttribute() {} };
    const run = async (status, body) => {
        globalThis.fetch = async () => ({ status, json: async () => body });
        const c = bookCheck({ rows: [], checkUrl: '/x' });
        c.$nextTick = (fn) => fn();
        c.$refs = {};
        c.$root = { requestSubmit: (s) => submits.push(s) };
        globalThis.document ??= { querySelector: () => null };
        c.openCheck(book);
        assert.equal(c.check.open, false);
        await c.runCheck.call(c);
        return c;
    };
    try {
        const invalid = await run(422, { errors: {} });
        assert.equal(invalid.check.open, false);
        assert.deepEqual(submits, [book]);
        const refused = await run(200, { error: 'Too long for SPX', title: 'Too long for SPX', detail: 'Length is 35 cm.' });
        assert.equal(refused.check.open, true);
        assert.equal(refused.check.state, 'refused');
        assert.equal(refused.checking, false);
        assert.equal(submits.length, 1, 'a refusal is not submitted');
    } finally {
        globalThis.fetch = realFetch;
        globalThis.FormData = realFormData;
    }
});
