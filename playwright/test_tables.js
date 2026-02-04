const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  await page.goto('https://kyoteibiyori.com/race_shusso.php?place_no=2&race_no=1&hiduke=20260131&slider=4');
  await page.waitForTimeout(2000);

  // ページ内の全テーブルの class を一覧表示
  const tables = await page.$$eval('table', els => els.map(e => e.className));
  console.log(tables);

  await browser.close();
})();