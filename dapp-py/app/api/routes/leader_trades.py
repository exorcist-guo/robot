from fastapi import APIRouter, Depends

from app.core.security import verify_internal_token
from app.schemas.leader_trade import FetchLeaderTradesRequest, FetchLeaderTradesResponse, LeaderTradeItem
from app.services.compare_service import CompareService
from app.services.leader_trade_service import LeaderTradeService

router = APIRouter(prefix="/internal/leader-trades", tags=["leader-trades"], dependencies=[Depends(verify_internal_token)])


@router.post("/fetch", response_model=FetchLeaderTradesResponse)
async def fetch_leader_trades(payload: FetchLeaderTradesRequest) -> FetchLeaderTradesResponse:
    source, items = await LeaderTradeService().fetch(user=payload.user, limit=payload.limit, offset=payload.offset)
    return FetchLeaderTradesResponse(source=source, count=len(items), items=[LeaderTradeItem(**item) for item in items])


@router.post("/compare")
async def compare_leader_trades(payload: FetchLeaderTradesRequest) -> dict:
    return await CompareService().compare(user=payload.user, limit=payload.limit, offset=payload.offset)
