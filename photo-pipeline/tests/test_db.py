"""Migration + seed test against a real Postgres 16.

Skipped automatically when Postgres is unreachable (CI without a database).
"""

from __future__ import annotations

import os
import subprocess
from pathlib import Path

import pytest
from sqlalchemy import create_engine, inspect, text

PROJECT = Path(__file__).parent.parent
ASYNC_URL = os.environ.get(
    "PP_DATABASE_URL",
    "postgresql+asyncpg://photopipe:photopipe@localhost:5432/photopipe",
)
SYNC_URL = ASYNC_URL.replace("+asyncpg", "+psycopg")


def _postgres_available() -> bool:
    try:
        engine = create_engine(SYNC_URL)
        with engine.connect():
            return True
    except Exception:
        return False


pytestmark = pytest.mark.skipif(
    not _postgres_available(), reason="postgres not reachable"
)


@pytest.fixture(scope="module")
def migrated() -> None:
    engine = create_engine(SYNC_URL)
    with engine.begin() as conn:
        conn.execute(text("DROP SCHEMA public CASCADE"))
        conn.execute(text("CREATE SCHEMA public"))
    env = os.environ | {"PP_DATABASE_URL": ASYNC_URL}
    subprocess.run(
        ["uv", "run", "alembic", "upgrade", "head"],
        cwd=PROJECT,
        env=env,
        check=True,
        capture_output=True,
    )


def test_tables_and_indexes(migrated: None) -> None:
    engine = create_engine(SYNC_URL)
    inspector = inspect(engine)
    tables = set(inspector.get_table_names())
    assert {"modes", "jobs", "items", "barcodes"} <= tables
    item_indexes = {ix["name"]: ix for ix in inspector.get_indexes("items")}
    assert item_indexes["ix_items_idempotency_key"]["unique"]
    barcode_uniques = inspector.get_unique_constraints("barcodes") + [
        {"column_names": ix["column_names"]}
        for ix in inspector.get_indexes("barcodes")
        if ix["unique"]
    ]
    assert any(u["column_names"] == ["barcode"] for u in barcode_uniques)


def test_modes_seeded_with_fill_ratios(migrated: None) -> None:
    engine = create_engine(SYNC_URL)
    with engine.connect() as conn:
        rows = conn.execute(
            text("SELECT name, version, fill_ratio FROM modes ORDER BY name")
        ).all()
    assert [(r[0], r[1], r[2]) for r in rows] == [
        ("accessory", 1, 0.62),
        ("flatlay", 1, 0.84),
        ("hanging", 1, 0.82),
        ("mannequin", 1, 0.80),
    ]
