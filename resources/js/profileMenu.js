import { rowMenu } from './rowMenu.js';

const MODES = ['light', 'dark', 'system'];

// The theme the boot script stored, or 'system' when there is none or it is not one of the three.
export function currentMode(theme) {
    const mode = theme?.get?.();

    return MODES.includes(mode) ? mode : 'system';
}

// The top-right profile menu: rowMenu's keyboard, placement and closing, plus the theme choice. `mode` only drives
// aria-checked; the lit segment comes from CSS on data-theme-mode, so nothing waits on Alpine at first paint.
export function profileMenu(theme = globalThis.window?.ventashipTheme) {
    return {
        ...rowMenu(),
        mode: currentMode(theme),

        setTheme(mode) {
            if (!MODES.includes(mode)) {
                return;
            }
            theme?.set?.(mode);
            this.mode = mode;
        },
    };
}
