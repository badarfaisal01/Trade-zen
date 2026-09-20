const fs = require('fs');
const appCode = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// search for `periods` prop or where `periods` object is created in app.js
let pos = 0;
while ((pos = appCode.indexOf('periods', pos)) !== -1) {
    const snippet = appCode.substring(Math.max(0, pos - 100), Math.min(appCode.length, pos + 150));
    console.log(`[app.js] at ${pos}:`);
    console.log(snippet);
    console.log('---');
    pos += 7;
}
