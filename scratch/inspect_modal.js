const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Find all occurrences of 5s, 10s, 15s, 30s in string decryption or raw text
// Let's search for how _0x4932d2 is used or exported!

// Look around line 5300:
console.log("--- Line 5300 to 5360 ---");
console.log(code.substring(code.indexOf("'Period_5'"), code.indexOf("'Period_5'") + 2000));
