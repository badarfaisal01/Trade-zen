const fs = require('fs');

function inspect() {
  const content = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');
  const lines = content.split('\n');
  console.log('Total lines:', lines.length);

  // Let's search for any occurrences of history/
  for (let i = 0; i < lines.length; i++) {
    if (lines[i].includes('_x') && lines[i].includes('5af75c')) {
      console.log('Line', i + 1, ':', lines[i].slice(0, 100));
    }
  }
}

inspect();
