const fs = require('fs');
const content = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Find all occurrences of socket binary / event message handlers
// Let's decode the strings array in app.js
const strArrayMatch = content.match(/function\s+(_0x[a-f0-9]+)\s*\(\)\s*\{\s*var\s+_0x[a-f0-9]+\s*=\s*\[([^\]]+)\]/);
if (strArrayMatch) {
  const fnName = strArrayMatch[1];
  console.log('Found string array fn:', fnName);
  eval('var ' + strArrayMatch[0] + ';');
  const arr = eval(fnName + '()');
  console.log('Sample strings:', arr.filter(s => typeof s === 'string' && (s.includes('quotes') || s.includes('stream') || s.includes('tick') || s.includes('history') || s.includes('balance') || s.includes('order'))));
}
