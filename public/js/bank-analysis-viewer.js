/**
 * Bank Analysis Viewer — Client-side JS
 * Handles: form loader, raw JSON toggle, transaction load-more
 */

// ── Form Loader ──────────────────────────────────────────────
(function () {
    var form = document.getElementById('analysisForm');
    if (form) {
        form.addEventListener('submit', function () {
            var loader = document.getElementById('loader');
            if (loader) loader.classList.add('active');
        });
    }
})();

// ── Raw JSON Toggle ──────────────────────────────────────────
function toggleRawJson() {
    var block = document.getElementById('rawJsonBlock');
    var btn   = document.getElementById('rawJsonBtn');
    if (!block) return;

    if (block.classList.contains('hidden')) {
        block.classList.remove('hidden');
        btn.textContent = 'Hide Raw JSON';
        btn.classList.add('bg-indigo-100', 'text-indigo-700');
        btn.classList.remove('bg-slate-100', 'text-slate-600');
    } else {
        block.classList.add('hidden');
        btn.textContent = 'Show Raw JSON';
        btn.classList.remove('bg-indigo-100', 'text-indigo-700');
        btn.classList.add('bg-slate-100', 'text-slate-600');
    }
}

// ── Transaction Load More ────────────────────────────────────
var txBatchSize   = 50;
var txCurrentShow = 50;

function loadMoreTransactions() {
    var rows    = document.querySelectorAll('#txTable .tx-row.hidden');
    var toShow  = Math.min(rows.length, txBatchSize);

    for (var i = 0; i < toShow; i++) {
        rows[i].classList.remove('hidden');
    }

    txCurrentShow += toShow;
    var showingEl = document.getElementById('txShowing');
    if (showingEl) showingEl.textContent = txCurrentShow;

    // Hide button if all shown
    if (toShow >= rows.length) {
        var wrap = document.getElementById('loadMoreWrap');
        if (wrap) wrap.style.display = 'none';
    }
}
