const fs = require('fs');
const files = fs.readdirSync('trade-room/react').filter(f => f.endsWith('.js'));

files.forEach(f => {
    const code = fs.readFileSync('trade-room/react/' + f, 'utf8');
    let pos = 0;
    while ((pos = code.indexOf('periods', pos)) !== -1) {
        const snippet = code.substring(Math.max(0, pos - 150), Math.min(code.length, pos + 200));
        if (snippet.includes('periods:') || snippet.includes('periods =') || snippet.includes('periods,')) {
            console.log(`[${f}] at ${pos}:`);
            console.log(snippet);
            console.log('-------------------------');
        }
        pos += 7;
    }
});
