const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Let's print lines 2340 to 2360 to get string decryptor functions
const pos = code.indexOf("'chartPeriod': _0x161ef7['z4']");
console.log(code.substring(pos - 1500, pos + 200));
