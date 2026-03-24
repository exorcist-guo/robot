from __future__ import annotations

from decimal import Decimal, ROUND_DOWN
from typing import Any


class LeaderTradeNormalizer:
    def normalize(self, trade: dict[str, Any]) -> dict[str, Any]:
        trade_id = str(trade.get("id") or trade.get("trade_id") or trade.get("transactionHash") or "")
        token_id = str(trade.get("asset_id") or trade.get("asset") or trade.get("token_id") or trade.get("tokenId") or "")
        market_id = str(trade.get("market") or trade.get("market_id") or trade.get("conditionId") or trade.get("condition_id") or "")
        side = str(trade.get("side") or "BUY").upper()
        if side != "SELL":
            side = "BUY"
        price = str(trade.get("price") or "0")
        size = str(trade.get("size") or trade.get("amount") or trade.get("shares") or "0")
        traded_at = str(trade.get("time") or trade.get("timestamp") or trade.get("created_at") or trade.get("createdAt") or "")

        return {
            "trade_id": trade_id,
            "market_id": market_id or None,
            "token_id": token_id or None,
            "side": side,
            "price": price,
            "size_usdc": self.to_usdc_atomic(price, size),
            "raw": trade,
            "traded_at": traded_at,
        }

    def to_usdc_atomic(self, price: str, size: str) -> int:
        usdc = (Decimal(price) * Decimal(size) * Decimal("1000000")).quantize(Decimal("1"), rounding=ROUND_DOWN)
        return int(usdc)
