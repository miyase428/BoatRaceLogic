#!/usr/bin/env node
'use strict';

/*
 * Amagasaki official exhibition-information proof of concept.
 *
 * This module deliberately has no DB, application, or Playwright dependency.
 * The official page exposes each direct-information tab as HTML, so a single
 * explicit CLI invocation uses at most three ordinary document requests:
 * direct information first, then start display and original data only after
 * six published exhibition times are confirmed.  It never retries 403, 429,
 * or 5xx responses.  A published result records completion only after all
 * three documents have been parsed and cross-validated; it is never backdated
 * to the first document response.
 */

const https = require('https');

const BASE_URL = 'https://boatrace-amagasaki.jp/modules/yosou/group-cyokuzen.php';
const PLACE_CODE = 'AMG';

class ParseError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'ParseError';
    this.code = code;
  }
}

function text(value) {
  return String(value || '')
    .replace(/<br\s*\/?\s*>/gi, ' ')
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&#x([0-9a-f]+);/gi, (_, hex) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, decimal) => String.fromCodePoint(Number(decimal)))
    .replace(/\s+/g, ' ')
    .trim();
}

function attribute(tag, name) {
  const match = String(tag).match(new RegExp(`\\b${name}\\s*=\\s*(["'])(.*?)\\1`, 'i'));
  return match ? match[2] : '';
}

function classCell(row, classNeedle) {
  const cells = String(row).match(/<t[dh]\b[^>]*>[\s\S]*?<\/t[dh]>/gi) || [];
  const cell = cells.find((candidate) => attribute(candidate, 'class').split(/\s+/).includes(classNeedle));
  return cell ? text(cell) : '';
}

function allRows(html) {
  return String(html).match(/<tr\b[^>]*>[\s\S]*?<\/tr>/gi) || [];
}

function parsePageIdentity(html, expectedDate, expectedRace) {
  const day = (String(html).match(/\bdata-day\s*=\s*["'](\d{8})["']/i) || [])[1];
  const race = (String(html).match(/\bdata-race\s*=\s*["'](\d{1,2})["']/i) || [])[1];
  if (day !== expectedDate || Number(race) !== expectedRace) {
    throw new ParseError('RACE_IDENTITY_MISMATCH', `expected ${expectedDate}/${expectedRace}, got ${day || '?'}/${race || '?'}`);
  }
}

function requireSixUnique(rows, context) {
  if (rows.length !== 6) throw new ParseError('INCOMPLETE_BOATS', `${context}: expected 6 boats, got ${rows.length}`);
  const ids = new Set(rows.map((row) => row.player_id));
  const lanes = new Set(rows.map((row) => row.lane));
  if (ids.size !== 6 || [...ids].some((id) => !/^\d{4,6}$/.test(id))) {
    throw new ParseError('PLAYER_ID_MISMATCH', `${context}: player IDs are not six unique official IDs`);
  }
  if (lanes.size !== 6 || [...lanes].some((lane) => !/^[1-6]$/.test(String(lane)))) {
    throw new ParseError('LANE_MISMATCH', `${context}: lanes are not one through six`);
  }
}

function parseDirectInfo(html, date, race) {
  parsePageIdentity(html, date, race);
  const rows = allRows(html).map((row) => ({
    lane: classCell(row, 'col1'),
    player_id: text((row.match(/<[^>]*class=["'][^"']*com-toban[^"']*["'][^>]*>[\s\S]*?<\/[^>]+>/i) || [''])[0]),
    exhibition_time: classCell(row, 'col3'),
  })).filter((row) => row.player_id || row.lane || row.exhibition_time);
  requireSixUnique(rows, 'direct information');
  const published = rows.every((row) => /^\d+\.\d{2}$/.test(row.exhibition_time));
  if (!published && rows.every((row) => /^(?:-|--|-\.--)$/.test(row.exhibition_time))) {
    return { status: 'not_yet_published', boats: rows };
  }
  if (!published) throw new ParseError('INVALID_EXHIBITION_TIME', 'direct information is partially published or malformed');
  return { status: 'published', boats: rows };
}

function parseStartDisplay(html, date, race) {
  parsePageIdentity(html, date, race);
  const boats = allRows(html).map((row) => ({
    lane: classCell(row, 'col2'),
    entry_course: classCell(row, 'col1'),
    player_id: text((row.match(/<[^>]*class=["'][^"']*com-toban[^"']*["'][^>]*>[\s\S]*?<\/[^>]+>/i) || [''])[0]),
  })).filter((row) => row.player_id || row.lane || row.entry_course);
  requireSixUnique(boats, 'start display');
  const courses = new Set(boats.map((boat) => boat.entry_course));
  if (courses.size !== 6 || [...courses].some((course) => !/^[1-6]$/.test(course))) {
    throw new ParseError('ENTRY_COURSE_MISMATCH', 'start display does not contain unique courses one through six');
  }
  const startTimings = [...String(html).matchAll(/<span\b[^>]*class=["'][^"']*st_area[^"']*["'][^>]*>([\s\S]*?)<\/span>/gi)]
    .map((match) => text(match[1]));
  if (startTimings.length !== 6 || startTimings.some((value) => !/^(?:F|L)?\.?\d{2}$/i.test(value))) {
    throw new ParseError('START_TIMING_MISMATCH', 'start display does not contain six valid raw ST values');
  }
  // The official start-display column is presented in entry-course order, not
  // necessarily source-table row (lane) order.  Bind its six values to the
  // explicit, unique course labels before joining back to player IDs.  If that
  // course contract or the count changes, fail closed rather than guessing.
  [...boats].sort((left, right) => Number(left.entry_course) - Number(right.entry_course))
    .forEach((boat, index) => { boat.start_timing = startTimings[index]; });
  return boats;
}

function parseOriginalData(html, date, race) {
  parsePageIdentity(html, date, race);
  const boats = allRows(html).map((row) => ({
    lane: classCell(row, 'col1'),
    player_id: text((row.match(/<[^>]*class=["'][^"']*com-toban[^"']*["'][^>]*>[\s\S]*?<\/[^>]+>/i) || [''])[0]),
    exhibition_time: classCell(row, 'col5'),
    lap_time: classCell(row, 'col6'),
    around_time: classCell(row, 'col7'),
  })).filter((row) => row.player_id || row.lane || row.exhibition_time || row.lap_time || row.around_time);
  requireSixUnique(boats, 'original data');
  for (const boat of boats) {
    for (const field of ['exhibition_time', 'lap_time', 'around_time']) {
      if (!/^\d+\.\d{2}$/.test(boat[field])) {
        throw new ParseError('ORIGINAL_DATA_INCOMPLETE', `${field} is missing or malformed for lane ${boat.lane}`);
      }
    }
  }
  return boats;
}

function mergePublished(direct, start, original, metadata) {
  const startById = new Map(start.map((row) => [row.player_id, row]));
  const originalById = new Map(original.map((row) => [row.player_id, row]));
  const boats = direct.map((row) => {
    const startRow = startById.get(row.player_id);
    const originalRow = originalById.get(row.player_id);
    if (!startRow || !originalRow || startRow.lane !== row.lane || originalRow.lane !== row.lane) {
      throw new ParseError('CROSS_PAGE_IDENTITY_MISMATCH', `official pages disagree for player ${row.player_id}`);
    }
    if (row.exhibition_time !== originalRow.exhibition_time) {
      throw new ParseError('EXHIBITION_TIME_MISMATCH', `official pages disagree on exhibition time for player ${row.player_id}`);
    }
    return {
      lane: Number(row.lane),
      player_id: row.player_id,
      entry_course: Number(startRow.entry_course),
      exhibition_time: row.exhibition_time,
      start_timing: startRow.start_timing,
      lap_time: originalRow.lap_time,
      around_time: originalRow.around_time,
      straight_time: null,
      availability: {
        exhibition_time: 'published', start_timing: 'published', lap_time: 'published', around_time: 'published',
        straight_time: 'not_published',
      },
    };
  });
  return {
    schema: 'official-exhibition/v1', status: 'published', source: 'official_amagasaki',
    source_url: metadata.sourceUrls.direct_info, source_urls: metadata.sourceUrls,
    fetched_at: metadata.fetchedAt, race_date: metadata.date, race_no: metadata.race,
    race_code: `${metadata.date}${PLACE_CODE}${String(metadata.race).padStart(2, '0')}`,
    start_timing_mapping: 'official_start_display_course_order',
    page_fetched_at: metadata.pageFetchedAt,
    boats,
  };
}

function officialUrl(date, race, kind) {
  return `${BASE_URL}?day=${encodeURIComponent(date)}&race=${encodeURIComponent(String(race))}&kind=${encodeURIComponent(String(kind))}&if=1`;
}

function fetchOnce(url) {
  return new Promise((resolve, reject) => {
    const request = https.get(url, { headers: { 'User-Agent': 'BoatRaceLogic official-exhibition PoC/1.0' } }, (response) => {
      const { statusCode = 0 } = response;
      if (statusCode === 403 || statusCode === 429 || statusCode >= 500) {
        response.resume();
        reject(new ParseError('HTTP_RESTRICTED', `official source returned HTTP ${statusCode}; not retrying`));
        return;
      }
      if (statusCode < 200 || statusCode >= 300) {
        response.resume();
        reject(new ParseError('HTTP_ERROR', `official source returned HTTP ${statusCode}`));
        return;
      }
      let body = '';
      response.setEncoding('utf8');
      response.on('data', (chunk) => { body += chunk; });
      response.on('end', () => resolve({ body, completedAt: new Date().toISOString() }));
    });
    request.setTimeout(30000, () => request.destroy(new Error('official source timed out')));
    request.on('error', reject);
  });
}

function normaliseFetchResult(result) {
  if (typeof result === 'string') return { body: result, completedAt: new Date().toISOString() };
  if (!result || typeof result.body !== 'string' || typeof result.completedAt !== 'string') {
    throw new ParseError('FETCH_RESULT_INVALID', 'fetch result must include body and completedAt');
  }
  const completedAt = new Date(result.completedAt);
  if (Number.isNaN(completedAt.getTime()) || completedAt.toISOString() !== result.completedAt) {
    throw new ParseError('FETCH_TIMESTAMP_INVALID', 'page completion time must be UTC ISO 8601');
  }
  return result;
}

async function capture(date, race, dependencies = {}) {
  if (!/^\d{8}$/.test(date) || !Number.isInteger(race) || race < 1 || race > 12) {
    throw new ParseError('INVALID_ARGUMENT', 'use --date YYYYMMDD and --race 1..12');
  }
  const fetchPage = dependencies.fetchPage || fetchOnce;
  const now = dependencies.now || (() => new Date().toISOString());
  const sourceUrls = { direct_info: officialUrl(date, race, 0), start_display: officialUrl(date, race, 1), original_data: officialUrl(date, race, 2) };
  const directPage = normaliseFetchResult(await fetchPage(sourceUrls.direct_info));
  const direct = parseDirectInfo(directPage.body, date, race);
  if (direct.status === 'not_yet_published') {
    return { schema: 'official-exhibition/v1', status: 'not_yet_published', source: 'official_amagasaki', source_url: sourceUrls.direct_info, source_urls: { direct_info: sourceUrls.direct_info }, fetched_at: directPage.completedAt, page_fetched_at: { direct_info: directPage.completedAt }, race_date: date, race_no: race, race_code: `${date}${PLACE_CODE}${String(race).padStart(2, '0')}`, boats: [] };
  }
  const [startPage, originalPage] = await Promise.all([
    fetchPage(sourceUrls.start_display).then(normaliseFetchResult),
    fetchPage(sourceUrls.original_data).then(normaliseFetchResult),
  ]);
  const start = parseStartDisplay(startPage.body, date, race);
  const original = parseOriginalData(originalPage.body, date, race);
  // Cross-page identity and exhibition-time validation complete before the
  // final completion timestamp is sampled.
  const result = mergePublished(direct.boats, start, original, {
    sourceUrls,
    pageFetchedAt: { direct_info: directPage.completedAt, start_display: startPage.completedAt, original_data: originalPage.completedAt },
    fetchedAt: null, date, race,
  });
  const fetchedAt = now();
  const finalAt = new Date(fetchedAt);
  if (Number.isNaN(finalAt.getTime()) || finalAt.toISOString() !== fetchedAt) {
    throw new ParseError('FETCH_TIMESTAMP_INVALID', 'final completion time must be UTC ISO 8601');
  }
  if ([directPage, startPage, originalPage].some((page) => new Date(page.completedAt).getTime() > finalAt.getTime())) {
    throw new ParseError('FETCH_TIMESTAMP_BACKDATED', 'final completion time precedes a page completion time');
  }
  result.fetched_at = fetchedAt;
  return result;
}

function readArgument(name) {
  const index = process.argv.indexOf(name);
  return index === -1 ? null : process.argv[index + 1];
}

if (require.main === module) {
  const date = readArgument('--date');
  const race = Number(readArgument('--race'));
  capture(date, race).then((result) => process.stdout.write(`${JSON.stringify(result)}\n`)).catch((error) => {
    process.stderr.write(`${error.code || 'ERROR'}: ${error.message}\n`);
    process.exitCode = 1;
  });
}

module.exports = { ParseError, officialUrl, parseDirectInfo, parseStartDisplay, parseOriginalData, mergePublished, capture };
