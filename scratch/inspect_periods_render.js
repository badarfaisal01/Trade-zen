const fs = require('fs');
const code = fs.readFileSync('trade-room/react/uikit.944983ce32773e612c15.js', 'utf8');

const pos = 1572981;
console.log(code.substring(pos - 3000, pos + 500));
