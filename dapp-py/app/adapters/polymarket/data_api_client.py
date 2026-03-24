from __future__ import annotations

from typing import Any

import httpx

from app.core.config import settings


class PolymarketDataApiClient:
    def __init__(self) -> None:
        self._base_url = settings.polymarket_data_api_base_url.rstrip("/")
        self._timeout = settings.request_timeout_seconds

    async def get_trades_by_user(self, user: str, limit: int = 50, offset: int = 0) -> list[dict[str, Any]]:
        async with httpx.AsyncClient(base_url=self._base_url, timeout=self._timeout) as client:
            response = await client.get(
                "/trades",
                params={"user": user, "limit": limit, "offset": offset},
            )
            response.raise_for_status()
            payload = response.json()
            return list(payload) if isinstance(payload, list) else []
