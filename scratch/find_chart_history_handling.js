const fs = require('fs');

const appCode = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');
const chartCode = fs.readFileSync('trade-room/react/chart.4c121fce51de79c82ca3.js', 'utf8');
const uikitCode = fs.readFileSync('trade-room/react/uikit.944983ce32773e612c15.js', 'utf8');

// Let's search for history payload keys: 'history', 'candles', 'period', 'asset'
console.log('Searching for history payload handling...');

function findInCode(code, name) {
  const lines = code.split('\n');
  for (let i = 0; i < lines.length; i++) {
    if (lines[i].includes('history') && lines[i].includes('candles')) {
      console.log(`[${name}] Line ${i + 1}:`, lines[i].trim().slice(0, 160));
    }
  }
}

findInCode(appCode, 'app.js');
findInCode(chartCode, 'chart.js');
findInCode(uikitCode, 'uikit.js');
