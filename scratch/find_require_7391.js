const fs = require('fs');
const files = fs.readdirSync('trade-room/react').filter(f => f.endsWith('.js'));

files.forEach(f => {
    const code = fs.readFileSync('trade-room/react/' + f, 'utf8');
    let pos = 0;
    while ((pos = code.indexOf('(0x7391)', pos)) !== -1) {
        console.log(`[${f}] at ${pos}:`);
        console.log(code.substring(Math.max(0, pos - 150), Math.min(code.length, pos + 250)));
        console.log('---');
        pos += 8;
    }
});
