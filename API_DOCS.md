# CV Tiang Telkom Akses — API Documentation

Base URL (local): `http://localhost:8080/api`
All endpoints return **JSON**. All request bodies (where applicable) must use `Content-Type: application/json`.

---

## Authentication

All `/api/admin/*` and `/api/health` endpoints require the header:

```
X-Admin-Key: <value of ADMIN_API_KEY in .env>
```

Default for local dev:
```
X-Admin-Key: demo-admin-key-123
```

Webhook endpoints use a separate secret — see [Webhooks](#webhooks).

---

## Endpoints

### 1. Health Check

```
GET /api/health
```

Returns service status including database and Redis connectivity.

**Headers:**
```
X-Admin-Key: demo-admin-key-123
```

**Response 200 — healthy:**
```json
{
  "status": "ok",
  "service": "tiang-cv-worker",
  "checks": {
    "database": "ok",
    "redis": "ok"
  },
  "time": "2026-03-08T04:00:00+07:00"
}
```

**Response 503 — degraded:**
```json
{
  "status": "degraded",
  "service": "tiang-cv-worker",
  "checks": {
    "database": "ok",
    "redis": "error: Connection refused"
  },
  "time": "2026-03-08T04:00:00+07:00"
}
```

---

### 2. Job Status / Queue Stats

```
GET /api/admin/jobs/status
```

Returns aggregate counts of processed, failed, and pending jobs plus 20 most recent audit log entries.

**Headers:**
```
X-Admin-Key: demo-admin-key-123
```

**Response 200:**
```json
{
  "total_processed": 12,
  "total_failed": 1,
  "total_pending": 0,
  "recent_logs": [
    {
      "id": 45,
      "event": "job.completed",
      "status": "success",
      "job_id": "uuid-...",
      "photo_id": "tiang-12",
      "context": {
        "pole_type": "2-segmen",
        "measurement_method": "segmentation",
        "is_compliant": true
      },
      "created_at": "2026-03-08T04:13:10+07:00"
    }
  ],
  "time": "2026-03-08T04:15:00+07:00"
}
```

---

### 3. Trigger Job Run

```
POST /api/admin/jobs/run?date=YYYY-MM-DD
```

Fetches all unprocessed photos for the given date from the Supabase `photos` table and dispatches them to the processing queue.

**Headers:**
```
X-Admin-Key: demo-admin-key-123
```

**Query params:**

| Param | Type   | Required | Description                    |
|-------|--------|----------|--------------------------------|
| date  | string | No       | Format `YYYY-MM-DD`. Defaults to today (WIB). |

**Response 200 — photos queued:**
```json
{
  "status": "ok",
  "date": "2026-03-08",
  "fetched": 5,
  "enqueued": 3,
  "time": "2026-03-08T04:00:00+07:00"
}
```

> `fetched` = total photos found in Supabase for that date.
> `enqueued` = how many were actually dispatched (`fetched` minus already-processed).

**Response 200 — nothing to process:**
```json
{
  "status": "ok",
  "message": "No new photos to process",
  "date": "2026-03-08",
  "enqueued": 0
}
```

**Response 422 — invalid date:**
```json
{
  "error": "Invalid date format. Use YYYY-MM-DD."
}
```

---

### 4. List Results

```
GET /api/admin/results?date=YYYY-MM-DD&page=1
```

Returns paginated list of analysis results. `inference_raw` is **excluded** from list — use the detail endpoint to get full geometry data.

**Headers:**
```
X-Admin-Key: demo-admin-key-123
```

**Query params:**

| Param | Type    | Required | Description                          |
|-------|---------|----------|--------------------------------------|
| date  | string  | No       | Filter by processed date `YYYY-MM-DD` |
| page  | integer | No       | Page number, default `1`             |

**Response 200:**
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 12,
      "photo_id": "tiang-12",
      "job_id": "550e8400-e29b-41d4-a716-446655440000",
      "pole_type": "2-segmen",
      "measurement_method": "segmentation",
      "total_visible_px": 440.58,
      "underground_depth_px": 88.12,
      "total_pole_px": 528.70,
      "total_visible_cm": 700.50,
      "underground_depth_cm": 140.10,
      "total_pole_cm": 840.60,
      "is_compliant": true,
      "status": "completed",
      "created_at": "2026-03-08T04:13:10.000000Z",
      "updated_at": "2026-03-08T04:13:10.000000Z"
    }
  ],
  "first_page_url": "http://localhost:8080/api/admin/results?page=1",
  "from": 1,
  "last_page": 1,
  "last_page_url": "http://localhost:8080/api/admin/results?page=1",
  "next_page_url": null,
  "path": "http://localhost:8080/api/admin/results",
  "per_page": 50,
  "prev_page_url": null,
  "to": 1,
  "total": 1
}
```

**`status` values:**

| Value       | Meaning                              |
|-------------|--------------------------------------|
| `completed` | Inference succeeded, result stored   |
| `failed`    | All retries exhausted, moved to dead-letter |
| `pending`   | In queue or processing               |

---

### 5. Get Result Detail

```
GET /api/admin/results/{photo_id}
```

Returns full result including `inference_raw` with all geometry data for canvas rendering.

**Headers:**
```
X-Admin-Key: demo-admin-key-123
```

**Path param:** `photo_id` — e.g. `tiang-12`

**Response 200:**
```json
{
  "id": 12,
  "photo_id": "tiang-12",
  "job_id": "550e8400-e29b-41d4-a716-446655440000",
  "pole_type": "2-segmen",
  "measurement_method": "segmentation",
  "total_visible_px": 440.58,
  "underground_depth_px": 88.12,
  "total_pole_px": 528.70,
  "total_visible_cm": 700.50,
  "underground_depth_cm": 140.10,
  "total_pole_cm": 840.60,
  "is_compliant": true,
  "status": "completed",
  "created_at": "2026-03-08T04:13:10.000000Z",
  "updated_at": "2026-03-08T04:13:10.000000Z",
  "inference_raw": {
    "photo_url": "https://jvrudzqfypdfwgfftcpd.supabase.co/storage/v1/object/public/tiang-photos/tiang-12.png",
    "inference": {
      "detection": {
        "pole_bbox": {
          "x1": 188.39, "y1": 80.70,
          "x2": 220.88, "y2": 559.86,
          "width": 32.49, "height": 479.17
        },
        "pole_bbox_height_px": 479.17,
        "reference_marker_bbox": {
          "x1": 200.34, "y1": 343.26,
          "x2": 212.99, "y2": 432.32,
          "width": 12.65, "height": 89.06
        },
        "reference_marker_height_px": 89.06,
        "raw_detections": [
          {
            "label": "pole",
            "confidence": 0.74,
            "bbox_xyxy": [188.39, 80.70, 220.88, 559.86]
          },
          {
            "label": "reference_marker",
            "confidence": 0.83,
            "bbox_xyxy": [200.34, 343.26, 212.99, 432.32]
          }
        ]
      },
      "segmentation": {
        "raw_segments": [
          {
            "label": "Segmen 1",
            "confidence": 0.94,
            "bbox_xyxy": [195.42, 299.83, 214.09, 539.13],
            "mask_polygon": [[204.13, 298.82], [204.13, 303.45], [202.97, 304.61]],
            "height_px": 239.30
          }
        ],
        "deduplicated_segments": [
          {
            "label": "Segmen 1",
            "confidence": 0.94,
            "bbox_xyxy": [195.42, 299.83, 214.09, 539.13],
            "mask_polygon": [[204.13, 298.82], [204.13, 303.45], [202.97, 304.61]],
            "height_px": 239.30
          },
          {
            "label": "reference_marker",
            "confidence": 0.83,
            "bbox_xyxy": [200.34, 343.26, 212.99, 432.32],
            "mask_polygon": [[200.34, 343.26], [212.99, 343.26], [212.99, 432.32], [200.34, 432.32]],
            "height_px": 89.06
          }
        ],
        "structural_segments": [
          {
            "label": "Segmen 1",
            "confidence": 0.94,
            "bbox_xyxy": [195.42, 299.83, 214.09, 539.13],
            "mask_polygon": [[204.13, 298.82], [204.13, 303.45], [202.97, 304.61]],
            "height_px": 239.30
          },
          {
            "label": "Segmen 2",
            "confidence": 0.90,
            "bbox_xyxy": [207.02, 83.65, 220.48, 284.92],
            "mask_polygon": [[211.08, 81.07], [211.08, 98.45], [212.23, 99.61]],
            "height_px": 201.27
          },
          {
            "label": "tapak",
            "confidence": 0.88,
            "bbox_xyxy": [189.09, 536.76, 212.68, 560.80],
            "mask_polygon": [[186.75, 535.09], [186.75, 561.73], [213.39, 561.73], [213.39, 535.09]],
            "height_px": 24.05
          },
          {
            "label": "Joint_1",
            "confidence": 0.82,
            "bbox_xyxy": [205.93, 283.71, 213.97, 298.43],
            "mask_polygon": [[205.29, 280.29], [205.29, 297.66], [213.39, 297.66], [213.39, 280.29]],
            "height_px": 14.72
          }
        ],
        "all_structural_segments": [
          {
            "label": "Segmen 1",
            "confidence": 0.94,
            "bbox_xyxy": [195.42, 299.83, 214.09, 539.13],
            "mask_polygon": [[204.13, 298.82], [204.13, 303.45], [202.97, 304.61]],
            "height_px": 239.30
          }
        ]
      }
    },
    "measurement": {
      "pole_type": "2-segmen",
      "required_segments": ["Segmen 1", "Segmen 2"],
      "detected_labels": ["Joint_1", "Segmen 1", "Segmen 2", "tapak"],
      "missing_segments": [],
      "measurement_method": "segmentation",
      "total_visible_px": 440.58,
      "underground_depth_px": 88.12,
      "total_pole_px": 528.70,
      "scale_cm_per_px": 1.1228,
      "total_visible_cm": 700.50,
      "underground_depth_cm": 140.10,
      "total_pole_cm": 840.60,
      "coverage_check": {
        "coverage_ratio": 0.9192,
        "threshold": 0.80,
        "is_partial_coverage": false,
        "warning": null
      },
      "tilt_angle_deg": 2.34,
      "pole_bbox": {
        "x1": 188.39, "y1": 80.70,
        "x2": 220.88, "y2": 559.86,
        "width": 32.49, "height": 479.17
      }
    },
    "compliance": {
      "is_compliant": true,
      "batas_gali_detected": false,
      "batas_gali_confidence": null,
      "notes": []
    },
    "image_meta": {
      "width": 640,
      "height": 853,
      "channels": 3
    }
  }
}
```

**Response 404:**
```json
{
  "error": "Result not found for photo_id: tiang-99"
}
```

---

## Webhooks

Webhook endpoints do **not** require `X-Admin-Key`. They use their own secret headers.

### Supabase Webhook (auto-trigger on INSERT)

```
POST /api/webhooks/supabase
```

Triggered automatically by Supabase Database Webhook when a new row is inserted into the `photos` table. No manual call needed — set up once in Supabase dashboard.

**Setup di Supabase:**
*Dashboard → Database → Webhooks → Create new webhook*
- Table: `photos`
- Events: `INSERT`
- URL: `https://your-domain.com/api/webhooks/supabase`
- HTTP Header: `X-Webhook-Secret: <WEBHOOK_SUPABASE_SECRET from .env>`

**Payload (sent by Supabase automatically):**
```json
{
  "type": "INSERT",
  "table": "photos",
  "schema": "public",
  "record": {
    "photo_id": "tiang-13",
    "photo_url": "https://xxx.supabase.co/storage/v1/object/public/tiang-photos/tiang-13.png",
    "category": "tiang",
    "captured_date": "2026-03-08",
    "location": "Jl. Sudirman, Jakarta",
    "captured_at": "2026-03-08T09:00:00+07:00"
  },
  "old_record": null
}
```

**Response 202 — queued:**
```json
{
  "status": "queued",
  "photo_id": "tiang-13",
  "job_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Response 200 — skipped (already processed):**
```json
{
  "status": "skipped",
  "reason": "already exists",
  "photo_id": "tiang-13"
}
```

**Response 401 — wrong secret:**
```json
{
  "error": "Unauthorized"
}
```

**Response 403 — URL not in whitelist:**
```json
{
  "error": "Photo URL not in allowed domains"
}
```

---

## Field Reference

### `inference_raw.measurement`

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| `pole_type` | string | No | `"2-segmen"` atau `"3-segmen"` |
| `measurement_method` | string | No | `"segmentation"` atau `"detection_bbox_fallback"` |
| `required_segments` | string[] | No | Segmen yang diharapkan untuk tipe tiang ini |
| `detected_labels` | string[] | No | Semua label yang terdeteksi |
| `missing_segments` | string[] | No | Segmen yang tidak ditemukan (kosong = lengkap) |
| `total_visible_px` | float | No | Panjang tiang yang terlihat di atas tanah (px) |
| `underground_depth_px` | float | No | Estimasi bagian terpendam (px), = `total_visible_px × 0.2` |
| `total_pole_px` | float | No | Total panjang tiang (px) |
| `scale_cm_per_px` | float | **Yes** | Null jika reference_marker tidak terdeteksi |
| `total_visible_cm` | float | **Yes** | Null jika reference_marker tidak terdeteksi |
| `underground_depth_cm` | float | **Yes** | Null jika reference_marker tidak terdeteksi |
| `total_pole_cm` | float | **Yes** | Null jika reference_marker tidak terdeteksi |
| `coverage_check.coverage_ratio` | float | Yes | Rasio cakupan segmen vs pole bbox |
| `coverage_check.is_partial_coverage` | bool | Yes | `true` → fallback ke bbox detection |
| `coverage_check.warning` | string | Yes | Penjelasan fallback jika terjadi |
| `tilt_angle_deg` | float | **Yes** | Kemiringan tiang terhadap vertikal (derajat). Positif = miring kanan, negatif = miring kiri. `null` jika structural segments < 2 |

### `inference_raw.compliance`

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| `is_compliant` | bool | No | `false` jika Batas Gali terdeteksi dengan conf ≥ 0.30 |
| `batas_gali_detected` | bool | No | Apakah label `Batas_gali` ditemukan |
| `batas_gali_confidence` | float | Yes | Confidence score jika terdeteksi |
| `notes` | string[] | No | Daftar catatan compliance |

### `inference_raw.inference.segmentation` — Sub-fields

| Field | Deskripsi |
|-------|-----------|
| `raw_segments` | Semua segmen dari model YOLO sebelum filter apapun |
| `deduplicated_segments` | Setelah deduplication (same label + bbox overlap → ambil confidence tertinggi) + filter pole bbox |
| `structural_segments` | Subset dari `deduplicated_segments` — hanya label struktural (Segmen, Joint, tapak). **Ini yang dipakai untuk pengukuran.** |
| `all_structural_segments` | Structural segments **sebelum** filter pole bbox — untuk audit/debug jika ada tiang ganda di 1 foto |

### `inference_raw.inference.segmentation.*[]` — Tiap Segment Object

| Field | Type | Description |
|-------|------|-------------|
| `label` | string | Nama class dari model YOLO (lihat [Label Classes](#label-classes)) |
| `confidence` | float | 0.0–1.0 |
| `bbox_xyxy` | float[4] | `[x1, y1, x2, y2]` dalam piksel koordinat gambar asli |
| `mask_polygon` | float[][] | `[[x,y], [x,y], ...]` titik polygon mask dalam piksel |
| `height_px` | float | Tinggi vertikal segment (`y2 - y1`) |

### Label Classes

| Label | Warna | Deskripsi |
|-------|-------|-----------|
| `Segmen 1` | Merah `#ef4444` | Segmen bawah tiang (dekat tanah) |
| `Segmen 2` | Hijau `#22c55e` | Segmen tengah tiang |
| `Segmen 3` | Biru `#3b82f6` | Segmen atas tiang (tiang 3-segmen) |
| `Joint_1` | Ungu `#8b5cf6` | Sambungan antara Segmen 1 dan 2 |
| `Joint_2` | Ungu muda `#a855f7` | Sambungan antara Segmen 2 dan 3 |
| `Batas_gali` | Amber `#f59e0b` | Tanda batas galian (safety marker) |
| `Reference_marker` | Pink `#ec4899` | Penanda referensi ukuran (default 100 cm) |
| `tapak` | Teal `#14b8a6` | Tapak / pondasi tiang |

---

## Rendering Before/After (Petunjuk Frontend)

Overlay canvas bersifat **fleksibel** berdasarkan `measurement_method`:

| `measurement_method` | Source data overlay | Render style |
|----------------------|---------------------|--------------|
| `"segmentation"` | `structural_segments` + inject `reference_marker` jika ada | Mask polygon (filled) + dashed bbox — **tanpa text label** |
| `"detection_bbox_fallback"` | `raw_detections` dari detection block | Solid filled bbox — **tanpa text label** |

> **Catatan Render:** Overlay AFTER hanya menampilkan **warna bbox/polygon** per kelas. Tidak ada teks (nama class maupun confidence %) yang dirender di atas canvas — identifikasi kelas dilakukan lewat warna saja (lihat [Label Classes](#label-classes)).

### Deduplication (wajib diterapkan di frontend)

Sebelum render, deduplikasi terlebih dahulu: **label sama + bbox overlap → ambil yang confidence tertinggi**.

```javascript
function deduplicateByLabel(segs) {
  function overlaps(a, b) {
    return a[0] < b[2] && a[2] > b[0] && a[1] < b[3] && a[3] > b[1];
  }
  const byLabel = {};
  segs.forEach(s => {
    const key = (s.label || '').toLowerCase().replace(/ /g, '_');
    (byLabel[key] = byLabel[key] || []).push(s);
  });
  const result = [];
  Object.values(byLabel).forEach(group => {
    const sorted = [...group].sort((a, b) => (b.confidence ?? 0) - (a.confidence ?? 0));
    const kept = [];
    sorted.forEach(c => {
      if (!kept.some(k => overlaps(c.bbox_xyxy, k.bbox_xyxy))) kept.push(c);
    });
    result.push(...kept);
  });
  return result;
}
```

### Contoh implementasi

```javascript
const raw    = data.inference_raw;
const method = raw.measurement?.measurement_method ?? '';
const isDetectionFallback = method === 'detection_bbox_fallback';

let overlaySegments;

if (isDetectionFallback) {
  // Gunakan raw_detections (pole, reference_marker, dll)
  const rawDetections = raw.inference.detection.raw_detections ?? [];
  overlaySegments = deduplicateByLabel(rawDetections.map(d => ({
    label: d.label, confidence: d.confidence,
    bbox_xyxy: d.bbox_xyxy, mask_polygon: [],
  })));
} else {
  // Gunakan structural_segments + tambahkan reference_marker jika tidak ada
  const structural = raw.inference.segmentation.structural_segments ?? [];
  const hasRef = structural.some(s =>
    s.label?.toLowerCase().includes('reference') || s.label?.toLowerCase().includes('marker')
  );

  let refExtra = [];
  if (!hasRef) {
    // Cari di deduplicated_segments (filtered) atau raw_segments (unfiltered)
    const dedup  = raw.inference.segmentation.deduplicated_segments ?? [];
    const rawSeg = raw.inference.segmentation.raw_segments ?? [];
    const isRef  = s => s.label?.toLowerCase().includes('reference') || s.label?.toLowerCase().includes('marker');
    const found  = dedup.find(isRef) || rawSeg.find(isRef);
    if (found) {
      refExtra.push(found);
    } else if (raw.inference.detection.reference_marker_bbox) {
      const rb = raw.inference.detection.reference_marker_bbox;
      refExtra.push({
        label: 'reference_marker', confidence: null,
        bbox_xyxy: [rb.x1, rb.y1, rb.x2, rb.y2], mask_polygon: [],
      });
    }
  }
  overlaySegments = [...structural, ...refExtra];
}

// Render ke canvas
const img = new Image();
img.crossOrigin = 'anonymous';
img.src = raw.photo_url;
img.onload = () => {
  const canvas = document.getElementById('my-canvas');
  const ctx    = canvas.getContext('2d');
  canvas.width  = img.naturalWidth;
  canvas.height = img.naturalHeight;
  ctx.drawImage(img, 0, 0);

  overlaySegments.forEach(seg => {
    const [x1, y1, x2, y2] = seg.bbox_xyxy;
    const color = CLASS_COLORS[seg.label.toLowerCase().replace(/ /g, '_')] ?? '#94a3b8';

    if (!isDetectionFallback && seg.mask_polygon?.length >= 3) {
      // Mode segmentation: gambar mask polygon
      const poly = seg.mask_polygon;
      ctx.beginPath();
      ctx.moveTo(poly[0][0], poly[0][1]);
      for (let i = 1; i < poly.length; i++) ctx.lineTo(poly[i][0], poly[i][1]);
      ctx.closePath();
      ctx.fillStyle = color + '73';   // 45% opacity
      ctx.fill();
      ctx.strokeStyle = color;
      ctx.lineWidth = 2;
      ctx.stroke();
    } else {
      // Mode detection / fallback: gambar solid bbox
      ctx.fillStyle = color + 'a6';   // 65% opacity
      ctx.fillRect(x1, y1, x2 - x1, y2 - y1);
      ctx.strokeStyle = color;
      ctx.lineWidth = 3;
      ctx.strokeRect(x1, y1, x2 - x1, y2 - y1);
    }
    // Tidak ada teks/label yang dirender — identifikasi kelas lewat warna saja
  });
};
```

> **Catatan CORS:** Gambar dari Supabase Storage (public bucket) dapat dimuat dengan `img.crossOrigin = 'anonymous'`. Pastikan bucket Supabase disetel **public** agar tidak ada CORS error.

---

## Error Responses

Semua endpoint error mengikuti format:

```json
{
  "error": "Pesan error"
}
```

| HTTP Code | Penyebab |
|-----------|----------|
| 401 | Header `X-Admin-Key` salah atau tidak ada |
| 403 | URL foto bukan dari domain yang diizinkan |
| 404 | `photo_id` tidak ditemukan |
| 422 | Format parameter tidak valid |
| 500 | Server error internal (cek container logs) |
| 503 | Service degraded (DB atau Redis down) |

---

## Contoh: Full Flow di Postman

### 1. Cek health
```
GET http://localhost:8080/api/health
X-Admin-Key: demo-admin-key-123
```

### 2. Trigger proses foto untuk tanggal tertentu
```
POST http://localhost:8080/api/admin/jobs/run?date=2026-03-08
X-Admin-Key: demo-admin-key-123
```

### 3. Tunggu beberapa detik, lalu ambil list hasil
```
GET http://localhost:8080/api/admin/results?date=2026-03-08
X-Admin-Key: demo-admin-key-123
```

### 4. Ambil detail lengkap satu foto
```
GET http://localhost:8080/api/admin/results/tiang-12
X-Admin-Key: demo-admin-key-123
```

---

## Catatan Penting untuk Frontend

1. **`total_pole_cm` bisa `null`** — terjadi jika `reference_marker` tidak terdeteksi di foto. Gunakan `total_pole_px` sebagai fallback jika hanya butuh proporsi relatif.

2. **`measurement_method`** — penentu mode render canvas:
   - `"segmentation"` → render `structural_segments` dengan mask polygon
   - `"detection_bbox_fallback"` → render `raw_detections` dengan solid bbox. Terjadi jika segmen tidak lengkap atau coverage < 80%.

3. **`structural_segments` vs `all_structural_segments`**:
   - `structural_segments` — sudah difilter per tiang target (pole bbox ± 50% margin). Pakai ini untuk pengukuran dan render utama.
   - `all_structural_segments` — sebelum filter. Gunakan untuk debug jika ada 2 tiang dalam 1 foto.

4. **`reference_marker` tidak ada di `structural_segments`** — label ini bukan segmen struktural, jadi tidak masuk `structural_segments`. Untuk render overlay, cari di `deduplicated_segments` → `raw_segments` → `detection.reference_marker_bbox` (urutan prioritas). Lihat contoh kode di bagian rendering.

5. **Deduplication wajib di frontend** — `raw_detections` dari model bisa mengandung deteksi duplikat (sama label, bbox tumpang tindih). Selalu terapkan dedup sebelum render: label sama + overlap → ambil confidence tertinggi.

6. **`is_compliant: false`** — terdeteksi `Batas_gali` dengan confidence ≥ 30%. Tiang tersebut memiliki tanda galian yang tidak sesuai standar.

7. **Pagination** di list endpoint per 50 item. Gunakan query `?page=2` dst.

8. **Overlay canvas tidak menampilkan teks** — class name dan confidence score tidak dirender di atas gambar. Identifikasi segmen dilakukan murni dari warna bbox/polygon (lihat tabel [Label Classes](#label-classes)). Ini desain yang disengaja agar tampilan tidak cluttered.

9. **`tilt_angle_deg`** — hasil kalkulasi kemiringan tiang menggunakan *centerline regression* melalui center point semua structural segments. Konvensi:
   - `> 0` → miring ke **kanan** (dari perspektif viewer)
   - `< 0` → miring ke **kiri**
   - `0` → tegak sempurna
   - `null` → tidak cukup data (structural segments < 2)

   Threshold interpretasi yang direkomendasikan:
   | Nilai absolut | Status |
   |---|---|
   | < 1° | Tegak (aman) |
   | 1° – 3° | Kemiringan minor |
   | > 3° | Perlu tindakan |

   Visualisasi tilt tersedia di canvas AFTER (mode segmentation): garis sumbu regression berwarna, garis referensi vertikal dashed, arc sudut di tapak, dan badge kemiringan di pojok kiri bawah.
