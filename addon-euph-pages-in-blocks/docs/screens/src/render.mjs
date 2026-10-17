/**
 * Takes the README's screenshots of the pages render.php drew.
 *
 *     node docs/screens/src/render.mjs <dir of render.php's pages> <output dir>
 *
 * Needs Playwright with Chromium: set PLAYWRIGHT_MODULE to its path when it
 * is installed globally (for example /usr/lib/node_modules/playwright/index.mjs).
 */
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const [pagesDir, outDir] = process.argv.slice(2);
if (!pagesDir || !outDir) {
    console.error('usage: node render.mjs <pages dir> <output dir>');
    process.exit(2);
}
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');

const desktop = { width: 1040, height: 900 };
const sidebar = { width: 420, height: 900 };
const phone = { width: 390, height: 844 };

// [screenshot, page, viewport, what to do first]
const shots = [
    ['stacked', 'stacked', sidebar, null],
    ['stacked-excerpt', 'stacked-excerpt', sidebar, null],
    ['tabs', 'tabs', desktop, null],
    ['tabs-keyboard', 'tabs', desktop, async (page) => {
        await page.keyboard.press('Tab');
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('ArrowRight');
    }],
    ['tabs-phone', 'tabs', phone, null],
    ['tabs-no-script', 'tabs', desktop, 'no-script'],
    ['accordion', 'accordion', desktop, null],
    ['accordion-phone', 'accordion', phone, async (page) => {
        await page.click('#euph-pib-12-p103 summary');
    }],
    ['cards', 'cards', desktop, null],
    ['cards-phone', 'cards', phone, null],
];

const browser = await chromium.launch();
for (const [name, file, viewport, before] of shots) {
    // Reduced motion: no transition caught half-way (the stylesheet honours it).
    const context = await browser.newContext({ viewport, deviceScaleFactor: 2, reducedMotion: 'reduce', javaScriptEnabled: before !== 'no-script' });
    const page = await context.newPage();
    await page.goto(pathToFileURL(resolve(pagesDir, file + '.html')).href);
    if (typeof before === 'function') {
        await before(page);
    }
    await page.locator('.shop').screenshot({ path: resolve(outDir, name + '.png') });
    console.log(name + '.png');
    await context.close();
}
await browser.close();
