# 55AK Trade 4.4.2 — QA

The release was checked against the actual compiled React bundles, not a replacement UI.

- React Demo route rendered the full trading app with no skeleton or maintenance fallback.
- React authorization and local socket initialization completed.
- Demo balance rendered as $1,000.00.
- Up → confirmation → trade submission called `/en/api/trade.php` once and displayed the success notice.
- Funded Live account ($500 fixture) used the same compiled confirmation flow, called the trade API once, and displayed the success notice.
- Live $0.00 Up click called the trade API zero times and displayed the insufficient-balance/Deposit notice.
- Canonical Demo and legacy route rules were checked for a one-way flow with no Demo/Live bounce.
- PHP syntax, JavaScript syntax, bridge DOM, administrator DOM, auth rendering, and signed trade-token tests passed.
- The supplied `trade-room/` frontend tree remained byte-for-byte unchanged.
