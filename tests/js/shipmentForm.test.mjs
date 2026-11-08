import test from 'node:test';
import assert from 'node:assert/strict';
import { cents, normalisePrice, normaliseQuantity, peso } from '../../resources/js/shipmentForm.js';

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
    assert.equal(normaliseQuantity(' +5 '), '5');
    assert.equal(cents('+2.5'), 250n);
});
