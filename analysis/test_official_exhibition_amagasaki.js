#!/usr/bin/env node
'use strict';

// Fully synthetic parser tests: no network, DB, model, or production path.
const assert = require('assert');
const adapter = require('../playwright/official_exhibition_amagasaki');

const DATE = '20261021';
const RACE = 1;
const ids = ['9001', '9002', '9003', '9004', '9005', '9006'];
const times = ['6.71', '6.72', '6.73', '6.74', '6.75', '6.76'];

function page(body) { return `<main data-day="${DATE}" data-race="${RACE}">${body}</main>`; }
function direct(values = times) {
  return page(values.map((value, i) => `<tr><td class="col1">${i + 1}</td><td><span class="com-toban">${ids[i]}</span></td><td class="col3">${value}</td></tr>`).join(''));
}
function start(st = ['.11', '.21', '.08', '.03', '.13', '.12'], courses = [1, 2, 3, 4, 5, 6]) {
  return page(ids.map((id, i) => `<tr><td class="col1">${courses[i]}</td><td class="col2">${i + 1}</td><td><span class="com-toban">${id}</span></td></tr>`).join('') + st.map((value) => `<span class="st_area">${value}</span>`).join(''));
}
function original(overrides = {}) {
  return page(ids.map((id, i) => `<tr><td class="col1">${i + 1}</td><td><span class="com-toban">${id}</span></td><td class="col5">${times[i]}</td><td class="col6">37.${10 + i}</td><td class="col7">11.${30 + i}</td></tr>`).join('')).replaceAll('37.12', overrides.lap || '37.12');
}
function mustThrow(code, fn) { assert.throws(fn, (error) => error && error.code === code); }

const parsedDirect = adapter.parseDirectInfo(direct(), DATE, RACE);
assert.equal(parsedDirect.status, 'published');
assert.equal(adapter.parseDirectInfo(direct(['-.--', '-.--', '-.--', '-.--', '-.--', '-.--']), DATE, RACE).status, 'not_yet_published');
const parsedStart = adapter.parseStartDisplay(start(['F.01', 'L.02', '.03', '.04', '.05', '.06']), DATE, RACE);
assert.equal(parsedStart[0].start_timing, 'F.01');
assert.equal(parsedStart[1].start_timing, 'L.02');
const parsedOriginal = adapter.parseOriginalData(original(), DATE, RACE);
const merged = adapter.mergePublished(parsedDirect.boats, parsedStart, parsedOriginal, { date: DATE, race: RACE, fetchedAt: '2026-10-21T00:00:00.000Z', sourceUrls: { direct_info: 'd', start_display: 's', original_data: 'o' } });
assert.equal(merged.boats.length, 6);
assert.equal(merged.boats[0].straight_time, null);
assert.equal(merged.boats[0].availability.straight_time, 'not_published');
assert.equal(adapter.parseStartDisplay(start(undefined, [2, 1, 3, 4, 5, 6]), DATE, RACE)[0].entry_course, '2');
mustThrow('RACE_IDENTITY_MISMATCH', () => adapter.parseDirectInfo(direct().replace('20261021', '20261022'), DATE, RACE));
mustThrow('PLAYER_ID_MISMATCH', () => adapter.parseDirectInfo(direct().replace('9006', '9005'), DATE, RACE));
mustThrow('INVALID_EXHIBITION_TIME', () => adapter.parseDirectInfo(direct().replace('class="col3"', 'class="changed-column"'), DATE, RACE));
mustThrow('ENTRY_COURSE_MISMATCH', () => adapter.parseStartDisplay(start().replace('class="col1">6', 'class="col1">5'), DATE, RACE));
mustThrow('START_TIMING_MISMATCH', () => adapter.parseStartDisplay(start(['.11']), DATE, RACE));
mustThrow('ORIGINAL_DATA_INCOMPLETE', () => adapter.parseOriginalData(original({ lap: '-.--' }), DATE, RACE));
const changedId = adapter.parseOriginalData(original().replace('9006', '9999'), DATE, RACE);
mustThrow('CROSS_PAGE_IDENTITY_MISMATCH', () => adapter.mergePublished(parsedDirect.boats, parsedStart, changedId, { date: DATE, race: RACE, fetchedAt: 'x', sourceUrls: {} }));
mustThrow('EXHIBITION_TIME_MISMATCH', () => adapter.mergePublished(parsedDirect.boats, parsedStart, adapter.parseOriginalData(original().replace('6.71', '6.99'), DATE, RACE), { date: DATE, race: RACE, fetchedAt: 'x', sourceUrls: {} }));
assert.equal(adapter.officialUrl(DATE, RACE, 2), 'https://boatrace-amagasaki.jp/modules/yosou/group-cyokuzen.php?day=20261021&race=1&kind=2&if=1');

console.log('PASS: 14 synthetic Amagasaki official exhibition parser cases');
