const fs = require('fs');

const appCode = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');
const chartCode = fs.readFileSync('trade-room/react/chart.4c121fce51de79c82ca3.js', 'utf8');

// Let's search for history action types or reducers
const lines = appCode.split('\n');
console.log('App code scan:');
for (let i = 0; i < lines.length; i++) {
  if (lines[i].toLowerCase().includes('history') && (lines[i].includes('case ') || lines[i].includes('action') || lines[i].includes('dispatch') || lines[i].includes('type:'))) {
    console.log('Line', i + 1, ':', lines[i].trim().slice(0, 150));
  }
}
