<?php
/**
 * Patrol360 - Web-based Base64 to Image Migration Dashboard
 * 
 * Access via browser:
 *   https://patrol360.sarsspl.com/backend/public/migrate_web.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\AlertMigrationService;

header('X-Robots-Tag: noindex, nofollow');

$service = new AlertMigrationService();

// Handle AJAX batch migration request
if (isset($_GET['action']) && $_GET['action'] === 'migrate') {
    header('Content-Type: application/json; charset=utf-8');
    $batchSize = isset($_GET['batch']) ? max(10, min(500, (int)$_GET['batch'])) : 250;
    
    try {
        $result = $service->migrateChunk($batchSize, false);
        echo json_encode([
            'status' => 'success',
            'data'   => $result
        ], JSON_PRETTY_PRINT);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle AJAX queue purge & sanitization request
if (isset($_GET['action']) && $_GET['action'] === 'clean_queue') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $purged = $service->purgeDeliveredQueuePayloads();
        $sanitized = $service->sanitizePendingQueuePayloads(500);
        echo json_encode([
            'status' => 'success',
            'data'   => [
                'purged_sent' => $purged,
                'sanitized'   => $sanitized['sanitized'],
                'remaining'   => $sanitized['remaining'],
                'bytes_freed' => $sanitized['bytes_freed']
            ]
        ], JSON_PRETTY_PRINT);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Initial status for web page
$remaining = $service->getRemainingCount();
$migrated  = $service->getMigratedCount();
$total     = $service->getTotalCount();
$percent   = $total > 0 ? round(($migrated / $total) * 100, 1) : 100;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patrol360 - Database Image Migration Runner</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: #0f172a; color: #f8fafc; min-height: 100vh; padding: 2rem 1rem; }
        .container { max-width: 760px; margin: 0 auto; background: #1e293b; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5); border: 1px solid #334155; padding: 2rem; }
        .header { display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #334155; padding-bottom: 1.25rem; margin-bottom: 1.5rem; }
        .title { font-size: 1.5rem; font-weight: 700; color: #38bdf8; display: flex; align-items: center; gap: 0.5rem; }
        .badge { background: #0369a1; color: #e0f2fe; font-size: 0.75rem; font-weight: 600; padding: 0.25rem 0.6rem; border-radius: 9999px; text-transform: uppercase; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: #0f172a; border: 1px solid #334155; border-radius: 12px; padding: 1.2rem; text-align: center; }
        .stat-val { font-size: 1.75rem; font-weight: 700; }
        .stat-label { font-size: 0.8rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.35rem; }
        .text-cyan { color: #38bdf8; }
        .text-amber { color: #f59e0b; }
        .text-emerald { color: #10b981; }

        .progress-box { background: #0f172a; border-radius: 12px; padding: 1rem; border: 1px solid #334155; margin-bottom: 1.5rem; }
        .progress-header { display: flex; justify-content: space-between; font-size: 0.875rem; margin-bottom: 0.5rem; color: #cbd5e1; }
        .progress-bar-bg { width: 100%; height: 16px; background: #334155; border-radius: 9999px; overflow: hidden; position: relative; }
        .progress-bar-fill { height: 100%; background: linear-gradient(90deg, #0284c7, #10b981); width: <?= $percent ?>%; transition: width 0.4s ease; }

        .actions { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        button { cursor: pointer; border: none; border-radius: 10px; font-weight: 600; font-size: 0.95rem; padding: 0.85rem 1.4rem; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; transition: all 0.2s; }
        .btn-primary { background: #0284c7; color: white; flex: 1; }
        .btn-primary:hover { background: #0369a1; }
        .btn-success { background: #059669; color: white; flex: 1; }
        .btn-success:hover { background: #047857; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }

        .log-box { background: #090d16; border: 1px solid #1e293b; border-radius: 12px; padding: 1rem; font-family: monospace; font-size: 0.82rem; height: 190px; overflow-y: auto; color: #94a3b8; }
        .log-line { margin-bottom: 0.35rem; line-height: 1.4; }
        .log-success { color: #34d399; }
        .log-info { color: #38bdf8; }
        .log-warn { color: #fbbf24; }
        .log-error { color: #f87171; }

        .optimization-box { margin-top: 1.5rem; background: #0c1a2e; border: 1px solid #1e3a8a; border-radius: 12px; padding: 1rem; font-size: 0.85rem; color: #93c5fd; }
        .optimization-box code { background: #1e293b; padding: 0.2rem 0.4rem; border-radius: 4px; color: #f1f5f9; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="title">
            <span>⚡ Patrol360 Image Migrator</span>
        </div>
        <span class="badge">Safe Production Runner</span>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-val text-cyan" id="stat-total"><?= number_format($total) ?></div>
            <div class="stat-label">Total Alerts</div>
        </div>
        <div class="stat-card">
            <div class="stat-val text-emerald" id="stat-migrated"><?= number_format($migrated) ?></div>
            <div class="stat-label">Saved to Disk</div>
        </div>
        <div class="stat-card">
            <div class="stat-val text-amber" id="stat-remaining"><?= number_format($remaining) ?></div>
            <div class="stat-label">Base64 Remaining</div>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="progress-box">
        <div class="progress-header">
            <span>Overall Migration Progress</span>
            <span id="progress-percent"><?= $percent ?>%</span>
        </div>
        <div class="progress-bar-bg">
            <div class="progress-bar-fill" id="progress-bar" style="width: <?= $percent ?>%"></div>
        </div>
    </div>

    <!-- Controls -->
    <div class="actions">
        <button id="btn-single" class="btn-primary" onclick="runSingleChunk()">
            ▶ Migrate 1 Batch (250 Records)
        </button>
        <button id="btn-auto" class="btn-success" onclick="startAutoRun()">
            ⚡ Auto-Migrate All (Continuous)
        </button>
        <button id="btn-stop" class="btn-danger" style="display:none;" onclick="stopAutoRun()">
            ⏹ Pause
        </button>
    </div>
    <div style="margin-top: 0.75rem;">
        <button id="btn-clean-queue" class="btn-primary" style="background: #4f46e5; width: 100%;" onclick="cleanQueuePayloads()">
            🧹 Purge & Sanitize Central Queue (Remove Heavy Base64 from Webhooks)
        </button>
    </div>

    <!-- Live Execution Logs -->
    <div class="log-box" id="log-console">
        <div class="log-line log-info">[Ready] Initialized. <?= number_format($remaining) ?> unmigrated records found.</div>
        <?php if ($remaining === 0): ?>
            <div class="log-line log-success">✔ All Base64 records have already been converted to verified files on disk!</div>
        <?php endif; ?>
    </div>

    <!-- Optimize reminder -->
    <div class="optimization-box" id="opt-box" style="<?= $remaining > 0 ? 'display:none;' : '' ?>">
        <strong>🎉 Migration Complete! Final Step:</strong><br>
        Open <strong>phpMyAdmin</strong> and run this query to reclaim the 6-8 GB free space:<br>
        <code>OPTIMIZE TABLE `ai_alerts`, `central_api_dispatch_queue`;</code>
    </div>
</div>

<script>
let isRunning = false;
let totalSavedBytes = 0;

function appendLog(text, type = 'info') {
    const consoleBox = document.getElementById('log-console');
    const time = new Date().toLocaleTimeString();
    const line = document.createElement('div');
    line.className = 'log-line log-' + type;
    line.textContent = `[${time}] ${text}`;
    consoleBox.appendChild(line);
    consoleBox.scrollTop = consoleBox.scrollHeight;
}

function updateUI(data) {
    document.getElementById('stat-migrated').textContent = (data.total - data.remaining).toLocaleString();
    document.getElementById('stat-remaining').textContent = data.remaining.toLocaleString();
    document.getElementById('stat-total').textContent = data.total.toLocaleString();

    const percent = data.total > 0 ? (((data.total - data.remaining) / data.total) * 100).toFixed(1) : 100;
    document.getElementById('progress-percent').textContent = percent + '%';
    document.getElementById('progress-bar').style.width = percent + '%';

    if (data.remaining === 0) {
        document.getElementById('opt-box').style.display = 'block';
        document.getElementById('btn-single').disabled = true;
        document.getElementById('btn-auto').disabled = true;
    }
}

async function runSingleChunk() {
    const btnSingle = document.getElementById('btn-single');
    btnSingle.disabled = true;
    appendLog('Processing chunk of up to 250 records...', 'info');

    try {
        const res = await fetch('migrate_web.php?action=migrate&batch=250');
        const json = await res.json();

        if (json.status === 'success') {
            const d = json.data;
            totalSavedBytes += d.bytes_saved;
            const totalMb = (totalSavedBytes / (1024 * 1024)).toFixed(2);

            appendLog(`✔ Batch Done: ${d.migrated} converted & verified | ${d.skipped} skipped | Freed: ${d.mb_saved} MB (Total: ${totalMb} MB). Remaining: ${d.remaining}`, 'success');
            
            if (d.errors && d.errors.length > 0) {
                d.errors.slice(0, 3).forEach(err => appendLog(`  ⚠ ${err}`, 'warn'));
            }

            updateUI(d);
            return d.remaining;
        } else {
            appendLog(`❌ Error: ${json.message}`, 'error');
            return -1;
        }
    } catch (e) {
        appendLog(`❌ Network error: ${e.message}`, 'error');
        return -1;
    } finally {
        btnSingle.disabled = false;
    }
}

async function startAutoRun() {
    isRunning = true;
    document.getElementById('btn-single').disabled = true;
    document.getElementById('btn-auto').style.display = 'none';
    document.getElementById('btn-stop').style.display = 'inline-flex';
    appendLog('Starting automatic continuous migration...', 'info');

    while (isRunning) {
        const remaining = await runSingleChunk();
        if (remaining <= 0) {
            appendLog('🎉 All records converted and verified successfully!', 'success');
            stopAutoRun();
            break;
        }
        // Small pause between chunks to give MySQL / server breathing room
        await new Promise(r => setTimeout(r, 800));
    }
}

function stopAutoRun() {
    isRunning = false;
    document.getElementById('btn-single').disabled = false;
    document.getElementById('btn-auto').style.display = 'inline-flex';
    document.getElementById('btn-stop').style.display = 'none';
    appendLog('Migration paused.', 'warn');
}

async function cleanQueuePayloads() {
    const btn = document.getElementById('btn-clean-queue');
    btn.disabled = true;
    appendLog('Purging delivered items and sanitizing queued Base64 payloads...', 'info');
    try {
        const res = await fetch('migrate_web.php?action=clean_queue');
        const json = await res.json();
        if (json.status === 'success') {
            const d = json.data;
            const mb = (d.bytes_freed / (1024 * 1024)).toFixed(2);
            appendLog(`✔ Queue Clean Complete: Purged ${d.purged_sent} delivered items | Sanitized ${d.sanitized} pending records (${mb} MB freed). Remaining bloated: ${d.remaining}`, 'success');
        } else {
            appendLog(`❌ Queue Error: ${json.message}`, 'error');
        }
    } catch (e) {
        appendLog(`❌ Network error: ${e.message}`, 'error');
    } finally {
        btn.disabled = false;
    }
}
</script>

</body>
</html>
