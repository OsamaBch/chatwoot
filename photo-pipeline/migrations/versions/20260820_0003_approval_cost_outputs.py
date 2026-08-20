"""Approval flag on barcodes; attempts, cost and output paths on items.

items.source_type (raw|jpeg|png|tiff, default raw) already exists from
revision 0002 and is not re-added here.

Revision ID: 0003
Revises: 0002
Create Date: 2026-08-20
"""

from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0003"
down_revision: str | None = "0002"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.add_column(
        "barcodes",
        sa.Column(
            "has_approved_assets",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("false"),
        ),
    )
    op.add_column(
        "items",
        sa.Column("attempts", sa.Integer(), nullable=False, server_default=sa.text("0")),
    )
    op.add_column(
        "items",
        sa.Column(
            "cost_usd", sa.Numeric(12, 4), nullable=False, server_default=sa.text("0")
        ),
    )
    op.add_column("items", sa.Column("zoom_path", sa.Text(), nullable=True))
    op.add_column("items", sa.Column("zoom_crop_path", sa.Text(), nullable=True))
    op.add_column("items", sa.Column("display_path", sa.Text(), nullable=True))
    op.add_column("items", sa.Column("display_crop_path", sa.Text(), nullable=True))


def downgrade() -> None:
    op.drop_column("items", "display_crop_path")
    op.drop_column("items", "display_path")
    op.drop_column("items", "zoom_crop_path")
    op.drop_column("items", "zoom_path")
    op.drop_column("items", "cost_usd")
    op.drop_column("items", "attempts")
    op.drop_column("barcodes", "has_approved_assets")
