# Clickfield Media

The hero leads with the business outcome: “More qualified enquiries. Not just clicks.” Large navy type occupies the wider left column. A smaller physical signal tuner connects reaching the right audience with giving their interest a useful next step.

## Tokens

- Putty #eee9e1: main page and hero; the warm starting surface.
- Navy #0a2541: all primary type and footer.
- Orange #e95d15: primary actions, with #ff7a38 for interaction.
- Amber #ecc267: marketing labels, chart and service surface.
- Plum #502c48: software section and ownership message.
- Coral #df816c: incoming information and the proof section.

Charcoal #23262b is body text. Tints and contrast-safe secondary colours derive from these roles and live in the CSS token block.

The tuner has its own material tokens: silver #e8edef, highlight #fafcfc, metal edge #aab8bf, smoked glass #152d38 and signal cyan #79dfe5. Cyan denotes signal state and stays inside the instrument. All lighting and metal gradients are static.

Clash Display 500–600 for headings; Switzer 400–600 for body and all UI. Self-hosted variable WOFF2, font-display swap. No third face, uppercase labels or italic headings.

## Structure and alignment

```
Logo                    navigation / enquiry
Large headline + actions   compact signal tuner
                client logos
Marketing half          Software half
Software copy           selectable working preview
Process intro           four steps
Results intro           three neo-brutalist case cards
Manually controlled testimonial deck
Ways to work together   three rows
FAQ intro               native disclosures
Contact                 form
Generous footer link inventory
```

All editorial content aligns left. The tuner right aligns at lg, centres below, and is capped at 510px on desktop and 310px on phones. At 1440px the headline is 82px; at 375px it is 45px. Mobile rearranges the dial above four 44px touch controls and the signal display. Sections become stacks, never squeezed desktop columns.

## Review against brief

Replaced the initial idea of an accent-coloured headline fragment with consistent navy typography. Colour now identifies marketing, software, actions and incoming information. Repeated service cards and decorative eyebrows were rejected; paired service fields, software workspace, process timeline and open proof layouts provide rhythm.

The reference inspired material and tactility: a sculpted silver shell, dark dial socket, inset glass and physical button caps. It was not traced or reproduced. The main dial supports pointer drag, tap and keyboard input; four presets and a lock action offer direct alternatives. Every state is available without animation. The headline and enquiry CTA remain the primary message and action.

The two result figures count once when visible, as requested in the original brief. No content arrives through fades or reveals. Parallax follows scrolling by at most 42px for the desktop console and 12px in the opposite direction for copy; mobile only moves the console, by at most 14px. The waveform and indicators change only on input. Counters pause offscreen, in background tabs and under dialogs. Lenis stops its frame driver when scrolling ends and leaves touch scrolling native. Reduced motion keeps the final figures and disables animated transforms.
