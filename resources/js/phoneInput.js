// The +63 mobile field: only the 10 digits after the fixed prefix are typed. Pasted or filled numbers in any written
// form ("0917 123 4567", "+63 917-123-4567", "639171234567") become the national 10 digits.
export const PH_DIGITS = 10;

export function nationalDigits(value) {
    let d = String(value ?? '').replace(/\D+/g, '');
    if (d.startsWith('63')) {
        d = d.slice(2);
    }
    if (d.startsWith('0')) {
        d = d.slice(1);
    }

    return d.slice(0, PH_DIGITS);
}

/** The national digits as they are shown: 917 555 5555 (partial numbers grouped as far as they go). */
export function groupDigits(digits) {
    const d = String(digits ?? '').replace(/\D+/g, '').slice(0, PH_DIGITS);

    return [d.slice(0, 3), d.slice(3, 6), d.slice(6)].filter((p) => p !== '').join(' ');
}

/** Where the caret goes in `shown` so that the same number of digits sits before it. */
export function caretAfterDigits(shown, count) {
    if (count <= 0) {
        return 0;
    }
    let seen = 0;
    for (let i = 0; i < shown.length; i++) {
        if (/\d/.test(shown[i]) && ++seen === count) {
            return i + 1;
        }
    }

    return shown.length;
}

export function phoneInput() {
    return {
        // The grouping is display only: the form posts the 10 digits.
        init() {
            const input = this.$el?.querySelector?.('input');
            const form = input?.form;
            if (!input || !form) {
                return;
            }
            form.addEventListener('formdata', (e) => {
                if (input.name && !input.disabled) {
                    e.formData.set(input.name, nationalDigits(input.value));
                }
            });
        },

        // Typing keeps only digits, grouped 917 555 5555, with the caret after the same digit it followed.
        typed(e) {
            const el = e.target;
            const caret = el.selectionStart ?? el.value.length;
            const before = el.value.slice(0, caret).replace(/\D+/g, '').length;
            this.clean(e);
            const shown = groupDigits(el.value);
            if (el.value !== shown) {
                el.value = shown;
            }
            if (typeof el.setSelectionRange === 'function' && el.ownerDocument?.activeElement === el) {
                const at = caretAfterDigits(shown, Math.min(before, el.value.replace(/\D+/g, '').length));
                el.setSelectionRange(at, at);
            }
        },

        clean(e) {
            const el = e.target;
            const next = nationalDigits(el.value);
            if (el.value !== next) {
                el.value = next;
            }
        },

        paste(e) {
            const text = e.clipboardData?.getData('text');
            if (typeof text !== 'string') {
                return;
            }
            e.preventDefault();
            const el = e.target;
            const start = el.selectionStart ?? el.value.length;
            const end = el.selectionEnd ?? el.value.length;
            // a paste into an empty or fully selected field is the whole number; otherwise digits are added at the caret
            const whole = el.value === '' || (start === 0 && end === el.value.length);
            el.value = whole ? nationalDigits(text) : (el.value.slice(0, start) + text.replace(/\D+/g, '') + el.value.slice(end)).replace(/\D+/g, '').slice(0, PH_DIGITS);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        },
    };
}
