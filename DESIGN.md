# Clickfield Media

The live pipeline is the signature: marketing brings interest in; software gives that interest somewhere useful to go. The hero is an open, straight-on tablet beside large, entirely navy type. No decorative shape competes with it.

## Tokens

- Putty #eee9e1: main page and hero; the warm starting surface.
- Navy #0a2541: all primary type, dashboard and footer.
- Orange #e95d15: primary actions, with #ff7a38 for interaction.
- Amber #ecc267: marketing labels, chart and service surface.
- Plum #502c48: software section and ownership message.
- Coral #df816c: incoming information and the proof section.

Charcoal #23262b is body text. Tints and contrast-safe secondary colours derive from these roles and live in the CSS token block.

Clash Display 500–600 for headings; Switzer 400–600 for body and all UI. Self-hosted variable WOFF2, font-display swap. No third face, uppercase labels or italic headings.

## Structure and alignment

```
Logo                    navigation / enquiry
Headline + actions      live tablet
                client logos
Marketing half          Software half
Software copy           selectable working preview
Process intro           four steps
Results intro           two case studies
Quote                   Quote
Ways to work together   three rows
FAQ intro               native disclosures
Contact                 form
Generous footer link inventory
```

All editorial content aligns left. Hero tablet right aligns at lg, centres below. At 375px the tablet stays landscape; internal dashboard reduces to two stats, one chart and two incoming rows. Sections become stacks, never squeezed desktop columns.

## Review against brief

Replaced the initial idea of an accent-coloured headline fragment with consistent navy typography. Colour now identifies marketing, software, actions and incoming information. Repeated service cards and decorative eyebrows were rejected; paired service fields, software workspace, process timeline and open proof layouts provide rhythm.

Neumorphism belongs only to the hero and modal: static raised bezel, inset stat tiles and raised button caps. Information stays crisp. No entrance, count-up, reveal, autoplay carousel or scroll-triggered sequence. The tablet rotates at most 1.5 degrees in its own plane, only following scroll, and is completely flat at rest at the top.

Dashboard uses a fixed absolute row pool, transform-only displacement and a static tint that disappears after each change. Six arrivals maximum. Visibility, page background, modal and reduced-motion state pause work. Lenis uses an event-started frame driver that stops when scrolling stops and is disabled for touch and reduced motion.
