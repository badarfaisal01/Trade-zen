const fs = require('fs');
const files = fs.readdirSync('trade-room/react').filter(f => f.endsWith('.js'));

files.forEach(f => {
    const code = fs.readFileSync('trade-room/react/' + f, 'utf8');
    
    // search for 5s in string table or code
    let idx = 0;
    while ((idx = code.indexOf("5s", idx)) !== -1) {
        console.log(`[${f}] at ${idx}:`);
        console.log(code.substring(Math.max(0, idx - 60), Math.min(code.length, idx + 80)));
        console.log("---");
        idx += 2;
    }
});
