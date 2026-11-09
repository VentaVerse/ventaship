import test from 'node:test';
import assert from 'node:assert/strict';
import { customerSearch, moveActive, phoneText, placeText, recipientValues, untouched } from '../../resources/js/customerSearch.js';

const juan = {
    public_id: 'cus_1', name: 'Juan Dela Cruz', phone: '09171234567', summary: 'Ermita, Manila',
    address: { first_name: 'Juan', last_name: 'Dela Cruz', phone: '09171234567', address_1: '1 Rizal St', address_2: 'Unit 2',
        barangay: 'Barangay 659', district: 'Ermita', city: 'Manila', state: 'Metro Manila', postcode: '1000', codes: { state: '130000000' } },
};

function make(t, responses = {}) {
    const calls = [];
    globalThis.fetch = async (url) => {
        calls.push(url);
        const q = new URL(url, 'http://x').searchParams.get('q');
        const body = responses[q];
        if (body instanceof Error) {
            throw body;
        }
        return { ok: true, json: async () => ({ customers: body ?? [] }) };
    };
    const filled = [];
    const c = customerSearch({ url: '/customers/search' });
    c.fill = (customer) => filled.push(customer);
    c.$nextTick = (fn) => fn();
    t.after(() => { delete globalThis.fetch; });

    return { c, calls, filled };
}

const flush = () => new Promise((r) => setImmediate(r));

test('arrow keys wrap through the rows', () => {
    assert.equal(moveActive(-1, 3, 'ArrowDown'), 0);
    assert.equal(moveActive(2, 3, 'ArrowDown'), 0);
    assert.equal(moveActive(-1, 3, 'ArrowUp'), 2);
    assert.equal(moveActive(0, 3, 'ArrowUp'), 2);
    assert.equal(moveActive(1, 3, 'Home'), 0);
    assert.equal(moveActive(0, 3, 'End'), 2);
    assert.equal(moveActive(0, 0, 'ArrowDown'), -1);
});

test('recipient values join the two street lines', () => {
    assert.deepEqual(recipientValues(juan.address), { first_name: 'Juan', last_name: 'Dela Cruz', phone: '09171234567', address_1: '1 Rizal St, Unit 2' });
    assert.deepEqual(recipientValues({ first_name: 'A', phone: '0917', address_1: null }), { first_name: 'A', last_name: '', phone: '0917', address_1: '' });
});

test('typing is debounced at 250 ms and needs 2 characters', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c, calls } = make(t, { ju: [juan], juan: [juan] });

    c.q = 'j';
    c.input();
    t.mock.timers.tick(300);
    assert.equal(calls.length, 0);
    assert.equal(c.open, false);

    c.q = 'ju';
    c.input();
    t.mock.timers.tick(200);
    c.q = 'juan';
    c.input();
    t.mock.timers.tick(249);
    assert.equal(calls.length, 0);
    t.mock.timers.tick(1);
    assert.equal(calls.length, 1);
    assert.equal(calls[0], '/customers/search?q=juan');
    await flush();
    assert.equal(c.open, true);
    assert.deepEqual(c.results, [juan]);
    assert.equal(c.total, 2); // the customer plus "New customer"
});

test('a late response for an older query is dropped', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let release;
    globalThis.fetch = (url) => new Promise((resolve) => {
        const q = new URL(url, 'http://x').searchParams.get('q');
        const answer = { ok: true, json: async () => ({ customers: q === 'ju' ? [juan] : [] }) };
        if (q === 'ju') {
            release = () => resolve(answer);
        } else {
            resolve(answer);
        }
    });
    t.after(() => { delete globalThis.fetch; });
    const c = customerSearch({ url: '/customers/search' });

    c.q = 'ju';
    c.input();
    t.mock.timers.tick(250);
    c.q = 'zz';
    c.input();
    t.mock.timers.tick(250);
    await flush();
    release();
    await flush();
    assert.deepEqual(c.results, []);
});

test('arrows, Enter and Escape drive the list; Enter never submits the form', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c, filled } = make(t, { juan: [juan] });
    c.q = 'juan';
    c.input();
    t.mock.timers.tick(250);
    await flush();

    const key = (k) => {
        const e = { key: k, prevented: false, preventDefault() { this.prevented = true; } };
        c.key(e);
        return e;
    };

    assert.equal(c.active, -1);
    assert.equal(key('ArrowDown').prevented, true);
    assert.equal(c.active, 0);
    assert.equal(c.optionId(0), 'f-customer-opt-0');
    assert.equal(c.activeId, 'f-customer-opt-0');
    key('ArrowDown');
    assert.equal(c.active, 1); // New customer
    key('ArrowUp');
    assert.equal(key('Enter').prevented, true);
    assert.deepEqual(filled, [juan]);
    assert.equal(c.open, false);
    assert.equal(c.q, '');

    // Enter with the list closed does nothing, but still does not submit the booking.
    assert.equal(key('Enter').prevented, true);
    assert.equal(filled.length, 1);

    c.q = 'juan';
    c.input();
    t.mock.timers.tick(250);
    await flush();
    key('Escape');
    assert.equal(c.open, false);
    assert.equal(c.q, 'juan');
    key('Escape');
    assert.equal(c.q, '');
});

test('picking New customer clears the customer instead of filling one', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c, filled } = make(t, { juan: [juan] });
    const cleared = [];
    c.clearCustomer = () => cleared.push(true);
    c.q = 'juan';
    c.input();
    t.mock.timers.tick(250);
    await flush();

    c.pick(1);
    assert.equal(filled.length, 0);
    assert.equal(cleared.length, 1);
    assert.equal(c.open, false);
});

test('a failed search shows only New customer', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c } = make(t, { juan: new Error('offline') });
    c.q = 'juan';
    c.input();
    t.mock.timers.tick(250);
    await flush();
    assert.deepEqual(c.results, []);
    assert.equal(c.open, true);
    assert.equal(c.total, 1);
});

test('the list shows the phone spaced and the area', () => {
    assert.equal(phoneText('09171234567'), '0917 123 4567');
    assert.equal(phoneText('+1 555'), '+1 555');
    assert.equal(customerSearch({ url: '' }).meta(juan), '0917 123 4567 · Ermita, Manila');
});

test('picking a customer links it; New customer or another phone unlinks it', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c } = make(t, { juan: [juan] });
    c.clearCustomer = () => {};
    assert.equal(c.customerId, '');
    c.q = 'juan';
    c.input();
    t.mock.timers.tick(250);
    await flush();
    c.pick(0);
    assert.equal(c.customerId, 'cus_1');

    const phone = (value, isTrusted = true) => c.edited({ isTrusted, target: { name: 'recipient[phone]', value } });
    phone('0999', false); // the fill itself
    assert.equal(c.customerId, 'cus_1');
    phone('0917 123 4567');
    assert.equal(c.customerId, 'cus_1');
    c.edited({ isTrusted: true, target: { name: 'recipient[first_name]', value: ' Juan ' } });
    assert.equal(c.customerId, 'cus_1'); // the same name
    phone('0918 000 0000');
    assert.equal(c.customerId, '');
    assert.equal(c.picked, null);

    // Editing the name unlinks too, so the saved record is never changed by a shipment it no longer describes.
    c.q = 'juan';
    c.input();
    t.mock.timers.tick(250);
    await flush();
    c.pick(0);
    c.edited({ isTrusted: true, target: { name: 'recipient[last_name]', value: 'Santos' } });
    assert.equal(c.customerId, '');

    c.q = 'juan';
    c.input();
    t.mock.timers.tick(250);
    await flush();
    c.pick(0);
    c.q = 'juan';
    c.input();
    t.mock.timers.tick(250);
    await flush();
    c.pick(1);
    assert.equal(c.customerId, '');
    assert.equal(customerSearch({ url: '', customerId: 'cus_9' }).customerId, 'cus_9');
});

test('the pick shows name, phone and City, Province', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { c } = make(t, { juan: [juan] });
    c.q = 'juan';
    c.input();
    t.mock.timers.tick(250);
    await flush();
    c.pick(0);
    assert.equal(c.picked, juan);
    assert.equal(c.pickedMeta, '0917 123 4567 · Manila, Metro Manila');
    assert.equal(placeText({ city: ' Makati ', state: 'Metro Manila' }), 'Makati, Metro Manila');
    assert.equal(placeText({ state: 'Cebu' }), 'Cebu');
});

test('a field still holding the pick is untouched; the phone by its digits', () => {
    assert.ok(untouched('first_name', ' Juan', 'Juan'));
    assert.ok(!untouched('first_name', 'Juana', 'Juan'));
    assert.ok(untouched('phone', '917 123 4567', '09171234567'));
    assert.ok(!untouched('phone', '918 123 4567', '09171234567'));
});

/** A tiny DOM: inputs by name, each with a value; set() dispatches like the real one. */
function fields(values) {
    const els = {};
    for (const [name, value] of Object.entries(values)) {
        els[name] = { name, value, dispatchEvent() {}, focus() {} };
    }
    globalThis.Event = class { constructor(type) { this.type = type; } };
    globalThis.document = {
        querySelector: (sel) => els[(sel.match(/name="([^"]+)"/) ?? [])[1]] ?? null,
        getElementById: () => null,
    };
    const sent = [];
    globalThis.CustomEvent = class { constructor(type, init) { this.type = type; this.detail = init?.detail; } };
    globalThis.window = { dispatchEvent: (e) => sent.push(e) };

    return { els, sent };
}

test('× clears the pick and only the fields it filled that are unchanged', (t) => {
    t.after(() => { delete globalThis.document; delete globalThis.window; delete globalThis.CustomEvent; });
    const { els, sent } = fields({
        'recipient[first_name]': 'Juan', 'recipient[last_name]': 'Dela Cruz', 'recipient[phone]': '917 123 4567',
        'recipient[address_1]': 'Hand typed street', 'recipient[state]': 'Metro Manila', 'recipient[city]': 'Manila',
        'recipient[barangay]': 'Barangay 659', 'recipient[postcode]': '1000', 'recipient[codes][state]': '130000000',
    });
    const c = customerSearch({ url: '', customerId: 'cus_1', picked: juan });
    c.$nextTick = (fn) => fn();
    assert.equal(c.picked, juan);

    c.clearPick();
    assert.equal(c.customerId, '');
    assert.equal(c.picked, null);
    assert.equal(els['recipient[first_name]'].value, '');
    assert.equal(els['recipient[last_name]'].value, '');
    assert.equal(els['recipient[phone]'].value, '');
    assert.equal(els['recipient[address_1]'].value, 'Hand typed street');
    assert.deepEqual(sent.map((e) => [e.type, e.detail.address]), [['ph-fill', {}]]);
});

test('New customer without a pick keeps what was typed; a changed address stays', (t) => {
    t.after(() => { delete globalThis.document; delete globalThis.window; delete globalThis.CustomEvent; });
    const { els, sent } = fields({ 'recipient[first_name]': 'Typed', 'recipient[state]': 'Cebu', 'recipient[city]': 'Cebu City' });
    const c = customerSearch({ url: '' });
    c.$nextTick = (fn) => fn();
    c.pick(0); // no results: the New customer row
    assert.equal(els['recipient[first_name]'].value, 'Typed');
    assert.equal(sent.length, 0);

    const d = customerSearch({ url: '', customerId: 'cus_1', picked: juan });
    d.$nextTick = (fn) => fn();
    d.clearCustomer();
    assert.equal(sent.length, 0); // the province is no longer the picked one
    assert.equal(els['recipient[first_name]'].value, 'Typed');
});

test('a pick from an earlier submit comes back only with its customer id', () => {
    assert.equal(customerSearch({ url: '', customerId: '', picked: juan }).picked, null);
    assert.equal(customerSearch({ url: '', customerId: 'cus_1', picked: juan }).picked, juan);
});

test('typing a letter on the pick box re-opens the search with it', () => {
    const c = customerSearch({ url: '', customerId: 'cus_1', picked: juan });
    let prevented = false;
    c.change({ key: 'm', preventDefault() { prevented = true; } });
    assert.ok(prevented);
    assert.equal(c.searching, true);
    assert.equal(c.q, 'm');
    c.searching = false;
    c.change({ key: 'Tab' });
    c.change({ key: ' ' });
    assert.equal(c.searching, false);
    c.q = '';
    c.searching = true;
    c.blurred();
    assert.equal(c.searching, false);
});
