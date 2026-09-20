const fs = require('fs');
const uikit = fs.readFileSync('trade-room/react/uikit.944983ce32773e612c15.js', 'utf8');

// Let's search for where '5s', '10s', etc. or period objects are used in uikit.js
// In uikit.js around line 1555000:
const pos = uikit.indexOf("activePeriod");
console.log("activePeriod at", pos);
if (pos !== -1) {
    console.log(uikit.substring(pos - 500, pos + 1000));
}
