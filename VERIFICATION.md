# Verification — 10 September 2026

## Completed

- Production build: successful.
- Astro/TypeScript diagnostics: zero errors, warnings or hints.
- Real Chrome responsive checks: 320, 375, 390, 430, 768, 1280 and 1440px.
- No horizontal overflow at those widths. The tuner is 510px wide on desktop and 310px at 375px, with all dial/preset/button targets at least 44px. The hero headline is 82px at 1440px and 45px at 375px.
- Exactly one H1, labelled sections, valid section links, and form input fonts of at least 16px.
- Local Clash Display and Switzer loaded. No external font requests.
- Final production hero screenshots inspected at 320, 375 and 1440px. A separate desktop results screenshot confirms final figures and stable spacing.
- Tuner keyboard Home/End/arrows, presets, lock and mouse dragging update the visual state and accessible slider value.
- Touch tap and horizontal dragging work. Vertical swipes through the dial scroll the page natively without changing its value. Lenis is disabled on touch.
- Repeated wheel events separated by an idle pause interpolate rather than jump: one checked sequence moves 0 → 97 → 247px, then 247 → 373 → 532px. Section anchors also animate and finish at their CSS-defined destination.
- Hero parallax remains within the 42px desktop and 14px mobile limits. Zero outstanding JavaScript animation-frame callbacks after scrolling settles and in reduced-motion mode.
- Results count through intermediate values to 28% and 86 exactly once. Offscreen, background-tab and dialog pause/resume checks pass. Both figures also complete when viewed separately on mobile. Reduced motion shows final values immediately.
- Native dialog focus containment, Escape, focus restoration and mobile navigation pass.
- Software tabs work with click and arrow keys.
- Form field-error and success states verified with controlled HTTP responses, including re-enabling the submit button.
- Axe WCAG 2/2.1 A/AA checks: zero violations at 375 and 1440px. This is automated coverage, not a full accessibility certification.
- No browser JavaScript errors in the interaction suite.
- PHP checks from the earlier build remain applicable; backend files were unchanged in this hero revision. All copied/adapted files passed PHP 8.4.25 syntax validation. Local endpoint checks covered email validation, service allowlist, cross-origin rejection, method guard, accepted/persisted enquiry and honeypot. Email sending was disabled.

## Mobile Lighthouse

Audited the generated production build on a local server with gzip and cache headers matching the Apache deployment configuration. Lighthouse mobile defaults use simulated network and CPU throttling.

| Measure | Result |
| --- | --- |
| Performance | 96 |
| Accessibility | 100 |
| Best practices | 100 |
| SEO | 100 |
| Largest contentful paint | 2.3 s |
| Cumulative layout shift | 0 |
| Total blocking time | 180 ms |
| First contentful paint | 1.2 s |

Raw report: `artifacts/lighthouse-signal-mobile-final.json`. Browser assertions: `artifacts/verification.json`. Current production screenshots: `artifacts/signal-tuner/hero-final-*.png` and `artifacts/signal-tuner/results-final-1440.png`.

An initial audit run overlapped other browser checks and recorded 67 performance with high main-thread blocking. The final audit above ran on its own against the rebuilt production output. Both raw reports are retained. The earlier dashboard revision's measurements do not describe this build.

Measured locally, not on a public deployment. Actual hosting latency and configuration affect performance. The host's real email delivery remains to be checked after upload; this build did not send external test emails.
