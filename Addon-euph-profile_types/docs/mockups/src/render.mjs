// Renders the mockup pages to PNG with Playwright's Chromium.
//   node render.mjs            (from this folder; needs the playwright package)
// The pages share the admin and storefront headers through <!--#top--> and
// <!--#sftop--> placeholders, filled in here before rendering; <!--#top:Orders-->
// highlights another item of the admin top menu (Customers by default). The
// viewport is short on purpose: each image is as tall as its page.
// The committed PNGs were then reduced to 256 colours with Pillow:
//   Image.open(f).convert('RGB').quantize(256, Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE).save(f, optimize=True)
import { readFileSync, writeFileSync, readdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, '..');
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const adminTop = readFileSync(join(here, '_admin_top.html'), 'utf8');
const storefrontTop = readFileSync(join(here, '_storefront_top.html'), 'utf8');
const build = mkdtempSync(join(tmpdir(), 'euph-mockups-'));

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ viewport: { width: 1360, height: 400 }, deviceScaleFactor: 1.5 });
for (const file of readdirSync(here).filter((name) => /^(admin|storefront)-.*\.html$/.test(name)).sort()) {
    const html = readFileSync(join(here, file), 'utf8')
        .replace(/<!--#top(?::([^>]+?))?-->/, (_, active = 'Customers') => adminTop.replace(`<a>${active}</a>`, `<a class="active">${active}</a>`))
        .replace('<!--#sftop-->', storefrontTop)
        .replaceAll('href="admin.css"', `href="${join(here, 'admin.css')}"`)
        .replaceAll('href="storefront.css"', `href="${join(here, 'storefront.css')}"`);
    const built = join(build, file);
    writeFileSync(built, html);
    await page.goto('file://' + built);
    await page.screenshot({ path: join(out, file.replace(/\.html$/, '.png')), fullPage: true });
    console.log('rendered', file);
}
await browser.close();
rmSync(build, { recursive: true, force: true });
