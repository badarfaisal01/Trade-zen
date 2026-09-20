const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Let's search for any occurrence of 5s, 10s, 15s, 30s or decryptor strings
console.log("Searching in app.js...");
['5s', '10s', '15s', '30s', '1m', '2m', '3m', '5m', '10m', '15m', '30m', '1h', '4h', '1d'].forEach(str => {
    let pos = 0;
    while ((pos = code.indexOf(`'${str}'`, pos)) !== -1) {
        console.log(`Found '${str}' at offset ${pos}:`);
        console.log(code.substring(Math.max(0, pos - 80), Math.min(code.length, pos + 120)));
        console.log('---');
        pos += str.length + 2;
    }
});
