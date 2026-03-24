from __future__ import annotations

from app.adapters.polymarket.sources.data_api_source import DataApiLeaderTradeSource


class LeaderTradeService:
    def __init__(self) -> None:
        self._source = DataApiLeaderTradeSource()

    async def fetch(self, user: str, limit: int = 10, offset: int = 0) -> tuple[str, list[dict]]:
        items = await self._source.fetch(user=user, limit=limit, offset=offset)
        return "data_api", items
