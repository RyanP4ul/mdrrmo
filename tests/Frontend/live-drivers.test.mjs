import assert from 'node:assert/strict';
import test from 'node:test';
import { mergeDriverMove, readDriverMove } from '../../resources/js/lib/live-drivers.ts';

const move = { id: 7, name: 'Taylor Responder', lat: 14.8, lng: 120.9, teamName: 'Rescue', status: 'available', speed: 0, heading: 0,
    assignedReportId: null, report: null, updatedAt: '2030-01-01T00:00:00Z', sentAt: '2030-01-01T00:00:01Z', expiresAt: '2030-01-01T00:03:00Z' };

test('a first mobile fix creates a named driver marker without a vehicle', () => {
    const parsed = readDriverMove(move);
    assert.equal(parsed.name, 'Taylor Responder');
    const drivers = mergeDriverMove([], parsed);
    assert.equal(drivers.length, 1);
    assert.equal(drivers[0].id, 7);
    assert.equal('plate' in drivers[0], false);
});

test('live driver fixes move markers, replace assignment details and reject older events', () => {
    const drivers = mergeDriverMove([], move);
    const newer = { ...move, lat: 14.81, sentAt: '2030-01-01T00:00:11Z', assignedReportId: 3, report: { id: 3, reference: 'RPT-3', location: 'Hall' } };
    const moved = mergeDriverMove(drivers, newer);
    assert.equal(moved[0].lat, 14.81);
    assert.equal(moved[0].report.reference, 'RPT-3');
    assert.equal(mergeDriverMove(moved, move)[0].lat, 14.81);
    assert.equal(mergeDriverMove(moved, { ...newer, report: null, assignedReportId: null })[0].report, null);
});

test('malformed positions cannot become driver markers', () => {
    for (const invalid of [{ lat: NaN }, { lng: 181 }, { name: null }, { sentAt: 'bad' }, { expiresAt: 'bad' }]) {
        assert.equal(readDriverMove({ ...move, ...invalid }), null);
    }
});
