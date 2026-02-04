
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  // まずは競艇日和のページにアクセスできるかだけ確認
  await page.goto('https://kyoteibiyori.com/race_shusso.php?place_no=2&race_no=1&hiduke=20260131&slider=4');

  // ページタイトルを表示してみる
  console.log(await page.title());

  await browser.close();
})();