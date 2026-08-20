"""Idempotency key (CLAUDE.md invariant 7).

sha256(source_sha256 + core_sha + mode_version + reference_sha +
model_snapshot) — unique index on items; same inputs never billed twice,
across restarts.
"""

from __future__ import annotations

import hashlib


def idempotency_key(
    source_sha256: str,
    core_sha: str,
    mode_version: int,
    reference_sha: str,
    model_snapshot: str,
) -> str:
    payload = f"{source_sha256}{core_sha}{mode_version}{reference_sha}{model_snapshot}"
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()
