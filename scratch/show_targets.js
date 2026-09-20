const fs = require('fs');
const code = fs.readFileSync('trade-room/react/uikit.944983ce32773e612c15.js', 'utf8');

console.log("=== Target 1 (pos 1557770) ===");
console.log(code.substring(1557740, 1557820));

console.log("=== Target 2 (pos 1571392) ===");
console.log(code.substring(1571360, 1571440));
