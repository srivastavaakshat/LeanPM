// Renders index.html to an MP4: headless Chrome draws each frame (window.seek(t)), ffmpeg encodes.
//   node marketing/intro-video/render.mjs [out.mp4] [--fps=30] [--from=0] [--to=57] [--frames-only]
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const opt = (k, d) => { const a = args.find(x => x.startsWith(`--${k}=`)); return a ? a.split('=')[1] : d; };
const out = resolve(args.find(a => !a.startsWith('--')) || join(here, 'LeanPM-intro-4x3.mp4'));
const fps = +opt('fps', 30), W = 1440, H = 1080;
const chrome = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const port = 9300 + Math.floor(Math.random() * 500);
const profile = join(tmpdir(), `lpm-video-${port}`);
const frames = join(tmpdir(), `lpm-frames-${port}`);
mkdirSync(frames, { recursive: true });

const proc = spawn(chrome, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, `--window-size=${W},${H}`, '--hide-scrollbars', '--force-device-scale-factor=1', '--allow-file-access-from-files', '--disable-gpu-vsync', 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ver;
for (let i = 0; i < 50 && !ver; i++) { try { ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); } catch { await sleep(150); } }
if (!ver) { proc.kill(); throw new Error('Chrome did not start'); }
const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
const ws = new WebSocket(targets.find(t => t.type === 'page').webSocketDebuggerUrl);
await new Promise(r => ws.addEventListener('open', r, { once: true }));
let seq = 0; const pending = new Map();
ws.addEventListener('message', e => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); } });
const send = (method, params = {}) => new Promise((res, rej) => { const id = ++seq; pending.set(id, m => (m.error ? rej(new Error(m.error.message)) : res(m.result))); ws.send(JSON.stringify({ id, method, params })); });
const evaluate = async expr => (await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true })).result.value;

await send('Page.enable');
await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 1, mobile: false });
await send('Page.navigate', { url: pathToFileURL(join(here, 'index.html')).href });
for (let i = 0; i < 100 && !(await evaluate('!!window.ready').catch(() => false)); i++) await sleep(100);
await evaluate('window.ready');
const duration = await evaluate('window.DURATION');
const from = +opt('from', 0), to = +opt('to', duration);
const total = Math.round((to - from) * fps);
console.log(`Rendering ${total} frames at ${fps} fps (${(to - from).toFixed(1)} s)…`);
for (let i = 0; i < total; i++) {
  await evaluate(`seek(${(from + i / fps).toFixed(4)}); new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)))`);
  const { data } = await send('Page.captureScreenshot', { format: 'jpeg', quality: 94, clip: { x: 0, y: 0, width: W, height: H, scale: 1 } });
  writeFileSync(join(frames, `f${String(i).padStart(5, '0')}.jpg`), Buffer.from(data, 'base64'));
  if (i % 150 === 0) console.log(`  ${i}/${total}`);
}
ws.close(); proc.kill();
if (args.includes('--frames-only')) { console.log('Frames in', frames); process.exit(0); }
const ff = spawnSync('ffmpeg', ['-y', '-loglevel', 'error', '-framerate', String(fps), '-i', join(frames, 'f%05d.jpg'), '-c:v', 'libx264', '-preset', 'slow', '-crf', '17', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', out], { stdio: 'inherit' });
rmSync(frames, { recursive: true, force: true }); rmSync(profile, { recursive: true, force: true });
if (ff.status !== 0) process.exit(ff.status);
console.log('Wrote', out);
