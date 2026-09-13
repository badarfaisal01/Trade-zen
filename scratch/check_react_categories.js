const fs = require('fs');
const content = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

const matches = [];
const regex = /(currencies|crypto|commodities|stocks|indices|indices)/gi;
let m;
while ((m = regex.exec(content)) !== null) {
  matches.push({ match: m[0], index: m.index, snippet: content.substring(Math.max(0, m.index - 50), Math.min(content.length, m.index + 50)) });
}
console.log('Total matches:', matches.length);
console.log(matches.slice(0, 10));
