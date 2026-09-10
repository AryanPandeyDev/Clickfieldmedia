// Local production QA server. Mirrors Apache compression/cache configuration.
// It serves only public static assets; PHP source is never served as text.
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { resolve, extname, sep } from 'node:path';
import { gzipSync } from 'node:zlib';
const root = resolve('dist');
const types = {'.html':'text/html; charset=utf-8','.css':'text/css; charset=utf-8','.js':'application/javascript; charset=utf-8','.json':'application/json','.xml':'application/xml','.txt':'text/plain','.woff2':'font/woff2','.webp':'image/webp','.png':'image/png','.svg':'image/svg+xml'};
createServer(async(req,res)=>{
  try {
    const pathname = decodeURIComponent(new URL(req.url,'http://localhost').pathname);
    if (pathname.startsWith('/api/') || pathname.includes('/.') || extname(pathname)==='.php') { res.writeHead(404);res.end();return; }
    let path = resolve(root,'.'+pathname);
    if (path!==root && !path.startsWith(root+sep)) {res.writeHead(403);res.end();return;}
    if ((await stat(path)).isDirectory()) path = resolve(path,'index.html');
    let data = await readFile(path);
    const type = types[extname(path)] || 'application/octet-stream';
    const headers = {'Content-Type':type,'X-Content-Type-Options':'nosniff','Cache-Control':extname(path)==='.html'?'no-cache':'public, max-age=2592000','Vary':'Accept-Encoding'};
    if (/gzip/.test(req.headers['accept-encoding']||'') && /html|css|javascript|json|xml|svg/.test(type)) { data = gzipSync(data);headers['Content-Encoding']='gzip'; }
    res.writeHead(200,headers);res.end(data);
  } catch {res.writeHead(404);res.end('Not found');}
}).listen(4323,'127.0.0.1',()=>console.log('Production QA preview: http://127.0.0.1:4323'));
