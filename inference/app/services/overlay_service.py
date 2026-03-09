"""
Overlay rendering service.
Builds a PNG overlay image (as data URL) so frontend can display validated
results without drawing on canvas.
"""

import base64
import math
import re
from io import BytesIO
from typing import Optional

from PIL import Image, ImageDraw, ImageFont

from app.schemas.response import DetectionResult, MeasurementResult, SegmentationResult, SegmentedObject


CLASS_COLORS = {
    "segmen_1": "#ef4444",
    "segmen_2": "#22c55e",
    "segmen_3": "#3b82f6",
    "joint_1": "#8b5cf6",
    "joint_2": "#a855f7",
    "batas_gali": "#f59e0b",
    "reference_marker": "#ec4899",
    "bendera": "#ec4899",
    "tapak": "#14b8a6",
    "pole": "#0ea5e9",
}
DEFAULT_COLOR = "#94a3b8"
SEGMENT_FILL_ALPHA = 80
DETECTION_FILL_ALPHA = 90


def _normalize_label(label: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", label.strip().lower()).strip("_")


def _hex_to_rgba(hex_color: str, alpha: int) -> tuple[int, int, int, int]:
    hex_color = hex_color.lstrip("#")
    return tuple(int(hex_color[i : i + 2], 16) for i in (0, 2, 4)) + (alpha,)


def _color_for_label(label: str) -> str:
    return CLASS_COLORS.get(_normalize_label(label), DEFAULT_COLOR)


def _pick_reference_segment(segmentation: SegmentationResult) -> Optional[SegmentedObject]:
    for seg in segmentation.deduplicated_segments:
        norm = _normalize_label(seg.label)
        if "reference" in norm or "marker" in norm or norm == "bendera":
            return seg
    for seg in segmentation.raw_segments:
        norm = _normalize_label(seg.label)
        if "reference" in norm or "marker" in norm or norm == "bendera":
            return seg
    return None


def _draw_detection_overlay(
    fill_draw: ImageDraw.ImageDraw,
    outline_draw: ImageDraw.ImageDraw,
    detection: DetectionResult,
) -> None:
    for d in detection.raw_detections:
        x1, y1, x2, y2 = d.bbox_xyxy
        color = _color_for_label(d.label)
        # Semi-transparent fill goes on the isolated fill layer
        fill_draw.rectangle([x1, y1, x2, y2], fill=_hex_to_rgba(color, DETECTION_FILL_ALPHA))
        # Solid outline goes directly on the composited canvas
        outline_draw.rectangle([x1, y1, x2, y2], outline=_hex_to_rgba(color, 255), width=3)


def _segment_center(seg: SegmentedObject) -> tuple[float, float]:
    x1, y1, x2, y2 = seg.bbox_xyxy
    return ((x1 + x2) / 2.0, (y1 + y2) / 2.0)


def _draw_dashed_line(
    draw: ImageDraw.ImageDraw,
    p1: tuple[float, float],
    p2: tuple[float, float],
    color: tuple[int, int, int, int],
    width: int = 2,
    dash: int = 10,
    gap: int = 7,
) -> None:
    x1, y1 = p1
    x2, y2 = p2
    length = math.hypot(x2 - x1, y2 - y1)
    if length <= 0:
        return

    dx = (x2 - x1) / length
    dy = (y2 - y1) / length
    progress = 0.0
    while progress < length:
        end = min(progress + dash, length)
        sx = x1 + dx * progress
        sy = y1 + dy * progress
        ex = x1 + dx * end
        ey = y1 + dy * end
        draw.line([(sx, sy), (ex, ey)], fill=color, width=width)
        progress += dash + gap


def _draw_tilt_guides(
    draw: ImageDraw.ImageDraw,
    structural_segments: list[SegmentedObject],
) -> None:
    if len(structural_segments) < 2:
        return

    centers = [_segment_center(seg) for seg in structural_segments]
    ys = [c[1] for c in centers]
    y_mean = sum(ys) / len(ys)
    y_var = sum((y - y_mean) ** 2 for y in ys)
    if y_var <= 1e-6:
        return

    xs = [c[0] for c in centers]
    x_mean = sum(xs) / len(xs)
    slope = sum((ys[i] - y_mean) * (xs[i] - x_mean) for i in range(len(xs))) / y_var
    intercept = x_mean - slope * y_mean

    y_top = min(ys)
    y_bottom = max(ys)
    x_top = slope * y_top + intercept
    x_bottom = slope * y_bottom + intercept

    # Centerline like sample image (bright yellow).
    draw.line([(x_top, y_top), (x_bottom, y_bottom)], fill=_hex_to_rgba("#facc15", 255), width=4)
    # Vertical reference (cyan dashed) for tilt comparison.
    _draw_dashed_line(
        draw,
        (x_bottom, y_bottom),
        (x_bottom, y_top),
        _hex_to_rgba("#22d3ee", 220),
        width=2,
        dash=9,
        gap=6,
    )

    # Anchor points on each structural segment center.
    for cx, cy in centers:
        r = 5
        draw.ellipse([cx - r, cy - r, cx + r, cy + r], fill=_hex_to_rgba("#ffffff", 255), outline=_hex_to_rgba("#f59e0b", 255), width=2)


def _draw_tilt_badge(
    draw: ImageDraw.ImageDraw,
    measurement: MeasurementResult,
    image_size: tuple[int, int],
) -> None:
    if measurement.tilt_angle_deg is None:
        return

    width, height = image_size
    font = ImageFont.load_default()
    direction = "Kanan" if measurement.tilt_angle_deg > 0 else "Kiri" if measurement.tilt_angle_deg < 0 else "Tegak"
    text = f"Kemiringan {abs(measurement.tilt_angle_deg):.1f}\N{DEGREE SIGN}  {direction}"
    tb = draw.textbbox((0, 0), text, font=font)
    text_w = tb[2] - tb[0]
    text_h = tb[3] - tb[1]

    pad_x = 12
    pad_y = 8
    x1 = 14
    y2 = height - 14
    x2 = min(width - 14, x1 + text_w + pad_x * 2)
    y1 = y2 - (text_h + pad_y * 2)

    draw.rounded_rectangle([x1, y1, x2, y2], radius=8, fill=_hex_to_rgba("#0f172a", 212), outline=_hex_to_rgba("#f59e0b", 255), width=2)
    draw.text((x1 + pad_x, y1 + pad_y), text, fill=_hex_to_rgba("#fbbf24", 255), font=font)


def _draw_batas_gali_overlay(
    fill_draw: ImageDraw.ImageDraw,
    outline_draw: ImageDraw.ImageDraw,
    segmentation: SegmentationResult,
) -> None:
    """Draw batas_gali segments from deduplicated_segments — always, regardless of measurement method."""
    batas_labels = {"batas_gali", "batas gali"}
    for seg in segmentation.deduplicated_segments:
        if _normalize_label(seg.label) not in batas_labels:
            continue
        color = _color_for_label(seg.label)
        x1, y1, x2, y2 = seg.bbox_xyxy
        if len(seg.mask_polygon) >= 3:
            points = [tuple(p) for p in seg.mask_polygon]
            fill_draw.polygon(points, fill=_hex_to_rgba(color, SEGMENT_FILL_ALPHA))
            outline_draw.polygon(points, outline=_hex_to_rgba(color, 255))
            outline_draw.rectangle([x1, y1, x2, y2], outline=_hex_to_rgba(color, 255), width=2)
        else:
            fill_draw.rectangle([x1, y1, x2, y2], fill=_hex_to_rgba(color, DETECTION_FILL_ALPHA))
            outline_draw.rectangle([x1, y1, x2, y2], outline=_hex_to_rgba(color, 255), width=3)


def _draw_segmentation_overlay(
    fill_draw: ImageDraw.ImageDraw,
    outline_draw: ImageDraw.ImageDraw,
    segmentation: SegmentationResult,
    detection: DetectionResult,
    measurement: MeasurementResult,
    image_size: tuple[int, int],
) -> None:
    structural_segments = list(segmentation.structural_segments)
    segments = list(structural_segments)
    ref_seg = _pick_reference_segment(segmentation)
    if ref_seg is not None:
        segments.append(ref_seg)
    elif detection.reference_marker_bbox is not None:
        rb = detection.reference_marker_bbox
        segments.append(
            SegmentedObject(
                label="reference_marker",
                confidence=1.0,
                bbox_xyxy=[rb.x1, rb.y1, rb.x2, rb.y2],
                mask_polygon=[],
                height_px=rb.height,
            )
        )

    for seg in segments:
        x1, y1, x2, y2 = seg.bbox_xyxy
        color = _color_for_label(seg.label)
        if len(seg.mask_polygon) >= 3:
            points = [tuple(p) for p in seg.mask_polygon]
            # Semi-transparent polygon fill on fill layer
            fill_draw.polygon(points, fill=_hex_to_rgba(color, SEGMENT_FILL_ALPHA))
            # Solid polygon outline + bbox border on outline layer
            outline_draw.polygon(points, outline=_hex_to_rgba(color, 255))
            outline_draw.rectangle([x1, y1, x2, y2], outline=_hex_to_rgba(color, 255), width=2)
        else:
            # Semi-transparent fill on fill layer
            fill_draw.rectangle([x1, y1, x2, y2], fill=_hex_to_rgba(color, DETECTION_FILL_ALPHA))
            # Solid outline on outline layer
            outline_draw.rectangle([x1, y1, x2, y2], outline=_hex_to_rgba(color, 255), width=3)

    # Tilt guides and badge are fully solid — draw directly on outline layer
    _draw_tilt_guides(outline_draw, structural_segments)
    _draw_tilt_badge(outline_draw, measurement, image_size)


def build_overlay_data_url(
    image: Image.Image,
    detection: DetectionResult,
    segmentation: SegmentationResult,
    measurement: MeasurementResult,
) -> str:
    """
    Render overlay image and return it as data URL:
    data:image/png;base64,<...>

    Uses two transparent layers so fills properly blend over the photo:
      - fill_layer:    semi-transparent colored fills (alpha ~80)
      - outline_layer: solid borders, lines, badges (alpha 255)
    Both are alpha_composite'd onto the original photo in order.
    """
    canvas = image.convert("RGBA")

    fill_layer    = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    outline_layer = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    fill_draw    = ImageDraw.Draw(fill_layer, "RGBA")
    outline_draw = ImageDraw.Draw(outline_layer, "RGBA")

    if measurement.measurement_method == "detection_bbox_fallback":
        _draw_detection_overlay(fill_draw, outline_draw, detection)
        # Batas gali always drawn from segmentation regardless of measurement method
        _draw_batas_gali_overlay(fill_draw, outline_draw, segmentation)
    else:
        _draw_segmentation_overlay(fill_draw, outline_draw, segmentation, detection, measurement, canvas.size)

    # Composite fills first (photo shows through), then solid outlines on top
    canvas = Image.alpha_composite(canvas, fill_layer)
    canvas = Image.alpha_composite(canvas, outline_layer)

    out = BytesIO()
    canvas.save(out, format="PNG")
    encoded = base64.b64encode(out.getvalue()).decode("ascii")
    return f"data:image/png;base64,{encoded}"
