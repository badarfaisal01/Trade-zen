const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// search for `z4:` or `'z4':` or export object containing z4
let pos = 0;
while ((pos = code.indexOf('z4', pos)) !== -1) {
    console.log(`z4 at ${pos}:`);
    console.log(code.substring(Math.max(0, pos - 150), Math.min(code.length, pos + 150)));
    console.log('-------------------');
    pos += 2;
}
