// Sends GA4 custom events for the site's buttons through one delegated click
// listener, instead of wiring a handler onto every individual button:
//   cta_click        buttons that open the enquiry form ("Let's talk" and friends)
//   contact_click    call, email, WhatsApp and Calendly links
//   sister_site_click links out to the real estate and tech sites
//   tuner_click      the hero console's Lock signal button and four small knobs
// The lead itself (generate_lead) fires on the thank-you page.

declare global {
  interface Window {
    dataLayer: unknown[];
    gtag?: (...args: unknown[]) => void;
  }
}

const send = (event: string, params: Record<string, unknown> = {}) => {
  window.gtag?.('event', event, params);
};

/** The nearest ancestor with an id, so a click can be tied to where on the
    page it happened (services, faq, contact, ...) without hardcoding a map.
    The top bar and footer have no id, so they are named by their tag. */
const sectionOf = (el: Element) => {
  const named = el.closest<HTMLElement>('[id]:not(dialog), header, footer, dialog');
  if (!named) return 'unknown';
  if (named.tagName === 'HEADER') return 'header';
  if (named.tagName === 'FOOTER') return 'footer';
  return named.id || 'unknown';
};

const labelOf = (el: Element) => el.textContent?.trim().replace(/\s+/g, ' ').replace(/\s*[↗→←]\s*$/, '') ?? '';

export function wireAnalytics() {
  document.addEventListener('click', (e) => {
    const target = e.target as Element | null;
    if (!target) return;

    const cta = target.closest<HTMLElement>('[data-open-enquiry]');
    if (cta) {
      send('cta_click', { cta_label: labelOf(cta), cta_section: sectionOf(cta) });
      return;
    }

    const tuner = target.closest<HTMLElement>('[data-lock-signal], [data-tune-stage]');
    if (tuner) {
      send('tuner_click', {
        control: tuner.matches('[data-lock-signal]') ? 'lock_signal' : labelOf(tuner.querySelector('.control-label') ?? tuner),
      });
      return;
    }

    const link = target.closest<HTMLAnchorElement>('a[href]');
    if (!link) return;
    const href = link.href;
    // transport_type 'beacon': every one of these can be the visitor's last
    // action before the tab navigates or hands off to another app, so the hit
    // has to survive that rather than racing it.
    const beacon = { transport_type: 'beacon' };

    if (href.startsWith('tel:')) {
      send('contact_click', { method: 'call', section: sectionOf(link), ...beacon });
    } else if (href.startsWith('mailto:')) {
      send('contact_click', { method: 'email', section: sectionOf(link), ...beacon });
    } else if (href.includes('wa.me') || href.includes('whatsapp.com')) {
      send('contact_click', { method: 'whatsapp', section: sectionOf(link), ...beacon });
    } else if (href.includes('calendly.com')) {
      send('contact_click', { method: 'calendly', section: sectionOf(link), ...beacon });
    } else if (href.includes('realestate.clickfieldmedia.com')) {
      send('sister_site_click', { site: 'real_estate', section: sectionOf(link), ...beacon });
    } else if (href.includes('tech.clickfieldmedia.com')) {
      send('sister_site_click', { site: 'tech', section: sectionOf(link), ...beacon });
    }
  });
}
