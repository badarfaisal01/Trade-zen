const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

const pos = 307593;
console.log(code.substring(pos - 1000, pos + 1000));
