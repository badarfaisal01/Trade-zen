<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

$root = realpath(__DIR__) ?: __DIR__;
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (realpath((string)$_SERVER['DOCUMENT_ROOT']) ?: (string)$_SERVER['DOCUMENT_ROOT']) : '';
$critical = [
    '/trade-room/react/uikit.3cf3eedf78f32f7d0d9d.css',
    '/trade-room/react/app.1e382d4238188d8c0401.css',
    '/trade-room/react/runtime.fa9ef21ed989beb8b8b7.js',
    '/trade-room/react/uikit.944983ce32773e612c15.js',
    '/trade-room/react/vendor.ae7d8f8a004fd2feac4d.js',
    '/trade-room/react/346.3bcb3c98900d5c44aedc.js',
    '/trade-room/react/617.1c3acbb07452931d5651.js',
    '/trade-room/react/708.530efc3bf733316adac2.js',
    '/trade-room/react/app.514f7c2627cb588c0e57.js',
    '/assets/js/original-trade-bridge.js',
    '/assets/css/original-trade.css',
];
$checks = [];
foreach ($critical as $url) {
    $path = $root . str_replace('/', DIRECTORY_SEPARATOR, $url);
    $checks[] = [
        'url' => $url,
        'exists' => is_file($path),
        'readable' => is_readable($path),
        'size' => is_file($path) ? filesize($path) : 0,
    ];
}
$dbStatus = ['configured' => false, 'driver' => in_array('mysql', PDO::getAvailableDrivers(), true), 'connected' => false, 'tables' => [], 'error' => ''];
$configPath = $root . '/config/database.local.php';
$defaultsPath = $root . '/config/database.defaults.php';
$dbConfig = null;
if (is_file($configPath)) {
    $dbStatus['configured'] = true;
    $dbConfig = (array)(require $configPath);
} elseif (is_file($defaultsPath)) {
    $dbConfig = (array)(require $defaultsPath);
}
if ($dbStatus['driver'] && is_array($dbConfig)) {
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string)($dbConfig['host'] ?? 'localhost'), (int)($dbConfig['port'] ?? 3306), (string)($dbConfig['database'] ?? ''));
        $pdo = new PDO($dsn, (string)($dbConfig['username'] ?? ''), (string)($dbConfig['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbStatus['connected'] = true;
        foreach (['users','market_assets','payment_methods','trades','fund_requests','wallet_transactions','site_settings','agents','agent_referrals','user_notifications'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
                $dbStatus['tables'][$table] = true;
            } catch (Throwable $e) {
                $dbStatus['tables'][$table] = false;
            }
        }
    } catch (Throwable $e) {
        $dbStatus['error'] = $e->getMessage();
    }
}
$rootMatch = $docRoot !== '' && rtrim(str_replace('\\','/',$root), '/') === rtrim(str_replace('\\','/',$docRoot), '/');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>55AK diagnostics</title>
<style>body{font-family:system-ui;background:#151927;color:#eef2ff;margin:0;padding:24px}.wrap{max-width:1050px;margin:auto}.card{background:#202638;border:1px solid #343c52;border-radius:14px;padding:18px;margin:14px 0}.ok{color:#48df93}.bad{color:#ff667d}.warn{color:#ffd166}code{background:#101523;padding:2px 6px;border-radius:6px;word-break:break-all}table{width:100%;border-collapse:collapse}td,th{padding:9px;border-bottom:1px solid #343c52;text-align:left;font-size:14px}.pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#111827}button{background:#19c77e;color:#07140f;border:0;padding:10px 14px;border-radius:9px;font-weight:700;cursor:pointer}</style></head><body><div class="wrap">
<h1>55AK v4.4.6 server diagnostics</h1>
<div class="card"><h2>Document-root check</h2><p>Application root: <code><?=htmlspecialchars($root)?></code></p><p>Web document root: <code><?=htmlspecialchars($docRoot)?></code></p><p class="<?=$rootMatch?'ok':'bad'?>"><strong><?=$rootMatch?'PASS — project is in the document root':'FAIL — project is one or more folders away from the document root'?></strong></p><?php if(!$rootMatch):?><p class="warn">Move the CONTENTS of this package into the domain document root. The URL <code>/trade-room/react/...</code> can only work when the <code>trade-room</code> folder is directly inside that root.</p><?php endif;?></div>
<div class="card"><h2>Critical trade files on disk</h2><table><thead><tr><th>URL</th><th>Disk</th><th>Bytes</th><th>HTTP</th></tr></thead><tbody><?php foreach($checks as $i=>$c):?><tr><td><code><?=htmlspecialchars($c['url'])?></code></td><td class="<?=$c['exists']&&$c['readable']?'ok':'bad'?>"><?=$c['exists']&&$c['readable']?'PASS':'MISSING'?></td><td><?=number_format((int)$c['size'])?></td><td id="http-<?=$i?>">checking…</td></tr><?php endforeach;?></tbody></table><p><button onclick="runHttpChecks()">Re-test HTTP asset URLs</button></p></div>
<div class="card"><h2>PHP / database</h2><p>PHP: <span class="pill"><?=htmlspecialchars(PHP_VERSION)?></span></p><p>PDO MySQL driver: <span class="<?=$dbStatus['driver']?'ok':'bad'?>"><?=$dbStatus['driver']?'PASS':'MISSING'?></span></p><p>database.local.php: <span class="<?=$dbStatus['configured']?'ok':'warn'?>"><?=$dbStatus['configured']?'present':'not created yet (installer can create it)'?></span></p><p>Database connection using configured/deployment credentials: <span class="<?=$dbStatus['connected']?'ok':'bad'?>"><?=$dbStatus['connected']?'PASS':'NOT CONNECTED'?></span></p><?php if($dbStatus['error']):?><p class="bad">DB error: <?=htmlspecialchars($dbStatus['error'])?></p><?php endif;?><?php if($dbStatus['tables']):?><table><tbody><?php foreach($dbStatus['tables'] as $t=>$ok):?><tr><td><?=htmlspecialchars($t)?></td><td class="<?=$ok?'ok':'bad'?>"><?=$ok?'PASS':'MISSING'?></td></tr><?php endforeach;?></tbody></table><?php endif;?></div>

<div class="card"><h2>Signed-in route check</h2><p>This browser-side test uses your current login session and checks where each button/page actually lands.</p><table><thead><tr><th>Expected page</th><th>URL</th><th>Status</th><th>Final URL</th></tr></thead><tbody id="route-check-body"></tbody></table><p><button onclick="runRouteChecks()">Re-test page routes</button></p></div>
<div class="card"><h2>Next check</h2><p>All disk and HTTP rows above must say PASS/200. Then open <code>/en/trade/</code>. Delete or rename <code>diagnostics.php</code> after setup.</p></div>
</div><script>
const critical = <?=json_encode(array_column($checks,'url'), JSON_UNESCAPED_SLASHES)?>;
const routeChecks = [
  ['Trade','/en/trade/','/en/trade/'],
  ['Demo trade','/en/demo-trade','/en/demo-trade'],
  ['Legacy Demo redirect','/en/demo-trade.html','/en/demo-trade'],
  ['Demo trailing-slash redirect','/en/demo-trade/','/en/demo-trade'],
  ['Deposit','/en/balance/?deposit=true','/en/balance/'],
  ['Withdrawal','/en/withdrawal/','/en/withdrawal/'],
  ['Legacy withdrawal redirect','/en/wallet/?action=withdrawal','/en/withdrawal/'],
  ['Legacy wallet redirect','/en/wallet/','/en/balance/'],
  ['Payments alias','/en/payments/','/en/balance/'],
  ['Account','/en/settings/','/en/settings/'],
  ['History','/en/history/','/en/history/'],
  ['Analytics','/en/analytics/','/en/analytics/'],
  ['Support','/en/support/','/en/support/'],
  ['About','/en/about/','/en/about/'],
  ['Blog','/en/blog/','/en/blog/'],
  ['FAQ','/en/faq/','/en/faq/'],
  ['Trade state API','/en/api/state.php','/en/api/state.php'],
  ['Market API','/en/api/market.php?symbol=EURUSD&interval=5&count=20','/en/api/market.php']
];
async function runHttpChecks(){for(let i=0;i<critical.length;i++){const el=document.getElementById('http-'+i);try{const r=await fetch(critical[i]+'?diag='+Date.now(),{cache:'no-store',credentials:'same-origin'});el.textContent=String(r.status);el.className=r.ok?'ok':'bad';}catch(e){el.textContent='FETCH ERROR';el.className='bad';}}}
async function runRouteChecks(){
  const body=document.getElementById('route-check-body');
  body.innerHTML='';
  for(const [name,url,expectedPath] of routeChecks){
    const tr=document.createElement('tr');
    tr.innerHTML='<td>'+name+'</td><td><code>'+url+'</code></td><td>checking…</td><td>—</td>';
    body.appendChild(tr);
    try{
      const r=await fetch(url+(url.includes('?')?'&':'?')+'diag='+Date.now(),{cache:'no-store',credentials:'same-origin'});
      const cells=tr.children;
      cells[2].textContent=String(r.status); cells[2].className=r.ok?'ok':'bad';
      const finalPath=new URL(r.url).pathname + new URL(r.url).search;
      cells[3].textContent=finalPath;
      const finalUrl=new URL(r.url);
      if(finalUrl.pathname.includes('/sign-in/')) cells[3].className='warn';
      else if(finalUrl.pathname===expectedPath) cells[3].className='ok';
      else cells[3].className='bad';
    }catch(e){tr.children[2].textContent='FETCH ERROR';tr.children[2].className='bad';tr.children[3].textContent=String(e);}
  }
}
runHttpChecks();
runRouteChecks();
</script></body></html>
