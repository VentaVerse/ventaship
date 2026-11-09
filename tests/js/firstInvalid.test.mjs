import test from 'node:test';
import assert from 'node:assert/strict';
import { firstInvalid, focusFirstInvalid, focusTarget } from '../../resources/js/firstInvalid.js';

/** A minimal element: tag, attributes, children, shown or not. */
function el(tag, attrs = {}, children = [], shown = true) {
    const node = {
        tag, attrs, children, disabled: !!attrs.disabled, checked: !!attrs.checked, focused: false, scrolled: null,
        getClientRects: () => (shown ? [{}] : []),
        matches(sel) {
            if (sel.includes('input') && tag === 'input') {
                return attrs.type !== 'hidden';
            }
            return ['select', 'textarea', 'button'].includes(tag) && sel.includes(tag);
        },
        all() {
            return children.flatMap((c) => [c, ...c.all()]);
        },
        querySelectorAll(sel) {
            if (sel === '[aria-invalid="true"]') {
                return this.all().filter((c) => c.attrs['aria-invalid'] === 'true');
            }
            return this.all().filter((c) => c.matches(sel));
        },
        querySelector(sel) {
            if (sel.startsWith('input:checked')) {
                return this.all().find((c) => c.tag === 'input' && c.checked && !c.disabled) ?? null;
            }
            return null;
        },
        focus(opts) { this.focused = opts; },
        closest: () => null,
        scrollIntoView(opts) { this.scrolled = opts; },
    };

    return node;
}

test('the first invalid control in page order, skipping hidden ones', () => {
    const hidden = el('input', { 'aria-invalid': 'true' }, [], false);
    const first = el('input', { 'aria-invalid': 'true' });
    const second = el('select', { 'aria-invalid': 'true' });
    const root = el('main', {}, [el('input'), hidden, first, second]);
    assert.equal(firstInvalid(root), first);
});

test('a group gives its checked option, else its first enabled one', () => {
    const a = el('input', { type: 'radio', disabled: true });
    const b = el('input', { type: 'radio' });
    const c = el('input', { type: 'radio', checked: true });
    assert.equal(focusTarget(el('div', { 'aria-invalid': 'true' }, [a, b, c])), c);
    c.checked = false;
    assert.equal(focusTarget(el('div', { 'aria-invalid': 'true' }, [a, b, c])), b);
    assert.equal(focusTarget(el('input', { disabled: true })), null);
});

test('focus moves without a jump, then scrolls; smooth unless reduced motion', () => {
    const target = el('input', { 'aria-invalid': 'true' });
    const main = el('main', {}, [target]);
    const doc = { querySelector: () => main, body: main };
    const reduce = (matches) => ({ matchMedia: () => ({ matches }) });

    assert.equal(focusFirstInvalid(doc, reduce(false)), target);
    assert.deepEqual(target.focused, { preventScroll: true });
    assert.deepEqual(target.scrolled, { block: 'center', behavior: 'smooth' });
    focusFirstInvalid(doc, reduce(true));
    assert.equal(target.scrolled.behavior, 'auto');
    assert.equal(focusFirstInvalid({ querySelector: () => el('main'), body: null }, reduce(false)), null);
});
