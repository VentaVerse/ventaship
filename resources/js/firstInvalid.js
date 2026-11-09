// After a failed submit the page comes back with its errors under their fields: the cursor goes to the first invalid
// control in page order and it is scrolled into view (smoothly unless reduced motion is asked for). A group (radio
// tiles, segments) is invalid as a whole; its checked option, else its first, takes the focus.
const CONTROL = 'input:not([type="hidden"]), select, textarea, button';

function visible(el) {
    return typeof el.getClientRects !== 'function' || el.getClientRects().length > 0;
}

/** The element to focus for an `[aria-invalid="true"]` node: itself when it is a control, else one inside it. */
export function focusTarget(node) {
    if (node.matches?.(CONTROL)) {
        return node.disabled ? null : node;
    }
    const checked = node.querySelector?.('input:checked:not([disabled])');
    if (checked && visible(checked)) {
        return checked;
    }
    const options = [...(node.querySelectorAll?.(CONTROL) ?? [])];

    return options.find((el) => !el.disabled && visible(el)) ?? null;
}

/** The first invalid control under `root`, skipping hidden or disabled ones. */
export function firstInvalid(root) {
    for (const node of root?.querySelectorAll?.('[aria-invalid="true"]') ?? []) {
        const target = focusTarget(node);
        if (target && visible(target)) {
            return target;
        }
    }

    return null;
}

export function focusFirstInvalid(doc, win) {
    const target = firstInvalid(doc.querySelector('main') ?? doc.body);
    if (!target) {
        return null;
    }
    const reduce = win?.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false;
    target.focus({ preventScroll: true });
    (target.closest?.('.fm-field, fieldset, [role="radiogroup"]') ?? target).scrollIntoView?.({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });

    return target;
}
