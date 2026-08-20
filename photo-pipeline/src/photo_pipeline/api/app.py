"""FastAPI application skeleton. API keys stay server-side only."""

from __future__ import annotations

from fastapi import FastAPI

app = FastAPI(title="photo-pipeline", docs_url=None, redoc_url=None)


@app.get("/healthz")
async def healthz() -> dict[str, str]:
    return {"status": "ok"}
