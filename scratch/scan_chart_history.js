const fs = require('fs');

const chartCode = fs.readFileSync('trade-room/react/chart.4c121fce51de79c82ca3.js', 'utf8');

// Find all occurrences of history/list in chartCode or all occurrences of history
const matches = [];
let pos = 0;
while ((pos = chartCode.indexOf('history', pos)) !== -1) {
  matches.push(chartCode.slice(Math.max(0, pos - 50), Math.min(chartCode.length, pos + 100)));
  pos += 7;
}

console.log('Total history matches in chartCode:', matches.length);
console.log('Sample matches:');
matches.slice(0, 15).forEach((m, idx) => console.log(`[${idx}]`, m.replace(/\n/g, ' ')));
