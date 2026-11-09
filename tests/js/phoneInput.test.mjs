import test from 'node:test';
import assert from 'node:assert/strict';
import { nationalDigits, phoneInput } from '../../resources/js/phoneInput.js';

test('written forms all become the 10 national digits', () => {
    for (const raw of ['0917 123 4567', '+63 917-123-4567', '639171234567', '9171234567', '(0917) 123-4567', '+639171234567']) {
        assert.equal(nationalDigits(raw), '9171234567', raw);
    }
});

test('digits only, capped at 10, partial typing kept', () => {
    assert.equal(nationalDigits('91712345678999'), '9171234567');
    assert.equal(nationalDigits('abc'), '');
    assert.equal(nationalDigits('6'), '6');
    assert.equal(nationalDigits('63'), '');
    assert.equal(nationalDigits('0'), '');
    assert.equal(nationalDigits('917'), '917');
    assert.equal(nationalDigits(null), '');
});

test('clean rewrites the field', () => {
    const el = { value: '+63 917 123 4567' };
    phoneInput().clean({ target: el });
    assert.equal(el.value, '9171234567');
});

test('paste replaces an empty field with the sanitised number', () => {
    const el = { value: '', selectionStart: 0, selectionEnd: 0, dispatchEvent() { this.fired = true; } };
    let prevented = false;
    globalThis.Event = class { constructor(t) { this.type = t; } };
    phoneInput().paste({ target: el, preventDefault() { prevented = true; }, clipboardData: { getData: () => '0917 123 4567' } });
    assert.ok(prevented);
    assert.equal(el.value, '9171234567');
    assert.ok(el.fired);
});
