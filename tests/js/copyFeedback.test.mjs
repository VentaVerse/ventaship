import test from 'node:test';
import assert from 'node:assert/strict';
import { copyFeedback, COPY_FEEDBACK_MS } from '../../resources/js/copyFeedback.js';

test('a copy shows Copied, then Copy again after 1.5 s', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const field = { copied: 'idle', copyNote: '', copyTimer: null };

    copyFeedback(field, 'ok');
    assert.equal(field.copied, 'ok');
    assert.equal(field.copyNote, 'Copied');

    t.mock.timers.tick(COPY_FEEDBACK_MS - 1);
    assert.equal(field.copied, 'ok');
    t.mock.timers.tick(1);
    assert.equal(field.copied, 'idle');
    assert.equal(field.copyNote, '');
    assert.equal(COPY_FEEDBACK_MS, 1500);
});

test("a failure says Couldn't copy", (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const field = { copied: 'idle', copyNote: '', copyTimer: null };
    copyFeedback(field, 'fail');
    assert.equal(field.copied, 'fail');
    assert.equal(field.copyNote, "Couldn't copy");
    t.mock.timers.tick(COPY_FEEDBACK_MS);
    assert.equal(field.copied, 'idle');
});

test('a second copy restarts the timer', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const field = { copied: 'idle', copyNote: '', copyTimer: null };
    copyFeedback(field, 'ok');
    t.mock.timers.tick(1000);
    copyFeedback(field, 'ok');
    t.mock.timers.tick(1000);
    assert.equal(field.copied, 'ok');
    t.mock.timers.tick(500);
    assert.equal(field.copied, 'idle');
});
