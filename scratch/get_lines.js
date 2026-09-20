const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

function getLineNumber(offset) {
    return code.substring(0, offset).split('\n').length;
}

console.log("Offset 153524 -> Line", getLineNumber(153524));
console.log("Offset 154465 -> Line", getLineNumber(154465));
console.log("Offset 314190 -> Line", getLineNumber(314190));
console.log("Offset 492712 -> Line", getLineNumber(492712));
