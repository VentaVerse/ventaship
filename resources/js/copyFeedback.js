// What a Copy button shows after a copy: "Copied" or "Couldn't copy" for a moment, then "Copy" again. The same
// words go to the button's live region (`note`), cleared on reset so the next copy is announced again.
export const COPY_FEEDBACK_MS = 1500;

export const COPY_NOTES = { ok: 'Copied', fail: "Couldn't copy" };

export function copyFeedback(target, outcome, timers = {}) {
    const set = timers.setTimeout ?? globalThis.setTimeout;
    const clear = timers.clearTimeout ?? globalThis.clearTimeout;
    const ms = timers.ms ?? COPY_FEEDBACK_MS;

    clear(target.copyTimer);
    target.copied = outcome;
    target.copyNote = COPY_NOTES[outcome] ?? '';
    target.copyTimer = set(() => {
        target.copied = 'idle';
        target.copyNote = '';
        target.copyTimer = null;
    }, ms);
}
