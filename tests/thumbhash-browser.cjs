/* Local browser acceptance tests. Never bootstraps WordPress or accesses a database. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
// Only system fonts are used. Held image requests intentionally postpone document load.
process.env.PW_TEST_SCREENSHOT_NO_FONTS_READY = '1';
const playwright = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
const output = path.resolve(process.env.ARTIFACT_DIR || path.join('/tmp', 'avif-thumbhash-browser'));
const php = process.env.PHP_BIN || 'php';
const helper = path.join(__dirname, 'thumbhash-fixture.php');
const fixture = JSON.parse(execFileSync(php, [helper, 'image'], { encoding: 'utf8' }));
const photo = Buffer.from(fixture.bytes, 'base64');
const lookaheadPath = process.env.LOOKAHEAD_FILE;
const lookahead = lookaheadPath ? fs.readFileSync(lookaheadPath, 'utf8') : '';
const oldRoot = fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'avif-thumbhash-before-'));
for (const file of ['includes/class-support.php', 'assets/thumbhash-decoder.min.js']) {
 fs.mkdirSync(path.dirname(path.join(oldRoot,file)),{recursive:true});
 fs.writeFileSync(path.join(oldRoot,file),execFileSync('git',['show',`HEAD:${file}`],{cwd:root}));
}
const heads = Object.fromEntries(['0', '1'].map(fade => [fade, execFileSync(php, [helper, fade], { encoding: 'utf8' })]));
heads.old=execFileSync(php,[helper,'1'],{encoding:'utf8',env:{...process.env,THUMBHASH_PLUGIN_DIR:oldRoot}});
const pluginCSS = fs.readFileSync(path.join(root, 'assets/frontend.css'), 'utf8');
fs.mkdirSync(output, { recursive: true });
const all = { generatedAt: new Date().toISOString(), source: 'isolated localhost fixtures; actual plugin inline output', photoSource: process.env.PHOTO_FILE || 'generated test image', lookaheadFile: lookaheadPath || null, runs: [] };
const requests = [];
const gates = new Map();
const documents = new Map();
let counter = 0;
let base;
function gate(key) { const value = { release: null, ready: false, error: false }; value.promise = new Promise(resolve => { value.release = () => { value.ready = true; resolve(); }; }); gates.set(key, value); return value; }
function release(key, error = false) { const g = gates.get(key); if (g) { g.error = error; g.release(); } }
function releaseAll(prefix) { for (const [key, g] of gates) if (key.startsWith(prefix)) g.release(); }
const server = http.createServer(async (req, res) => {
  const u = new URL(req.url, 'http://localhost');
  if (u.pathname.startsWith('/photo/')) {
    const key = u.pathname.slice('/photo/'.length).replace(/\.jpg$/, '');
    requests.push({ key, at: Date.now(), url: u.pathname });
    const g = gates.get(key); if (g) await g.promise;
    if (res.destroyed) return;
    if (g?.error) { res.writeHead(404); res.end('fixture error'); return; }
    res.writeHead(200, { 'Content-Type': 'image/jpeg', 'Content-Length': photo.length, 'Cache-Control': 'public, max-age=3600' }); res.end(photo); return;
  }
  if (u.pathname === '/pause.js') { await new Promise(resolve => setTimeout(resolve, 120)); res.writeHead(200, {'Content-Type':'text/javascript'}); res.end(';'); return; }
  const html = documents.get(u.pathname);
  if (html) { res.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' }); res.end(html); return; }
  res.writeHead(404); res.end();
});
const common = `<style>${pluginCSS}
html,body{margin:0;padding:0;background:#e8e8e8;color:#222;font:15px system-ui}main{padding:0 20px}picture{display:block;line-height:0;width:min(600px,100%)}img{display:block;width:100%;height:auto}figure{margin:0} .spacer{height:1200px} .label{font:14px system-ui;padding:8px 0} .frame{width:min(600px,100%)}
</style>`;
const instrument = `<script>
window.decodeCalls=[]; window.samples=[]; window.events=[];
const nativeDecode=HTMLImageElement.prototype.decode;
HTMLImageElement.prototype.decode=function(){
 const img=this, mode=img.dataset.decode;
 if(!mode)return nativeDecode.call(img);
 let resolve,reject;
 const p=new Promise((a,b)=>{resolve=a;reject=b});
 const call={id:img.id,src:img.currentSrc,resolve,reject};
 window.decodeCalls.push(call);
 return nativeDecode.call(img).then(()=>mode==='reject'?Promise.reject(new Error('fixture decode rejection')):p);
};
document.addEventListener('transitionend',e=>window.events.push({type:e.type,id:e.target.id,property:e.propertyName,at:performance.now()}),true);
document.addEventListener('transitioncancel',e=>window.events.push({type:e.type,id:e.target.id,property:e.propertyName,at:performance.now()}),true);
window.capture=function(id){const i=document.getElementById(id),p=i.closest('picture')||i,r=i.getBoundingClientRect(),s=getComputedStyle(i);return {at:performance.now(),opacity:+s.opacity,background:getComputedStyle(p).backgroundImage,complete:i.complete,naturalWidth:i.naturalWidth,src:i.currentSrc,top:r.top,width:r.width,height:r.height,transition:s.transitionDuration,style:i.getAttribute('style'),className:i.className};};
(function tick(){if(window.sampleTarget&&document.getElementById(window.sampleTarget))window.samples.push(window.capture(window.sampleTarget));requestAnimationFrame(tick)})();
</script>`;
function img(run, id, extra = '', { hash = fixture.hash, picture = true, source = '', style = '' } = {}) {
 const h = hash === null ? '' : ` data-thumbhash="${hash}"`;
 const markup = `<img id="${id}" src="/photo/${run}-${id}.jpg" width="${fixture.width}" height="${fixture.height}"${h} ${extra}>`;
 return picture ? `<picture class="aviflosu-picture" style="${style}">${source}${markup}</picture>` : markup;
}
function html(run, body, { fade = '1', delayed = false, buffer = false, before = '' } = {}) {
 const head = heads[fade];
 return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">${common}${instrument}${before}${delayed ? '' : head}</head><body><main>${body}</main>${buffer ? `<script>${lookahead}</script>` : ''}${delayed ? `<script>window.injectDecoder=function(){const box=document.createElement('div');box.innerHTML=${JSON.stringify(head).replace(/</g, '\\u003c')};for(const el of box.children){const copy=document.createElement(el.tagName);for(const a of el.attributes)copy.setAttribute(a.name,a.value);copy.textContent=el.textContent;document.head.appendChild(copy)}};</script>` : ''}</body></html>`;
}
async function casePage(context, label, body, opts = {}, held = []) {
 const run = `${label}-${++counter}`;
 for (const id of held) gate(`${run}-${id}`);
 documents.set(`/${run}`, html(run, body(run), opts));
 const page = await context.newPage();
 const errors = []; page.on('pageerror', e => errors.push(e.message));
 await page.goto(`${base}/${run}`, { waitUntil: 'domcontentloaded' });
 return { run, page, errors };
}
async function state(page, id = 'target') { return page.evaluate(id => window.capture(id), id); }
async function settled(page, id = 'target') {
 await page.waitForFunction(id => { const i = document.getElementById(id); return +getComputedStyle(i).opacity === 1 && getComputedStyle(i.closest('picture') || i).backgroundImage === 'none'; }, id);
}
async function decodeReady(page, count = 1) { await page.waitForFunction(count => window.decodeCalls.length >= count, count); }
function check(run, condition, message, detail) { assert.ok(condition, `${run.name}: ${message}${detail ? ` ${JSON.stringify(detail)}` : ''}`); run.checks.push(message); }
async function closeCase(c) { releaseAll(c.run); await c.page.close(); }
async function basic(run, context) {
 const c = await casePage(context, 'basic', r => `${img(r,'top')}<div style="position:absolute;top:calc(100vh - 10px);width:300px">${img(r,'partial')}</div><div style="height:1200px"></div>${img(r,'target','data-decode="hold"')}<div style="height:40px"></div>${img(r,'high','fetchpriority="high"')}${img(r,'bare','',{picture:false})}${img(r,'invalid','',{hash:'bad!'})}${img(r,'truncated','',{hash:'AAAA'})}${img(r,'missing','',{hash:null})}<div style="display:none">${img(r,'unmeasurable')}</div>`, {}, ['top','partial','target','high','bare','invalid','truncated','missing','unmeasurable']);
 try {
  await c.page.waitForTimeout(90);
  check(run,(await state(c.page,'top')).opacity===1,'initial viewport image remains visible');
  for (const id of ['partial','high','bare','invalid','truncated','missing','unmeasurable']) check(run,(await state(c.page,id)).opacity===1,`${id} fails open without hiding`);
  let s=await state(c.page); check(run,s.opacity===0 && s.background.startsWith('url('),'eligible below-fold image has a real visible ThumbHash before load',s);
  // Free other connections: WebKit can otherwise queue the below-fold request behind held visible images.
  for(const [key,g] of gates)if(key.startsWith(c.run+'-')&&key!==`${c.run}-target`)g.release();
  await c.page.evaluate(()=>{window.sampleTarget='target'});
  release(`${c.run}-target`); await decodeReady(c.page);
  s=await state(c.page);check(run,s.opacity===0&&s.background!=='none','ThumbHash persists through deliberately held decode',s);
  await c.page.evaluate(()=>window.decodeCalls[0].resolve()); await settled(c.page);
  const samples=await c.page.evaluate(()=>window.samples);
  check(run,samples.some(s=>s.opacity>0&&s.opacity<1),'actual intermediate opacity frames observed');
  check(run,samples.filter(s=>s.opacity<1).every(s=>s.background!=='none'),'no transparent frame lacks the ThumbHash');
  check(run,(await state(c.page)).top>run.viewport.height,'reveal completes offscreen without waiting for a scroll');
  check(run,(await c.page.evaluate(()=>window.events)).some(e=>e.type==='transitionend'&&e.property==='opacity'),'normal cleanup follows opacity transitionend');
  run.slowSamples=samples;check(run,c.errors.length===0,'no page errors in delayed load/decode path',c.errors);
 } finally { await closeCase(c); }
}
async function options(run, browser, configuration) {
 for(const mode of ['disabled','reduced','decode-reject','error','no-decode','timeout','cancel']) {
  const ctx=await browser.newContext({...configuration,...(mode==='reduced'?{reducedMotion:'reduce'}:{})});
  const extra=mode==='decode-reject'?'data-decode="reject"':'';
  const before=mode==='no-decode'?'<script>HTMLImageElement.prototype.decode=undefined;</script>':'';
  const c=await casePage(ctx,mode,r=>`<div class="spacer"></div>${img(r,'target',extra)}`,{fade:mode==='disabled'?'0':'1',before},['target']);
  try {
   await c.page.waitForTimeout(50); let s=await state(c.page);
   check(run,s.opacity===(mode==='disabled'?1:0),`${mode}: initial visibility follows setting`);
   await c.page.evaluate(mode=>{window.sampleTarget='target'; if(mode==='timeout')document.addEventListener('transitionend',e=>e.stopImmediatePropagation(),true); if(mode==='cancel')document.addEventListener('transitionrun',e=>{if(e.target.id==='target')e.target.style.transition='none'},true)},mode);
   release(`${c.run}-target`,mode==='error');await settled(c.page);
   s=await state(c.page);check(run,s.opacity===1,`${mode}: eventually visible and preview cleaned`);
   const samples=await c.page.evaluate(()=>window.samples);
   if(['disabled','reduced'].includes(mode))check(run,!samples.some(s=>s.opacity>0&&s.opacity<1),`${mode}: no animation`);
   if(mode==='error')check(run,s.naturalWidth===0,'network error exposes normal broken-image/alt state');
   check(run,c.errors.length===0,`${mode}: no uncaught exceptions`,c.errors);
  } finally {await closeCase(c);await ctx.close();}
 }
}
async function cachedAndRace(run, context) {
 for(const mode of ['cached','racing']) {
  const c=await casePage(context,mode,r=>`<div class="spacer"></div>${img(r,'target')}`,{delayed:true},[]);
  try {
   await c.page.waitForFunction(()=>document.getElementById('target').complete&&document.getElementById('target').naturalWidth>0);
   await c.page.evaluate(()=>{window.sampleTarget='target';window.injectDecoder()});await c.page.waitForTimeout(260);
   check(run,(await c.page.evaluate(()=>window.samples)).every(s=>s.opacity===1),`${mode}: loaded image never hidden by initialization`);
   await settled(c.page);
  }finally{await closeCase(c)}
 }
 // Event can arrive after handlers are installed but before the second completeness check.
 const c=await casePage(context,'load-race',r=>`<div class="spacer"></div>${img(r,'target')}`,{delayed:true},[]);
 try {
  await c.page.waitForFunction(()=>document.getElementById('target').complete);
  await c.page.evaluate(()=>{
   const i=document.getElementById('target');let reads=0;
   Object.defineProperty(i,'complete',{get(){return ++reads>1}});
   window.sampleTarget='target';window.injectDecoder();
  });await settled(c.page);check(run,true,'completeness recheck handles initialization/load race');
 }finally{await closeCase(c)}
}
async function eligibilityEdges(run, context) {
 const c=await casePage(context,'shifted',r=>`${img(r,'target')}<script>setTimeout(()=>{const spacer=document.createElement('div');spacer.style.height='1500px';document.querySelector('main').prepend(spacer)},30)</script><script src="/pause.js"></script>`,{},['target']);
 try{await c.page.waitForTimeout(80);check(run,(await state(c.page)).top>run.viewport.height,'late layout fixture actually shifts image below viewport');check(run,(await state(c.page)).opacity===1,'image initially observed in first screen remains exempt after layout shift');}finally{await closeCase(c)}
 const style='display:flex;flex-direction:column;background-image:linear-gradient(rgb(255, 255, 255), rgb(0, 0, 0));background-size:contain;background-position:left center;background-repeat:repeat-x';
 const d=await casePage(context,'styles',r=>`<div class="spacer"></div>${img(r,'target','',{style})}`,{},['target']);
 try{await d.page.waitForTimeout(50);release(`${d.run}-target`);await d.page.waitForTimeout(600);const restored=await d.page.locator('picture').evaluate(p=>({display:p.style.display,size:p.style.backgroundSize,position:p.style.backgroundPosition,repeat:p.style.backgroundRepeat,image:p.style.backgroundImage}));check(run,restored.display==='flex'&&restored.size==='contain'&&restored.position==='left center'&&restored.repeat==='repeat-x'&&restored.image.includes('linear-gradient'),'authored picture display and background styles restored',restored);}finally{await closeCase(d)}
 const e=await casePage(context,'priority-updated',r=>`<div class="spacer"></div>${img(r,'target')}`,{},['target']);
 try{await e.page.waitForTimeout(40);await e.page.locator('#target').evaluate(i=>i.setAttribute('fetchpriority','high'));await e.page.waitForTimeout(60);release(`${e.run}-target`);await settled(e.page);check(run,true,'priority changes while loading remain usable');}finally{await closeCase(e)}
}
async function presentationEdges(run, context) {
 for(const mode of ['overridden','overflow','scroll-before-classify','pixelated','background-overridden']) {
  const before=mode==='overridden'?'<style>img{opacity:1!important}</style>':mode==='overflow'?'<style>picture{width:200px}img{width:400px}</style>':mode==='pixelated'?'<style>.thumbhash-bg{image-rendering:pixelated}</style>':mode==='background-overridden'?'<style>picture{background-image:none!important}</style>':'';
  const c=await casePage(context,mode,r=>`<div class="spacer"></div>${img(r,'target')}${mode==='scroll-before-classify'?'<script>setTimeout(()=>{scrollTo(0,20);window.fixtureScroll={at:performance.now(),readyState:document.readyState,y:scrollY,height:document.documentElement.scrollHeight}},30)</script><script src="/pause.js"></script>':''}`,{before},['target']);
  try{await c.page.waitForTimeout(80);const s=await state(c.page);if(mode==='background-overridden'){check(run,s.opacity===1,'overridden preview background never hides foreground');}else if(mode==='pixelated'){check(run,await c.page.locator('#target').evaluate(i=>getComputedStyle(i).imageRendering==='auto'&&getComputedStyle(i.parentElement).imageRendering==='pixelated'),'pixelated placeholder does not pixelate decoded photograph');}else check(run,s.opacity===1&&s.background!=='none',`${mode}: uncertain/overridden presentation retains preview and normal foreground`,{...s,fixtureScroll:await c.page.evaluate(()=>window.fixtureScroll||null),scrollY:await c.page.evaluate(()=>scrollY)});release(`${c.run}-target`);await settled(c.page);if(mode==='pixelated')check(run,await c.page.locator('#target').evaluate(i=>i.style.imageRendering===''),'foreground image-rendering inline style restored after cleanup');}finally{await closeCase(c)}
 }
}
async function responsive(run, context) {
 const c=await casePage(context,'stale',r=>`<div class="spacer"></div>${img(r,'target','data-decode="hold"')}`,{},['target','replacement']);
 try {
  await c.page.waitForTimeout(40);release(`${c.run}-target`);await decodeReady(c.page);
  await c.page.evaluate(src=>{document.getElementById('target').src=src},`/photo/${c.run}-replacement.jpg`);
  await c.page.waitForTimeout(60);
  await c.page.evaluate(()=>window.decodeCalls[0].resolve());await c.page.waitForTimeout(230);
  let s=await state(c.page);check(run,s.background!=='none','stale decode cannot remove preview while replacement photograph is still downloading',s);
  release(`${c.run}-replacement`);await c.page.waitForTimeout(150);
  await c.page.evaluate(()=>window.decodeCalls.forEach(c=>c.resolve()));await settled(c.page);
  check(run,(await state(c.page)).src.endsWith('replacement.jpg'),'replacement source remains selected and visible');
  // A source change after the completed fade must not hide the photograph again.
  await c.page.evaluate(src=>{window.samples=[];window.sampleTarget='target';document.getElementById('target').src=src},`/photo/${c.run}-third.jpg`);
  await c.page.waitForTimeout(300);check(run,(await c.page.evaluate(()=>window.samples)).every(s=>s.opacity===1),'already revealed responsive image never hidden again');
 }finally{await closeCase(c)}
}
async function resizePending(run, context) {
 const c=await casePage(context,'resize-pending',r=>`<div class="spacer"></div>${img(r,'target','',{source:`<source media="(min-width:700px)" srcset="/photo/${r}-wide.jpg">`})}`,{},['target','wide']);
 try{
  await c.page.waitForTimeout(70);await c.page.setViewportSize({width:run.viewport.width>700?390:1100,height:run.viewport.height});await c.page.waitForTimeout(90);
  const s=await state(c.page);check(run,s.background!=='none','responsive resize keeps ThumbHash while replacement still downloads');
  await c.page.evaluate(()=>{window.sampleTarget='target';window.samples=[]});releaseAll(c.run);await settled(c.page);
  check(run,(await c.page.evaluate(()=>window.samples)).every(s=>s.opacity===1),'responsive resize fails open without hiding replacement again');
 }finally{await closeCase(c)}
}
async function delivery(run, context) {
 const observations=[];
 for(const fade of ['old','0','1']){
  const c=await casePage(context,`delivery-${fade}`,r=>`${img(r,'top','fetchpriority="high"')}<div class="spacer"></div>${img(r,'target','srcset="/photo/'+r+'-small.jpg 600w, /photo/'+r+'-large.jpg 1200w" sizes="(max-width: 600px) 350px, 600px"',{source:`<source media="(min-width: 700px)" srcset="/photo/${r}-wide.jpg 600w, /photo/${r}-wider.jpg 1200w" sizes="600px">`})}<video width="320" height="180" preload="none" controls><source src="/movie.mp4" type="video/mp4"></video>`,{fade},[]);
  try{
   await c.page.waitForFunction(()=>[...document.images].every(i=>i.complete));await c.page.waitForTimeout(260);
   const snapshot=()=>[...document.images].map(i=>({id:i.id,src:i.currentSrc.replace(/delivery-(?:old|[01])-\d+-/g,''),naturalWidth:i.naturalWidth,naturalHeight:i.naturalHeight,rect:{x:i.getBoundingClientRect().x,y:i.getBoundingClientRect().y,width:i.getBoundingClientRect().width,height:i.getBoundingClientRect().height},attrs:[...i.attributes].filter(a=>!['style','class'].includes(a.name)).map(a=>[a.name,a.value.replace(/delivery-(?:old|[01])-\d+-/g,'')]),sources:[...(i.closest('picture')?.querySelectorAll('source')||[])].map(s=>[...s.attributes].map(a=>[a.name,a.value.replace(/delivery-(?:old|[01])-\d+-/g,'')]))}));
   const initial=await c.page.evaluate(snapshot);
   await c.page.setViewportSize({width:run.viewport.width>700?390:1100,height:run.viewport.height});await c.page.waitForTimeout(300);const resized=await c.page.evaluate(snapshot);
   observations.push({initial,resized,requests:requests.filter(r=>r.key.startsWith(c.run+'-')).map(r=>r.key.slice(c.run.length+1)).sort(),video:await c.page.locator('video').evaluate(v=>v.outerHTML)});
  }finally{await closeCase(c)}
 }
 assert.deepEqual(observations[0],observations[1],`${run.name}: prior implementation vs fade disabled delivery/layout/source inventory`);
 assert.deepEqual(observations[0],observations[2],`${run.name}: prior implementation vs fade enabled delivery/layout/source inventory`);
 check(run,true,'prior implementation and fade on/off select identical image URLs, request counts, dimensions, layout, source attributes, and video markup before/after resizing');run.delivery=observations;
}
async function lookaheadTest(run, context) {
 if(!lookahead){run.skipped.push('3000px lookahead: supply LOOKAHEAD_FILE');return;}
 const c=await casePage(context,'lookahead',r=>`${img(r,'top')}<div style="height:1800px"></div>${img(r,'target','loading="lazy" sizes="auto, 600px"')}<div style="height:6000px"></div>${img(r,'far','loading="lazy"')}<div style="height:1000px"></div>`,{buffer:true},['top','target','far']);
 try{
  await c.page.waitForTimeout(60);release(`${c.run}-top`);
  // Loading buffer starts at window load or first scroll. A tiny scroll avoids waiting for held images.
  await c.page.evaluate(()=>scrollTo(0,1));
  await c.page.waitForFunction(()=>document.getElementById('target').loading==='eager');
  check(run,(await state(c.page)).opacity===0,'3000px buffer can promote lazy to eager without changing fade eligibility');
  check(run,await c.page.locator('#far').getAttribute('loading')==='lazy','image beyond 3000px buffer remains lazy');
  release(`${c.run}-target`);await settled(c.page);
  check(run,await c.page.locator('#target').getAttribute('loading')==='lazy','buffer restores original lazy attribute');
  check(run,await c.page.locator('#target').getAttribute('sizes')==='auto, 600px','buffer restores original auto sizes');
  check(run,(await state(c.page)).top>run.viewport.height,'buffer-triggered decode/fade completes offscreen');
  await c.page.locator('#far').evaluate(i=>scrollTo(0,i.getBoundingClientRect().top+scrollY-innerHeight-2800));
  await c.page.waitForFunction(()=>document.getElementById('far').loading==='eager');
  check(run,true,'existing 3000px buffer promotes the distant image on approach');release(`${c.run}-far`);await settled(c.page,'far');
 }finally{await closeCase(c)}
}
async function visual(run, context) {
 const c=await casePage(context,'visual',r=>`<div style="height:1200px"></div><div class="frame"><div class="label">Local preview · 180 ms ThumbHash crossfade</div>${img(r,'target','data-decode="hold"')}</div><div style="height:1200px"></div>`,{},['target']);
 try{
  await c.page.waitForTimeout(50);await c.page.locator('#target').evaluate(i=>scrollTo(0,i.getBoundingClientRect().top+scrollY-70));
  await c.page.evaluate(()=>window.sampleTarget='target');
  const clip=await c.page.locator('.frame').boundingBox();
  const prefix=`${run.name}`;
  const previewFrame=await c.page.screenshot({path:path.join(output,`${prefix}-01-preview.png`),clip});
  release(`${c.run}-target`);await decodeReady(c.page);
  await c.page.screenshot({path:path.join(output,`${prefix}-02-decoding.png`),clip});
  await c.page.evaluate(()=>window.decodeCalls[0].resolve());
  await c.page.waitForFunction(()=>{const o=+getComputedStyle(document.getElementById('target')).opacity;return o>0&&o<0.8},{},{polling:'raf'});
  const transitionFrame=await c.page.screenshot({path:path.join(output,`${prefix}-03-transition.png`),clip});
  await settled(c.page);const photoFrame=await c.page.screenshot({path:path.join(output,`${prefix}-04-photo.png`),clip});
  const pixelDifference=await c.page.evaluate(async frames=>{const pixels=await Promise.all(frames.map(async data=>{const i=new Image();i.src='data:image/png;base64,'+data;await i.decode();const cv=document.createElement('canvas');cv.width=i.width;cv.height=i.height;const cx=cv.getContext('2d');cx.drawImage(i,0,0);return cx.getImageData(0,0,i.width,i.height).data}));function delta(a,b){let sum=0;for(let n=0;n<a.length;n++)sum+=Math.abs(a[n]-b[n]);return sum/a.length}return {previewToTransition:delta(pixels[0],pixels[1]),transitionToPhoto:delta(pixels[1],pixels[2])}},[previewFrame,transitionFrame,photoFrame].map(b=>b.toString('base64')));
  check(run,pixelDifference.previewToTransition>0.5&&pixelDifference.transitionToPhoto>0.5,'recorded transition frame contains a real intermediate blend',pixelDifference);
  run.visual={pixelDifference,frames:[1,2,3,4].map((n,i)=>`${prefix}-0${n}-${['preview','decoding','transition','photo'][i]}.png`),samples:await c.page.evaluate(()=>window.samples)};
 }finally{await closeCase(c)}
}
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));base=`http://127.0.0.1:${server.address().port}`;
 const configurations=[{name:'desktop',viewport:{width:1440,height:900},deviceScaleFactor:1},{name:'mobile',viewport:{width:390,height:844},deviceScaleFactor:1,isMobile:true}].filter(c=>!process.env.VIEWPORTS||process.env.VIEWPORTS.split(',').includes(c.name));
 try{
  for(const browserName of (process.env.BROWSERS||'chromium,webkit').split(',')){
   const browser=await playwright[browserName].launch({headless:true});
   try{for(const config of configurations){
    const {name,...configuration}=config;const run={name:`${browserName}-${name}`,browser:browserName,viewport:configuration.viewport,checks:[],skipped:[]};all.runs.push(run);
    const context=await browser.newContext(configuration);
    try{await basic(run,context);await options(run,browser,configuration);await cachedAndRace(run,context);await eligibilityEdges(run,context);await presentationEdges(run,context);await responsive(run,context);await resizePending(run,context);await delivery(run,context);await lookaheadTest(run,context);await visual(run,context);run.passed=true;console.log(`${run.name}: ${run.checks.length} checks passed`)}catch(e){run.error=e.stack;run.passed=false;console.error(e.stack);process.exitCode=1;}finally{await context.close();fs.writeFileSync(path.join(output,'results.json'),JSON.stringify(all,null,2));}
   }}finally{await browser.close()}
  }
 }finally{for(const g of gates.values())g.release();server.close();fs.rmSync(oldRoot,{recursive:true,force:true});}
})();
