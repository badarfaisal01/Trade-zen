const fs = require('fs');
const code = fs.readFileSync('trade-room/react/uikit.944983ce32773e612c15.js', 'utf8');

const pos = code.indexOf("_0x19a49b", 1570000);
console.log("Pos:", pos);
console.log(code.substring(pos - 100, pos + 800));
