"""SQLAlchemy 2.0 typed ORM models: modes, jobs, items, barcodes.

Provenance per CLAUDE.md invariant 6 lives on ``items`` (the asset record):
core_sha, mode_id, mode_version, reference_sha, provider, model_snapshot,
tier_reached — pinned snapshots, never aliases.
"""

from __future__ import annotations

import enum
import uuid
from datetime import datetime
from decimal import Decimal

from sqlalchemy import (
    JSON,
    Boolean,
    DateTime,
    Enum,
    Float,
    ForeignKey,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    func,
    text,
)
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship


class Base(DeclarativeBase):
    pass


class JobStatus(enum.StrEnum):
    PENDING = "pending"
    RUNNING = "running"
    SUCCEEDED = "succeeded"
    FAILED = "failed"
    QUARANTINED = "quarantined"


class ItemStatus(enum.StrEnum):
    PENDING = "pending"
    PROCESSING = "processing"
    SUCCEEDED = "succeeded"
    FAILED = "failed"
    QUARANTINED = "quarantined"


class ExecutionMode(enum.StrEnum):
    LIVE = "live"
    BATCH = "batch"


class SourceType(enum.StrEnum):
    """Ingest format, decided by magic bytes. Shown in the run report and
    approval queue so a non-RAW image is visibly non-RAW when reviewing
    colour."""

    RAW = "raw"
    JPEG = "jpeg"
    PNG = "png"
    TIFF = "tiff"


class BarcodeSource(enum.StrEnum):
    """Which resolution step produced the barcode (first hit wins)."""

    CARD = "card"
    FILENAME = "filename"
    METADATA = "metadata"
    MANUAL = "manual"


class Mode(Base):
    """A photography mode: versioned, photo_lead-editable prompt block."""

    __tablename__ = "modes"
    __table_args__ = (UniqueConstraint("name", "version", name="uq_modes_name_version"),)

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    name: Mapped[str] = mapped_column(String(32), nullable=False)
    version: Mapped[int] = mapped_column(Integer, nullable=False, default=1)
    fill_ratio: Mapped[float] = mapped_column(Float, nullable=False)
    prompt_block: Mapped[str] = mapped_column(Text, nullable=False, default="")
    # Generation is opt-in per mode; Tier 0 (composite, no AI) is the default.
    generation_enabled: Mapped[bool] = mapped_column(
        Boolean, nullable=False, server_default=text("false")
    )
    tier2_default: Mapped[bool] = mapped_column(
        Boolean, nullable=False, server_default=text("false")
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )

    items: Mapped[list[Item]] = relationship(back_populates="mode")


class Job(Base):
    """A batch run. Batches end succeeded/failed/quarantined."""

    __tablename__ = "jobs"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    status: Mapped[JobStatus] = mapped_column(
        Enum(JobStatus, name="job_status", values_callable=lambda e: [m.value for m in e]),
        nullable=False,
        default=JobStatus.PENDING,
    )
    execution_mode: Mapped[ExecutionMode] = mapped_column(
        Enum(
            ExecutionMode,
            name="execution_mode",
            values_callable=lambda e: [m.value for m in e],
        ),
        nullable=False,
        default=ExecutionMode.LIVE,
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    started_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    finished_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    error: Mapped[str | None] = mapped_column(Text)

    items: Mapped[list[Item]] = relationship(back_populates="job")


class Barcode(Base):
    """One product barcode. Front and back share one scale factor
    (invariant 5 — larger bbox wins, stored here)."""

    __tablename__ = "barcodes"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    barcode: Mapped[str] = mapped_column(String(14), nullable=False, unique=True)
    scale_factor: Mapped[float | None] = mapped_column(Float)
    # A barcode with approved assets requires explicit replace confirmation;
    # old files are versioned (_02, _03), never overwritten.
    has_approved_assets: Mapped[bool] = mapped_column(
        Boolean, nullable=False, server_default=text("false")
    )
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )

    items: Mapped[list[Item]] = relationship(back_populates="barcode")


class Item(Base):
    """One generation unit (one view of one product) and its asset provenance."""

    __tablename__ = "items"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    job_id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), ForeignKey("jobs.id"), nullable=False, index=True
    )
    barcode_id: Mapped[uuid.UUID | None] = mapped_column(
        UUID(as_uuid=True), ForeignKey("barcodes.id"), index=True
    )
    view: Mapped[str] = mapped_column(String(16), nullable=False, default="front")
    source_path: Mapped[str] = mapped_column(Text, nullable=False)
    source_sha256: Mapped[str] = mapped_column(String(64), nullable=False)
    # Ingest format from magic bytes (never extension); surfaced in the run
    # report and approval queue.
    source_type: Mapped[SourceType] = mapped_column(
        Enum(SourceType, name="source_type", values_callable=lambda e: [m.value for m in e]),
        nullable=False,
        default=SourceType.RAW,
        server_default=SourceType.RAW.value,
    )
    # Full source resolution, upright (drives the no-upscale rule).
    source_px_width: Mapped[int | None] = mapped_column(Integer)
    source_px_height: Mapped[int | None] = mapped_column(Integer)
    # Generation attempts made for this item (drives tier escalation).
    attempts: Mapped[int] = mapped_column(
        Integer, nullable=False, default=0, server_default=text("0")
    )
    # Provider spend for this item across all attempts/tiers.
    cost_usd: Mapped[Decimal] = mapped_column(
        Numeric(12, 4), nullable=False, default=Decimal(0), server_default=text("0")
    )
    # The four output files of one compositor invocation (invariant 2).
    zoom_path: Mapped[str | None] = mapped_column(Text)
    zoom_crop_path: Mapped[str | None] = mapped_column(Text)
    display_path: Mapped[str | None] = mapped_column(Text)
    display_crop_path: Mapped[str | None] = mapped_column(Text)
    # sha256(source_sha256 + core_sha + mode_version + reference_sha +
    # model_snapshot) — same inputs never billed twice, across restarts.
    idempotency_key: Mapped[str] = mapped_column(
        String(64), nullable=False, unique=True, index=True
    )
    status: Mapped[ItemStatus] = mapped_column(
        Enum(ItemStatus, name="item_status", values_callable=lambda e: [m.value for m in e]),
        nullable=False,
        default=ItemStatus.PENDING,
    )
    barcode_source: Mapped[BarcodeSource | None] = mapped_column(
        Enum(
            BarcodeSource,
            name="barcode_source",
            values_callable=lambda e: [m.value for m in e],
        )
    )
    quarantine_reason: Mapped[str | None] = mapped_column(Text)
    # Gate failures that drove escalation (list of gate names, in order).
    gate_failures: Mapped[list[str] | None] = mapped_column(JSON)
    sharpness_score: Mapped[float | None] = mapped_column(Float)
    # Recorded when the zoom master shrank under the no-upscale rule
    # (invariant 3): width in px of the shrunken zoom master.
    zoom_native_px: Mapped[int | None] = mapped_column(Integer)

    # --- Provenance (invariant 6) ---
    core_sha: Mapped[str | None] = mapped_column(String(64))
    mode_id: Mapped[int | None] = mapped_column(ForeignKey("modes.id"))
    mode_version: Mapped[int | None] = mapped_column(Integer)
    reference_sha: Mapped[str | None] = mapped_column(String(64))
    provider: Mapped[str | None] = mapped_column(String(32))
    model_snapshot: Mapped[str | None] = mapped_column(String(128))
    tier_reached: Mapped[int | None] = mapped_column(Integer)

    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True),
        nullable=False,
        server_default=func.now(),
        onupdate=func.now(),
    )

    job: Mapped[Job] = relationship(back_populates="items")
    barcode: Mapped[Barcode | None] = relationship(back_populates="items")
    mode: Mapped[Mode | None] = relationship(back_populates="items")
