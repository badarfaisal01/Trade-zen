const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

function searchMatches(term) {
    let pos = 0;
    while ((pos = code.indexOf(term, pos)) !== -1) {
        console.log(`Match '${term}' at ${pos}:`);
        console.log(code.substring(Math.max(0, pos - 120), Math.min(code.length, pos + 180)));
        console.log('-------------------');
        pos += term.length;
    }
}

searchMatches("['$t']");
