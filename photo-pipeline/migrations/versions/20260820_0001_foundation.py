"""Foundation: modes, jobs, barcodes, items (+ seed the four modes).

Revision ID: 0001
Revises:
Create Date: 2026-08-20
"""

from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

from photo_pipeline.settings import ModeName, get_settings

revision: str = "0001"
down_revision: str | None = None
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

_JOB_STATUS = sa.Enum(
    "pending", "running", "succeeded", "failed", "quarantined", name="job_status"
)
_ITEM_STATUS = sa.Enum(
    "pending", "processing", "succeeded", "failed", "quarantined", name="item_status"
)
_EXECUTION_MODE = sa.Enum("live", "batch", name="execution_mode")
_BARCODE_SOURCE = sa.Enum("card", "filename", "metadata", "manual", name="barcode_source")


def upgrade() -> None:
    op.create_table(
        "modes",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(32), nullable=False),
        sa.Column("version", sa.Integer(), nullable=False),
        sa.Column("fill_ratio", sa.Float(), nullable=False),
        sa.Column("prompt_block", sa.Text(), nullable=False),
        sa.Column(
            "generation_enabled", sa.Boolean(), nullable=False, server_default=sa.text("false")
        ),
        sa.Column(
            "tier2_default", sa.Boolean(), nullable=False, server_default=sa.text("false")
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
        ),
        sa.UniqueConstraint("name", "version", name="uq_modes_name_version"),
    )

    op.create_table(
        "jobs",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("status", _JOB_STATUS, nullable=False),
        sa.Column("execution_mode", _EXECUTION_MODE, nullable=False),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
        ),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("error", sa.Text(), nullable=True),
    )

    op.create_table(
        "barcodes",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("barcode", sa.String(14), nullable=False, unique=True),
        sa.Column("scale_factor", sa.Float(), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
        ),
    )

    op.create_table(
        "items",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column(
            "job_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("jobs.id"),
            nullable=False,
        ),
        sa.Column(
            "barcode_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("barcodes.id"),
            nullable=True,
        ),
        sa.Column("view", sa.String(16), nullable=False),
        sa.Column("source_path", sa.Text(), nullable=False),
        sa.Column("source_sha256", sa.String(64), nullable=False),
        sa.Column("idempotency_key", sa.String(64), nullable=False),
        sa.Column("status", _ITEM_STATUS, nullable=False),
        sa.Column("barcode_source", _BARCODE_SOURCE, nullable=True),
        sa.Column("quarantine_reason", sa.Text(), nullable=True),
        sa.Column("gate_failures", sa.JSON(), nullable=True),
        sa.Column("sharpness_score", sa.Float(), nullable=True),
        sa.Column("zoom_native_px", sa.Integer(), nullable=True),
        # Provenance (CLAUDE.md invariant 6).
        sa.Column("core_sha", sa.String(64), nullable=True),
        sa.Column("mode_id", sa.Integer(), sa.ForeignKey("modes.id"), nullable=True),
        sa.Column("mode_version", sa.Integer(), nullable=True),
        sa.Column("reference_sha", sa.String(64), nullable=True),
        sa.Column("provider", sa.String(32), nullable=True),
        sa.Column("model_snapshot", sa.String(128), nullable=True),
        sa.Column("tier_reached", sa.Integer(), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
        ),
    )
    op.create_index("ix_items_job_id", "items", ["job_id"])
    op.create_index("ix_items_barcode_id", "items", ["barcode_id"])
    # Invariant 7: same inputs never billed twice, across restarts.
    op.create_index("ix_items_idempotency_key", "items", ["idempotency_key"], unique=True)

    # Seed the four modes with their locked fill ratios (from settings — the
    # single source of tunables).
    fill_ratios = get_settings().fill_ratios
    modes_table = sa.table(
        "modes",
        sa.column("name", sa.String),
        sa.column("version", sa.Integer),
        sa.column("fill_ratio", sa.Float),
        sa.column("prompt_block", sa.Text),
    )
    op.bulk_insert(
        modes_table,
        [
            {
                "name": mode.value,
                "version": 1,
                "fill_ratio": fill_ratios.for_mode(mode),
                "prompt_block": "",
            }
            for mode in ModeName
        ],
    )


def downgrade() -> None:
    op.drop_table("items")
    op.drop_table("barcodes")
    op.drop_table("jobs")
    op.drop_table("modes")
    for enum in (_ITEM_STATUS, _JOB_STATUS, _EXECUTION_MODE, _BARCODE_SOURCE):
        enum.drop(op.get_bind(), checkfirst=True)
