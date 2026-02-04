const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  await page.goto('https://kyoteibiyori.com/race_shusso.php?place_no=2&race_no=1&hiduke=20260131&slider=4');

  // JS がデータを埋め込むまで少し待つ
  await page.waitForTimeout(2000);

  // 1号艇の展示タイムを取得（最初の .tenji_time の2列目）
  const tenji1 = await page.textContent('table.tenji_time tr:nth-child(1) td:nth-child(2)');
  console.log('1号艇 展示タイム:', tenji1);

  await browser.close();
})();