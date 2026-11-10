import test from 'node:test';
import assert from 'node:assert/strict';
import { currentMode, profileMenu } from '../../resources/js/profileMenu.js';
import { MENU_ITEMS } from '../../resources/js/rowMenu.js';

function fakeTheme(start) {
    const calls = [];
    return { calls, get: () => start, set: (mode) => { calls.push(mode); start = mode; } };
}

test('reads the stored mode, falling back to system', () => {
    assert.equal(currentMode(fakeTheme('dark')), 'dark');
    assert.equal(currentMode(fakeTheme('purple')), 'system');
    assert.equal(currentMode(fakeTheme(null)), 'system');
    assert.equal(currentMode(undefined), 'system');
});

test('setTheme stores the mode and updates aria state', () => {
    const theme = fakeTheme('light');
    const menu = profileMenu(theme);
    assert.equal(menu.mode, 'light');

    menu.setTheme('dark');
    assert.equal(menu.mode, 'dark');
    assert.deepEqual(theme.calls, ['dark']);

    menu.setTheme('bogus');
    assert.equal(menu.mode, 'dark');
    assert.deepEqual(theme.calls, ['dark']);
});

test('keeps the row menu behaviour and starts closed', () => {
    const menu = profileMenu(fakeTheme('system'));
    assert.equal(menu.open, false);
    for (const fn of ['toggle', 'show', 'close', 'onKey', 'onTriggerKey', 'onFocusOut']) {
        assert.equal(typeof menu[fn], 'function', fn);
    }
});

test('arrow keys reach the theme choices too', () => {
    assert.match(MENU_ITEMS, /\[role="menuitem"\]/);
    assert.match(MENU_ITEMS, /\[role="menuitemradio"\]/);
});

test('works without a theme store', () => {
    const menu = profileMenu(undefined);
    assert.equal(menu.mode, 'system');
    menu.setTheme('light');
    assert.equal(menu.mode, 'light');
});
