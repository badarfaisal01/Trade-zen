const fs = require('fs');
const code = fs.readFileSync('trade-room/react/uikit.944983ce32773e612c15.js', 'utf8');

function find(name) {
    let pos = 0;
    while ((pos = code.indexOf(name, pos)) !== -1) {
        console.log(`Match ${name} at ${pos}:`);
        console.log(code.substring(Math.max(0, pos - 150), Math.min(code.length, pos + 250)));
        console.log('---');
        pos += name.length;
    }
}

find('_0x3a4523');
find('_0x5adfbb');
