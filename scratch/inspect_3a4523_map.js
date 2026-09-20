const fs = require('fs');
const code = fs.readFileSync('trade-room/react/uikit.944983ce32773e612c15.js', 'utf8');

let pos = 1555000;
while ((pos = code.indexOf("_0x44ad2b", pos)) !== -1 && pos < 1560000) {
    console.log("Pos:", pos);
    console.log(code.substring(Math.max(0, pos - 50), Math.min(code.length, pos + 250)));
    console.log('---');
    pos += 9;
}
