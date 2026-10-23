'use strict';
const assert = require('assert');
const p = require('../playwright/official_exhibition_suminoe.js');
const ids = ['3900', '5277', '4443', '3871', '5357', '5418'];
const exhs = ['6.83', '6.84', '6.93', '6.93', '6.87', '6.95'];
const laps = ['36.89', '36.95', '37.60', '37.88', '37.40', '38.47'];
const arounds = ['11.37', '11.39', '11.74', '11.83', '11.71', '11.95'];
const timings = ['F.03', 'F.13', 'F.02', 'F.02', '.00', '.03'];
function identity(race = 1, date = '20261023') {
  return `<script>var link_params={"current":${race},"link":"st01_RACENUM_.htm"};</script><iframe src="raceinfo.html?raceType=exhibition&raceDate=${date}&raceNumber=${race}"></iframe>`;
}
function startHtml({published = true, course = '123.456', mismatch = false} = {}) {
  const rows = ids.map((id, i) => `<tbody><tr><td class="waku0${i + 1}">${i + 1}</td><td class="racer_name"><li class="small">${i % 2 ? 'B1' : 'A2'}/${mismatch && i === 5 ? '9999' : id}</li></td></tr></tbody>`).join('');
  const display = ids.map((_, i) => `<dl><dt class="${timings[i].startsWith('F') ? 'f_l' : ''}">${published ? timings[i] : '-.--'}</dt><dd class="boat${i + 1}"></dd></dl>`).join('');
  return `${identity()}${published ? `<div class="course">[1回目]123.456 [2回目]${course}</div><table>${rows}</table><td class="st_tenji">${display}</td>` : 'NO DATA'}`;
}
function originalHtml({mismatch = false, published = true} = {}) {
  const rows = ids.map((id, i) => `<tbody><tr><td class="waku0${i + 1}">${i + 1}</td><td class="racer_name"><li class="small">A1/${mismatch && i === 4 ? '9999' : id}</li></td><td>52.0</td><td>-0.5</td><td>${published ? exhs[i] : '-.--'}</td><td>${published ? laps[i] : '-.--'}</td><td>${published ? arounds[i] : '-.--'}</td></tr></tbody>`).join('');
  return `${identity()}<table>${rows}</table>`;
}
let cases = 0;
const start = p.parseStartDisplay(startHtml(), '20261023', 1);
assert.equal(start.status, 'published'); assert.equal(start.boats.length, 6); assert.equal(start.boats[0].player_id, '3900'); assert.equal(start.boats[0].start_timing, 'F.03'); cases++;
assert.equal(p.parseStartDisplay(startHtml({published: false}), '20261023', 1).status, 'not_yet_published'); cases++;
const changed = p.parseStartDisplay(startHtml({course: '124.356'}), '20261023', 1);
assert.equal(changed.boats.find((b) => b.lane === '3').entry_course, 4); assert.equal(changed.boats.find((b) => b.lane === '4').entry_course, 3); cases++;
assert.throws(() => p.parseStartDisplay(startHtml({course: '123.455'}), '20261023', 1), /sequence is invalid/); cases++;
const original = p.parseOriginalData(originalHtml(), '20261023', 1);
assert.equal(original.length, 6); assert.equal(original[5].player_id, '5418'); assert.equal(original[5].around_time, '11.95'); assert.equal(original[0].straight_time, null); cases++;
assert.throws(() => p.mergePublished(start.boats, p.parseOriginalData(originalHtml({mismatch: true}), '20261023', 1), {}), /disagree/); cases++;
assert.throws(() => p.parseStartDisplay(startHtml(), '20261022', 1), /date or race label/); cases++;
(async () => {
  const calls = [];
  const result = await p.capture('20261023', 1, {fetchPage: async (url) => {calls.push(url); return {body: url.includes('st01') ? startHtml() : originalHtml(), completedAt: url.includes('st01') ? '2026-10-23T06:08:00.000Z' : '2026-10-23T06:08:01.000Z'};}, now: () => '2026-10-23T06:08:02.000Z'});
  assert.equal(calls.length, 2); assert.equal(result.race_code, '20261023SME01'); assert.equal(result.boats.length, 6); assert.equal(result.boats[0].start_timing, 'F.03'); assert.equal(result.boats[0].straight_time, null); assert.equal(result.boats[5].around_time, '11.95'); assert.equal(result.page_fetched_at.original_data, '2026-10-23T06:08:01.000Z'); cases++;
  const notYet = await p.capture('20261023', 1, {fetchPage: async () => ({body: startHtml({published: false}), completedAt: '2026-10-23T06:08:00.000Z'})});
  assert.equal(notYet.status, 'not_yet_published'); cases++;
  console.log(`PASS ${cases} Suminoe parser cases`);
})().catch((error) => { console.error(error.stack || String(error)); process.exitCode = 1; });
