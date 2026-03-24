from __future__ import annotations

from app.adapters.polymarket.data_api_client import PolymarketDataApiClient
from app.adapters.polymarket.leader_trade_normalizer import LeaderTradeNormalizer


class DataApiLeaderTradeSource:
    def __init__(self) -> None:
        self._client = PolymarketDataApiClient()
        self._normalizer = LeaderTradeNormalizer()

    async def fetch(self, user: str, limit: int = 10, offset: int = 0) -> list[dict]:
        trades = await self._client.get_trades_by_user(user=user, limit=limit, offset=offset)
        return [self._normalizer.normalize(trade) for trade in trades]
