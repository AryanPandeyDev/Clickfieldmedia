# Build brief — Clickfield Media agency site

Build the landing page for **Clickfield Media**, a digital marketing agency that also builds software. Single page, production quality, built to be deployed.

You have wide latitude over copy, layout detail and visual execution. What is written below is settled and should not be renegotiated. Everything else is your call — including all wording. Do not treat any phrasing in this brief as copy to paste onto the page.

---

## 1. The business

Clickfield Media sells two things that belong together:

- **Marketing** — Meta and Google ads, creative, landing pages, SEO, social, and the measurement around it.
- **Software** — CRMs, client portals, landing pages and custom builds, made for the client's own pipeline.

The connection is the point: the marketing brings people in, the software catches and keeps them. A page that reads as "we do lots of digital things" has failed.

Based in Gomti Nagar, Lucknow. Works across India, mostly Lucknow and Bengaluru, and takes overseas work. Lucknow is the only office and the only address that may be published — no second office may be implied anywhere.

**Important factual constraint:** Clickfield does **not** call, telecall or speak to the client's leads. The client's own sales team does that. Never write a claim to the contrary, and never imply a qualification desk or a calling team.

## 1a. Where the company information already lives

There is an existing sister site for the real-estate vertical on this machine at `C:\Projects\realestateclickfieldmedia`. **Everything you need to know about the company is in that folder — read it before you write a word of copy.** Do not invent company facts and do not ask for them.

What is where:

| Path | What it gives you |
|---|---|
| `content.md` | The full brand brief — positioning, voice rules, words that are banned, density caps, the SEO plan, and the structure of the existing page |
| `HANDOFF.md` | Standing constraints, factual corrections already made, and the history of what was rejected and why. The most useful single file |
| `MOCK-DATA.md` | Which figures on the existing site are invented rather than real |
| `src/components/` | Every existing section, with its real copy |
| `src/data/faqs.ts`, `testimonials.ts`, `enquiry.ts` | Real FAQ text, testimonials and enquiry form fields |
| `src/layouts/Base.astro` | Meta tags and the JSON-LD schema, including the address and contact details |
| `public/logo-navy.png`, `logo-white.png`, `logo-white.webp`, `mark-white.png`, `favicon.png` | The real logo files |
| `src/assets/brands/` | Client logos |
| `src/styles/global.css` | The exact brand colour values |

Copy the logo files across rather than linking to them. **Never redraw the logo or its arc mark in CSS or SVG** — use the real asset or leave the space empty.

Take facts, tone, contact details, FAQ material and assets from there. Do **not** copy its look — this site has different typography, a different palette balance and a different hero.

---

## 2. Stack

- **Astro** with **Tailwind CSS**, **GSAP** and **Lenis**.
- Ship as little JavaScript as possible. Astro's zero-JS default is the baseline; GSAP and Lenis are the only meaningful additions.
- Self-hosted fonts. No external font CDN calls at runtime.
- Targets: LCP under 2.0s on 4G, CLS under 0.05, Lighthouse 95+.

---

## 3. Typography

- **Headings — Clash Display**
- **Body — Switzer**

Both are free from Fontshare and must be self-hosted: download the `.woff2` files into the project and declare them with `@font-face` and `font-display: swap`. Do not substitute Google Font lookalikes.

You may add a third face for small labels and figures if the design genuinely needs one. Do **not** reuse the sister site's stack (Bricolage Grotesque, Public Sans, IBM Plex Mono) — this site is meant to look different.

---

## 4. Colour

Inherited brand base, sampled from the sister site:

- Navy `#0a2541`, with darker steps for deep surfaces
- Orange `#e95d15`, lift `#ff7a38`
- Charcoal `#23262b`

The brief is a **warm expansion** of that base. A page that is only navy and orange is too cold and too narrow. Add warm colours — amber, coral and a deep plum are the agreed direction — and build the light sections on a warm putty ground rather than plain white.

Give the added colours a job rather than scattering them. Decide the mapping yourself and apply it consistently.

---

## 5. Neumorphism

Required, and confined to the hero and the enquiry modal. Not a whole-page treatment.

Where it applies:

- The tablet bezel in the hero — a soft extruded slab, lit from the top-left, no hard border
- The stat tiles inside the dashboard — gently recessed
- The enquiry modal — a raised card
- Buttons — raised caps that depress about 1px when pressed

Where it must never apply: text, figures, chart lines, status pills, and anything else carrying information. Soft shadows carry shape only. Every label, number and control must sit at proper contrast and have a clearly visible keyboard focus ring. Neumorphism that turns into grey-on-grey mush is a failed build.

---

## 6. The hero

**Layout.** Two columns. Headline, supporting line and calls to action on the left; a tablet on the right. From `lg` up the tablet is right-aligned in its column; below that it centres, and on mobile it drops under the headline. Landscape, 16:11, `width: min(33rem, 92vw)`. Flat and straight on — no tilt, no perspective, no 3D. It scales down; it never crops, and it never becomes a phone.

This matches the sister site's placement exactly, and is deliberate.

**Inside the tablet is a live dashboard.** The sister site's tablet is blurred behind a lock overlay; this one is sharp, unlocked and running:

- Three stat tiles across the top with small sparklines
- An area chart of leads over time, and a smaller bar chart broken down by source
- A list of incoming leads — avatar initial, name, masked phone, source, status

**It is already populated when the page loads.** Nothing counts up, draws in, or assembles. The dashboard behaves like an application that has been running for a while.

**What moves:** every few seconds a new lead arrives at the top of the list, the rest shift down, the row briefly tints, and the "new leads today" figure changes. That is content changing, not content appearing.

**How it must be built:**

- Movement by transform only. Never insert a row and let the browser reflow the list.
- No animated shadows, blurs or filters.
- Pause when scrolled out of view and when the tab is in the background.
- Bound the sequence — it stops after a set number of leads rather than running forever.
- `prefers-reduced-motion` freezes it fully populated.

This runs on mobile too. It is cheap enough if the rules above are followed.

**On mobile the dashboard re-lays out rather than shrinking.** At 375px the tablet's screen area is roughly 345×237px, so its contents must reduce: fewer stat tiles, the bar chart dropped, fewer lead rows, and type sizes holding rather than scaling down with the frame. Same object, less inside it. Everything on it must stay readable.

---

## 7. Motion — read this twice

**Nothing animates on arrival.** The page loads fully formed.

This rules out, everywhere on the page: fade-ins, fade-ups, headings wiping up on entry, lines drawing themselves on load, bars growing on load, rows unrolling, dividers drawing across, staggered section reveals, and every other variation of the page assembling itself as the visitor arrives or scrolls to it. Do not reintroduce these under a different easing or a different property. They read as generic and they are rejected.

**Motion happens only when the visitor causes it.** Three permitted sources:

1. **Hover and press.** This is where the character lives. Approved examples, which should be the level of invention throughout: a button that leans toward the cursor and springs back; a highlight block that glides between rows of a list instead of blinking on and off; an underline that draws in from the left of a link; letters that roll over one after another to reveal a second word. Invent more in this spirit.

2. **Scroll position, where the visitor is driving.** Movement that tracks scroll and stops the instant scrolling stops. Never a sequence that plays at the visitor once triggered.

3. **Content genuinely changing** — the hero dashboard's incoming leads.

**Counting figures** are allowed once, in the results section, on the two or three numbers that actually matter. Not on the hero, not on every number.

**Lenis** does three things: the page glides when scrolled rather than jumping; menu links slide down to their section rather than snapping; the hero tablet tips a degree or two as it passes, so it reads as an object in space. Smoothing is **off on touch devices** — phones keep their native scrolling.

The standard every effect is judged against: creative, and never confusing to the viewer or damaging to visibility. Anything that interrupts the visitor's scroll must earn the interruption. Small interactive details do not have to.

---

## 8. Page structure

1. Hero
2. Client logos
3. Services — both halves, marketing and software, sitting still as a normal section
4. Software — a proper look at the CRMs, portals and custom builds
5. Process — how an engagement actually runs
6. Results — case studies with figures. Real estate is where the track record was built, so say so plainly and link across to the real estate site. Frame it as depth in a hard market, never as a limitation
7. Testimonials
8. How you can work with us — monthly retainer, fixed-scope project, or build and hand over. Naming the three shapes matters; publishing prices is optional and defaults to none, because prices anchor sales calls
9. FAQ
10. Contact, with an enquiry form
11. Footer — a generous link inventory is wanted here, unlike the rest of the page

There is deliberately **no "industries we serve" section.** Almost all current clients are real estate, and a list of fields with no work behind it reads as padding.

---

## 9. Mobile

Not an afterthought — most visitors are on a phone.

- Design at 375px first, then widen. Verify at 320, 390, 430, 768, 1280 and 1440.
- The layout must genuinely rearrange on small screens, not compress.
- Tap targets at least 44px, spaced 8px apart.
- Form fields at least 16px so iOS does not zoom on focus.
- No pinned sections and no sideways scroll-jacking anywhere below `lg`.
- No horizontal overflow at any width from 320px up.
- Nothing may keep a `requestAnimationFrame` loop alive after it leaves the screen.

---

## 10. Writing

Write all copy yourself. The register is **plain, simple language a client understands immediately**. No agency jargon, no buzzwords, no cleverness for its own sake. Say what something is and what it does for the reader.

- Sentence case throughout. Active voice. Buttons name what happens.
- Never: passionate, cutting-edge, one-stop solution, next level, seamless, leverage.
- Headings around nine words or fewer. Card descriptions one short line. At most three or four figures in any one section. Density is a live concern.
- Money is always ₹, in lakh and crore notation. Never dollars.
- Where you have no real data, write modest believable figures as ordinary confident copy, at the scale of a small-to-mid Indian agency. Never put "draft", "placeholder" or `[X]` on the page. Note the invented figures in a separate file so they can be replaced.

---

## 11. Quality floor

- Semantic landmarks, one `<h1>`, correct heading order, each section labelled by its heading
- Visible keyboard focus on every interactive element
- `prefers-reduced-motion` respected everywhere
- Explicit width and height on images, lazy below the fold, modern formats
- Canonical, Open Graph and Twitter tags, `sitemap.xml`, `robots.txt`, JSON-LD for the organisation and the FAQ
- **Verify in a real browser before reporting anything as finished.** Take screenshots at 320px and 1440px, open the enquiry modal, open the mobile menu, and confirm the hero dashboard is still animating and still readable at 375px. Do not report the build as working from reading your own code.
