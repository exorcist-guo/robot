from __future__ import annotations

from app.adapters.polymarket.sources.data_api_source import DataApiLeaderTradeSource
from app.adapters.polymarket.sources.polygon_rpc_source import PolygonRpcLeaderTradeSource


class HybridLeaderTradeSource:
    def __init__(self) -> None:
        self._data_api = DataApiLeaderTradeSource()
        self._polygon_rpc = PolygonRpcLeaderTradeSource()

    async def fetch(self, user: str, limit: int = 10, offset: int = 0) -> list[dict]:
        trades = await self._polygon_rpc.fetch(user=user, limit=limit, offset=offset)
        if trades:
            return trades
        return await self._data_api.fetch(user=user, limit=limit, offset=offset)
