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

The deployable output is `dist/`. Upload its contents into the website document root, not a nested `dist` folder.

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

The hero signal tuner uses CSS material layers and an SVG waveform. Drag, tap, arrow keys, presets and the lock button change its five audience stages. The waveform updates only on input; the object has no timer, canvas, audio or ambient animation. Mobile rearranges the console into a compact 310px-wide control surface with touch targets of at least 44px and native vertical swiping through the dial.

Only the three results figures count up, once: 28%, 86 and 24. GSAP loads when a result becomes visible. Counters pause offscreen, in background tabs and under dialogs, and show final values immediately with reduced motion. Static HTML retains final values for accessibility and visits without JavaScript.

Lenis uses an event-started frame driver with an active clock, stopping as soon as smooth scrolling ends. Idle time cannot become a large first-frame jump. Touch devices use native scrolling. Hero parallax moves just two flat layers: the object by at most 42px and copy by 12px on desktop; only the object moves, by at most 14px, on touch. Reduced motion disables Lenis and parallax. No entry or scroll-triggered reveal effects are used.

Software examples are manual keyboard-operable tabs. Dialogs use native focus trapping, Escape handling and focus restoration. The FAQ is built from native `details` elements, with one shared source feeding the visible questions and JSON-LD.

## Content and assets

- `src/data/site.ts`: contact details, FAQs, engagement choices and testimonial content.
- `src/pages/index.astro`: page sections and copy.
- `src/styles/global.css`: design tokens, layout and responsive styles.
- `src/scripts/site.ts`: scrolling, hero parallax, navigation, dialogs and forms.
- `src/components/SignalTuner.astro`, `src/styles/signal-tuner.css` and `src/scripts/signal-tuner.ts`: the interactive hero object and its responsive layout.
- `src/scripts/results-counters.ts`: the two visibility-aware, one-time figure animations.
- `MOCK-DATA.md`: provenance of illustrative results, people, quotes and product examples. Replace case claims and testimonials with approved material before public launch.
- `DESIGN.md`: the design direction and the decisions made against the brief.

Original logos were copied from the sister site. WebP derivatives are included. No logo or arc was redrawn. Font binaries came from the official Fontshare API and are self-hosted under `public/fonts/`.

## Verification

`npm run verify` uses locally installed Chrome through Puppeteer. It checks all seven requested widths, semantic landmarks, tuner controls, native touch swiping, smooth wheel and anchor scrolling, bounded parallax, count-up lifecycles, zero idle frame callbacks, modal and menu controls, software tabs, form states and reduced motion. It intercepts form submissions during UI checks and sends no external messages.

`node scripts/screenshots.mjs` writes screenshots to `artifacts/`. `node scripts/preview-production.mjs` serves the production build with compression and caching at `http://127.0.0.1:4323` for performance audits. It deliberately does not serve PHP source or execute submissions.

See `VERIFICATION.md` for the measured results and their limits.
