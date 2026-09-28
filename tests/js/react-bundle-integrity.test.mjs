import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Guards the COMMITTED React booking-engine bundles
 * (addon-travel-core/js/addons/travel_core/) against regressing to
 * development builds. History: webpack.config.js keyed "production" off
 * NODE_ENV, which the `webpack --mode production` build script never set —
 * so the committed bundles were eval-source-map development builds
 * (1.79 MB total, eval()-wrapped, inline base64 source maps) shipped to
 * every hotel product page. The config now keys off argv.mode; these pins
 * fail the suite if a dev-mode artifact is ever committed again.
 */

// Resolved from the repo root (vitest's cwd) — import.meta.url is not a
// real file URL under the jsdom test environment.
const bundle = (name) =>
    readFileSync(
        resolve(process.cwd(), 'addon-travel-core/js/addons/travel_core', name),
        'utf8',
    );

// Production sizes are ~185 KiB (vendor) and ~32 KiB (app). Budgets leave
// headroom for dependency/feature growth while sitting far below the
// 1.5 MB / 277 KB dev-build sizes this test exists to block.
const BUDGETS = {
    'react-vendor.js': 400_000,
    'react19-bundle.js': 150_000,
};

describe.each(Object.entries(BUDGETS))('%s', (name, budget) => {
    const src = bundle(name);

    it('is not an eval-wrapped development build', () => {
        expect(src).not.toMatch(/\beval\(/);
    });

    it('carries no source map (inline or sibling reference)', () => {
        expect(src).not.toContain('sourceMappingURL');
    });

    it(`stays under the ${Math.round(budget / 1000)} KB production budget`, () => {
        expect(src.length).toBeLessThan(budget);
    });

    it('is minified (no indented statement lines)', () => {
        // A couple of long lines is the minified shape; dev builds have
        // thousands of short, indented lines.
        const lines = src.split('\n');
        expect(lines.length).toBeLessThan(50);
    });
});

describe('react19-bundle.js is built from the current source', () => {
    it('restores the last inline search after a reload without dates (language switch)', () => {
        const src = bundle('react19-bundle.js');
        const engine = readFileSync(resolve(process.cwd(), 'addon-travel-core/react-src/src/BookingEngine.jsx'), 'utf8');

        expect(engine).toContain("'travel_inline_search:'");
        expect(src).toContain('travel_inline_search:');
    });
});

describe('react19-bundle.js carries the language-switch and room-limit fixes', () => {
    it('re-points the switcher links and honours the provider room limit', () => {
        const src = bundle('react19-bundle.js');

        expect(src).toContain('currency');
        expect(src).toMatch(/serverConfig\.maxRooms|\.maxRooms,10\)/);
    });
});
