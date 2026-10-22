#!/usr/bin/env node
'use strict';

// Fully synthetic parser tests: no network, DB, model, or production path.
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const adapter = require('../playwright/official_exhibition_amagasaki');

const DATE = '20261021';
const RACE = 1;
const ids = ['9001', '9002', '9003', '9004', '9005', '9006'];
const times = ['6.71', '6.72', '6.73', '6.74', '6.75', '6.76'];

function page(body) { return `<main data-day="${DATE}" data-race="${RACE}">${body}</main>`; }
function direct(values = times) {
  const header = '<table><thead><tr><th class="col1">枠</th><th class="col2">級別 / 登番</th><th class="col3">展示</th></tr></thead>';
  const boats = values.map((value, i) => `<tbody><tr><td class="col1" rowspan="2">${i + 1}</td><td class="col2" rowspan="2"><ul><li class="com-toban"><span class="grade">${i === 2 ? 'A1' : 'B1'}</span> ${ids[i]}</li></ul></td><td class="col3" rowspan="2">${value}</td></tr><tr><td class="history">-</td></tr></tbody>`).join('');
  return page(`${header}${boats}</table>`);
}
function start(st = ['.11', '.21', '.08', '.03', '.13', '.12'], courses = [1, 2, 3, 4, 5, 6]) {
  const rows = ids.map((id, i) => `<tr><td class="col1">${courses[i]}</td><td class="col2">${i + 1}</td><td><ul><li class="com-toban"><span class="grade">${i === 2 ? 'A1' : 'B1'}</span> ${id}</li></ul></td></tr>`).join('');
  const visualOrder = courses.map((course, index) => ({ course, lane: index + 1 })).sort((left, right) => left.course - right.course);
  const water = visualOrder.map(({ lane }, index) => `<div class="suimen_div"><div class="com-yosou-boat"><img src="/cdn/img_boat_${lane}.png"></div><span class="st_area">${st[index]}</span></div>`).join('');
  return page(`<table><thead><tr><th class="col1">コース</th><th class="col2">枠</th></tr></thead><tbody>${rows}</tbody></table>${water}`);
}
function original(overrides = {}) {
  const header = '<table><thead><tr><th class="col1">枠</th><th class="col5">展示</th><th class="col6">一周</th><th class="col7">まわり足</th></tr><tr><th class="col1">番</th><th class="col5">タイム</th><th class="col6">データ</th><th class="col7">データ</th></tr></thead>';
  const boats = ids.map((id, i) => `<tr><td class="col1">${i + 1}</td><td><ul><li class="com-toban"><span class="grade">${i === 2 ? 'A1' : 'B1'}</span> ${id}</li></ul></td><td class="col5">${times[i]}</td><td class="col6">37.${10 + i}</td><td class="col7">11.${30 + i}</td></tr>`).join('');
  return page(`${header}<tbody>${boats}</tbody></table>`).replaceAll('37.12', overrides.lap || '37.12');
}
function mustThrow(code, fn) { assert.throws(fn, (error) => error && error.code === code); }

const parsedDirect = adapter.parseDirectInfo(direct(), DATE, RACE);
assert.equal(parsedDirect.status, 'published');
assert.equal(adapter.parseDirectInfo(direct(['-.--', '-.--', '-.--', '-.--', '-.--', '-.--']), DATE, RACE).status, 'not_yet_published');
const parsedStart = adapter.parseStartDisplay(start(['F.01', 'L.02', '.03', '.04', '.05', '.06']), DATE, RACE);
assert.equal(parsedStart[0].start_timing, 'F.01');
assert.equal(parsedStart[1].start_timing, 'L.02');
const parsedOriginal = adapter.parseOriginalData(original(), DATE, RACE);
assert.deepEqual(parsedOriginal, [
  { lane: '1', player_id: '9001', exhibition_time: '6.71', lap_time: '37.10', around_time: '11.30' },
  { lane: '2', player_id: '9002', exhibition_time: '6.72', lap_time: '37.11', around_time: '11.31' },
  { lane: '3', player_id: '9003', exhibition_time: '6.73', lap_time: '37.12', around_time: '11.32' },
  { lane: '4', player_id: '9004', exhibition_time: '6.74', lap_time: '37.13', around_time: '11.33' },
  { lane: '5', player_id: '9005', exhibition_time: '6.75', lap_time: '37.14', around_time: '11.34' },
  { lane: '6', player_id: '9006', exhibition_time: '6.76', lap_time: '37.15', around_time: '11.35' },
]);
const merged = adapter.mergePublished(parsedDirect.boats, parsedStart, parsedOriginal, { date: DATE, race: RACE, fetchedAt: '2026-10-21T00:00:00.000Z', sourceUrls: { direct_info: 'd', start_display: 's', original_data: 'o' } });
assert.equal(merged.boats.length, 6);
assert.equal(merged.boats[0].straight_time, null);
assert.equal(merged.boats[0].availability.straight_time, 'not_published');
assert.equal(adapter.parseStartDisplay(start(undefined, [2, 1, 3, 4, 5, 6]), DATE, RACE)[0].entry_course, '2');
const changedEntry = adapter.parseStartDisplay(start(['.11', '.12', '.13', '.14', '.15', '.16'], [2, 1, 3, 4, 5, 6]), DATE, RACE);
assert.equal(changedEntry.find((boat) => boat.lane === '1').start_timing, '.12');
assert.equal(changedEntry.find((boat) => boat.lane === '2').start_timing, '.11');
const actualStyleEntry = adapter.parseStartDisplay(start(['.13', '.12', '.06', '.02', 'F.04', '.13'], [1, 2, 3, 4, 6, 5]), DATE, RACE);
assert.deepEqual(actualStyleEntry.find((boat) => boat.lane === '5'), { lane: '5', entry_course: '6', player_id: '9005', start_timing: '.13' });
assert.deepEqual(actualStyleEntry.find((boat) => boat.lane === '6'), { lane: '6', entry_course: '5', player_id: '9006', start_timing: 'F.04' });
const imageOrderMismatch = start(['.13', '.12', '.06', '.02', 'F.04', '.13'], [1, 2, 3, 4, 6, 5])
  .replace('img_boat_6.png', 'img_boat_x.png').replace('img_boat_5.png', 'img_boat_6.png').replace('img_boat_x.png', 'img_boat_5.png');
mustThrow('START_TIMING_LANE_MISMATCH', () => adapter.parseStartDisplay(imageOrderMismatch, DATE, RACE));
mustThrow('RACE_IDENTITY_MISMATCH', () => adapter.parseDirectInfo(direct().replace('20261021', '20261022'), DATE, RACE));
mustThrow('PLAYER_ID_MISMATCH', () => adapter.parseDirectInfo(direct().replace('9006', '9005'), DATE, RACE));
mustThrow('INVALID_EXHIBITION_TIME', () => adapter.parseDirectInfo(direct().replaceAll('class="col3"', 'class="changed-column"'), DATE, RACE));
mustThrow('ENTRY_COURSE_MISMATCH', () => adapter.parseStartDisplay(start().replace('class="col1">6', 'class="col1">5'), DATE, RACE));
mustThrow('START_TIMING_MISMATCH', () => adapter.parseStartDisplay(start(['.11']), DATE, RACE));
mustThrow('ORIGINAL_DATA_INCOMPLETE', () => adapter.parseOriginalData(original({ lap: '-.--' }), DATE, RACE));
const changedId = adapter.parseOriginalData(original().replace('9006', '9999'), DATE, RACE);
mustThrow('CROSS_PAGE_IDENTITY_MISMATCH', () => adapter.mergePublished(parsedDirect.boats, parsedStart, changedId, { date: DATE, race: RACE, fetchedAt: 'x', sourceUrls: {} }));
mustThrow('EXHIBITION_TIME_MISMATCH', () => adapter.mergePublished(parsedDirect.boats, parsedStart, adapter.parseOriginalData(original().replace('6.71', '6.99'), DATE, RACE), { date: DATE, race: RACE, fetchedAt: 'x', sourceUrls: {} }));
assert.equal(adapter.officialUrl(DATE, RACE, 2), 'https://boatrace-amagasaki.jp/modules/yosou/group-cyokuzen.php?day=20261021&race=1&kind=2&if=1');

function fakeFetcher(pages) {
  return async (url) => {
    const kind = new URL(url).searchParams.get('kind');
    if (!pages[kind]) throw new Error(`unexpected kind ${kind}`);
    return pages[kind];
  };
}

(async () => {
  const published = await adapter.capture(DATE, RACE, {
    fetchPage: fakeFetcher({
      0: { body: direct(), completedAt: '2026-10-21T00:00:01.000Z' },
      1: { body: start(), completedAt: '2026-10-21T00:00:02.000Z' },
      2: { body: original(), completedAt: '2026-10-21T00:00:03.000Z' },
    }),
    now: () => '2026-10-21T00:00:04.000Z',
  });
  assert.equal(published.fetched_at, '2026-10-21T00:00:04.000Z');
  assert.deepEqual(published.page_fetched_at, {
    direct_info: '2026-10-21T00:00:01.000Z', start_display: '2026-10-21T00:00:02.000Z', original_data: '2026-10-21T00:00:03.000Z',
  });
  assert.equal(published.start_timing_mapping, 'official_start_display_course_order');

  const unpublished = await adapter.capture(DATE, RACE, {
    fetchPage: fakeFetcher({ 0: { body: direct(['-.--', '-.--', '-.--', '-.--', '-.--', '-.--']), completedAt: '2026-10-21T00:05:00.000Z' } }),
    now: () => { throw new Error('not_yet_published must not sample a later final timestamp'); },
  });
  assert.equal(unpublished.fetched_at, '2026-10-21T00:05:00.000Z');
  assert.deepEqual(unpublished.page_fetched_at, { direct_info: '2026-10-21T00:05:00.000Z' });

  await assert.rejects(
    () => adapter.capture(DATE, RACE, {
      fetchPage: fakeFetcher({
        0: { body: direct(), completedAt: '2026-10-21T00:00:01.000Z' },
        1: { body: start(), completedAt: '2026-10-21T00:00:02.000Z' },
        2: { body: original(), completedAt: '2026-10-21T00:00:03.000Z' },
      }),
      now: () => '2026-10-21T00:00:02.500Z',
    }),
    (error) => error && error.code === 'FETCH_TIMESTAMP_BACKDATED',
  );

  const priorAppEnv = process.env.APP_ENV;
  process.env.APP_ENV = 'isolated_test';
  const insecureDiagnosticDir = fs.mkdtempSync(path.join(os.tmpdir(), 'amg-diagnostic-insecure-test-'));
  try {
    fs.chmodSync(insecureDiagnosticDir, 0o755);
    assert.throws(() => adapter.createDiagnosticStore(insecureDiagnosticDir, 60), (error) => error && error.code === 'DIAGNOSTIC_DIRECTORY_INVALID');
  } finally {
    fs.rmSync(insecureDiagnosticDir, { recursive: true, force: true });
  }
  const diagnosticDir = fs.mkdtempSync(path.join(os.tmpdir(), 'amg-diagnostic-test-'));
  try {
    const diagnostic = adapter.createDiagnosticStore(diagnosticDir, 60);
    await adapter.capture(DATE, RACE, {
      fetchPage: fakeFetcher({
        0: { body: direct(), completedAt: '2026-10-21T00:00:01.000Z' },
        1: { body: start(), completedAt: '2026-10-21T00:00:02.000Z' },
        2: { body: original(), completedAt: '2026-10-21T00:00:03.000Z' },
      }),
      now: () => '2026-10-21T00:00:04.000Z',
      diagnosticStore: diagnostic,
    });
    const stored = fs.readdirSync(diagnosticDir).sort();
    assert.equal(stored.length, 6);
    const htmlFile = stored.find((name) => name.endsWith('.html'));
    const metadataFile = stored.find((name) => name.endsWith('.json'));
    assert.ok(htmlFile && metadataFile);
    assert.equal(fs.statSync(path.join(diagnosticDir, htmlFile)).mode & 0o777, 0o600);
    assert.equal(fs.statSync(path.join(diagnosticDir, metadataFile)).mode & 0o777, 0o600);
    const metadata = JSON.parse(fs.readFileSync(path.join(diagnosticDir, metadataFile), 'utf8'));
    assert.equal(metadata.source, 'official_amagasaki');
    assert.equal(metadata.completed_at, '2026-10-21T00:00:01.000Z');
    assert.throws(
      () => diagnostic.record({ date: DATE, race: RACE, kind: 'direct_info', url: 'https://example.invalid/', body: 'x'.repeat(adapter.MAX_DIAGNOSTIC_HTML_BYTES + 1), completedAt: '2026-10-21T00:00:01.000Z' }),
      (error) => error && error.code === 'DIAGNOSTIC_CAPTURE_REJECTED',
    );

    const failureDir = fs.mkdtempSync(path.join(os.tmpdir(), 'amg-diagnostic-failure-test-'));
    try {
      const failureDiagnostic = adapter.createDiagnosticStore(failureDir, 60);
      await assert.rejects(
        () => adapter.capture(DATE, RACE, {
          fetchPage: fakeFetcher({ 0: { body: page('<tr><td class="col1">1</td></tr>'), completedAt: '2026-10-21T00:00:01.000Z' } }),
          diagnosticStore: failureDiagnostic,
        }),
        (error) => error && error.code === 'INCOMPLETE_BOATS',
      );
      assert.equal(fs.readdirSync(failureDir).filter((name) => name.endsWith('.html')).length, 1, 'received direct page remains available for offline diagnosis');
    } finally {
      fs.rmSync(failureDir, { recursive: true, force: true });
    }
  } finally {
    fs.rmSync(diagnosticDir, { recursive: true, force: true });
    if (priorAppEnv === undefined) delete process.env.APP_ENV;
    else process.env.APP_ENV = priorAppEnv;
  }

  console.log('PASS: 23 synthetic Amagasaki official exhibition parser cases');
})().catch((error) => {
  console.error(error.stack || error.message);
  process.exitCode = 1;
});
