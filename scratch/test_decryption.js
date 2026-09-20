const fs = require('fs');
const code = fs.readFileSync('trade-room/react/app.514f7c2627cb588c0e57.js', 'utf8');

// Find end of main string table array / helper function
const firstModulePos = code.indexOf('0xfaac:');
const tableCode = code.substring(0, firstModulePos);
// wrap in IIFE
try {
    eval(tableCode + '\n global.a4_0x49720b = a4_0x49720b; global.a4_0xb7005d = a4_0xb7005d;');
    console.log("a4_0x49720b defined successfully");
} catch(e) {
    console.log("Eval error:", e.message);
}
