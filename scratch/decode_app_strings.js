const fs = require('fs');

const content = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Extract the string array and rotation code from app.js
// Let's find the array definition at the beginning of app.js
const arrayMatch = content.match(/function\s+([a-zA-Z0-9_$]+)\s*\(\)\s*\{\s*var\s+([a-zA-Z0-9_$]+)\s*=\s*\[([\s\S]*?)\];\s*\1\s*=\s*function/);

console.log('Array match:', arrayMatch ? arrayMatch[1] : 'not found');
