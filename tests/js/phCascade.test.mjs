import test from 'node:test';
import assert from 'node:assert/strict';
import { phCascade } from '../../resources/js/phCascade.js';

function make(overrides = {}) {
    const c = phCascade({
        url: '/ph',
        sel: { state: '', city: '', district: '', barangay: '' },
        free: { state: false, city: false, district: false, barangay: false },
        text: { state: '', city: '', district: '', barangay: '' },
        postcode: '',
        lists: { provinces: [{ code: 'P1', name: 'P1' }], cities: [], districts: [], barangays: [] },
        ...overrides,
    });
    c.pending = [];
    c.fetchList = (level, parent) => new Promise((resolve, reject) => c.pending.push({ level, parent, resolve, reject }));
    return c;
}

const tick = () => new Promise((r) => setImmediate(r));
const take = (c, level, parent) => c.pending.findLast((p) => p.level === level && p.parent === parent);

test('changing city twice with out-of-order responses keeps the last city', async () => {
    const c = make();
    c.sel.state = 'P1';
    c.lists.cities = [{ code: 'A', name: 'A', zip: '1' }, { code: 'B', name: 'B', zip: '2' }];

    c.sel.city = 'A';
    const first = c.changed('city');
    c.sel.city = 'B';
    const second = c.changed('city');

    take(c, 'district', 'B').resolve([{ code: 'DB', name: 'DB' }]);
    await tick();
    take(c, 'district', 'A').resolve([{ code: 'DA', name: 'DA' }]);
    await Promise.all([first, second]);

    assert.deepEqual(c.lists.districts, [{ code: 'DB', name: 'DB' }]);
    assert.equal(c.pending.filter((p) => p.level === 'barangay').length, 0);
    assert.equal(c.postcode, '2');
});

test('stale city response does not continue to load barangays of the old city', async () => {
    const c = make();
    c.lists.cities = [{ code: 'A', name: 'A', zip: '' }, { code: 'B', name: 'B', zip: '' }];
    c.sel.city = 'A';
    const first = c.changed('city');
    c.sel.city = 'B';
    const second = c.changed('city');
    take(c, 'district', 'A').resolve([]);
    await first;
    assert.equal(c.pending.filter((p) => p.level === 'barangay').length, 0);
    take(c, 'district', 'B').resolve([]);
    await tick();
    take(c, 'barangay', 'B').resolve([{ code: 'X', name: 'X' }]);
    await second;
    assert.deepEqual(c.lists.barangays, [{ code: 'X', name: 'X' }]);
});

test('province reset to blank while cities load leaves the city list empty', async () => {
    const c = make();
    c.sel.state = 'P1';
    const loading = c.changed('state');
    c.sel.state = '';
    await c.changed('state');
    take(c, 'city', 'P1').resolve([{ code: 'A', name: 'A', zip: '' }]);
    await loading;
    assert.deepEqual(c.lists.cities, []);
});

test('postcode follows the city zip until the user edits it', async () => {
    const c = make({ postcode: '9999' });
    c.lists.cities = [{ code: 'A', name: 'A', zip: '1006' }, { code: 'B', name: 'B', zip: '' }];

    c.sel.city = 'A';
    let p = c.changed('city');
    assert.equal(c.postcode, '1006');
    take(c, 'district', 'A').resolve([{ code: 'D', name: 'D' }]);
    await p;

    c.sel.city = 'B';
    p = c.changed('city');
    assert.equal(c.postcode, '');
    take(c, 'district', 'B').resolve([{ code: 'D', name: 'D' }]);
    await p;

    c.postcode = '1234';
    c.postcodeEdited = true;
    c.sel.city = 'A';
    p = c.changed('city');
    assert.equal(c.postcode, '1234');
    take(c, 'district', 'A').resolve([{ code: 'D', name: 'D' }]);
    await p;
});

test('failed load sets an error and retry loads again', async () => {
    const c = make();
    c.sel.state = 'P1';
    const loading = c.changed('state');
    take(c, 'city', 'P1').reject(new Error('x'));
    await loading;
    assert.deepEqual(c.error, { from: 'state' });
    assert.deepEqual(c.lists.cities, []);

    c.pending.length = 0;
    const retrying = c.retry();
    assert.equal(c.error, null);
    take(c, 'city', 'P1').resolve([{ code: 'A', name: 'A', zip: '' }]);
    await retrying;
    assert.equal(c.lists.cities.length, 1);
});
