from __future__ import annotations

from app.adapters.polymarket.sources.data_api_source import DataApiLeaderTradeSource
from app.adapters.polymarket.sources.hybrid_source import HybridLeaderTradeSource


class CompareService:
    def __init__(self) -> None:
        self._data_api = DataApiLeaderTradeSource()
        self._hybrid = HybridLeaderTradeSource()

    async def compare(self, user: str, limit: int = 10, offset: int = 0) -> dict:
        old_items = await self._data_api.fetch(user=user, limit=limit, offset=offset)
        new_items = await self._hybrid.fetch(user=user, limit=limit, offset=offset)

        old_ids = {item["trade_id"] for item in old_items if item.get("trade_id")}
        new_ids = {item["trade_id"] for item in new_items if item.get("trade_id")}

        return {
            "old_count": len(old_items),
            "new_count": len(new_items),
            "same_trade_ids": sorted(old_ids & new_ids),
            "only_old": sorted(old_ids - new_ids),
            "only_new": sorted(new_ids - old_ids),
        }
