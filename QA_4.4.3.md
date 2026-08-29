# 55AK Trade 4.4.3 — QA

- Reproduced the reported screen with a Demo profile on `/en/trade/`: compiled React rendered only `LOADING` and attempted hard navigation.
- Added server-side account/route alignment before any React HTML is rendered; the Live controller renders Live immediately without redirecting.
- Added a pre-React client guard for stale cached HTML.
- Verified canonical Demo and Live routes still render the full supplied React application.
- Verified Demo starts at $1,000 and a confirmed Demo trade reaches the API once.
- Verified funded Live trade reaches the API once.
- Verified zero-balance Live trade reaches the API zero times and shows the insufficient-balance/Deposit notice.
- PHP syntax, JavaScript syntax, bridge/admin DOM, auth rendering, signed-token, route, checksum, and fresh-extract checks pass.
- The supplied `trade-room/` tree remains byte-for-byte unchanged.
