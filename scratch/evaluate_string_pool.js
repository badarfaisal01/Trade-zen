const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Find the start of module 0xfaac or string array
const startPos = code.indexOf("'chartPeriod': _0x161ef7['z4']");
console.log(code.substring(startPos - 3000, startPos - 1500));
