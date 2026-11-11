import type { APIRoute } from 'astro';
// Omit optional lastmod until we have a reliable significant-content update date.
export const GET: APIRoute = ({ site }) => new Response(`<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>${site}</loc></url><url><loc>${new URL('/hiring/', site)}</loc></url><url><loc>${new URL('/hiring/video-editor/', site)}</loc></url></urlset>`, { headers: { 'Content-Type': 'application/xml; charset=utf-8' } });
