/**
 * Captures the nine screenshots docs/STAFF_MANUAL.md expects.
 *
 * The manual is written for people with no technical background, and a manual
 * that describes a screen without showing it is much harder to follow. These
 * were taken by hand once and then went stale the first time a button moved,
 * so this script takes them instead: run it again after a UI change and the
 * manual is current.
 *
 * It never points at the real database. tests/screenshots/run.sh builds a
 * throwaway SQLite copy, seeds it with demo data and serves that, so no real
 * visitor's name or email can end up in a committed image.
 *
 * Chrome already on the machine is driven directly (channel: 'chrome'), because
 * Playwright's own browser download is a few hundred megabytes and this needs
 * nothing that installed Chrome cannot do.
 *
 *   node tests/screenshots/capture.mjs
 *
 * Environment: BASE_URL, STAFF_EMAIL, STAFF_PASSWORD, OUT_DIR.
 */

import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8123';
const EMAIL = process.env.STAFF_EMAIL ?? 'admin@museobaler.com';
const PASSWORD = process.env.STAFF_PASSWORD ?? 'Sh0tsDemo@2026x';
const OUT = process.env.OUT_DIR ?? 'docs/images/screenshots';

/** The panel is laid out for a desktop; DesktopOnly turns phones away. */
const VIEWPORT = { width: 1280, height: 900 };

/**
 * Two device pixels per CSS pixel. The manual still lays the image out at
 * 1280 CSS px, but the file has the detail to survive being printed in the
 * manuscript appendix.
 */
const SCALE = 2;

/**
 * Each shot names the page it starts from, anything that has to be clicked or
 * scrolled to first, and whether the whole scrollable page is wanted or just
 * what fits on screen.
 *
 * `click`, `scrollTo` and `element` take a Playwright selector. A missing one
 * is a warning, not a failure: the shot is still taken, and the warning says
 * which selector to fix rather than leaving a hole in the manual.
 *
 * `element` shoots one card rather than the page, for the sections the manual
 * discusses on their own. `expandDetails` opens every collapsed <details>
 * first, because a screenshot of a closed accordion shows nothing.
 */
const SHOTS = [
    {
        file: 'sign-in.png',
        path: '/login',
        anonymous: true,
        describe: 'The sign-in form.',
    },
    {
        file: 'add-exhibit.png',
        path: '/exhibits',
        click: 'button:has-text("Add Exhibit")',
        waitFor: '#addModal.open',
        describe: 'The Add Exhibit modal.',
    },
    {
        file: 'edit-exhibit.png',
        path: '/exhibits/1/edit',
        fullPage: true,
        describe: 'The whole edit page: form, translations, recognition, gallery.',
    },
    {
        // Just the card. The manual talks about translations on their own, and
        // a second full-page shot would only repeat edit-exhibit.png.
        file: 'ai-translate-narrate.png',
        path: '/exhibits/1/edit',
        element: '.card.card-p:has-text("Translations & Audio")',
        describe: 'The language cards and the narration players.',
    },
    {
        file: 'qr-codes.png',
        path: '/qr-codes',
        describe: 'The QR sheet with Print All and Download All.',
    },
    {
        file: 'museum-map.png',
        path: '/map',
        click: '#btn-edit',
        describe: 'The floor plan with pins, in edit mode.',
    },
    {
        file: 'museum-info.png',
        path: '/museum',
        scrollTo: '.info-hd-title:has-text("Geofencing")',
        describe: 'The fee, hours and the Location & Geofence block.',
    },
    {
        file: 'front-desk.png',
        path: '/desk',
        expandDetails: true,
        fullPage: true,
        describe: 'Both registration forms and the Registered today list.',
    },
    {
        file: 'recognition.png',
        path: '/recognition',
        describe: 'The exhibit list with photo counts and Train the model.',
    },
];

/** Signs in through the real form rather than forging a session cookie. */
async function signIn(page) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 15000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);

    if (page.url().includes('password')) {
        throw new Error(
            `${EMAIL} is being forced to change its password. Seed it with ` +
            '`php artisan staff:password <email> --ready` so it goes straight into the panel.'
        );
    }
}

/**
 * Grows the window until the whole page fits in it.
 *
 * The panel puts the sidebar and the content side by side and scrolls the
 * content inside its own element, not the window. Playwright's `fullPage` only
 * knows about the window, so on this layout it returns exactly one viewport and
 * silently cuts off everything below the fold - which is the opposite of what
 * the shots marked `fullPage` are for. Measuring the real scroll height and
 * resizing to it gets the whole screen into one image.
 */
async function fitToContent(page) {
    const height = await page.evaluate(() => {
        let tallest = document.documentElement.scrollHeight;

        document.querySelectorAll('*').forEach((el) => {
            const overflowY = getComputedStyle(el).overflowY;

            if (!/(auto|scroll)/.test(overflowY)) return;
            if (el.scrollHeight <= el.clientHeight) return;

            const offset = el.getBoundingClientRect().top + window.scrollY;
            tallest = Math.max(tallest, Math.ceil(offset + el.scrollHeight));
        });

        // A runaway measurement would produce an unusable image; 6000 px is
        // already far longer than any screen in the manual.
        return Math.min(tallest, 6000);
    });

    if (height > VIEWPORT.height) {
        await page.setViewportSize({ width: VIEWPORT.width, height });
        await page.waitForTimeout(500);
    }
}

/**
 * Animations and lazily-loaded images both produce a half-drawn screenshot, so
 * settle the page before the shutter.
 */
async function settle(page) {
    await page.evaluate(async () => {
        await Promise.all(
            Array.from(document.images)
                .filter((img) => !img.complete)
                .map((img) => new Promise((done) => {
                    img.addEventListener('load', done, { once: true });
                    img.addEventListener('error', done, { once: true });
                }))
        );
    });
    await page.waitForTimeout(400);
}

async function capture(page, shot) {
    // Every shot starts from the standard window; the tall ones grow later.
    await page.setViewportSize(VIEWPORT);
    await page.goto(`${BASE}${shot.path}`, { waitUntil: 'networkidle' });

    if (shot.click) {
        const target = page.locator(shot.click).first();
        if (await target.count()) {
            await target.click();
        } else {
            console.warn(`  ! ${shot.file}: no element matched ${shot.click}`);
        }
    }

    if (shot.waitFor) {
        await page.waitForSelector(shot.waitFor, { timeout: 5000 }).catch(() => {
            console.warn(`  ! ${shot.file}: never saw ${shot.waitFor}`);
        });
    }

    if (shot.expandDetails) {
        await page.evaluate(() => {
            document.querySelectorAll('details').forEach((d) => { d.open = true; });
        });
        await page.waitForTimeout(300);
    }

    if (shot.scrollTo) {
        const target = page.locator(shot.scrollTo).first();
        if (await target.count()) {
            await target.scrollIntoViewIfNeeded();
            await page.waitForTimeout(300);
        } else {
            console.warn(`  ! ${shot.file}: nothing to scroll to at ${shot.scrollTo}`);
        }
    }

    if (shot.fullPage) {
        await fitToContent(page);
    }

    await settle(page);

    if (shot.element) {
        const card = page.locator(shot.element).first();

        if (await card.count()) {
            await card.screenshot({ path: `${OUT}/${shot.file}` });
            console.log(`  ok ${shot.file.padEnd(26)} ${shot.describe}`);

            return;
        }

        console.warn(`  ! ${shot.file}: no element matched ${shot.element}, shooting the page instead`);
    }

    await page.screenshot({
        path: `${OUT}/${shot.file}`,
        fullPage: shot.fullPage ?? false,
    });

    console.log(`  ok ${shot.file.padEnd(26)} ${shot.describe}`);
}

const browser = await chromium.launch({ channel: 'chrome' });
const context = await browser.newContext({
    viewport: VIEWPORT,
    deviceScaleFactor: SCALE,
    // The panel refuses phones. Be explicit rather than relying on the default.
    userAgent:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
        '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
});

await mkdir(OUT, { recursive: true });

const page = await context.newPage();
let failed = 0;

console.log(`Capturing ${SHOTS.length} screenshots from ${BASE}`);

try {
    // The sign-in form has to be shot before there is a session to shoot it with.
    for (const shot of SHOTS.filter((s) => s.anonymous)) {
        await capture(page, shot);
    }

    await signIn(page);

    for (const shot of SHOTS.filter((s) => !s.anonymous)) {
        try {
            await capture(page, shot);
        } catch (error) {
            failed++;
            console.error(`  FAILED ${shot.file}: ${error.message}`);
        }
    }
} finally {
    await browser.close();
}

if (failed) {
    console.error(`\n${failed} of ${SHOTS.length} screenshots failed.`);
    process.exit(1);
}

console.log(`\nAll ${SHOTS.length} written to ${OUT}/`);
