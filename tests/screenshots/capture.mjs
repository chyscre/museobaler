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
const TOURISM_EMAIL = process.env.TOURISM_STAFF_EMAIL ?? 'tourism@baler.gov.ph';
const PASSWORD = process.env.STAFF_PASSWORD ?? 'Sh0tsDemo@2026x';
const VISITOR_PASSWORD = process.env.VISITOR_PASSWORD ?? PASSWORD;
const OUT = process.env.OUT_DIR ?? 'docs/images/screenshots';

/** Created by tests/screenshots/demo-visitors.php in the throwaway database. */
const VISITOR_CLEARED = 'demo.visitor@museobaler.test';
const VISITOR_WAITING = 'demo.waiting@museobaler.test';

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
        click: '#avOpen',
        describe: 'Add Visitor open on its first step: a single visitor or a group / family.',
    },
    {
        file: 'recognition.png',
        path: '/recognition',
        describe: 'The exhibit list with photo counts and Train the model.',
    },

    // -- The rest of the panel -------------------------------------------
    //
    // The nine above are the screens the staff manual walks through step by
    // step. These are the remaining ones a reader meets in the menu, so that
    // no screen the manual names is left to the imagination.
    {
        file: 'dashboard.png',
        path: '/dashboard',
        fullPage: true,
        describe: 'The dashboard: counts, the visitor chart, recent activity.',
    },
    {
        file: 'exhibits.png',
        path: '/exhibits',
        describe: 'The collection list, with the archived filter.',
    },
    {
        file: 'records.png',
        path: '/records',
        fullPage: true,
        describe: 'Visitor records with their payment state.',
    },
    {
        file: 'feedback.png',
        path: '/feedback',
        fullPage: true,
        describe: 'What visitors said, and the ARTA CSM figures.',
    },
    {
        file: 'tours.png',
        path: '/tours',
        describe: 'Guided tours: starting one and ending it.',
    },
    {
        file: 'attendance-kiosk.png',
        path: '/attendance/kiosk',
        describe: 'The staff-room kiosk, whose code rotates every 60 seconds.',
    },
    {
        file: 'staff-attendance.png',
        path: '/staff-attendance',
        fullPage: true,
        describe: 'The attendance sheet, and the way to one person’s DTR.',
    },
    {
        file: 'logs.png',
        path: '/logs',
        fullPage: true,
        describe: 'The audit trail: who did what, from where, when.',
    },
    {
        file: 'desk-poster.png',
        path: '/desk/poster',
        describe: 'The printable entrance poster visitors scan to sign in.',
    },
    {
        file: 'reports-logbook.png',
        path: '/reports/logbook',
        fullPage: true,
        describe: 'The daily logbook report, with its export toolbar.',
    },

    // -- Tourism office only ---------------------------------------------
    //
    // An Administrator session gets a 403 on all four of these, which is the
    // point of the role split, so they are shot from the Tourism account.
    {
        file: 'staff.png',
        path: '/staff',
        role: 'tourism',
        fullPage: true,
        describe: 'Staff accounts: adding one, resetting a password, disabling.',
    },
    {
        file: 'survey.png',
        path: '/survey',
        role: 'tourism',
        fullPage: true,
        describe: 'The ARTA survey question bank.',
    },
    {
        file: 'corrections.png',
        path: '/attendance/corrections',
        role: 'tourism',
        fullPage: true,
        describe: 'Attendance corrections awaiting the office’s approval.',
    },
];

/**
 * The visitor app, shot on a phone.
 *
 * It had no screenshots at all, which left the half of the system that visitors
 * actually touch undocumented in pictures. The app is a single page that swaps
 * `.screen` elements rather than navigating, so these are driven by clicking
 * the app's own navigation and letting it load its own data — not by forcing
 * elements visible, which would photograph screens in states a visitor can
 * never reach.
 *
 * Three accounts' worth of state is needed and the order below is deliberate:
 * signed out, signed in but still waiting at the desk, and signed in and
 * cleared. The waiting screen cannot be reached from a cleared account.
 */
const PHONE = { width: 390, height: 844 };

/** Signs in through the real form rather than forging a session cookie. */
async function signIn(page, email = EMAIL) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 15000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);

    if (page.url().includes('password')) {
        throw new Error(
            `${email} is being forced to change its password. Seed it with ` +
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
    // Bounded on purpose. An image whose request hangs fires neither load nor
    // error, and waiting on it would stall the whole run rather than produce a
    // slightly early screenshot.
    await page.evaluate(async () => {
        const waitFor = (img) => new Promise((done) => {
            img.addEventListener('load', done, { once: true });
            img.addEventListener('error', done, { once: true });
        });

        await Promise.race([
            Promise.all(Array.from(document.images).filter((i) => !i.complete).map(waitFor)),
            new Promise((done) => setTimeout(done, 8000)),
        ]);
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

// -- The visitor app -----------------------------------------------------

/** The id of the `.screen` the app is currently showing. */
function activeScreen(page) {
    return page.evaluate(() => document.querySelector('.screen.active')?.id ?? null);
}

/**
 * Moves to a screen the way a visitor does: by pressing the app's own
 * navigation. The app wires those buttons with inline `showScreen('s-map')`
 * handlers, so the attribute is the selector. Only if no such control is on
 * screen does this call the function directly, which is the case for screens
 * reached from a card rather than the tab bar.
 */
async function visitorGoto(page, screen) {
    // The map opens a preview sheet over the page, and its backdrop keeps
    // swallowing the next tap on the tab bar. Dismissing it is exactly what
    // that backdrop's own handler does.
    await page.evaluate(() => {
        document.getElementById('exhibit-preview-sheet')?.remove();
    });

    const button = page.locator(`[onclick*="showScreen('${screen}')"]`).first();

    // The tab bar exists in the markup on every screen but is hidden on the
    // ones before the museum unlocks, so presence is not enough to click.
    if (await button.count() && await button.isVisible()) {
        await button.click();
    } else {
        await page.evaluate((id) => window.showScreen?.(id), screen);
    }

    await page.waitForTimeout(700);
}

/** Signs in on the app's own form. */
async function visitorSignIn(page, email) {
    await page.goto(`${BASE}/visitor/`, { waitUntil: 'networkidle' });
    await page.click('#auth-tab-signin');
    await page.fill('#reg-email', email);
    await page.fill('#reg-password', VISITOR_PASSWORD);
    await page.click('#auth-submit');

    // The app posts, stores its token and swaps screens; there is no
    // navigation to wait on.
    await page.waitForTimeout(2500);
}

async function shootPhone(page, file, describe) {
    await settle(page);
    await page.screenshot({ path: `${OUT}/${file}` });
    console.log(`  ok ${file.padEnd(26)} ${describe}`);
}

/**
 * Signed out, then waiting at the desk, then cleared — in that order, because
 * the waiting screen is unreachable once an account has been cleared.
 */
async function captureVisitorApp(context) {
    const page = await context.newPage();
    let taken = 0;

    // 1. Signed out: the screen the entrance poster leads to.
    await page.goto(`${BASE}/visitor/`, { waitUntil: 'networkidle' });
    await page.click('#auth-tab-signup').catch(() => {});
    await page.waitForTimeout(500);
    await shootPhone(page, 'visitor-register.png', 'Registering on the visitor’s own phone.');
    taken++;

    // 2. Signed in, not yet cleared: the admission gate, as the visitor sees it.
    await visitorSignIn(page, VISITOR_WAITING);
    const waiting = await activeScreen(page);

    if (waiting === 's-pending') {
        await shootPhone(page, 'visitor-pending.png', 'Waiting for the desk to take the fee.');
        taken++;
    } else {
        console.warn(`  ! visitor-pending.png: expected s-pending, app showed ${waiting}`);
    }

    // 3. Cleared. A fresh context would be tidier, but clearing the token the
    //    app stores is what signing out does anyway.
    await page.evaluate(() => { try { localStorage.clear(); } catch (e) {} });
    await visitorSignIn(page, VISITOR_CLEARED);

    const landed = await activeScreen(page);

    // The choice of storyline or free roaming is offered once, after a visitor
    // first registers. Signing in reports them as returning, which is exactly
    // what makes the app skip it, so an account that can be created in advance
    // can never land here. The screen is opened directly instead: it is a real
    // screen in a state a first-time visitor genuinely sees, just not one this
    // fixture can arrive at.
    if (landed !== 's-mode-choice') {
        await visitorGoto(page, 's-mode-choice');
    }

    if (await activeScreen(page) === 's-mode-choice') {
        await shootPhone(page, 'visitor-mode.png', 'Choosing between the storyline and free roaming.');
        taken++;
        await page.click('#mode-storyline').catch(() => {});
        await page.waitForTimeout(300);
        await page.click('#mode-start-btn').catch(() => {});
        await page.waitForTimeout(1500);
    } else {
        console.warn('  ! visitor-mode.png: could not open the mode screen');
    }

    const tour = [
        ['s-home', 'visitor-home.png', 'The visitor’s home screen.'],
        ['s-home-exhibits', 'visitor-exhibits.png', 'Browsing the collection.'],
        ['s-map', 'visitor-map.png', 'The museum map, both floors.'],
        ['s-profile', 'visitor-profile.png', 'Progress: what was scanned and saved.'],
        ['s-settings', 'visitor-settings.png', 'Language, explore mode, audio, accessibility.'],
    ];

    for (const [screen, file, describe] of tour) {
        // One screen that will not open must not cost the rest of the tour.
        try {
            await visitorGoto(page, screen);

            const now = await activeScreen(page);
            if (now !== screen) {
                console.warn(`  ! ${file}: asked for ${screen}, app showed ${now}`);
            }

            await shootPhone(page, file, describe);
            taken++;
            // One exhibit, opened from the list the way a visitor opens it.
            if (screen === 's-home-exhibits') {
                const card = page.locator('#s-home-exhibits [onclick^="openExhibitCard"]').first();

                if (await card.count()) {
                    await card.click();
                    await page.waitForTimeout(1800);
                    await shootPhone(page, 'visitor-exhibit.png', 'An exhibit: description, narration, facts, gallery.');
                    taken++;
                } else {
                    console.warn('  ! visitor-exhibit.png: no exhibit card to open');
                }
            }

            // About sits inside Settings, not on the tab bar.
            if (screen === 's-settings') {
                const about = page.locator('[onclick*="s-about"]').first();

                if (await about.count()) {
                    await about.click();
                    await page.waitForTimeout(1200);
                    await shootPhone(page, 'visitor-about.png', 'The About screen: hours, fee, contact.');
                    taken++;
                } else {
                    console.warn('  ! visitor-about.png: no way into the About screen');
                }
            }
        } catch (error) {
            console.error(`  FAILED ${file}: ${error.message.split('\n')[0]}`);
        }
    }

    await page.close();

    return taken;
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

let visitorShots = 0;

try {
    // The sign-in form has to be shot before there is a session to shoot it with.
    for (const shot of SHOTS.filter((s) => s.anonymous)) {
        await capture(page, shot);
    }

    // Administrator first, then Tourism: switching costs a sign-in, so each
    // role's screens are taken together rather than in menu order.
    for (const role of ['admin', 'tourism']) {
        const mine = SHOTS.filter((s) => !s.anonymous && (s.role ?? 'admin') === role);

        if (!mine.length) continue;

        if (role === 'tourism') {
            // The panel pins a session to one account, so the Administrator
            // session has to go before the Tourism one can be opened.
            await context.clearCookies();
        }

        await signIn(page, role === 'tourism' ? TOURISM_EMAIL : EMAIL);

        for (const shot of mine) {
            try {
                await capture(page, shot);
            } catch (error) {
                failed++;
                console.error(`  FAILED ${shot.file}: ${error.message}`);
            }
        }
    }

    // The visitor app is a phone, not a desktop, so it needs its own context.
    console.log('\nCapturing the visitor app on a phone');

    const phone = await browser.newContext({
        viewport: PHONE,
        deviceScaleFactor: SCALE,
        isMobile: true,
        hasTouch: true,
        userAgent:
            'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 ' +
            '(KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
    });

    try {
        visitorShots = await captureVisitorApp(phone);
    } catch (error) {
        failed++;
        console.error(`  FAILED the visitor app: ${error.message}`);
    } finally {
        await phone.close();
    }
} finally {
    await browser.close();
}

const total = SHOTS.length + visitorShots;

if (failed) {
    console.error(`\n${failed} capture${failed === 1 ? '' : 's'} failed.`);
    process.exit(1);
}

console.log(`\nAll ${total} written to ${OUT}/ (${SHOTS.length} panel, ${visitorShots} visitor app)`);
