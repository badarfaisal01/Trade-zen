const fs = require('fs');

const appCode = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

const regex = /(history:\s*[a-zA-Z0-9_$]+,\s*candles:\s*[a-zA-Z0-9_$]+)/g;
let match;
while ((match = regex.exec(appCode)) !== null) {
  const start = Math.max(0, match.index - 300);
  const end = Math.min(appCode.length, match.index + 600);
  console.log('=== MATCH AT ' + match.index + ' ===');
  console.log(appCode.substring(start, end));
  console.log('====================================');
}
