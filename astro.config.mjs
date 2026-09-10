import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  site: 'https://clickfieldmedia.com',
  output: 'static',
  devToolbar: { enabled: false },
  vite: {
    plugins: [tailwindcss()],
    build: { cssTarget: ['safari15', 'chrome100', 'firefox100'] },
  },
});
