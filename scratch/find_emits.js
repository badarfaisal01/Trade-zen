const fs = require('fs');

const chartCode = fs.readFileSync('trade-room/react/chart.4c121fce51de79c82ca3.js', 'utf8');
const appCode = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

console.log('Scanning appCode for emit or socket calls...');
// Search for pattern: emit(
const emitMatches = appCode.match(/\.emit\([^)]+\)/g) || [];
console.log('Total emit matches in appCode:', emitMatches.length);
console.log('Sample emit matches:', emitMatches.slice(0, 30));

const chartEmitMatches = chartCode.match(/\.emit\([^)]+\)/g) || [];
console.log('Total emit matches in chartCode:', chartEmitMatches.length);
console.log('Sample chart emit matches:', chartEmitMatches.slice(0, 30));
