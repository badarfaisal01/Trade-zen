<?php
require dirname(__DIR__) . '/app/bootstrap.php';

foreach (['USDJPY', 'USDCAD', 'EURUSD'] as $sym) {
    $snap = market_snapshot($sym, 60, 10, true);
    $last = end($snap['candles']);
    $gap = abs($snap['asset']['price'] - $last['close']);
    $gapPct = $last['close'] > 0 ? $gap / $last['close'] * 100 : 0;
    echo "$sym: asset.price=" . $snap['asset']['price'] . "  lastCandle.close=" . $last['close'] . "  GAP=" . number_format($gapPct, 4) . "%\n";
}
