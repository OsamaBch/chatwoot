"""Stage 1 (CPU): ingest → develop → segment → cutout-alpha.

ProcessPoolExecutor workers (one per CPU) ingest and downscale frames to
segmentation resolution; the main process runs batched ONNX inference on
the warmed session and pushes results into a bounded asyncio queue sized
~2x the IO concurrency, which stage 2 consumes. pyvips/onnx work never
blocks the event loop (run_in_executor).
"""

from __future__ import annotations

import asyncio
import multiprocessing
import os
import time
from collections.abc import Awaitable, Callable, Sequence
from concurrent.futures import ProcessPoolExecutor
from dataclasses import dataclass
from pathlib import Path

import numpy as np
import numpy.typing as npt

from photo_pipeline.db.models import SourceType
from photo_pipeline.ingest.source import ingest_thumbnail
from photo_pipeline.segment.birefnet import BiRefNetSegmenter, SegmentInput
from photo_pipeline.settings import MaterialFamily, Settings, get_settings


@dataclass(frozen=True)
class Stage1Result:
    """One segmented frame, ready for stage 2 (cutout + composite)."""

    path: Path
    source_type: SourceType
    source_px: tuple[int, int]
    rgb_small: npt.NDArray[np.uint8]
    alpha: npt.NDArray[np.uint8]  # at source resolution


@dataclass(frozen=True)
class Stage1Stats:
    frames: int
    wall_s: float
    workers: int

    @property
    def frames_per_s(self) -> float:
        return self.frames / self.wall_s if self.wall_s > 0 else 0.0

    @property
    def frames_per_s_per_core(self) -> float:
        return self.frames_per_s / self.workers if self.workers else 0.0


def _preprocess_worker(path_str: str, seg_px: int) -> tuple[bytes, int, int, str]:
    """Runs in a pool worker: ingest + downscale to segmentation size.

    Returns (rgb_small bytes, source_w, source_h, source_type) — compact so
    IPC stays cheap (a seg-res frame is ~3 MB vs ~36 MB at full res).
    """
    settings = get_settings()
    ingested = ingest_thumbnail(Path(path_str), seg_px, settings)
    return (
        bytes(ingested.image.write_to_memory()),
        ingested.source_px[0],
        ingested.source_px[1],
        ingested.source_type.value,
    )


async def run_stage1(
    paths: Sequence[Path],
    settings: Settings,
    *,
    io_concurrency: int,
    consume: Callable[[Stage1Result], Awaitable[None]] | None = None,
    material_families: dict[Path, MaterialFamily] | None = None,
    max_workers: int | None = None,
) -> Stage1Stats:
    """Run stage 1 over ``paths``; each result is handed to ``consume``
    (stage 2) through the bounded queue."""
    workers = max_workers or os.cpu_count() or 1
    conc = settings.concurrency
    seg_px = conc.segmentation_px
    segmenter = BiRefNetSegmenter(settings)
    loop = asyncio.get_running_loop()

    queue: asyncio.Queue[Stage1Result | None] = asyncio.Queue(
        maxsize=conc.stage_queue_factor * io_concurrency
    )

    async def consumer() -> int:
        count = 0
        while True:
            result = await queue.get()
            if result is None:
                return count
            if consume is not None:
                await consume(result)
            count += 1

    started = time.perf_counter()
    consumer_task = asyncio.create_task(consumer())

    # spawn, not fork: the parent already runs libvips and onnxruntime
    # threads, and forking a multithreaded process deadlocks the children.
    with ProcessPoolExecutor(
        max_workers=workers, mp_context=multiprocessing.get_context("spawn")
    ) as pool:
        inflight = asyncio.Semaphore(workers * 2)

        async def preprocess(path: Path) -> tuple[Path, bytes, int, int, str]:
            async with inflight:
                blob, width, height, source_type = await loop.run_in_executor(
                    pool, _preprocess_worker, str(path), seg_px
                )
            return path, blob, width, height, source_type

        tasks = [asyncio.create_task(preprocess(path)) for path in paths]

        buffer: list[tuple[Path, npt.NDArray[np.uint8], int, int, str]] = []
        # Segmentation overlaps preprocessing: batches run as background
        # tasks (bounded) while workers keep feeding the next batch.
        seg_slots = asyncio.Semaphore(2)
        flush_tasks: list[asyncio.Task[None]] = []

        async def flush(
            items: list[tuple[Path, npt.NDArray[np.uint8], int, int, str]],
        ) -> None:
            batch = [
                SegmentInput(
                    rgb=rgb,
                    material_family=(material_families or {}).get(path),
                    out_size=(width, height),
                )
                for path, rgb, width, height, _ in items
            ]
            async with seg_slots:
                alphas = await loop.run_in_executor(None, segmenter.segment_batch, batch)
            for (path, rgb, width, height, source_type), alpha in zip(
                items, alphas, strict=True
            ):
                await queue.put(
                    Stage1Result(
                        path=path,
                        source_type=SourceType(source_type),
                        source_px=(width, height),
                        rgb_small=rgb,
                        alpha=alpha,
                    )
                )

        for done in asyncio.as_completed(tasks):
            path, blob, width, height, source_type = await done
            rgb = np.ndarray(buffer=blob, dtype=np.uint8, shape=(seg_px, seg_px, 3))
            buffer.append((path, rgb, width, height, source_type))
            if len(buffer) >= conc.onnx_batch_max:
                flush_tasks.append(asyncio.create_task(flush(buffer)))
                buffer = []
        if buffer:
            flush_tasks.append(asyncio.create_task(flush(buffer)))
        if flush_tasks:
            await asyncio.gather(*flush_tasks)

    await queue.put(None)
    frames = await consumer_task
    return Stage1Stats(
        frames=frames, wall_s=time.perf_counter() - started, workers=workers
    )
