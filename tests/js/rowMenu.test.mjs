import test from 'node:test';
import assert from 'node:assert/strict';
import { menuPosition, nextIndex } from '../../resources/js/rowMenu.js';

const viewport = { width: 1366, height: 768 };
const menu = { width: 160, height: 120 };

test('opens below the trigger, right edges aligned', () => {
    const trigger = { top: 100, bottom: 130, left: 1200, right: 1230 };
    assert.deepEqual(menuPosition(trigger, menu, viewport), { top: 134, left: 1070 });
});

test('flips above when there is no room below', () => {
    const trigger = { top: 700, bottom: 730, left: 1200, right: 1230 };
    assert.deepEqual(menuPosition(trigger, menu, viewport), { top: 576, left: 1070 });
});

test('stays below when there is no room above either, clamped to the viewport', () => {
    const trigger = { top: 40, bottom: 70, left: 10, right: 40 };
    const tall = { width: 160, height: 760 };
    assert.deepEqual(menuPosition(trigger, tall, viewport), { top: 8, left: 8 });
});

test('never runs off the left or right edge on a phone', () => {
    const phone = { width: 390, height: 844 };
    assert.equal(menuPosition({ top: 100, bottom: 144, left: 0, right: 44 }, menu, phone).left, 8);
    assert.equal(menuPosition({ top: 100, bottom: 144, left: 380, right: 400 }, menu, phone).left, 390 - 8 - 160);
});

test('arrow keys wrap, Home and End jump', () => {
    assert.equal(nextIndex('ArrowDown', 0, 3), 1);
    assert.equal(nextIndex('ArrowDown', 2, 3), 0);
    assert.equal(nextIndex('ArrowUp', 0, 3), 2);
    assert.equal(nextIndex('ArrowUp', -1, 3), 2);
    assert.equal(nextIndex('Home', 2, 3), 0);
    assert.equal(nextIndex('End', 0, 3), 2);
    assert.equal(nextIndex('a', 1, 3), null);
    assert.equal(nextIndex('ArrowDown', 0, 0), null);
});
