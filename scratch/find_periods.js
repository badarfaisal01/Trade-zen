const fs = require('fs');

const files = fs.readdirSync('trade-room/react').filter(f => f.endsWith('.js'));

files.forEach(f => {
    const code = fs.readFileSync('trade-room/react/' + f, 'utf8');
    ['Period_5', 'Period_10', 'Period_15', 'Period_30', 'Period_60', '5s', '10s', '15s', '30s', '1m'].forEach(term => {
        let pos = 0;
        while ((pos = code.indexOf(term, pos)) !== -1) {
            console.log(`[${f}] '${term}' at ${pos}:`);
            console.log(code.substring(Math.max(0, pos - 100), Math.min(code.length, pos + 120)));
            console.log('===');
            pos += term.length;
        }
    });
});
