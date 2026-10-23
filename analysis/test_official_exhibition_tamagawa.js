'use strict';
const assert = require('assert');
const p = require('../playwright/official_exhibition_tamagawa.js');
const ids=['5178','5209','5307','5290','5212','5345'];
const exhs=['6.74','6.72','6.69','6.61','6.70','6.73'];
const sts=['.06','F.08','.07','F.01','.01','F.02'];
function raceScript(race=4){return `<script>$(".raceinfo_race", parent.document).html("${race}R");</script>`;}
function startHtml({published=true, changedCourse=false}={}){
  const rows=ids.map((id,i)=>`<tr><td class="col1">${changedCourse && i===4 ? 6 : i+1}</td><td class="col2">${i+1}</td><td class="col3"><li class="com-toban">B1/${id}</li></td><td class="col4">${published?exhs[i]:'-.--'}</td></tr>`).join('');
  const st=ids.map((_,i)=>`<img src="/img_boat_0${i+1}.png"><span class="st_area">${sts[i]}</span>`).join('');
  return `${raceScript()}<table><thead><tr><th>header</th></tr></thead><tbody>${rows}</tbody></table><div>${st}</div>`;
}
function originalHtml({mismatch=false}={}){
  const rows=ids.map((id,i)=>`<tr><td class="col1">${i+1}</td><td class="col2"><li class="com-toban">A1/${mismatch&&i===5?'9999':id}</li></td><td class="col3">x</td><td class="col4">x</td><td class="col5">x</td><td class="col6">${exhs[i]}</td><td class="col7">${(36.80+i/10).toFixed(2)}</td><td class="col8">${(5.50+i/10).toFixed(2)}</td><td class="col9">${(6.70+i/10).toFixed(2)}</td></tr>`).join('');
  return `${raceScript()}<table><tbody>${rows}</tbody></table>`;
}
let cases=0;
const start=p.parseStartDisplay(startHtml(), '20261023', 4);
assert.equal(start.status,'published'); assert.equal(start.boats.length,6); assert.equal(start.boats[1].player_id,'5209'); assert.equal(start.boats[1].start_timing,'F.08'); cases++;
assert.equal(p.parseStartDisplay(startHtml({published:false}),'20261023',4).status,'not_yet_published'); cases++;
assert.throws(()=>p.parseStartDisplay(startHtml({changedCourse:true}),'20261023',4),/courses invalid/); cases++;
const original=p.parseOriginalData(originalHtml(),'20261023',4);
assert.equal(original[5].straight_time,'7.20'); assert.equal(original[0].player_id,'5178'); cases++;
assert.throws(()=>p.mergePublished(start.boats,p.parseOriginalData(originalHtml({mismatch:true}),'20261023',4),{}),/disagree/); cases++;
(async()=>{
 const calls=[];
 const value=await p.capture('20261023',4,{fetchPage:async(url)=>{calls.push(url); return {body:url.includes('tenji.php')?startHtml():originalHtml(),completedAt:url.includes('tenji.php')?'2026-10-23T01:00:00.000Z':'2026-10-23T01:00:01.000Z'};},now:()=> '2026-10-23T01:00:02.000Z'});
 assert.equal(calls.length,2); assert.equal(value.race_code,'20261023TMG04'); assert.equal(value.boats[4].entry_course,5); assert.equal(value.boats[4].straight_time,'7.10'); assert.equal(value.page_fetched_at.original_data,'2026-10-23T01:00:01.000Z'); cases++;
 const notYet=await p.capture('20261023',4,{fetchPage:async()=>({body:startHtml({published:false}),completedAt:'2026-10-23T01:00:00.000Z'})});
 assert.equal(notYet.status,'not_yet_published'); cases++;
 console.log(`PASS ${cases} Tamagawa parser cases`);
})().catch(error=>{console.error(error.stack||String(error));process.exitCode=1;});
