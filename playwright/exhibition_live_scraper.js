const { chromium } = require('playwright');

(async () => {
  try {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();

    const url = process.argv[2];
    if (!url) {
      console.error("URL が指定されていません");
      process.exit(1);
    }

    async function safeGoto(targetUrl) {
      try {
        await page.goto(targetUrl, {
          timeout: 60000,
          waitUntil: "load"
        });
      } catch (e) {
        console.log("retry");
        try {
          await page.goto(targetUrl, {
            timeout: 60000,
            waitUntil: "load"
          });
        } catch (e2) {
          console.error("goto failed twice");
          process.exit(1);
        }
      }
    }

    await safeGoto(url);
    await page.waitForTimeout(1500); // DOM安定のため

    // ★ 中止レース判定（展示情報が無い＝中止）
    const baseTenjiExists = await page.$("//td[normalize-space(text())='展示情報']");
    if (!baseTenjiExists) {
      const results = [];
      for (let course = 1; course <= 6; course++) {
        results.push({
          entry_course: course,
          player_id: null,
          exhibition_time: null,
          lap_time: null,
          around_time: null,
          straight_time: null,
          start_timing: null
        });
      }

      console.log(JSON.stringify(results));
      await browser.close();
      process.exit(0);
    }

    // ★ 通常レース処理
    await page.waitForSelector('.tab__button.tab_button_color5', { timeout: 10000 });
    await page.click('.tab__button.tab_button_color5');
    await page.waitForTimeout(2000);

    const playerTable = "(//table[contains(@class,'table_fixed')])[1]";
    const baseTenji = "//td[normalize-space(text())='展示情報']/parent::tr";

    async function safeText(selector) {
      try {
        const t = await page.textContent(selector);
        return t ? t.trim() : "";
      } catch {
        return "";
      }
    }

    const results = [];

    for (let course = 1; course <= 6; course++) {
      const col = course + 1;

      results.push({
        entry_course: course,
        player_id:       await safeText(`${playerTable}//tr[2]/td[${course}]`),
        exhibition_time: await safeText(`${baseTenji}/following-sibling::tr[2]/td[${col}]`),
        lap_time:        await safeText(`${baseTenji}/following-sibling::tr[3]/td[${col}]`),
        around_time:     await safeText(`${baseTenji}/following-sibling::tr[4]/td[${col}]`),
        straight_time:   await safeText(`${baseTenji}/following-sibling::tr[5]/td[${col}]`),
        start_timing:    await safeText(`${baseTenji}/following-sibling::tr[6]/td[${col}]`)
      });
    }

    await page.close();
    await context.close();
    await browser.close();

    console.log(JSON.stringify(results));
    process.exit(0);

  } catch (e) {
    console.error("Playwright fatal error:", e);
    process.exit(1);
  }
})();
