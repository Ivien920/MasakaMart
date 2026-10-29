export default async function run(page, ui) {
  await page.goto('http://localhost/nowww/index.php?nocache=' + Date.now(), { waitUntil: 'networkidle' });
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForSelector('.hero-slide');
  const bg = await page.evaluate(() => getComputedStyle(document.querySelector('.hero-lead-slide.is-active') || document.querySelector('.hero-slide')).backgroundColor);
  await page.evaluate(() => document.querySelectorAll('.hero-dot')[3].click());
  await page.waitForTimeout(800);
  await page.screenshot({ path: 'C:/Users/ivien/AppData/Local/Temp/navy_desktop.png' });
  await page.setViewportSize({ width: 375, height: 812 });
  await page.waitForTimeout(500);
  await page.evaluate(() => document.querySelectorAll('.hero-dot')[0].click());
  await page.waitForTimeout(800);
  await page.screenshot({ path: 'C:/Users/ivien/AppData/Local/Temp/navy_mobile.png' });
  return { bannerBg: bg, overflow: await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth) };
}
