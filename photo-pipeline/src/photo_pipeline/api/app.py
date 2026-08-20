"""FastAPI application skeleton. API keys stay server-side only.

The items listing is the data surface for the run report and the approval
queue: it always carries ``source_type`` and ``source_px`` so a non-RAW
image is visibly non-RAW when reviewing colour.
"""

from __future__ import annotations

import uuid

from fastapi import FastAPI
from pydantic import BaseModel
from sqlalchemy import select

from photo_pipeline.db.engine import get_session_factory
from photo_pipeline.db.models import Item, ItemStatus, SourceType

app = FastAPI(title="photo-pipeline", docs_url=None, redoc_url=None)


class ItemSummary(BaseModel):
    """One row of the run report / approval queue."""

    id: uuid.UUID
    view: str
    status: ItemStatus
    source_type: SourceType
    source_px: tuple[int, int] | None
    tier_reached: int | None
    zoom_native_px: int | None
    quarantine_reason: str | None


def _summary(item: Item) -> ItemSummary:
    source_px = (
        (item.source_px_width, item.source_px_height)
        if item.source_px_width is not None and item.source_px_height is not None
        else None
    )
    return ItemSummary(
        id=item.id,
        view=item.view,
        status=item.status,
        source_type=item.source_type,
        source_px=source_px,
        tier_reached=item.tier_reached,
        zoom_native_px=item.zoom_native_px,
        quarantine_reason=item.quarantine_reason,
    )


@app.get("/healthz")
async def healthz() -> dict[str, str]:
    return {"status": "ok"}


@app.get("/jobs/{job_id}/items")
async def list_job_items(job_id: uuid.UUID) -> list[ItemSummary]:
    async with get_session_factory()() as session:
        items = (
            (await session.execute(select(Item).where(Item.job_id == job_id)))
            .scalars()
            .all()
        )
    return [_summary(item) for item in items]
