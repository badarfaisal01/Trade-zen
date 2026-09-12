const fs = require('fs');
const path = require('path');

const dir = 'trade-room/react';
const files = fs.readdirSync(dir).filter(f => f.endsWith('.js'));

for (const file of files) {
  const content = fs.readFileSync(path.join(dir, file), 'utf8');
  if (content.includes('history/')) {
    console.log('Found history/ in', file);
  }
}
