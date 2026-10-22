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
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');

const BASE_URL = 'https://boatrace-amagasaki.jp/modules/yosou/group-cyokuzen.php';
const PLACE_CODE = 'AMG';
const DIAGNOSTIC_DIRECTORY = '/var/lib/boatrace/official-exhibition-diagnostics';
const MAX_DIAGNOSTIC_HTML_BYTES = 2 * 1024 * 1024;
const DEFAULT_DIAGNOSTIC_RETENTION_SECONDS = 24 * 60 * 60;

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

function bodyRows(html) {
  const bodies = String(html).match(/<tbody\b[^>]*>[\s\S]*?<\/tbody>/gi) || [];
  return bodies.flatMap((body) => allRows(body));
}

function playerIdFromToban(row) {
  const match = String(row).match(/<li\b[^>]*class=["'][^"']*\bcom-toban\b[^"']*["'][^>]*>[\s\S]*?<\/li>/i);
  const id = match ? text(match[0]).match(/\b(\d{4,6})\b/) : null;
  return id ? id[1] : '';
}

function racerRows(html) {
  return allRows(html).filter((row) => /\bcom-toban\b/i.test(row));
}

function startTimingsByLane(html) {
  const markers = [...String(html).matchAll(/<img\b[^>]*\bsrc=["'][^"']*img_boat_([1-6])\.png[^"']*["'][^>]*>|<span\b[^>]*class=["'][^"']*\bst_area\b[^"']*["'][^>]*>([\s\S]*?)<\/span>/gi)];
  const timings = [];
  let lane = null;
  for (const marker of markers) {
    if (marker[1] !== undefined) {
      if (lane !== null) throw new ParseError('START_TIMING_MISMATCH', 'official start display has a boat image without ST');
      lane = marker[1];
      continue;
    }
    const value = text(marker[2]);
    if (lane === null || !/^(?:F|L)?\.?\d{2}$/i.test(value)) throw new ParseError('START_TIMING_MISMATCH', 'start display does not contain valid lane-bound ST values');
    timings.push({ lane, start_timing: value });
    lane = null;
  }
  const lanes = new Set(timings.map((timing) => timing.lane));
  if (lane !== null || timings.length !== 6 || lanes.size !== 6) throw new ParseError('START_TIMING_MISMATCH', 'start display does not contain six lane-bound ST values');
  return timings;
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
  const rows = racerRows(html).map((row) => ({
    lane: classCell(row, 'col1'),
    player_id: playerIdFromToban(row),
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
  const boats = racerRows(html).map((row) => ({
    lane: classCell(row, 'col2'),
    entry_course: classCell(row, 'col1'),
    player_id: playerIdFromToban(row),
  })).filter((row) => row.player_id || row.lane || row.entry_course);
  requireSixUnique(boats, 'start display');
  const courses = new Set(boats.map((boat) => boat.entry_course));
  if (courses.size !== 6 || [...courses].some((course) => !/^[1-6]$/.test(course))) {
    throw new ParseError('ENTRY_COURSE_MISMATCH', 'start display does not contain unique courses one through six');
  }
  const timings = startTimingsByLane(html);
  const timingByLane = new Map(timings.map((timing) => [timing.lane, timing.start_timing]));
  // The official display emits boat images and ST values in entry-course
  // order.  Require that visual order to agree with the separately labelled
  // rows, then bind each ST to its explicit boat-image lane.
  const courseOrderedLanes = [...boats].sort((left, right) => Number(left.entry_course) - Number(right.entry_course)).map((boat) => boat.lane);
  if (JSON.stringify(timings.map((timing) => timing.lane)) !== JSON.stringify(courseOrderedLanes)) {
    throw new ParseError('START_TIMING_LANE_MISMATCH', 'boat-image order disagrees with labelled entry courses');
  }
  boats.forEach((boat) => { boat.start_timing = timingByLane.get(boat.lane); });
  return boats;
}

function parseOriginalData(html, date, race) {
  parsePageIdentity(html, date, race);
  // The original-data page has multi-row table headers.  Restrict parsing to
  // tbody so header cells never become phantom boats.  Its com-toban element
  // contains an inner grade span before the actual registration number.
  const boats = bodyRows(html).map((row) => ({
    lane: classCell(row, 'col1'),
    player_id: playerIdFromToban(row),
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

function diagnosticDirectory(directory) {
  const resolved = path.resolve(directory);
  if (process.env.APP_ENV === 'isolated_test') {
    const temporaryRoot = `${path.resolve(os.tmpdir())}${path.sep}`;
    if (!resolved.startsWith(temporaryRoot)) throw new ParseError('DIAGNOSTIC_DIRECTORY_INVALID', 'isolated diagnostic directory must be under the temporary directory');
    return resolved;
  }
  if (resolved !== DIAGNOSTIC_DIRECTORY) throw new ParseError('DIAGNOSTIC_DIRECTORY_INVALID', 'diagnostic directory is not the approved non-web path');
  return resolved;
}

function createDiagnosticStore(directory, retentionSeconds = DEFAULT_DIAGNOSTIC_RETENTION_SECONDS) {
  const target = diagnosticDirectory(directory);
  if (!Number.isInteger(retentionSeconds) || retentionSeconds < 60 || retentionSeconds > 7 * 24 * 60 * 60) {
    throw new ParseError('DIAGNOSTIC_RETENTION_INVALID', 'diagnostic retention is outside the permitted range');
  }
  let info;
  try { info = fs.lstatSync(target); } catch (_) { throw new ParseError('DIAGNOSTIC_DIRECTORY_INVALID', 'diagnostic directory is unavailable'); }
  if (!info.isDirectory() || info.isSymbolicLink() || (info.mode & 0o777) !== 0o700) {
    throw new ParseError('DIAGNOSTIC_DIRECTORY_INVALID', 'diagnostic directory must be a non-symlink 0700 directory');
  }
  const prefix = 'amg_official_';
  const cleanup = () => {
    const expires = Date.now() - (retentionSeconds * 1000);
    for (const name of fs.readdirSync(target)) {
      if (!/^amg_official_[0-9]{8}_r(?:0[1-9]|1[0-2])_(?:direct_info|start_display|original_data)_[0-9]{13}_[a-f0-9]{16}\.(?:html|json)$/.test(name)) continue;
      const candidate = path.join(target, name);
      const candidateInfo = fs.lstatSync(candidate);
      if (candidateInfo.isFile() && !candidateInfo.isSymbolicLink() && candidateInfo.mtimeMs < expires) fs.unlinkSync(candidate);
    }
  };
  const writeExclusive = (filename, contents) => {
    const temporary = path.join(target, `.${filename}.${crypto.randomBytes(8).toString('hex')}.tmp`);
    try {
      const handle = fs.openSync(temporary, 'wx', 0o600);
      try {
        fs.writeFileSync(handle, contents, { encoding: 'utf8' });
        fs.fsyncSync(handle);
      } finally {
        fs.closeSync(handle);
      }
      fs.renameSync(temporary, path.join(target, filename));
    } catch (error) {
      try { fs.unlinkSync(temporary); } catch (_) { /* preserve the original error */ }
      throw error;
    }
  };
  cleanup();
  return {
    record({ date, race, kind, url, body, completedAt }) {
      if (!['direct_info', 'start_display', 'original_data'].includes(kind) || typeof body !== 'string' || Buffer.byteLength(body, 'utf8') > MAX_DIAGNOSTIC_HTML_BYTES) {
        throw new ParseError('DIAGNOSTIC_CAPTURE_REJECTED', 'received diagnostic page is invalid or exceeds the size limit');
      }
      const completed = new Date(completedAt);
      if (Number.isNaN(completed.getTime()) || completed.toISOString() !== completedAt) throw new ParseError('DIAGNOSTIC_CAPTURE_REJECTED', 'diagnostic completion time is invalid');
      const name = `${prefix}${date}_r${String(race).padStart(2, '0')}_${kind}_${Date.now()}_${crypto.randomBytes(8).toString('hex')}`;
      const htmlName = `${name}.html`;
      try {
        writeExclusive(htmlName, body);
        writeExclusive(`${name}.json`, JSON.stringify({ schema: 'official-exhibition-diagnostic/v1', source: 'official_amagasaki', race_code: `${date}${PLACE_CODE}${String(race).padStart(2, '0')}`, kind, source_url: url, completed_at: completedAt, captured_at: new Date().toISOString(), bytes: Buffer.byteLength(body, 'utf8') }) + '\n');
      } catch (error) {
        try { fs.unlinkSync(path.join(target, htmlName)); } catch (_) { /* preserve the original error */ }
        throw error;
      }
    },
  };
}

async function capture(date, race, dependencies = {}) {
  if (!/^\d{8}$/.test(date) || !Number.isInteger(race) || race < 1 || race > 12) {
    throw new ParseError('INVALID_ARGUMENT', 'use --date YYYYMMDD and --race 1..12');
  }
  const fetchPage = dependencies.fetchPage || fetchOnce;
  const now = dependencies.now || (() => new Date().toISOString());
  const diagnosticStore = dependencies.diagnosticStore || null;
  const sourceUrls = { direct_info: officialUrl(date, race, 0), start_display: officialUrl(date, race, 1), original_data: officialUrl(date, race, 2) };
  if (diagnosticStore !== null && typeof diagnosticStore.record !== 'function') throw new ParseError('DIAGNOSTIC_CAPTURE_REJECTED', 'diagnostic store is invalid');
  const record = (kind, page) => {
    if (diagnosticStore !== null) diagnosticStore.record({ date, race, kind, url: sourceUrls[kind], body: page.body, completedAt: page.completedAt });
  };
  const directPage = normaliseFetchResult(await fetchPage(sourceUrls.direct_info));
  record('direct_info', directPage);
  const direct = parseDirectInfo(directPage.body, date, race);
  if (direct.status === 'not_yet_published') {
    return { schema: 'official-exhibition/v1', status: 'not_yet_published', source: 'official_amagasaki', source_url: sourceUrls.direct_info, source_urls: { direct_info: sourceUrls.direct_info }, fetched_at: directPage.completedAt, page_fetched_at: { direct_info: directPage.completedAt }, race_date: date, race_no: race, race_code: `${date}${PLACE_CODE}${String(race).padStart(2, '0')}`, boats: [] };
  }
  // These pages may complete in either order.  Record each completed page in
  // its own promise chain so a failure in its sibling cannot discard HTML
  // that was already received and is needed for offline diagnosis.
  const fetchAndRecord = async (kind) => {
    const page = normaliseFetchResult(await fetchPage(sourceUrls[kind]));
    record(kind, page);
    return page;
  };
  const [startPage, originalPage] = await Promise.all([
    fetchAndRecord('start_display'),
    fetchAndRecord('original_data'),
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
  const diagnosticDirectoryArgument = readArgument('--diagnostic-html-dir');
  const diagnosticRetentionArgument = readArgument('--diagnostic-retention-seconds');
  let diagnosticStore = null;
  try {
    if (diagnosticDirectoryArgument !== null || diagnosticRetentionArgument !== null) {
      if (diagnosticDirectoryArgument === null || diagnosticRetentionArgument === null || !/^\d+$/.test(diagnosticRetentionArgument)) throw new ParseError('DIAGNOSTIC_ARGUMENT_INVALID', 'both diagnostic arguments are required');
      diagnosticStore = createDiagnosticStore(diagnosticDirectoryArgument, Number(diagnosticRetentionArgument));
    }
  } catch (error) {
    process.stderr.write(`${error.code || 'ERROR'}\n`);
    process.exitCode = 1;
  }
  if (process.exitCode !== 1) capture(date, race, { diagnosticStore }).then((result) => process.stdout.write(`${JSON.stringify(result)}\n`)).catch((error) => {
    process.stderr.write(`${error.code || 'ERROR'}\n`);
    process.exitCode = 1;
  });
}

module.exports = { ParseError, officialUrl, parseDirectInfo, parseStartDisplay, parseOriginalData, mergePublished, capture, createDiagnosticStore, DIAGNOSTIC_DIRECTORY, MAX_DIAGNOSTIC_HTML_BYTES };
