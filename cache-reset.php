<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>55AK Trade cache reset</title>
<style>
html,body{height:100%;margin:0;background:#1c1f2d;color:#fff;font-family:Arial,sans-serif}main{min-height:100%;display:grid;place-items:center;padding:24px;box-sizing:border-box}.box{text-align:center;max-width:520px}.logo{font-size:30px;font-weight:900}.logo b{color:#18c86b}p{color:#aab3c5;line-height:1.55}.spin{width:34px;height:34px;margin:20px auto;border:3px solid #394155;border-top-color:#18c86b;border-radius:50%;animation:s .8s linear infinite}@keyframes s{to{transform:rotate(360deg)}}a{display:inline-block;margin-top:12px;padding:12px 18px;border-radius:7px;background:#18c86b;color:#fff;text-decoration:none;font-weight:700}
</style>
</head>
<body><main><div class="box"><div class="logo">55<b>AK</b></div><div class="spin"></div><h2>Refreshing trading files…</h2><p id="status">Removing old browser cache and service-worker files. Your login cookies are not cleared.</p><a id="manual" href="/en/trade/?_refresh=1" hidden>Open Trade</a></div></main>
<script>
(async function(){
  const status=document.getElementById('status');
  try{
    if(window.caches&&caches.keys){const keys=await caches.keys();await Promise.all(keys.filter(k=>k.indexOf('55ak-trade')===0).map(k=>caches.delete(k)));}
    if(navigator.serviceWorker&&navigator.serviceWorker.getRegistrations){const regs=await navigator.serviceWorker.getRegistrations();await Promise.all(regs.map(r=>r.unregister()));}
    try{sessionStorage.removeItem('55ak-trade-assets-version');}catch(e){}
    status.textContent='Old trading files removed. Opening the stable trading screen…';
  }catch(e){status.textContent='Cache reset finished with a browser warning. Opening Trade anyway…';}
  setTimeout(()=>location.replace('/en/trade/?_refresh='+Date.now()),500);
})().catch(function(){document.getElementById('manual').hidden=false;});
</script></body></html>
