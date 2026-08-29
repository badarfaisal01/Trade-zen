# 55AK Trade 4.4.4

This patch fixes individual chart-drawing removal and verifies the funded Live Up/Down trade flow while preserving every supplied compiled frontend and stylesheet file.

## Root cause

The supplied React room emits `drawing/delete` when the red `×` is clicked. Its first local reducer action contains an object, while the reducer's removal function expects a scalar drawing id. The same-origin bridge did not acknowledge the delete event, so the follow-up success action never arrived and the selected drawing chip remained visible.

## Fixed

- The bridge now acknowledges `drawing/delete` with the exact scalar id required by the compiled reducer.
- Clicking the red `×` removes only the chosen drawing; other drawings remain on the chart.
- `Flat Top/Bottom` and `Trend Angle` individual removal are covered by compiled-UI regression tests.
- Funded Live Up and Down trades reach the authenticated trade API once, update the balance, and appear immediately in the open-trade list.
- Demo $1,000, canonical Live/Demo routing, refresh-loop prevention, and insufficient Live balance handling remain active.
- The service-worker cache version is advanced so browsers load the updated bridge.

No administrator-only or hidden per-user price/result manipulation is introduced. Chart prices and settlement continue through the existing global transparent market-source flow.

## Upgrade

Back up the database, `config/database.local.php`, and `storage/`. Extract the ZIP directly into the domain document root, restore the preserved local configuration/storage, open `/cache-reset.php` once, and then sign in again. Do not place the files inside an extra nested version folder.
