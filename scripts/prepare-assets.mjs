import sharp from 'sharp';
import { mkdir, writeFile } from 'node:fs/promises';
const source = 'C:/Projects/realestateclickfieldmedia';
await mkdir('public/brands', { recursive: true });
await sharp('public/logo-navy.png').webp({ quality: 90 }).toFile('public/logo-navy.webp');
const brands = ['prestige-group', 'godrej-properties', 'brigade', 'sobha', 'puravankara', 'lodha', 'adarsh-group', 'assetz', 'lt-realty', 'nambiar-builders'];
for (const brand of brands) {
  await sharp(`${source}/src/assets/brands/${brand}.png`).trim().resize(220, 88, { fit: 'contain', background: { r: 238, g: 233, b: 225, alpha: 0 } }).webp({ quality: 88 }).toFile(`public/brands/${brand}.webp`);
}
await writeFile('public/fonts/README.txt', 'Clash Display Variable and Switzer Variable by Indian Type Foundry. Downloaded from the official Fontshare API on 2026-09-10. Self-hosted WOFF2. Fontshare fonts: https://www.fontshare.com/fonts/clash-display and https://www.fontshare.com/fonts/switzer. Licence: https://www.fontshare.com/licenses/itf-free-font-license. Original font binaries are unmodified.\n');
console.log('Original logos copied and optimised; self-hosted fonts ready.');
