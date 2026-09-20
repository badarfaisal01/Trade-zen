const fs = require('fs');
const files = fs.readdirSync('trade-room/react').filter(f => f.endsWith('.js'));

files.forEach(f => {
    const code = fs.readFileSync('trade-room/react/' + f, 'utf8');
    
    // search for 5s, 10s, 15s, 30s
    ['5s', '10s', '15s', '30s'].forEach(s => {
        let pos = 0;
        while ((pos = code.indexOf(s, pos)) !== -1) {
            console.log(`[${f}] '${s}' at ${pos}:`);
            console.log(code.substring(Math.max(0, pos - 80), Math.min(code.length, pos + 120)));
            console.log('---');
            pos += s.length;
        }
    });
});
