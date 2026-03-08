<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CV Tiang Telkom Akses — Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #0f1117;
            color: #e2e8f0;
            min-height: 100vh;
        }

        /* ── Header ── */
        header {
            background: #1a1d27;
            border-bottom: 1px solid #2d3148;
            padding: 16px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        header h1 { font-size: 18px; font-weight: 600; color: #fff; }
        header h1 span { color: #6366f1; }
        #health-badge {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: #94a3b8;
        }
        #health-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #64748b;
        }
        #health-dot.ok  { background: #22c55e; box-shadow: 0 0 6px #22c55e88; }
        #health-dot.err { background: #ef4444; box-shadow: 0 0 6px #ef444488; }

        /* ── Layout ── */
        main { max-width: 1200px; margin: 0 auto; padding: 32px; }

        /* ── Pipeline Diagram ── */
        .pipeline {
            display: flex;
            align-items: center;
            gap: 0;
            background: #1a1d27;
            border: 1px solid #2d3148;
            border-radius: 12px;
            padding: 24px 32px;
            margin-bottom: 32px;
            overflow-x: auto;
        }
        .pipe-step {
            display: flex; flex-direction: column; align-items: center;
            min-width: 130px; text-align: center;
        }
        .pipe-icon {
            width: 52px; height: 52px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; margin-bottom: 10px;
        }
        .pipe-step .pipe-label { font-size: 12px; font-weight: 600; color: #cbd5e1; }
        .pipe-step .pipe-sub   { font-size: 11px; color: #64748b; margin-top: 4px; }
        .pipe-arrow {
            flex: 1; min-width: 32px;
            border-top: 2px dashed #2d3148;
            position: relative; top: -18px;
        }
        .pipe-arrow::after {
            content: '';
            position: absolute; right: -1px; top: -5px;
            border: 5px solid transparent;
            border-left-color: #2d3148;
        }
        .step-telkom  .pipe-icon { background: #1e3a5f; }
        .step-queue   .pipe-icon { background: #2d1b4e; }
        .step-ai      .pipe-icon { background: #1a3a2a; }
        .step-storage .pipe-icon { background: #3a2a0a; }
        .step-result  .pipe-icon { background: #1a2a3a; }

        /* ── Stats Row ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: #1a1d27;
            border: 1px solid #2d3148;
            border-radius: 10px;
            padding: 20px 24px;
        }
        .stat-card .stat-label { font-size: 12px; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-card .stat-value { font-size: 36px; font-weight: 700; margin-top: 6px; }
        .stat-card.completed .stat-value { color: #22c55e; }
        .stat-card.failed    .stat-value { color: #ef4444; }
        .stat-card.pending   .stat-value { color: #f59e0b; }

        /* ── Controls ── */
        .controls {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .controls label { font-size: 13px; color: #94a3b8; }
        input[type="date"] {
            background: #1a1d27;
            border: 1px solid #2d3148;
            color: #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 14px;
            outline: none;
        }
        input[type="date"]:focus { border-color: #6366f1; }

        .btn {
            padding: 9px 20px; border-radius: 8px; font-size: 14px;
            font-weight: 600; cursor: pointer; border: none; transition: all .15s;
        }
        .btn-primary {
            background: #6366f1; color: #fff;
        }
        .btn-primary:hover { background: #4f46e5; }
        .btn-primary:disabled { background: #3730a3; cursor: not-allowed; opacity: .7; }
        .btn-ghost {
            background: transparent; color: #94a3b8;
            border: 1px solid #2d3148;
        }
        .btn-ghost:hover { border-color: #6366f1; color: #6366f1; }

        #run-status {
            font-size: 13px; color: #22c55e;
            display: none;
        }

        /* ── Table ── */
        .table-wrap {
            background: #1a1d27;
            border: 1px solid #2d3148;
            border-radius: 10px;
            overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: #141620;
            padding: 12px 16px;
            font-size: 11px; font-weight: 600;
            color: #64748b; text-transform: uppercase; letter-spacing: .05em;
            text-align: left;
            border-bottom: 1px solid #2d3148;
        }
        tbody tr {
            border-bottom: 1px solid #1e2133;
            cursor: pointer;
            transition: background .1s;
        }
        tbody tr:hover { background: #1e2133; }
        tbody tr:last-child { border-bottom: none; }
        td { padding: 14px 16px; font-size: 13px; }
        td.photo-id { font-family: monospace; color: #93c5fd; font-size: 12px; }

        .badge {
            display: inline-block; padding: 3px 8px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
        }
        .badge-completed { background: #14532d; color: #86efac; }
        .badge-failed    { background: #450a0a; color: #fca5a5; }
        .badge-pending   { background: #451a03; color: #fcd34d; }
        .badge-compliant { background: #14532d; color: #86efac; }
        .badge-noncompliant { background: #450a0a; color: #fca5a5; }
        .badge-na { background: #1e293b; color: #64748b; }

        /* ── Empty / Loading ── */
        .empty {
            padding: 64px; text-align: center; color: #64748b; font-size: 14px;
        }
        .spinner {
            display: inline-block; width: 14px; height: 14px;
            border: 2px solid #6366f133; border-top-color: #6366f1;
            border-radius: 50%; animation: spin .6s linear infinite;
            margin-right: 6px; vertical-align: middle;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Pagination ── */
        .pagination {
            display: flex; align-items: center; justify-content: flex-end;
            gap: 8px; padding: 16px;
            border-top: 1px solid #2d3148;
        }
        .page-info { font-size: 12px; color: #64748b; margin-right: 8px; }

        /* ── Modal ── */
        .modal-backdrop {
            display: none;
            position: fixed; inset: 0;
            background: #00000099;
            z-index: 100;
            align-items: center; justify-content: center;
        }
        .modal-backdrop.open { display: flex; }
        .modal {
            background: #1a1d27;
            border: 1px solid #2d3148;
            border-radius: 14px;
            width: 90%; max-width: 720px;
            max-height: 85vh;
            overflow-y: auto;
            padding: 28px;
            position: relative;
        }
        .modal h2 { font-size: 16px; margin-bottom: 20px; color: #fff; }
        .modal-close {
            position: absolute; top: 20px; right: 20px;
            background: none; border: none; color: #64748b;
            font-size: 20px; cursor: pointer;
        }
        .modal-close:hover { color: #fff; }
        .detail-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
            margin-bottom: 20px;
        }
        .detail-item { background: #141620; border-radius: 8px; padding: 12px 16px; }
        .detail-item .d-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .05em; }
        .detail-item .d-value { font-size: 15px; font-weight: 600; margin-top: 4px; }

        .segments-title { font-size: 13px; font-weight: 600; color: #94a3b8; margin-bottom: 10px; }
        .segment-row {
            background: #141620; border-radius: 8px; padding: 12px 16px;
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 8px; font-size: 13px;
        }
        .segment-row .seg-name { color: #93c5fd; font-weight: 500; }
        .segment-row .seg-len  { color: #e2e8f0; }
        .segment-row .seg-conf { color: #64748b; font-size: 11px; }

        pre.raw-json {
            background: #0f1117; border-radius: 8px; padding: 16px;
            font-size: 11px; line-height: 1.6; overflow-x: auto;
            color: #94a3b8; max-height: 200px;
        }
        .section-label {
            font-size: 11px; color: #64748b; text-transform: uppercase;
            letter-spacing: .05em; margin: 16px 0 8px;
        }

        @media (max-width: 640px) {
            main { padding: 16px; }
            .stats-row { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
            .pipeline { padding: 16px; }
        }
    </style>
</head>
<body>

<header>
    <h1>CV <span>Tiang Telkom Akses</span> — Worker Dashboard</h1>
    <div id="health-badge">
        <div id="health-dot"></div>
        <span id="health-text">Checking...</span>
    </div>
</header>

<main>

    <!-- Pipeline diagram -->
    <div class="pipeline">
        <div class="pipe-step step-telkom">
            <div class="pipe-icon">📡</div>
            <div class="pipe-label">Telkom API</div>
            <div class="pipe-sub">GET /v1/photos</div>
        </div>
        <div class="pipe-arrow"></div>
        <div class="pipe-step step-queue">
            <div class="pipe-icon">⚙️</div>
            <div class="pipe-label">Queue</div>
            <div class="pipe-sub">Redis / Worker</div>
        </div>
        <div class="pipe-arrow"></div>
        <div class="pipe-step step-ai">
            <div class="pipe-icon">🤖</div>
            <div class="pipe-label">YOLO Inference</div>
            <div class="pipe-sub">POST /infer</div>
        </div>
        <div class="pipe-arrow"></div>
        <div class="pipe-step step-storage">
            <div class="pipe-icon">💾</div>
            <div class="pipe-label">Storage</div>
            <div class="pipe-sub">PostgreSQL</div>
        </div>
        <div class="pipe-arrow"></div>
        <div class="pipe-step step-result">
            <div class="pipe-icon">📊</div>
            <div class="pipe-label">Results</div>
            <div class="pipe-sub">GET /admin/results</div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card completed">
            <div class="stat-label">Completed</div>
            <div class="stat-value" id="stat-completed">—</div>
        </div>
        <div class="stat-card pending">
            <div class="stat-label">Pending / In-Progress</div>
            <div class="stat-value" id="stat-pending">—</div>
        </div>
        <div class="stat-card failed">
            <div class="stat-label">Failed</div>
            <div class="stat-value" id="stat-failed">—</div>
        </div>
    </div>

    <!-- Controls -->
    <div class="controls">
        <label>Filter date:</label>
        <input type="date" id="filter-date" value="{{ date('Y-m-d') }}">
        <button class="btn btn-ghost" onclick="loadResults()">Refresh</button>
        <div style="flex:1"></div>
        <label>Run job for date:</label>
        <input type="date" id="run-date" value="{{ date('Y-m-d') }}">
        <button class="btn btn-primary" id="run-btn" onclick="runJob()">Run Now</button>
        <span id="run-status"></span>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Photo ID</th>
                    <th>Status</th>
                    <th>Pole Type</th>
                    <th>Total Height</th>
                    <th>Compliant</th>
                    <th>Processed At</th>
                </tr>
            </thead>
            <tbody id="results-body">
                <tr><td colspan="6" class="empty"><span class="spinner"></span>Loading...</td></tr>
            </tbody>
        </table>
        <div class="pagination" id="pagination" style="display:none">
            <span class="page-info" id="page-info"></span>
            <button class="btn btn-ghost" id="btn-prev" onclick="changePage(-1)">← Prev</button>
            <button class="btn btn-ghost" id="btn-next" onclick="changePage(1)">Next →</button>
        </div>
    </div>

</main>

<!-- Detail Modal -->
<div class="modal-backdrop" id="modal" onclick="closeModal(event)">
    <div class="modal" id="modal-box">
        <button class="modal-close" onclick="closeModalDirect()">✕</button>
        <h2 id="modal-title">Photo Detail</h2>
        <div id="modal-body"></div>
    </div>
</div>

<script>
    const ADMIN_KEY  = 'demo-admin-key-123'; // same as .env ADMIN_API_KEY
    const BASE_URL   = '/api';
    let currentPage  = 1;
    let lastMeta     = null;

    const headers = {
        'Content-Type': 'application/json',
        'X-Admin-Key': ADMIN_KEY,
    };

    /* ── Health ── */
    async function checkHealth() {
        try {
            const res = await fetch(`${BASE_URL}/health`, { headers });
            const data = await res.json();
            const dot  = document.getElementById('health-dot');
            const txt  = document.getElementById('health-text');
            if (data.status === 'ok') {
                dot.className = 'ok';
                txt.textContent = 'Service healthy · DB + Redis OK';
            } else {
                dot.className = 'err';
                txt.textContent = 'Service degraded';
            }
        } catch {
            document.getElementById('health-dot').className = 'err';
            document.getElementById('health-text').textContent = 'Unreachable';
        }
    }

    /* ── Stats ── */
    async function loadStats() {
        try {
            const res  = await fetch(`${BASE_URL}/admin/jobs/status`, { headers });
            const data = await res.json();
            document.getElementById('stat-completed').textContent = data.total_processed ?? '—';
            document.getElementById('stat-failed').textContent    = data.total_failed    ?? '—';
            document.getElementById('stat-pending').textContent   = data.total_pending   ?? '—';
        } catch {}
    }

    /* ── Results Table ── */
    async function loadResults(page = 1) {
        currentPage = page;
        const date  = document.getElementById('filter-date').value;
        const tbody = document.getElementById('results-body');
        tbody.innerHTML = '<tr><td colspan="6" class="empty"><span class="spinner"></span>Loading...</td></tr>';

        try {
            let url = `${BASE_URL}/admin/results?page=${page}`;
            if (date) url += `&date=${date}`;

            const res  = await fetch(url, { headers });
            const data = await res.json();
            lastMeta   = data;

            const rows = data.data ?? [];

            if (rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="empty">No results found for this date.</td></tr>';
                document.getElementById('pagination').style.display = 'none';
                return;
            }

            tbody.innerHTML = rows.map(r => `
                <tr onclick="showDetail('${r.photo_id}')">
                    <td class="photo-id">${r.photo_id}</td>
                    <td>${statusBadge(r.status)}</td>
                    <td>${r.pole_type ?? '<span style="color:#64748b">—</span>'}</td>
                    <td>${r.total_pole_cm != null ? r.total_pole_cm.toFixed(1) + ' cm' : '<span style="color:#64748b">—</span>'}</td>
                    <td>${complianceBadge(r.is_compliant)}</td>
                    <td style="color:#64748b;font-size:12px">${formatDate(r.created_at)}</td>
                </tr>
            `).join('');

            // Pagination
            const pag = document.getElementById('pagination');
            if (data.last_page > 1) {
                pag.style.display = 'flex';
                document.getElementById('page-info').textContent =
                    `Page ${data.current_page} of ${data.last_page} · ${data.total} total`;
                document.getElementById('btn-prev').disabled = data.current_page <= 1;
                document.getElementById('btn-next').disabled = data.current_page >= data.last_page;
            } else {
                pag.style.display = 'none';
            }

        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="6" class="empty" style="color:#ef4444">Error loading results: ${e.message}</td></tr>`;
        }
    }

    function changePage(dir) {
        loadResults(currentPage + dir);
    }

    /* ── Run Job ── */
    async function runJob() {
        const btn  = document.getElementById('run-btn');
        const stat = document.getElementById('run-status');
        const date = document.getElementById('run-date').value;

        btn.disabled    = true;
        btn.textContent = 'Running...';
        stat.style.display = 'none';

        try {
            const res  = await fetch(`${BASE_URL}/admin/jobs/run?date=${date}`, {
                method: 'POST', headers
            });
            const data = await res.json();

            stat.style.display = 'inline';
            if (data.enqueued > 0) {
                stat.style.color = '#22c55e';
                stat.textContent = `Queued ${data.enqueued} photo(s) from ${date}`;
            } else {
                stat.style.color = '#f59e0b';
                stat.textContent = data.message ?? 'No new photos to process';
            }

            setTimeout(() => {
                loadResults();
                loadStats();
            }, 2000);

        } catch (e) {
            stat.style.display  = 'inline';
            stat.style.color    = '#ef4444';
            stat.textContent    = 'Run failed: ' + e.message;
        } finally {
            btn.disabled    = false;
            btn.textContent = 'Run Now';
        }
    }

    /* ── Detail Modal ── */
    async function showDetail(photoId) {
        document.getElementById('modal-title').textContent = `Detail: ${photoId}`;
        document.getElementById('modal-body').innerHTML    = '<div style="text-align:center;padding:32px"><span class="spinner"></span>Loading...</div>';
        document.getElementById('modal').classList.add('open');

        try {
            const res  = await fetch(`${BASE_URL}/admin/results/${photoId}`, { headers });
            const r    = await res.json();

            if (res.status === 404) {
                document.getElementById('modal-body').innerHTML = `<div class="empty">Result not found.</div>`;
                return;
            }

            const segments = r.inference_raw?.segments ?? [];
            const m = r.inference_raw?.measurement ?? {};

            document.getElementById('modal-body').innerHTML = `
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="d-label">Photo ID</div>
                        <div class="d-value" style="font-family:monospace;font-size:13px;color:#93c5fd">${r.photo_id}</div>
                    </div>
                    <div class="detail-item">
                        <div class="d-label">Status</div>
                        <div class="d-value">${statusBadge(r.status)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="d-label">Pole Type</div>
                        <div class="d-value">${r.pole_type ?? '—'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="d-label">Total Height</div>
                        <div class="d-value">${r.total_pole_cm != null ? r.total_pole_cm.toFixed(2) + ' cm' : '—'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="d-label">Compliant</div>
                        <div class="d-value">${complianceBadge(r.is_compliant)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="d-label">Measurement Method</div>
                        <div class="d-value" style="font-size:13px">${m.measurement_method ?? '—'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="d-label">Processed At</div>
                        <div class="d-value" style="font-size:13px;color:#94a3b8">${formatDate(r.created_at)}</div>
                    </div>
                    <div class="detail-item">
                        <div class="d-label">Job ID</div>
                        <div class="d-value" style="font-family:monospace;font-size:11px;color:#64748b">${r.job_id ?? '—'}</div>
                    </div>
                </div>

                ${segments.length > 0 ? `
                <div class="segments-title">Segment Breakdown (${segments.length} segments)</div>
                ${segments.map((s, i) => `
                    <div class="segment-row">
                        <span class="seg-name">Segment ${i + 1} — ${s.label ?? s.class_name ?? 'unknown'}</span>
                        <span class="seg-len">${s.length_cm != null ? s.length_cm.toFixed(1) + ' cm' : '—'}</span>
                        <span class="seg-conf">${s.confidence != null ? (s.confidence * 100).toFixed(0) + '% conf' : ''}</span>
                    </div>
                `).join('')}
                ` : ''}

                ${r.compliance_notes ? `
                <div class="section-label">Compliance Notes</div>
                <div style="background:#141620;border-radius:8px;padding:12px 16px;font-size:13px;color:#94a3b8">${r.compliance_notes}</div>
                ` : ''}

                <div class="section-label">Raw Inference Output</div>
                <pre class="raw-json">${JSON.stringify(r.inference_raw, null, 2)}</pre>
            `;

        } catch (e) {
            document.getElementById('modal-body').innerHTML =
                `<div class="empty" style="color:#ef4444">Error: ${e.message}</div>`;
        }
    }

    function closeModal(e) {
        if (e.target === document.getElementById('modal')) closeModalDirect();
    }
    function closeModalDirect() {
        document.getElementById('modal').classList.remove('open');
    }

    /* ── Helpers ── */
    function statusBadge(status) {
        const map = {
            completed: 'badge-completed',
            failed:    'badge-failed',
            pending:   'badge-pending',
        };
        return `<span class="badge ${map[status] ?? 'badge-na'}">${status ?? '—'}</span>`;
    }

    function complianceBadge(val) {
        if (val === null || val === undefined) return `<span class="badge badge-na">—</span>`;
        return val
            ? `<span class="badge badge-compliant">Compliant</span>`
            : `<span class="badge badge-noncompliant">Non-compliant</span>`;
    }

    function formatDate(iso) {
        if (!iso) return '—';
        return new Date(iso).toLocaleString('id-ID', {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit',
        });
    }

    /* ── Init ── */
    checkHealth();
    loadStats();
    loadResults();

    // Auto-refresh every 30 seconds
    setInterval(() => {
        checkHealth();
        loadStats();
        loadResults(currentPage);
    }, 30000);
</script>

</body>
</html>
