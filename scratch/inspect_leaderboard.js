const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

let pos = 0;
while ((pos = code.indexOf('leaderBoard', pos)) !== -1) {
    console.log("Match at", pos);
    console.log(code.substring(Math.max(0, pos - 150), Math.min(code.length, pos + 300)));
    console.log('---');
    pos += 11;
}
