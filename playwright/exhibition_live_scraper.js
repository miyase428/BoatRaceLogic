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
          timeout: 30000,
          waitUntil: "domcontentloaded"
        });
      } catch (e) {
        // stdout は最終JSON専用にする。PHP側の json_decode を壊さないよう
        // リトライ通知は stderr へ出す。
        console.error("retry");
        try {
          await page.goto(targetUrl, {
            timeout: 30000,
            waitUntil: "domcontentloaded"
          });
        } catch (e2) {
          console.error("goto failed twice");
          process.exit(1);
        }
      }
    }

    await safeGoto(url);
    await page.waitForTimeout(1500); // DOM安定のため

    // ------------------------------------------------------------
    // 直前情報を開く
    // ------------------------------------------------------------
    // 競艇日和側で直前情報が動的読み込みになったため、
    // 初期DOMに「展示情報」が無いだけでは未公開と判断しない。
    // まず従来のタブセレクタを試し、見つからない場合は表示テキストでフォールバックする。
    let openedBeforeInfo = false;

    try {
      const legacyTab = page.locator('.tab__button.tab_button_color5').first();
      if (await legacyTab.count()) {
        await legacyTab.click({ timeout: 10000 });
        openedBeforeInfo = true;
      }
    } catch (_) {
      // 次のフォールバックへ
    }

    if (!openedBeforeInfo) {
      try {
        const textTab = page.getByText('直前情報', { exact: true }).first();
        if (await textTab.count()) {
          await textTab.click({ timeout: 10000 });
          openedBeforeInfo = true;
        }
      } catch (_) {
        // 後続の展示情報待機で最終判定する
      }
    }

    if (openedBeforeInfo) {
      await page.waitForTimeout(1200);
    }

    // ------------------------------------------------------------
    // 展示情報の動的読み込み待ち
    // ------------------------------------------------------------
    const baseTenjiSelector = "//td[normalize-space(text())='展示情報']";
    let baseTenjiExists = false;

    try {
      await page.waitForSelector(baseTenjiSelector, { timeout: 10000 });
      baseTenjiExists = true;
    } catch (_) {
      baseTenjiExists = false;
    }

    // 展示未公開・中止・取得失敗時は従来どおり6艇分nullを返す。
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
      await page.close();
      await context.close();
      await browser.close();
      process.exit(0);
    }

    // ------------------------------------------------------------
    // 通常レース処理
    // ------------------------------------------------------------
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
