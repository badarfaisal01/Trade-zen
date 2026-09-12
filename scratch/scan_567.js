const fs = require('fs');

const code = fs.readFileSync('trade-room/react/567.3c36782b82ef2b2e7f48.js', 'utf8');

const matches = [];
let pos = 0;
while ((pos = code.indexOf('history', pos)) !== -1) {
  matches.push({ pos, text: code.slice(Math.max(0, pos - 50), Math.min(code.length, pos + 100)) });
  pos += 7;
}

console.log('Total history matches in 567.js:', matches.length);
matches.slice(0, 15).forEach((m, idx) => console.log(`[${idx}]`, m.text.replace(/\n/g, ' ')));
