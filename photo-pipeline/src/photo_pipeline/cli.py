"""photo-pipeline CLI. ``bench`` measures the stage-1 rows of the targets
table in CLAUDE.md on real frames (or synthesised ones when none exist)
and prints measured numbers, including peak RSS.
"""

from __future__ import annotations

import argparse
import asyncio
import statistics
import sys
import threading
import time
from pathlib import Path

import psutil

from photo_pipeline.settings import Settings, get_settings

_FRAME_EXTENSIONS = {".cr3", ".jpg", ".jpeg", ".png", ".tif", ".tiff"}


class _RssSampler:
    """Samples RSS of this process + children; tracks the concurrent peak."""

    def __init__(self, interval_s: float = 0.2) -> None:
        self._interval = interval_s
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._run, daemon=True)
        self.peak_bytes = 0

    def _total_rss(self) -> int:
        process = psutil.Process()
        total = process.memory_info().rss
        for child in process.children(recursive=True):
            try:
                total += child.memory_info().rss
            except psutil.NoSuchProcess:
                continue
        return total

    def _run(self) -> None:
        while not self._stop.is_set():
            self.peak_bytes = max(self.peak_bytes, self._total_rss())
            self._stop.wait(self._interval)

    def __enter__(self) -> _RssSampler:
        self._thread.start()
        return self

    def __exit__(self, *exc: object) -> None:
        self._stop.set()
        self._thread.join()


def _synthesize_frames(directory: Path, count: int, width: int, height: int) -> list[Path]:
    """Deterministic dark-garment-on-backdrop JPEGs standing in for real
    frames when no shoot data is available."""
    import numpy as np
    import pyvips

    settings = get_settings()
    r, g, b = settings.canvas.background_rgb
    directory.mkdir(parents=True, exist_ok=True)
    paths: list[Path] = []
    for index in range(count):
        path = directory / f"frame_{index:04d}.jpg"
        if not path.exists():
            frame = np.empty((height, width, 3), dtype=np.uint8)
            frame[:] = (r, g, b)
            gw, gh = width // 2, int(height * 0.7)
            gx, gy = (width - gw) // 2, (height - gh) // 2
            ys, xs = np.meshgrid(np.arange(gh), np.arange(gw), indexing="ij")
            stripe = ((ys + xs + index) // 32 % 2).astype(np.uint8)
            frame[gy : gy + gh, gx : gx + gw, 0] = 24 + stripe * 10
            frame[gy : gy + gh, gx : gx + gw, 1] = 32 + stripe * 8
            frame[gy : gy + gh, gx : gx + gw, 2] = 84 + stripe * 12
            image = pyvips.Image.new_from_memory(
                frame.tobytes(), width, height, 3, "uchar"
            ).copy(interpretation="srgb")
            image.jpegsave(str(path), Q=85)
        paths.append(path)
    return paths


def _bench_composite(settings: Settings, out_dir: Path, views: int) -> float:
    """Mean milliseconds for one compositor invocation (4 files + encode)."""
    import numpy as np

    from photo_pipeline.compose.compositor import compose_view
    from photo_pipeline.settings import ModeName

    canvas = np.zeros((5200, 4600, 4), dtype=np.uint8)
    ys, xs = np.meshgrid(np.arange(4000), np.arange(2200), indexing="ij")
    stripe = ((ys + xs) // 24 % 2).astype(np.uint8)
    canvas[600:4600, 1200:3400, 0] = 28 + stripe * 8
    canvas[600:4600, 1200:3400, 1] = 36 + stripe * 6
    canvas[600:4600, 1200:3400, 2] = 88 + stripe * 10
    canvas[600:4600, 1200:3400, 3] = 255

    import pyvips

    cutout = pyvips.Image.new_from_memory(canvas.tobytes(), 4600, 5200, 4, "uchar").copy(
        interpretation="srgb"
    )
    timings: list[float] = []
    for index in range(views):
        start = time.perf_counter()
        compose_view(cutout, ModeName.HANGING, settings, out_dir, f"bench_{index}")
        timings.append((time.perf_counter() - start) * 1000)
    return statistics.mean(timings)


def _run_bench(args: argparse.Namespace) -> int:
    from photo_pipeline.segment.stage1 import run_stage1

    settings = get_settings()
    model_path = Path(settings.segmentation.model_path)
    standin = False
    if not model_path.is_file():
        if not args.allow_standin:
            print(
                f"segmentation model missing at {model_path} — pass --allow-standin "
                "to bench with the contract-identical stand-in model",
                file=sys.stderr,
            )
            return 2
        from photo_pipeline.segment.standin import write_standin_model

        write_standin_model(model_path)
        standin = True

    work = Path(args.workdir)
    if args.frames:
        paths = sorted(
            p for p in Path(args.frames).iterdir() if p.suffix.lower() in _FRAME_EXTENSIONS
        )[: args.count]
        if not paths:
            print(f"no frames found in {args.frames}", file=sys.stderr)
            return 2
    else:
        paths = _synthesize_frames(work / "frames", args.count, args.frame_width, args.frame_height)

    with _RssSampler() as sampler:
        stats = asyncio.run(
            run_stage1(
                paths,
                settings,
                io_concurrency=settings.concurrency.aimd_start,
            )
        )
        composite_ms = _bench_composite(settings, work / "composites", args.composite_views)

    peak_gb = sampler.peak_bytes / (1024**3)
    model_note = "STAND-IN model (pipeline cost only, not BiRefNet)" if standin else str(model_path)
    print(f"bench: {stats.frames} frames, {stats.workers} workers, model: {model_note}")
    print(f"stage-1 wall clock: {stats.wall_s:.1f}s")
    print()
    print("| Metric | Target | Measured |")
    print("|---|---|---|")
    print(
        f"| Stage 1 throughput | >= 20 frames/s/core at "
        f"{settings.concurrency.segmentation_px}px segmentation | "
        f"{stats.frames_per_s_per_core:.1f} frames/s/core "
        f"({stats.frames_per_s:.1f} frames/s on {stats.workers} cores) |"
    )
    print(f"| Composite (4 files + encode) | <= 250ms per view | {composite_ms:.0f}ms per view |")
    print(f"| Peak RSS | <= 4 GB | {peak_gb:.2f} GB |")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="photo-pipeline")
    commands = parser.add_subparsers(dest="command", required=True)

    bench = commands.add_parser("bench", help="measure stage-1 targets on real frames")
    bench.add_argument("--frames", help="directory of real frames (CR3/JPEG/PNG/TIFF)")
    bench.add_argument("--count", type=int, default=200)
    bench.add_argument("--frame-width", type=int, default=4000)
    bench.add_argument("--frame-height", type=int, default=3000)
    bench.add_argument("--composite-views", type=int, default=20)
    bench.add_argument("--workdir", default="bench-work")
    bench.add_argument(
        "--allow-standin",
        action="store_true",
        help="generate a contract-identical stand-in model if the real one is missing",
    )
    args = parser.parse_args(argv)
    if args.command == "bench":
        return _run_bench(args)
    return 2


if __name__ == "__main__":
    sys.exit(main())
