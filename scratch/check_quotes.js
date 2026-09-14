const fs = require('fs');
const content = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');
const idx = content.indexOf('quotes');
if (idx !== -1) {
  console.log('Found quotes at', idx, content.substring(idx - 100, idx + 300));
}
const idx2 = content.indexOf('history/list/v2');
if (idx2 !== -1) {
  console.log('Found history/list/v2 at', idx2, content.substring(idx2 - 100, idx2 + 300));
}
