"""Structured JSON logging (one JSON object per line)."""

from __future__ import annotations

import json
import logging


def log_event(logger: logging.Logger, event: str, /, **fields: str | int | float) -> None:
    logger.info(json.dumps({"event": event, **fields}, sort_keys=True))
