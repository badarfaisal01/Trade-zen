const fs = require('fs');
let uikit = fs.readFileSync('trade-room/react/uikit.944983ce32773e612c15.js', 'utf8');

const t1 = "'children': Object['keys'](_0x44ad2b)[_0x719eed(0x76a, 0x25e2, 0x1ce2, 0x736)]";
const r1 = "'children': Object['keys'](_0x44ad2b).filter(_k => _0x44ad2b[_k] && !_0x44ad2b[_k].includes('s'))[_0x719eed(0x76a, 0x25e2, 0x1ce2, 0x736)]";

const t2 = "Object[_0x43cfd8(-0xe6d, 0x746, 0x1038, 0x1bc5)](_0x19a49b)[_0x43cfd8(0x380d, 0x870, 0x1db3, 0x38c)]";
const r2 = "Object[_0x43cfd8(-0xe6d, 0x746, 0x1038, 0x1bc5)](_0x19a49b).filter(_k => _0x19a49b[_k] && !_0x19a49b[_k].includes('s'))[_0x43cfd8(0x380d, 0x870, 0x1db3, 0x38c)]";

if (!uikit.includes(t1)) throw new Error("Target 1 not found");
if (!uikit.includes(t2)) throw new Error("Target 2 not found");

uikit = uikit.replace(t1, r1).replace(t2, r2);
fs.writeFileSync('trade-room/react/uikit.944983ce32773e612c15.js', uikit, 'utf8');
console.log("uikit.944983ce32773e612c15.js updated successfully");
