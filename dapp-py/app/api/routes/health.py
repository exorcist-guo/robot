from fastapi import APIRouter, Depends

from app.core.config import settings
from app.core.security import verify_internal_token
from app.schemas.common import HealthResponse

router = APIRouter(prefix="/internal", tags=["internal"])


@router.get("/health", response_model=HealthResponse, dependencies=[Depends(verify_internal_token)])
async def health() -> HealthResponse:
    return HealthResponse(ok=True, service=settings.app_name, env=settings.app_env)
