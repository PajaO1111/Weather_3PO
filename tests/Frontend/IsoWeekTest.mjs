import assert from 'node:assert/strict';
import { isoWeekOf, parseWeek, shiftDate, weekKey, weekList, weeksInYear, weekStart } from '../../public/assets/js/isoweek.js';

// Přelomy roku: ISO rok se liší od kalendářního.
assert.deepEqual(isoWeekOf('2026-01-01'), { year: 2026, week: 1 });
assert.deepEqual(isoWeekOf('2027-01-01'), { year: 2026, week: 53 });
assert.deepEqual(isoWeekOf('2027-01-03'), { year: 2026, week: 53 });
assert.deepEqual(isoWeekOf('2027-01-04'), { year: 2027, week: 1 });
assert.deepEqual(isoWeekOf('2024-12-30'), { year: 2025, week: 1 });
assert.deepEqual(isoWeekOf('2021-01-03'), { year: 2020, week: 53 });
assert.deepEqual(isoWeekOf('2026-10-25'), { year: 2026, week: 43 }); // neděle se změnou času
assert.equal(weeksInYear(2026), 53);
assert.equal(weeksInYear(2025), 52);
assert.equal(weekStart(2026, 1), '2025-12-29');
assert.equal(weekStart(2026, 53), '2026-12-28');
assert.equal(shiftDate('2026-03-28', 2), '2026-03-30');
assert.equal(weekKey(2026, 5), '2026-W05');

const w43 = parseWeek('2026-W43');
assert.equal(w43.days[0], '2026-10-19');
assert.equal(w43.days[6], '2026-10-25');
assert.equal(w43.days.length, 7);
assert.equal(parseWeek('2025-W53'), null);
assert.equal(parseWeek('2026-W00'), null);
assert.equal(parseWeek('2026-43'), null);
assert.equal(parseWeek('<script>'), null);
assert.equal(parseWeek(null), null);

const list = weekList('2026-01-07', 2025);
assert.equal(list[0].key, '2026-W02');
assert.equal(list[2].key, '2025-W52');
assert.equal(list.at(-1).key, '2025-W01');
assert.equal(list.length, 54);
assert.ok(list.every((w, i) => i === 0 || shiftDate(w.days[0], 7) === list[i - 1].days[0]), 'souvislé týdny');

console.log('PASS iso week: year boundaries, week 53, DST Sunday, parsing and week list.');
