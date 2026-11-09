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

export function phoneInput() {
    return {
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
