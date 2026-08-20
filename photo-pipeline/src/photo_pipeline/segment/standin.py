"""Contract-identical stand-in ONNX model for environments without the
real BiRefNet weights (tests, offline benches).

Same I/O contract as BiRefNet: input float32 [N, 3, S, S] (ImageNet
normalised), output float32 logits [N, 1, S, S]. The graph scores each
pixel by its (normalised) darkness, which segments a dark garment on the
light studio backdrop — enough to drive the full pipeline end-to-end.
It is NOT BiRefNet and bench numbers taken with it measure the pipeline,
not BiRefNet's conv stack. Requires the dev-only ``onnx`` package.
"""

from __future__ import annotations

from pathlib import Path


def write_standin_model(path: Path) -> None:
    import onnx
    from onnx import TensorProto, helper

    image = helper.make_tensor_value_info(
        "image", TensorProto.FLOAT, ["batch", 3, "height", "width"]
    )
    logits = helper.make_tensor_value_info(
        "logits", TensorProto.FLOAT, ["batch", 1, "height", "width"]
    )
    gain = helper.make_tensor("gain", TensorProto.FLOAT, [], [3.0])
    nodes = [
        helper.make_node("ReduceMean", ["image"], ["mean"], axes=[1], keepdims=1),
        helper.make_node("Neg", ["mean"], ["neg"]),
        helper.make_node("Mul", ["neg", "gain"], ["logits"]),
    ]
    graph = helper.make_graph(nodes, "standin_segmenter", [image], [logits], [gain])
    model = helper.make_model(
        graph, opset_imports=[helper.make_opsetid("", 17)], ir_version=8
    )
    onnx.checker.check_model(model)
    path.parent.mkdir(parents=True, exist_ok=True)
    onnx.save(model, str(path))
