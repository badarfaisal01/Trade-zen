const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Let's search for _0x4932d2 or _0x59a7e0 or where period labels are mapped in JSX/React components
// Let's check export statement for module bb4d or the module ending at 5352

console.log(code.substring(5000, 5200));
