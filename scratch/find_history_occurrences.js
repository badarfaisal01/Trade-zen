const fs = require('fs');

const content = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Find all matches of history
let idx = 0;
while ((idx = content.indexOf('history/', idx)) !== -1) {
  const start = Math.max(0, idx - 200);
  const end = Math.min(content.length, idx + 300);
  console.log('--- Occurrence at', idx, '---');
  console.log(content.substring(start, end));
  idx += 8;
}
