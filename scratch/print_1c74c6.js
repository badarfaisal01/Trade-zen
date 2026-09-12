const fs = require('fs');

const appCode = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

const start = 344000;
const length = 2000;
console.log(appCode.substring(start, start + length));
