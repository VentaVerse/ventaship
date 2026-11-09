import test from 'node:test';
import assert from 'node:assert/strict';
import { cents, itemText, normalisePrice, peso, shipmentForm } from '../../resources/js/shipmentForm.js';

test('the value parses to centavos without floats', () => {
    assert.equal(cents('500.1'), 50010n);
    assert.equal(cents('0.29'), 29n);
    assert.equal(cents('12'), 1200n);
    assert.equal(cents(''), 0n);
    assert.equal(cents('1.234'), 0n);
    assert.equal(cents('abc'), 0n);
});

test('peso format', () => {
    assert.equal(peso(130017n), '₱1,300.17');
    assert.equal(peso(0n), '₱0.00');
    assert.equal(peso(999999999999n), '₱9,999,999,999.99');
});

test('normalisation matches the server', () => {
    assert.equal(normalisePrice('.5'), '0.50');
    assert.equal(normalisePrice('5.'), '5.00');
    assert.equal(normalisePrice('+5'), '5');
    assert.equal(normalisePrice(' 1.234 '), '1.234');
    assert.equal(normalisePrice('.'), '.');
    assert.equal(cents('.5'), 50n);
    assert.equal(cents('5.'), 500n);
    assert.equal(cents('+2.5'), 250n);
});

test('courier fields show only for their courier', async () => {
    const { courierFields } = await import('../../resources/js/shipmentForm.js');

    const quadx = courierFields('quadx', 'box');
    assert.deepEqual(
        { service: quadx.service, weight: quadx.weight, size: quadx.size, category: quadx.category, shippingPayment: quadx.shippingPayment, handover: quadx.handover, pickupDate: quadx.pickupDate, note: quadx.note },
        { service: true, weight: false, size: false, category: false, shippingPayment: true, handover: false, pickupDate: false, note: true },
    );

    const spx = courierFields('spx', 'small');
    assert.equal(spx.service, false);
    assert.equal(spx.weight, true);
    assert.equal(spx.size, false);
    assert.equal(spx.category, true);
    assert.equal(spx.shippingPayment, true);
    assert.equal(spx.handover, true);
    assert.equal(spx.pickupDate, false);
    assert.equal(spx.note, true);
    assert.equal(courierFields('spx', 'custom').size, true);
    assert.equal(courierFields('spx', 'custom').sizeRequired, true);

    const lbc = courierFields('lbc', '45');
    assert.equal(lbc.weight, true);
    assert.equal(lbc.size, false);
    assert.equal(lbc.addSize, false);
    assert.equal(lbc.category, false);
    assert.equal(lbc.shippingPayment, false);
    assert.equal(lbc.handover, false);
    assert.equal(lbc.pickupDate, true);
    assert.equal(lbc.note, false);
    assert.equal(courierFields('lbc', '16').size, true);
    assert.equal(courierFields('lbc', '16').sizeRequired, true);
    assert.equal(courierFields('lbc', '63').addSize, true);
    assert.equal(courierFields('lbc', '63').size, false);
    assert.equal(courierFields('lbc', '63', true).size, true);
    assert.equal(courierFields('lbc', '63', true).sizeRequired, false);
    assert.equal(courierFields('quadx', '16').size, false);
});

test('switching courier clears the other courier\'s choices', async () => {
    const { courierReset } = await import('../../resources/js/shipmentForm.js');

    assert.deepEqual(courierReset('spx', { parcel: null, firstDate: '2026-11-16' }), {
        parcel: '', length: '', width: '', height: '', sizeOpen: false, shippingPayment: 'sender_pay',
        category: 'General merchandise', handover: 'dropoff', slot: '', slots: [], slotState: 'idle', pickupDate: '2026-11-16',
    });
    assert.equal(courierReset('quadx', { parcel: 'medium-pouch', firstDate: '' }).parcel, 'medium-pouch');
    assert.equal(courierReset('quadx', { parcel: null, firstDate: '' }).shippingPayment, 'cash');
    assert.equal(courierReset('lbc', { parcel: null, firstDate: '' }).shippingPayment, '');
});

test('the SPX pickup-time query carries the chosen pickup address', async () => {
    const { pickupParams } = await import('../../resources/js/shipmentForm.js');
    const params = pickupParams({ contact_name: 'Venta', contact_phone: '0918', address_1: '5 Shaw', address_2: null, city: 'Manila', codes: { state: 'NCR', city: 'MNL' }, country: 'PH' });

    assert.deepEqual(params, [
        ['pickup[contact_name]', 'Venta'], ['pickup[contact_phone]', '0918'], ['pickup[address_1]', '5 Shaw'], ['pickup[city]', 'Manila'],
        ['pickup[codes][state]', 'NCR'], ['pickup[codes][city]', 'MNL'],
    ]);
    assert.deepEqual(pickupParams(undefined), []);
});

test('the summary shows the item text, or a dash when blank', () => {
    assert.equal(itemText('  Assorted goods '), 'Assorted goods');
    assert.equal(itemText('2 × Shirt, 1 × Cap'), '2 × Shirt, 1 × Cap');
    assert.equal(itemText('   '), '—');
    assert.equal(itemText(undefined), '—');
});

test('the form holds one item field, without rows', () => {
    const form = shipmentForm({ account: 'acc_1', service: '', method: 'prepaid', insure: false, value: '', item: '2 × Shirt', courier: {} });
    assert.equal(form.item, '2 × Shirt');
    assert.equal(form.itemText, '2 × Shirt');
    form.item = '';
    assert.equal(form.itemText, '—');
    assert.equal('rows' in form, false);
    assert.equal(typeof form.add, 'undefined');
    assert.equal(typeof form.remove, 'undefined');
});
