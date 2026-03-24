from fastapi import FastAPI

from app.api.routes.health import router as health_router
from app.api.routes.leader_trades import router as leader_trades_router
from app.core.config import settings

app = FastAPI(title=settings.app_name)
app.include_router(health_router)
app.include_router(leader_trades_router)
