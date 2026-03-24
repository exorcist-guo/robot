from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    app_name: str = "dapp-py"
    app_env: str = "local"
    app_debug: bool = False
    host: str = "127.0.0.1"
    port: int = 8001
    internal_token: str = ""
    polymarket_data_api_base_url: str = "https://data-api.polymarket.com"
    polymarket_rpc_url: str = ""
    request_timeout_seconds: float = 15.0

    model_config = SettingsConfigDict(
        env_prefix="DAPP_PY_",
        env_file=".env",
        extra="ignore",
    )


settings = Settings()
