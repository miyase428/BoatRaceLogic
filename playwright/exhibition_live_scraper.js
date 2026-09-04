const { chromium } = require('playwright');

(async () => {
  try {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({
      serviceWorkers: 'block'
    });
    const page = await context.newPage();

    // 展示表の取得に不要な重いリソースは読み込まない。
    // HTML / CSS / JavaScript / XHR は残し、サイトへの総リクエスト量を抑える。
    await page.route('**/*', async (route) => {
      const resourceType = route.request().resourceType();
      if (['image', 'font', 'media'].includes(resourceType)) {
        await route.abort();
        return;
      }
      await route.continue();
    });

    const url = process.argv[2];
    if (!url) {
      console.error("URL が指定されていません");
      process.exit(1);
    }

    async function gotoOnce(targetUrl) {
      const response = await page.goto(targetUrl, {
        timeout: 60000,
        waitUntil: "load"
      });

      // 明確なアクセス制限・サーバ障害は通常ページとして処理しない。
      if (response) {
        const status = response.status();
        if (status === 403 || status === 429 || status >= 500) {
          throw new Error(`HTTP ${status}`);
        }
      }
    }

    async function safeGoto(targetUrl) {
      try {
        await gotoOnce(targetUrl);
      } catch (e) {
        // stdout は最終JSON専用。リトライ通知は stderr へ出す。
        console.error(`retry: ${e.message}`);

        // 接続不調時に即時再アクセスしない。
        await page.waitForTimeout(5000);

        try {
          await gotoOnce(targetUrl);
        } catch (e2) {
          console.error(`goto failed twice: ${e2.message}`);
          process.exit(1);
        }
      }
    }

    await safeGoto(url);
    await page.waitForTimeout(1500); // DOM安定のため

    // 展示情報が無いページでは、以前は6艇分のnull行を返していた。
    // PHP側がそれを「6艇取得済み」と誤認して保存できてしまうため、
    // 今後は空配列を返し、exhibition_live に偽の6行を作らない。
    const baseTenjiExists = await page.$("//td[normalize-space(text())='展示情報']");
    if (!baseTenjiExists) {
      console.log(JSON.stringify([]));
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

    // 展示欄は見つかったが、実データがまだ入っていない場合も保存対象にしない。
    const hasExhibitionData = results.some((row) => {
      return String(row.exhibition_time || '').trim() !== ''
        || String(row.start_timing || '').trim() !== '';
    });

    if (!hasExhibitionData) {
      await page.close();
      await context.close();
      await browser.close();
      console.log(JSON.stringify([]));
      process.exit(0);
    }

    // 実データがあるのに選手ID取得が壊れている場合はアクセス/解析失敗として扱う。
    // 空player_idのまま6行保存されることを防ぎ、PHP側の再試行対象にする。
    const validPlayerCount = results.filter((row) => /^\d+$/.test(String(row.player_id || '').trim())).length;
    if (validPlayerCount !== 6) {
      throw new Error(`player_id parse error: valid=${validPlayerCount}/6`);
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
