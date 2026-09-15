import express from 'express';
import './lib/mma-safety-patch.js';
import { connectRedis, runSync, status, stopSync } from './lib/vps-sync.js';

const app=express();
app.use(express.json({limit:'20mb'}));
const port=Number(process.env.PORT||8787);
const secret=String(process.env.SYNC_SECRET||'');
let currentPromise=null;

function auth(req,res,next){
  const given=String(req.get('x-sync-key')||'');
  if(!secret || !given || given!==secret) return res.status(401).json({ok:false,error:'Unauthorized'});
  next();
}
function start(mode){
  if(currentPromise) return false;
  currentPromise=runSync(mode).catch(err=>console.error('[sync]',err.stack||err)).finally(()=>{currentPromise=null;});
  return true;
}

app.get('/health',async(req,res)=>{try{await connectRedis();res.json({ok:true,service:'kickbox-sync-vps'});}catch(e){res.status(500).json({ok:false,error:e.message});}});
app.get('/status',auth,async(req,res)=>{try{res.json({ok:true,...await status()});}catch(e){res.status(500).json({ok:false,error:e.message});}});
app.post('/sync/full',auth,async(req,res)=>{if(!start('full'))return res.status(409).json({ok:false,error:'Sync already running'});res.json({ok:true,started:'full'});});
app.post('/sync/new',auth,async(req,res)=>{if(!start('new'))return res.status(409).json({ok:false,error:'Sync already running'});res.json({ok:true,started:'new'});});
app.post('/sync/stop',auth,async(req,res)=>{await stopSync();res.json({ok:true,stopping:true});});

await connectRedis();
app.listen(port,'0.0.0.0',()=>console.log(`Kickbox Sync VPS listening on :${port}`));

if(String(process.env.AUTO_START_FULL||'false').toLowerCase()==='true') setTimeout(()=>start('full'),4000);
const interval=Math.max(0,Number(process.env.AUTO_NEW_INTERVAL_MINUTES||60));
if(interval>0) setInterval(()=>{if(!currentPromise)start('new');},interval*60*1000);

// A full run re-checks every known source product. Price and inStock are part of
// the staged payload, so WooCommerce is updated whenever either value changes.
// Cap the interval at 12h so stale supplier stock cannot sit for a full day.
const requestedFullHours=Math.max(0,Number(process.env.AUTO_FULL_INTERVAL_HOURS||12));
const fullHours=requestedFullHours>0?Math.min(requestedFullHours,12):0;
if(fullHours>0) setInterval(()=>{if(!currentPromise)start('full');},fullHours*60*60*1000);
