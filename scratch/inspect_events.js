const fs = require('fs');
const dir = 'trade-room/react/';
fs.readdirSync(dir).filter(f => f.endsWith('.js')).forEach(file => {
  const content = fs.readFileSync(dir + file, 'utf8');
  const m = content.match(/["'][a-zA-Z0-9_\/]{3,35}["']/g);
  if (m) {
    const events = m.filter(s => s.includes('quote') || s.includes('tick') || s.includes('price') || s.includes('candle') || s.includes('history'));
    if (events.length) {
      console.log(file, [...new Set(events)]);
    }
  }
});
