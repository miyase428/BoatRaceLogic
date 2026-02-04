const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  await page.goto('https://kyoteibiyori.com/race_shusso.php?place_no=2&race_no=1&hiduke=20260131&slider=4');

  // JS がデータを埋め込むまで少し待つ
  await page.waitForTimeout(2000);

  const html = await page.content();
  console.log(html);

  await browser.close();
})();