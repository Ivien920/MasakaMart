export default async function run(page, ui) {
  await page.waitForSelector('.hero-slide');
  const data = await page.evaluate(() => {
    const slides = [...document.querySelectorAll('.hero-slide')];
    const dots = [...document.querySelectorAll('.hero-dot')];
    return {
      slideCount: slides.length,
      dotCount: dots.length,
      slides: slides.map(s => ({
        cls: s.className,
        h: s.querySelector('h1,h2')?.textContent,
        img: s.querySelector('img')?.getAttribute('src'),
        cta: s.querySelector('a.hero-lead-cta, a.btn-slide')?.getAttribute('href'),
        ariaHidden: s.getAttribute('aria-hidden'),
        visible: getComputedStyle(s).visibility,
        opacity: getComputedStyle(s).opacity
      })),
      dotsActive: dots.findIndex(d => d.classList.contains('is-active'))
    };
  });
  // advance via next arrow
  await page.click('.hero-slider-next');
  await page.waitForTimeout(600);
  data.afterNext = await page.evaluate(() => ({
    activeIdx: [...document.querySelectorAll('.hero-slide')].findIndex(s => s.classList.contains('is-active')),
    dotIdx: [...document.querySelectorAll('.hero-dot')].findIndex(d => d.classList.contains('is-active')),
    visibleSlides: [...document.querySelectorAll('.hero-slide')].filter(s => getComputedStyle(s).visibility === 'visible').length
  }));
  // wait 5.5s to check autoplay
  await page.waitForTimeout(5500);
  data.afterAutoplay = await page.evaluate(() =>
    [...document.querySelectorAll('.hero-slide')].findIndex(s => s.classList.contains('is-active'))
  );
  return data;
}
