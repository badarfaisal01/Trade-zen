const fs = require('fs');
const files = fs.readdirSync('trade-room/react').filter(f => f.endsWith('.js'));

files.forEach(f => {
    const code = fs.readFileSync('trade-room/react/' + f, 'utf8');
    ['5s', '10s', '15s', '30s'].forEach(str => {
        let pos = 0;
        while ((pos = code.indexOf(`'${str}'`, pos)) !== -1) {
            console.log(`[${f}] Found '${str}' at offset ${pos}`);
            pos += str.length + 2;
        }
    });
});
