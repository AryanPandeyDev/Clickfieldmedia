# Clickfield Media agency site

Single-page Astro site with Tailwind CSS, self-hosted Clash Display and Switzer, GSAP and Lenis. No frontend framework runtime, font CDN, analytics or external embed loads with the page.

## Local work

```sh
npm install
npm run dev
npm run check
npm run build
```

The development preview is at `http://127.0.0.1:4321`. Astro serves the page and assets, but does not execute PHP. On a static-only preview the form correctly reports that it could not send, rather than claiming success.

The deployable output is `dist/`. A ZIP of its contents is provided as `clickfield-media-deploy.zip`. The ZIP contains the full static page and the PHP enquiry endpoint; upload its contents into the website document root, not a nested `dist` folder.

## Deployment

The existing business website uses PHP hosting, so this build reuses and adapts that enquiry backend. Deploy to Apache/LiteSpeed with PHP 8.1+ and the `mbstring` extension. The page itself is static; PHP runs only for enquiries.

1. Upload all contents of `dist/`, including the root `.htaccess` and `api/.htaccess`, to the host's `public_html` directory.
2. The production URL is configured as `https://clickfieldmedia.com`. If that changes, update `astro.config.mjs` and `public/robots.txt`, then rebuild. Metadata and organisation/FAQ schema derive from the Astro site setting.
3. `public/api/config.php` contains the recipient (`info@clickfieldmedia.com`), sender (`noreply@clickfieldmedia.com`), field labels and rate limits. The hosting account must be allowed to send from that sender. Set up domain mail authentication through the host as appropriate.
4. Make the directory one level above the document root writable so the endpoint can create `clickfield-agency-data`. This holds enquiry backups and failures, outside the public site. If unavailable, the inherited backend falls back to `api/storage`, protected by Apache rules. On any other web server, implement equivalent access denials before serving it.
5. Test an enquiry on the deployed host and confirm both inbox delivery and the CSV backup. No external test email was sent during this build. The local backend test explicitly disabled `mail()`.

Do not place PHP source on a static-only host. For that hosting model, move the API to a PHP-capable service and configure a same-origin proxy, or replace the endpoint with a supported backend. Nothing was published or uploaded by this task.

## Enquiry behaviour

Both the modal and contact section use the same Astro form component. Inputs use native validation; API errors name and focus the offending field. Sending is bounded by a timeout and disables duplicate submissions. Success is shown only after a successful JSON response. Without JavaScript, a successful POST redirects to the small thank-you page.

The endpoint includes a honeypot, validation, allowed service choices, origin checks, request-size limits, rate limits, email and CSV fallback. The copied diagnostic token was disabled. A failed email is logged and the enquiry is retained. If neither delivery nor storage succeeds, the endpoint returns an error instead of a false success. Storage is isolated from the sister site's directory.

## Motion

The dashboard is fully populated in HTML. Its fixed row pool moves by transform, with no inserted layout rows. GSAP downloads only when the first scheduled update is due. Six arrivals change the displayed daily total from 24 to 30, then stop. A pause/replay control is available. Scrolling the dashboard offscreen, backgrounding the page, opening a dialog or enabling reduced motion stops its work.

Lenis uses an event-started frame driver, stopping as soon as smooth scrolling ends. Touch devices use native scrolling. The tablet starts flat and follows desktop scroll by up to 1.5 degrees in its own plane. No entry or scroll-triggered reveal effects are used.

Software examples are manual keyboard-operable tabs. Dialogs use native focus trapping, Escape handling and focus restoration. The FAQ is built from native `details` elements, with one shared source feeding the visible questions and JSON-LD.

## Content and assets

- `src/data/site.ts`: contact details, FAQs, engagement choices and testimonial content.
- `src/pages/index.astro`: page sections and copy.
- `src/styles/global.css`: design tokens, layout and responsive styles.
- `src/scripts/site.ts`: interactions and bounded dashboard updates.
- `MOCK-DATA.md`: provenance of illustrative results, people, quotes and dashboard data. Replace case claims and testimonials with approved material before public launch.
- `DESIGN.md`: the design direction and the decisions made against the brief.

Original logos were copied from the sister site. WebP derivatives are included. No logo or arc was redrawn. Font binaries came from the official Fontshare API and are self-hosted under `public/fonts/`.

## Verification

`npm run verify` uses locally installed Chrome through Puppeteer. It checks all seven requested widths, semantic landmarks, two/three dashboard rows, the live sequence, pauses, zero idle frame callbacks, modal and menu controls, software tabs, form states and reduced motion. It intercepts form submissions during UI checks and sends no external messages.

`node scripts/screenshots.mjs` writes screenshots to `artifacts/`. `node scripts/preview-production.mjs` serves the production build with compression and caching at `http://127.0.0.1:4323` for performance audits. It deliberately does not serve PHP source or execute submissions.

See `VERIFICATION.md` for the measured results and their limits.
