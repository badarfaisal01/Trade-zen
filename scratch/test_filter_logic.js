const fs = require('fs');

// Let's test the filter on period labels
const samplePeriods = {
    0: '5s',
    1: '10s',
    2: '15s',
    3: '30s',
    4: '1m',
    13: '2m',
    14: '3m',
    6: '5m',
    7: '10m',
    8: '15m',
    9: '30m',
    10: '1h',
    11: '4h',
    12: '1d'
};

const keys = Object.keys(samplePeriods);
console.log("Original keys:", keys);

const filteredKeys = keys.filter(k => samplePeriods[k] && !samplePeriods[k].includes('s'));
console.log("Filtered keys:", filteredKeys);
console.log("Filtered items:", filteredKeys.map(k => samplePeriods[k]));
