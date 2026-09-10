# Verification — 10 September 2026

## Completed

- Production build: successful.
- Astro/TypeScript diagnostics: zero errors, warnings or hints.
- Real Chrome responsive checks: 320, 375, 390, 430, 768, 1280 and 1440px.
- No horizontal overflow at those widths. Tablet aspect is 16:11, with two full lead rows on phones and three at larger widths.
- Exactly one H1, labelled sections, valid section links, and form input fonts of at least 16px.
- Local Clash Display and Switzer loaded. No external font requests.
- Actual screenshots inspected at 320 and 1440px, including the menu and enquiry modal. The 375px dashboard was inspected separately.
- 375px dashboard: a new row arrives and the figure updates; existing rows move by transform; no elements are added to the list.
- Offscreen, background-tab and manual pause checks pass. Zero outstanding animation-frame callbacks when idle, offscreen or in reduced-motion mode.
- Six arrivals maximum; final daily count 30; replay available.
- Native dialog focus containment, Escape, focus restoration and mobile navigation pass.
- Software tabs work with click and arrow keys.
- Form field-error and success states verified with controlled HTTP responses, including re-enabling the submit button.
- Axe WCAG 2/2.1 A/AA checks: zero violations at 375 and 1440px. This is automated coverage, not a full accessibility certification.
- No browser JavaScript errors in the interaction suite.
- All copied/adapted PHP files pass syntax validation in PHP 8.4.25.
- Actual local PHP endpoint: email validation, service allowlist, cross-origin rejection, method guard, accepted/persisted enquiry and honeypot pass. Email sending was disabled for local tests.

## Mobile Lighthouse

Audited the generated production build on a local server with gzip and cache headers matching the Apache deployment configuration. Lighthouse mobile defaults use simulated network and CPU throttling.

| Measure | Result |
| --- | --- |
| Performance | 99 |
| Accessibility | 100 |
| Best practices | 100 |
| SEO | 100 |
| Largest contentful paint | 1.974 s |
| Cumulative layout shift | 0.036 |
| Total blocking time | 0 ms |
| First contentful paint | 0.9 s |

Raw report: `artifacts/lighthouse-mobile-final.json`. Browser assertions: `artifacts/verification.json`.

The first audit against PHP's uncompressed development server was 92 performance. Production compression and deferred GSAP loading improved the measured result. The final Lighthouse report completed successfully; its CLI subsequently encountered a Windows temporary-profile cleanup permission error. That teardown error does not alter the saved measurements.

Measured locally, not on a public deployment. Actual hosting latency and configuration affect performance. The host's real email delivery remains to be checked after upload; this build did not send external test emails.
