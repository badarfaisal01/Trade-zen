# 55AK Trade 4.4.4 — QA

- Opened the supplied compiled Drawings panel and added `Flat Top/Bottom` plus `Trend Angle` together.
- Clicked the red `×` on `Flat Top/Bottom`; its chip and Redux drawing record were removed while `Trend Angle` remained.
- Independently clicked the red `×` on `Trend Angle`; its chip and drawing record were removed.
- Confirmed the compiled room emits `drawing/delete` and receives `draw/delete/success` with the matching scalar id.
- Verified funded Live Up and Down clicks each call the authenticated trade API exactly once, reduce the Live balance, and create one visible open trade with the matching `call`/`put` direction.
- Verified zero-balance Live clicks call the trade API zero times and show the insufficient-balance/Deposit notice.
- Verified Demo starts at $1,000 and its trade flow remains operational.
- PHP syntax, JavaScript syntax, bridge/admin DOM, auth rendering, signed-token, canonical route, checksum, and fresh-extract checks pass.
- The supplied `trade-room/` tree remains byte-for-byte unchanged.
