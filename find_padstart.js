const fs = require('fs');
const c = fs.readFileSync('c:/xampp/htdocs/trade-room/react/567.3c36782b82ef2b2e7f48.js', 'utf8');

// Let's search for where the price line badge or countdown text is created
// In the screenshot: a badge showing "00:01" attached to the dashed horizontal price line
// Look for String padding / formatting mm:ss / 00:
console.log('File size:', c.length);

// Search for 'padStart'
let idx = 0;
while ((idx = c.indexOf('padStart', idx + 1)) !== -1) {
  console.log('padStart at', idx, c.substring(idx - 60, idx + 80));
}
