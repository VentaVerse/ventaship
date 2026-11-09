import test from 'node:test';
import assert from 'node:assert/strict';
import { caretAfterDigits, groupDigits, nationalDigits, phoneInput } from '../../resources/js/phoneInput.js';

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

test('the digits show grouped 917 555 5555 as they are typed', () => {
    assert.equal(groupDigits('9175555555'), '917 555 5555');
    assert.equal(groupDigits('9175'), '917 5');
    assert.equal(groupDigits('917'), '917');
    assert.equal(groupDigits(''), '');
    assert.equal(groupDigits('917 555 55559'), '917 555 5555');
});

test('typing regroups and keeps the caret after the same digit', () => {
    const doc = {};
    const el = { value: '+63 9175555555', selectionStart: 14, ownerDocument: doc, setSelectionRange(a) { this.caret = a; } };
    doc.activeElement = el;
    phoneInput().typed({ target: el });
    assert.equal(el.value, '917 555 5555');

    el.value = '917 5525 5555'; // a digit typed after "917 55"
    el.selectionStart = 7;
    phoneInput().typed({ target: el });
    assert.equal(el.value, '917 552 5555');
    assert.equal(el.caret, 7);
    assert.equal(caretAfterDigits('917 552 5555', 3), 3);
    assert.equal(caretAfterDigits('917 552 5555', 4), 5);
    assert.equal(caretAfterDigits('917', 0), 0);
});

test('the form posts the 10 digits, not the grouping', () => {
    const listeners = {};
    const input = { name: 'phone', value: '917 555 5555', disabled: false };
    input.form = { addEventListener: (type, fn) => { listeners[type] = fn; } };
    const p = phoneInput();
    p.$el = { querySelector: () => input };
    p.init();
    const data = new Map();
    listeners.formdata({ formData: { set: (k, v) => data.set(k, v) } });
    assert.equal(data.get('phone'), '9175555555');
});
