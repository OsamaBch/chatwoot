"""ONNX Runtime session management: one session per process, warmed once.

The session is created lazily on first use and cached for the life of the
process (``lru_cache``); every later call returns the same object.
``creation_count`` exists so tests can prove exactly-once creation.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path

import numpy as np
import onnxruntime as ort

from photo_pipeline.errors import BadSource

# Incremented on every real session construction — proof of exactly-once.
creation_count = 0


@lru_cache(maxsize=1)
def get_session(model_path: str, intra_op_threads: int, warm_px: int) -> ort.InferenceSession:
    """Create, warm and cache the process-wide inference session."""
    global creation_count  # noqa: PLW0603 - deliberate process-wide counter
    if not Path(model_path).is_file():
        raise BadSource(f"segmentation model not found: {model_path}")
    options = ort.SessionOptions()
    if intra_op_threads > 0:
        options.intra_op_num_threads = intra_op_threads
    session = ort.InferenceSession(
        model_path, sess_options=options, providers=["CPUExecutionProvider"]
    )
    # Warm once so the first real batch doesn't pay allocation/JIT cost.
    input_name = session.get_inputs()[0].name
    warm = np.zeros((1, 3, warm_px, warm_px), dtype=np.float32)
    session.run(None, {input_name: warm})
    creation_count += 1
    return session
