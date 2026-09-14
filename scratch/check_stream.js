const fs = require('fs');
const content = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Find all occurrences of socket event listeners
const regex = /on\s*\(\s*["']([^"']+)["']/g;
let m;
const events = [];
while ((m = regex.exec(content)) !== null) {
  events.push(m[1]);
}
console.log('Registered events:', [...new Set(events)]);
