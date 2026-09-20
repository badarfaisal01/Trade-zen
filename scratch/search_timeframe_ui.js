const fs = require('fs');
const files = fs.readdirSync('trade-room/react').filter(f => f.endsWith('.js'));

files.forEach(f => {
    const code = fs.readFileSync('trade-room/react/' + f, 'utf8');
    ['timeframe', 'Timeframe', 'chartPeriod', 'chart_period', 'period-list', 'periods'].forEach(term => {
        let pos = 0;
        let count = 0;
        while ((pos = code.indexOf(term, pos)) !== -1 && count < 5) {
            console.log(`[${f}] '${term}' at ${pos}:`);
            console.log(code.substring(Math.max(0, pos - 100), Math.min(code.length, pos + 150)));
            console.log('===');
            pos += term.length;
            count++;
        }
    });
});
