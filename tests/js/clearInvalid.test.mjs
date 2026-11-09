import test from 'node:test';
import assert from 'node:assert/strict';
import { clearInvalid } from '../../resources/js/firstInvalid.js';

function classes(list) {
    const set = new Set(list);
    return { remove: (c) => set.delete(c), contains: (c) => set.has(c) };
}

test('editing a marked control drops its mark, red edge and message', () => {
    const message = { hidden: false };
    const field = { classList: classes(['fm-field', 'fm-field--invalid']), querySelector: () => null };
    const attrs = { 'aria-invalid': 'true', 'aria-describedby': 'f-item-error' };
    const input = {
        classList: classes(['x-input', 'is-invalid']),
        getAttribute: (n) => attrs[n] ?? null,
        removeAttribute: (n) => delete attrs[n],
        querySelectorAll: () => [],
        ownerDocument: { getElementById: (id) => (id === 'f-item-error' ? message : null) },
        closest(sel) {
            if (sel === '[aria-invalid="true"]') return attrs['aria-invalid'] ? this : null;
            if (sel === '.fm-field') return field;
            return null;
        },
    };

    assert.equal(clearInvalid(input), true);
    assert.equal(attrs['aria-invalid'], undefined);
    assert.equal(input.classList.contains('is-invalid'), false);
    assert.equal(message.hidden, true);
    assert.equal(field.classList.contains('fm-field--invalid'), false);
});

test('editing an unmarked control changes nothing', () => {
    assert.equal(clearInvalid({ closest: () => null }), false);
    assert.equal(clearInvalid(null), false);
});
