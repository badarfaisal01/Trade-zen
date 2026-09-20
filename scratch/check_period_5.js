const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

function checkTerm(term) {
    let pos = 0;
    while ((pos = code.indexOf(term, pos)) !== -1) {
        console.log(`[app.js] '${term}' at ${pos}:`);
        console.log(code.substring(Math.max(0, pos - 100), Math.min(code.length, pos + 150)));
        console.log('---');
        pos += term.length;
    }
}

checkTerm('Period_5');
