# photo-pipeline

Foundation for the Mazyoud product photo pipeline (see `CLAUDE.md` — the
authoritative spec). This stage contains: typed settings, DB schema +
Alembic migration (modes/jobs/items/barcodes), CR3 ingest, barcode
resolution, the Tier-0 pyvips compositor, QA gates 1-4, and golden-image
tests. No provider code, no model calls.

## Setup

Requires Python 3.12, `uv`, `libvips`, `exiftool`, and Postgres 16.

```sh
uv sync
uv run alembic upgrade head        # PP_DATABASE_URL overrides the default
```

## Checks

```sh
uv run ruff check .
uv run mypy
uv run pytest                      # DB tests skip when Postgres is absent
```

Golden manifest regeneration (only after an intentional compositor change):

```sh
UPDATE_GOLDEN=1 uv run pytest tests/test_golden.py
```
