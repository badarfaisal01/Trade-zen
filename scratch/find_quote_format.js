const fs = require('fs');
const files = ['trade-room/react/app.514f7c2627cb588c0e57.js', 'trade-room/react/chart.4c121fce51de79c82ca3.js'];
files.forEach(file => {
  const content = fs.readFileSync(file, 'utf8');
  ['quotes', 'stream', 'quote', 'tick', 'history/list'].forEach(term => {
    let pos = 0;
    while ((pos = content.indexOf(term, pos)) !== -1) {
      console.log(`[${file}] ${term} at ${pos}:`);
      console.log(content.substring(Math.max(0, pos - 80), Math.min(content.length, pos + 180)));
      console.log('---');
      pos += term.length + 1;
      if (pos > 1000000) break;
    }
  });
});
