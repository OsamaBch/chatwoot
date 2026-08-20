"""Distinct exception types per CLAUDE.md failure handling."""

from __future__ import annotations


class PipelineError(Exception):
    """Base class for pipeline errors."""


class RateLimited(PipelineError):
    """Provider rate limit — retryable with backoff."""


class PolicyRefusal(PipelineError):
    """Provider refused — never escalates, never retries; manual queue."""


class Transport(PipelineError):
    """Network / 5xx — retryable with backoff."""


class BadOutput(PipelineError):
    """Model output violated the contract (e.g. wrong dimensions)."""


class BadSource(PipelineError):
    """Source file is not a valid CR3 or cannot be developed."""


class BarcodeQuarantine(PipelineError):
    """Barcode unknown / invalid — quarantine, never guess."""
