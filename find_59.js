const fs = require('fs');
const c = fs.readFileSync('c:/xampp/htdocs/trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

let idx = 0;
while ((idx = c.indexOf('% 60', idx + 1)) !== -1) {
  console.log('% 60 at', idx, c.substring(idx - 40, idx + 60));
}

idx = 0;
while ((idx = c.indexOf(' 59', idx + 1)) !== -1) {
  if (idx < 500000) console.log('59 at', idx, c.substring(idx - 40, idx + 60));
}
