const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: false });
  const page = await browser.newPage();

  const url = process.argv[2]; // ← PHP から渡すURL
  if (!url) {
    console.error("URL が指定されていません");
    process.exit(1);
  }

  // ------------------------------------------------------------
  // ★ timeout 60秒 ＋ retry 1回 の安全版 goto
  // ------------------------------------------------------------
  async function safeGoto(targetUrl) {
    try {
      await page.goto(targetUrl, {
        timeout: 60000,       // ← 60秒に延長
        waitUntil: "load"
      });
    } catch (e) {
      console.log("retry");
      await page.goto(targetUrl, {
        timeout: 60000,
        waitUntil: "load"
      });
    }
  }

  await safeGoto(url);
  await page.waitForTimeout(2000);

  // 出走表テーブル（選手ID）
  const playerTable = "(//table[contains(@class,'table_fixed')])[1]";

  // 展示情報タブ
  await page.click('.tab__button.tab_button_color5');
  await page.waitForTimeout(2000);

  const baseTenji = "//td[normalize-space(text())='展示情報']/parent::tr";

  // ★ これが必要！
  const results = [];

  for (let course = 1; course <= 6; course++) {
    const player_id = await page.textContent(`${playerTable}//tr[2]/td[${course}]`);
    const col = course + 1;

    const exhibition_time = await page.textContent(`${baseTenji}/following-sibling::tr[2]/td[${col}]`);
    const lap_time        = await page.textContent(`${baseTenji}/following-sibling::tr[3]/td[${col}]`);
    const around_time     = await page.textContent(`${baseTenji}/following-sibling::tr[4]/td[${col}]`);
    const straight_time   = await page.textContent(`${baseTenji}/following-sibling::tr[5]/td[${col}]`);
    const start_timing    = await page.textContent(`${baseTenji}/following-sibling::tr[6]/td[${col}]`);

    results.push({
      entry_course: course,
      player_id: player_id.trim(),
      exhibition_time: exhibition_time.trim(),
      start_timing: start_timing.trim(),
      lap_time: lap_time.trim(),
      around_time: around_time.trim(),
      straight_time: straight_time.trim()
    });
  }

  await browser.close();

  // JSON を標準出力に返す
  console.log(JSON.stringify(results));

})();