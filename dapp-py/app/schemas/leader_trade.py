from typing import Any

from pydantic import BaseModel, Field


class FetchLeaderTradesRequest(BaseModel):
    user: str = Field(min_length=1)
    limit: int = Field(default=10, ge=1, le=100)
    offset: int = Field(default=0, ge=0)


class LeaderTradeItem(BaseModel):
    trade_id: str
    market_id: str | None = None
    token_id: str | None = None
    side: str
    price: str
    size_usdc: int
    raw: dict[str, Any]
    traded_at: str


class FetchLeaderTradesResponse(BaseModel):
    source: str
    count: int
    items: list[LeaderTradeItem]
