const fs = require('fs');

const appCode = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Let's search where history/list/v2 or history list handler is in app.js
const lines = appCode.split('\n');

for (let i = 0; i < lines.length; i++) {
  if (lines[i].includes('history') && lines[i].includes('list')) {
    console.log('Line', i + 1, ':', lines[i].trim().slice(0, 150));
  }
}
