"""Add source_type and source_px to items.

Revision ID: 0002
Revises: 0001
Create Date: 2026-08-20
"""

from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0002"
down_revision: str | None = "0001"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

_SOURCE_TYPE = sa.Enum("raw", "jpeg", "png", "tiff", name="source_type")


def upgrade() -> None:
    _SOURCE_TYPE.create(op.get_bind(), checkfirst=True)
    op.add_column(
        "items",
        sa.Column("source_type", _SOURCE_TYPE, nullable=False, server_default="raw"),
    )
    op.add_column("items", sa.Column("source_px_width", sa.Integer(), nullable=True))
    op.add_column("items", sa.Column("source_px_height", sa.Integer(), nullable=True))


def downgrade() -> None:
    op.drop_column("items", "source_px_height")
    op.drop_column("items", "source_px_width")
    op.drop_column("items", "source_type")
    _SOURCE_TYPE.drop(op.get_bind(), checkfirst=True)
