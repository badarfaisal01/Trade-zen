const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

const pos = code.indexOf("'Period_5'");
console.log(code.substring(pos - 4000, pos + 500));
