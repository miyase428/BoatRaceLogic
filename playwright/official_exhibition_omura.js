#!/usr/bin/env node
'use strict';
const https = require('https');
const PLACE = 'OMR';
class ParseError extends Error { constructor(code, message) { super(message); this.code = code; } }
const text = (v) => String(v || '').replace(/<br\s*\/?\s*>/gi, ' ').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/gi, ' ').replace(/&amp;/gi, '&').replace(/&#(\d+);/g, (_, n) => String.fromCharCode(Number(n))).replace(/\s+/g, ' ').trim().normalize('NFKC');
const tags = (html, name) => String(html).match(new RegExp(`<${name}\\b[^>]*>[\\s\\S]*?<\\/${name}>`, 'gi')) || [];
const cells = (row) => tags(row, 't[dh]');
const attr = (tag, name) => ((String(tag).match(new RegExp(`\\b${name}\\s*=\\s*(["'])(.*?)\\1`, 'i')) || [])[2] || '');
const classHas = (tag, value) => attr(tag, 'class').split(/\s+/).includes(value);
const idFrom = (value) => { const hit = String(value).match(/[?&]toban=(\d{4,6})\b/i); return hit ? hit[1] : ''; };
const validTime = (value) => /^\d+\.\d{2}$/.test(value) && Number(value) > 0;
function assertIdentity(html, date, race, page) {
  const race2 = String(Number(race)).padStart(2, '0');
  const dateEvidence = new RegExp(`<option\\b[^>]*value=["'][^"']*[?&]day=${date}(?:[&"'])[^>]*\\bselected(?:=[^ >]*)?[^>]*>|<option\\b[^>]*\\bselected(?:=[^ >]*)?[^>]*value=["'][^"']*[?&]day=${date}(?:[&"'])`, 'i');
  const activeRace = new RegExp(`<li\\b[^>]*class=["'][^"']*\\bactive\\b[^"']*["'][^>]*>[\\s\\S]{0,160}?${page}\\.php\\?[^"']*day=${date}[^"']*race=${race2}[\\s\\S]{0,80}?${Number(race)}R`, 'i');
  const heading = new RegExp(`<th\\b[^>]*>\\s*${Number(race)}R\\s*<\\/th>`, 'i');
  if (!dateEvidence.test(html) || !activeRace.test(html) || !heading.test(html)) throw new ParseError('RACE_IDENTITY_MISMATCH', `${page}: date or race identity is not independently present in response`);
}
function unique(rows, fields, code, message) { for (const field of fields) if (new Set(rows.map((row) => row[field])).size !== 6) throw new ParseError(code, message); }
function parseStartDisplay(html, date, race) {
  assertIdentity(html, date, race, 'start');
  const table = (String(html).match(/<table\b[^>]*\bid=["']tblstart["'][^>]*>[\s\S]*?<\/table>/i) || [])[0];
  if (!table) throw new ParseError('INCOMPLETE_BOATS', 'start table missing');
  const rows = tags(table, 'tr').slice(1).map((row) => {
    const c = cells(row); const lane = text(c[0]); const playerId = idFrom(row); return { lane, player_id: playerId, entry_course: text(c[2]), start_timing: text(c[3]) };
  });
  if (rows.length !== 6) throw new ParseError('INCOMPLETE_BOATS', 'start table does not contain six boats');
  unique(rows, ['lane', 'player_id', 'entry_course'], 'PLAYER_ID_MISMATCH', 'start table has duplicate or missing boat identity');
  if (rows.some((row) => !/^[1-6]$/.test(row.lane) || !/^\d{4,6}$/.test(row.player_id) || !/^[1-6]$/.test(row.entry_course))) throw new ParseError('ENTRY_COURSE_MISMATCH', 'start table identity is invalid');
  if (rows.every((row) => /^(?:-|－)\.?--$/.test(row.start_timing))) return { status: 'not_yet_published', boats: rows };
  if (rows.some((row) => !/^(?:F|L)?\.\d{2}$/i.test(row.start_timing))) throw new ParseError('START_TIMING_MISMATCH', 'start timings are incomplete');
  return { status: 'published', boats: rows };
}
function parseDirectInfo(html, date, race) {
  assertIdentity(html, date, race, 'chokuzen');
  const table = (String(html).match(/<table\b[^>]*\bid=["']tblchokuzen["'][^>]*>[\s\S]*?<\/table>/i) || [])[0];
  if (!table) throw new ParseError('INCOMPLETE_BOATS', 'direct-info table missing');
  const trs = tags(table, 'tr'); const rows = [];
  for (const row of trs) {
    if (!/rowspan=["']2["']/i.test(row)) continue;
    const c = cells(row); const lane = text(c[0]); const playerId = idFrom(row); const exhibition = text(c[4]);
    rows.push({ lane, player_id: playerId, exhibition_time: exhibition });
  }
  if (rows.length !== 6) throw new ParseError('INCOMPLETE_BOATS', 'direct-info table does not contain six boats');
  unique(rows, ['lane', 'player_id'], 'PLAYER_ID_MISMATCH', 'direct-info table has duplicate or missing boat identity');
  if (rows.every((row) => /^(?:-|－)\.?--$/.test(row.exhibition_time))) return { status: 'not_yet_published', boats: rows };
  if (rows.some((row) => !validTime(row.exhibition_time))) throw new ParseError('INVALID_EXHIBITION_TIME', 'direct-info exhibition times are incomplete');
  return { status: 'published', boats: rows };
}
function parseDetail(html, date, race) {
  const dateNeedle = `<input type="hidden" name="day" value="${date}">`;
  const raceNeedle = `<option value="${Number(race)}" selected>${Number(race)}R</option>`;
  if (!String(html).includes(dateNeedle) || !String(html).includes(raceNeedle)) throw new ParseError('RACE_IDENTITY_MISMATCH', 'detail: date or selected race absent');
  const section = (String(html).match(/<h1 id=['"]cyokuzen['"]>[\s\S]*?<table\b[^>]*class=['"][^'"]*\bchokuzen\b[^'"]*['"][^>]*>[\s\S]*?<\/table>/i) || [])[0];
  if (!section) throw new ParseError('ORIGINAL_DATA_INCOMPLETE', 'detail measurement table missing');
  const rows = tags(section, 'tr').slice(1).map((row) => {
    const c = cells(row); return { lane: text(c[0]), start_timing: text(c[1]), exhibition_time: text(c[2]), lap_time: text(c[3]), around_time: text(c[4]), straight_time: text(c[5]) };
  });
  if (rows.length !== 6) throw new ParseError('INCOMPLETE_BOATS', 'detail measurement table does not contain six boats');
  unique(rows, ['lane'], 'PLAYER_ID_MISMATCH', 'detail measurement lanes are invalid');
  if (rows.some((row) => !/^[1-6]$/.test(row.lane) || !/^(?:F|L)?\.\d{2}$/i.test(row.start_timing) || !validTime(row.exhibition_time) || !validTime(row.lap_time) || !validTime(row.around_time) || !validTime(row.straight_time))) throw new ParseError('ORIGINAL_DATA_INCOMPLETE', 'detail measurement values are incomplete');
  return rows;
}
function mergePublished(start, direct, detail, meta) {
  const byLane = new Map(detail.map((row) => [row.lane, row])); const directByLane = new Map(direct.map((row) => [row.lane, row]));
  const boats = start.map((row) => {
    const d = byLane.get(row.lane); const c = directByLane.get(row.lane);
    if (!d || !c || c.player_id !== row.player_id || c.exhibition_time !== d.exhibition_time || row.start_timing !== d.start_timing) throw new ParseError('CROSS_PAGE_IDENTITY_MISMATCH', 'official pages disagree by lane, player, exhibition, or start timing');
    return { lane: Number(row.lane), player_id: row.player_id, entry_course: Number(row.entry_course), exhibition_time: d.exhibition_time, start_timing: d.start_timing, lap_time: d.lap_time, around_time: d.around_time, straight_time: d.straight_time, availability: { exhibition_time: 'published', start_timing: 'published', lap_time: 'published', around_time: 'published', straight_time: 'published' } };
  });
  return { schema: 'official-exhibition/v1', status: 'published', source: 'official_omura', source_url: meta.sourceUrls.direct_info, source_urls: meta.sourceUrls, fetched_at: meta.fetchedAt, race_date: meta.date, race_no: meta.race, race_code: `${meta.date}${PLACE}${String(meta.race).padStart(2, '0')}`, start_timing_mapping: 'official_start_table_lane', page_fetched_at: meta.pageFetchedAt, boats };
}
function officialUrl(date, race, kind) { const base = kind === 'start_display' ? 'https://omurakyotei.jp/yosou/start.php' : kind === 'direct_info' ? 'https://omurakyotei.jp/yosou/chokuzen.php' : 'https://pic.omurakyotei.jp/yosou/sp/syussou/'; return `${base}?day=${encodeURIComponent(date)}&race=${encodeURIComponent(String(race).padStart(2, '0'))}`; }
function fetchOnce(url) { return new Promise((resolve, reject) => { const req = https.get(url, { headers: { 'User-Agent': 'BoatRaceLogic official-exhibition manual/1.0' } }, (res) => { const status = res.statusCode || 0; if (status === 403 || status === 429 || status >= 500) { res.resume(); return reject(new ParseError('HTTP_RESTRICTED', `HTTP ${status}`)); } if (status < 200 || status >= 300) { res.resume(); return reject(new ParseError('HTTP_ERROR', `HTTP ${status}`)); } let body = ''; res.setEncoding('utf8'); res.on('data', (c) => body += c); res.on('end', () => resolve({ body, completedAt: new Date().toISOString() })); }); req.setTimeout(30000, () => req.destroy(new Error('timed out'))); req.on('error', reject); }); }
function norm(value) { if (typeof value === 'string') return { body: value, completedAt: new Date().toISOString() }; if (!value || typeof value.body !== 'string' || typeof value.completedAt !== 'string' || Number.isNaN(new Date(value.completedAt).getTime())) throw new ParseError('FETCH_RESULT_INVALID', 'invalid fetch response'); return value; }
async function capture(date, race, options = {}) {
  if (!/^\d{8}$/.test(date) || !Number.isInteger(Number(race)) || Number(race) < 1 || Number(race) > 12) throw new ParseError('RACE_IDENTITY_MISMATCH', 'invalid request');
  const fetchPage = options.fetchPage || fetchOnce; const urls = { start_display: officialUrl(date, race, 'start_display'), direct_info: officialUrl(date, race, 'direct_info'), detail: officialUrl(date, race, 'detail') };
  const startResponse = norm(await fetchPage(urls.start_display)); const start = parseStartDisplay(startResponse.body, date, race);
  if (start.status === 'not_yet_published') return { schema: 'official-exhibition/v1', status: 'not_yet_published', source: 'official_omura', source_url: urls.start_display, fetched_at: startResponse.completedAt, race_date: date, race_no: Number(race), race_code: `${date}${PLACE}${String(race).padStart(2, '0')}`, page_fetched_at: { start_display: startResponse.completedAt } };
  const directResponse = norm(await fetchPage(urls.direct_info)); const direct = parseDirectInfo(directResponse.body, date, race);
  if (direct.status !== 'published') return { schema: 'official-exhibition/v1', status: 'not_yet_published', source: 'official_omura', source_url: urls.direct_info, fetched_at: directResponse.completedAt, race_date: date, race_no: Number(race), race_code: `${date}${PLACE}${String(race).padStart(2, '0')}`, page_fetched_at: { start_display: startResponse.completedAt, direct_info: directResponse.completedAt } };
  const detailResponse = norm(await fetchPage(urls.detail)); const detail = parseDetail(detailResponse.body, date, race); const fetchedAt = (options.now || (() => new Date().toISOString()))();
  if (new Date(fetchedAt) < new Date(detailResponse.completedAt)) throw new ParseError('FETCH_TIMESTAMP_BACKDATED', 'final completion time precedes source response');
  return mergePublished(start.boats, direct.boats, detail, { date, race: Number(race), fetchedAt, sourceUrls: urls, pageFetchedAt: { start_display: startResponse.completedAt, direct_info: directResponse.completedAt, detail: detailResponse.completedAt } });
}
if (require.main === module) { let date = '', race = 0; for (let i = 2; i < process.argv.length; i++) { if (process.argv[i] === '--date') date = process.argv[++i]; else if (process.argv[i] === '--race') race = Number(process.argv[++i]); } capture(date, race).then((result) => process.stdout.write(JSON.stringify(result) + '\n')).catch((error) => { process.stderr.write(`${error.code || 'PARSE_ERROR'}: ${error.message}\n`); process.exitCode = 1; }); }
module.exports = { ParseError, parseStartDisplay, parseDirectInfo, parseDetail, mergePublished, officialUrl, capture };
