const fs = require('fs');

const appCode = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');
const chartCode = fs.readFileSync('trade-room/react/chart.4c121fce51de79c82ca3.js', 'utf8');

function searchMatches(code, filename) {
  console.log(`=== Matches in ${filename} ===`);
  const regex = /([a-zA-Z0-9_$]+\.candles|candles\s*:\s*)/g;
  let match;
  while ((match = regex.exec(code)) !== null) {
    const start = Math.max(0, match.index - 100);
    const end = Math.min(code.length, match.index + 200);
    console.log(code.substring(start, end));
    console.log('-------------------------');
  }
}

searchMatches(appCode, 'app.js');
searchMatches(chartCode, 'chart.js');
