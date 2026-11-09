// Copies text to the clipboard. The Clipboard API only exists on secure origins (https, localhost), and the owner
// opens Ventaship over plain http on the LAN, so when it is missing or refuses, the text is copied through a
// temporary off-screen textarea and execCommand('copy'). The textarea is removed straight away: a secret never
// stays in the DOM. Resolves true when either way worked.
export async function copyText(value, env = {}) {
    const clipboard = 'clipboard' in env ? env.clipboard : globalThis.navigator?.clipboard;
    const doc = env.document ?? globalThis.document;

    if (typeof clipboard?.writeText === 'function') {
        try {
            await clipboard.writeText(value);
            return true;
        } catch (e) {
            // Fall through to the textarea.
        }
    }

    return copyWithTextarea(value, doc);
}

function copyWithTextarea(value, doc) {
    if (!doc?.body || typeof doc.execCommand !== 'function') {
        return false;
    }
    const focused = doc.activeElement;
    const area = doc.createElement('textarea');
    area.value = value;
    area.setAttribute('readonly', '');
    area.setAttribute('aria-hidden', 'true');
    area.setAttribute('tabindex', '-1');
    area.className = 'sr-only';
    doc.body.appendChild(area);
    try {
        area.select();
        area.setSelectionRange?.(0, value.length);
        return doc.execCommand('copy') === true;
    } catch (e) {
        return false;
    } finally {
        area.value = '';
        area.remove();
        focused?.focus?.();
    }
}
