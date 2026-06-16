<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>theta cockpit</title>
    <link rel="icon" href="data:,">
    <style>
        :root {
            --bg: #0b0f14;
            --bg-2: #0e141b;
            --panel: #131a22;
            --panel-2: #0f151c;
            --border: #1f2730;
            --border-2: #2a343f;
            --text: #e6edf3;
            --muted: #8b97a5;
            --faint: #5c6773;
            --good: #3fb950;
            --bad: #f85149;
            --warn: #d6a015;
            --active: #58a6ff;
            --info: #a371f7;
            --neutral: #7d8896;
            --mono: ui-monospace, "SF Mono", "JetBrains Mono", Menlo, Consolas, monospace;
            --sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: radial-gradient(1200px 600px at 70% -10%, #11202e 0%, var(--bg) 55%) fixed, var(--bg);
            color: var(--text);
            font-family: var(--sans);
            font-size: 13px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
            padding: 14px 18px 60px;
        }
        a { color: var(--active); }
        .mono { font-family: var(--mono); font-variant-numeric: tabular-nums; }

        /* ── Header ───────────────────────────────────────── */
        header {
            display: flex; align-items: center; gap: 14px;
            flex-wrap: wrap;
            padding-bottom: 12px; margin-bottom: 14px;
            border-bottom: 1px solid var(--border);
        }
        .brand { display: flex; align-items: baseline; gap: 9px; }
        .brand h1 { font-size: 16px; margin: 0; letter-spacing: .04em; font-weight: 650; }
        .brand .acct { color: var(--muted); font-family: var(--mono); font-size: 12px; }
        .head-right { margin-left: auto; display: flex; align-items: center; gap: 14px; color: var(--muted); font-size: 12px; }
        .live-dot { display: inline-flex; align-items: center; gap: 6px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--neutral); box-shadow: 0 0 0 0 rgba(63,185,80,.5); }
        .dot.live { background: var(--good); animation: pulse 2.2s infinite; }
        .dot.stale { background: var(--warn); }
        .dot.down { background: var(--bad); }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(63,185,80,.45);} 70% { box-shadow: 0 0 0 7px rgba(63,185,80,0);} 100% { box-shadow: 0 0 0 0 rgba(63,185,80,0);} }
        button.ctl {
            background: var(--panel); color: var(--text); border: 1px solid var(--border-2);
            border-radius: 6px; padding: 4px 10px; font-size: 12px; cursor: pointer; font-family: var(--sans);
        }
        button.ctl:hover { border-color: var(--active); }

        /* ── Status strip ─────────────────────────────────── */
        .status-strip { display: flex; flex-wrap: wrap; gap: 8px; width: 100%; }
        .stat {
            display: flex; flex-direction: column; gap: 1px;
            background: var(--panel); border: 1px solid var(--border);
            border-radius: 8px; padding: 7px 11px; min-width: 96px;
            border-left-width: 3px;
        }
        .stat .k { color: var(--faint); font-size: 10px; text-transform: uppercase; letter-spacing: .07em; }
        .stat .v { font-weight: 600; font-size: 13px; }
        .stat .sub { color: var(--muted); font-size: 10.5px; font-family: var(--mono); }

        /* ── Tone system ──────────────────────────────────── */
        .t-good   { --tone: var(--good); }
        .t-bad    { --tone: var(--bad); }
        .t-warn   { --tone: var(--warn); }
        .t-active { --tone: var(--active); }
        .t-info   { --tone: var(--info); }
        .t-neutral{ --tone: var(--neutral); }
        .stat.t-good, .stat.t-bad, .stat.t-warn, .stat.t-active, .stat.t-info, .stat.t-neutral { border-left-color: var(--tone); }
        .stat .v.toned { color: var(--tone); }

        .chip {
            display: inline-flex; align-items: center; gap: 5px;
            background: color-mix(in srgb, var(--tone) 14%, transparent);
            color: var(--tone);
            border: 1px solid color-mix(in srgb, var(--tone) 34%, transparent);
            border-radius: 5px; padding: 1px 7px; font-size: 11.5px; font-weight: 550;
            white-space: nowrap; cursor: default;
        }
        .chip.unknown { border-style: dashed; }
        .chip.dotled::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--tone); }

        /* ── Cards ────────────────────────────────────────── */
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 14px; }
        .card {
            background: linear-gradient(180deg, var(--panel) 0%, var(--panel-2) 100%);
            border: 1px solid var(--border); border-radius: 10px; padding: 11px 13px;
        }
        .card .k { color: var(--muted); font-size: 11px; display: flex; justify-content: space-between; align-items: center; }
        .card .big { font-family: var(--mono); font-size: 21px; font-weight: 600; margin-top: 4px; letter-spacing: -.02em; }
        .card .note { color: var(--faint); font-size: 10.5px; margin-top: 3px; }
        .unverified { color: var(--warn) !important; }
        .pos { color: var(--good); } .neg { color: var(--bad); }

        /* ── Cap bars ─────────────────────────────────────── */
        .caps { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-bottom: 16px; }
        .capbar { background: var(--panel); border: 1px solid var(--border); border-radius: 9px; padding: 9px 12px; }
        .capbar .top { display: flex; justify-content: space-between; font-size: 11.5px; margin-bottom: 6px; }
        .capbar .top .lbl { color: var(--muted); }
        .capbar .top .val { font-family: var(--mono); }
        .track { height: 6px; background: var(--panel-2); border-radius: 4px; overflow: hidden; border: 1px solid var(--border); }
        .fill { height: 100%; background: var(--tone); border-radius: 4px; transition: width .4s ease; }

        /* ── Grid + panels ────────────────────────────────── */
        .grid { display: grid; grid-template-columns: 1.35fr 1fr; gap: 14px; align-items: start; }
        @media (max-width: 1100px) { .grid { grid-template-columns: 1fr; } }
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 11px; margin-bottom: 14px; overflow: hidden; }
        .panel > h2 {
            font-size: 12px; text-transform: uppercase; letter-spacing: .07em; color: var(--muted);
            margin: 0; padding: 10px 14px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 8px; font-weight: 600;
        }
        .panel > h2 .badge { margin-left: auto; color: var(--faint); font-weight: 500; letter-spacing: 0; text-transform: none; font-size: 11px; }
        .panel .body { padding: 4px 0; max-height: 460px; overflow-y: auto; }
        .panel .body.tall { max-height: 620px; }
        .empty { color: var(--faint); padding: 16px 14px; font-style: italic; }

        /* ── Feed rows ────────────────────────────────────── */
        .feed-row { display: flex; gap: 10px; padding: 8px 14px; border-bottom: 1px solid var(--border); align-items: flex-start; }
        .feed-row:last-child { border-bottom: none; }
        .feed-row .time { color: var(--faint); font-family: var(--mono); font-size: 11px; min-width: 44px; padding-top: 1px; }
        .feed-row .main { flex: 1; min-width: 0; }
        .feed-row .line1 { display: flex; gap: 7px; align-items: center; flex-wrap: wrap; }
        .feed-row .sym { font-weight: 650; font-family: var(--mono); }
        .feed-row .line2 { color: var(--muted); font-size: 11.5px; margin-top: 3px; display: flex; gap: 8px; flex-wrap: wrap; align-items:center; }
        .feed-row .nums { font-family: var(--mono); color: var(--muted); }

        /* ── Why-not rollup ───────────────────────────────── */
        .why-row { display: flex; align-items: center; gap: 10px; padding: 7px 14px; border-bottom: 1px solid var(--border); }
        .why-row:last-child { border-bottom: none; }
        .why-row .cnt { font-family: var(--mono); font-weight: 700; font-size: 15px; color: var(--tone); min-width: 34px; text-align: right; }
        .why-row .meat { flex: 1; min-width: 0; }
        .why-row .dets { color: var(--faint); font-size: 10.5px; margin-top: 2px; }

        /* ── Tables ───────────────────────────────────────── */
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { text-align: left; color: var(--faint); font-weight: 500; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; padding: 7px 10px; border-bottom: 1px solid var(--border); position: sticky; top: 0; background: var(--panel); }
        td { padding: 7px 10px; border-bottom: 1px solid var(--border); }
        tr:last-child td { border-bottom: none; }
        td.num, th.num { text-align: right; font-family: var(--mono); }
        .sym { font-family: var(--mono); font-weight: 600; }

        .pillrow { display: flex; gap: 5px; flex-wrap: wrap; }
        .scorecard { display: flex; gap: 7px; flex-wrap: wrap; padding: 10px 14px; }
        .score { background: var(--panel-2); border: 1px solid var(--border); border-radius: 7px; padding: 5px 10px; text-align: center; min-width: 64px; }
        .score .n { font-family: var(--mono); font-size: 17px; font-weight: 650; }
        .score .l { color: var(--muted); font-size: 10px; text-transform: uppercase; letter-spacing: .04em; }

        .errbar { background: color-mix(in srgb, var(--bad) 12%, transparent); border: 1px solid color-mix(in srgb, var(--bad) 35%, transparent); color: var(--bad); border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; font-size: 12px; }

        /* ── Tooltip ──────────────────────────────────────── */
        #tip {
            position: fixed; z-index: 999; max-width: 320px; pointer-events: none;
            background: #05080c; color: var(--text); border: 1px solid var(--border-2);
            border-radius: 7px; padding: 7px 10px; font-size: 12px; line-height: 1.4;
            box-shadow: 0 8px 28px rgba(0,0,0,.55); opacity: 0; transition: opacity .1s; display: none;
        }
        #tip .tip-h { font-weight: 650; margin-bottom: 2px; }
        ::-webkit-scrollbar { width: 9px; height: 9px; }
        ::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
    </style>
</head>
<body>
    <header>
        <div class="brand">
            <h1>theta&nbsp;cockpit</h1>
            <span class="acct" id="acct">—</span>
        </div>
        <div class="head-right">
            <span id="updated">connecting…</span>
            <span class="live-dot"><span class="dot" id="conn"></span><span id="conn-label">—</span></span>
            <button class="ctl" id="pause">⏸ Pause</button>
            <button class="ctl" id="refresh">↻ Refresh</button>
        </div>
        <div class="status-strip" id="statusbar"></div>
    </header>

    <div id="errors"></div>
    <div class="cards" id="cards"></div>
    <div class="caps" id="caps"></div>

    <div class="grid">
        <div class="col">
            <section class="panel">
                <h2>Decisions <span class="badge" id="decisions-badge"></span></h2>
                <div class="scorecard" id="scorecard"></div>
                <div class="body tall" id="decisions"></div>
            </section>
        </div>
        <div class="col">
            <section class="panel">
                <h2>Monitor <span class="badge" id="monitor-badge"></span></h2>
                <div class="body" id="monitor"></div>
            </section>
            <section class="panel">
                <h2>Why it isn't trading <span class="badge">today's blocks</span></h2>
                <div class="body" id="whynot"></div>
            </section>
            <section class="panel">
                <h2>Open positions <span class="badge" id="positions-badge"></span></h2>
                <div class="body" id="positions"></div>
            </section>
            <section class="panel">
                <h2>Working orders <span class="badge" id="orders-badge"></span></h2>
                <div class="body" id="orders"></div>
            </section>
            <section class="panel">
                <h2>Broker order ledger <span class="badge" id="recon-badge"></span></h2>
                <div class="body" id="reconciliation"></div>
            </section>
            <section class="panel">
                <h2>System events <span class="badge" id="mcp-badge"></span></h2>
                <div class="body" id="events"></div>
            </section>
        </div>
    </div>

    <section class="panel">
        <h2>Journal <span class="badge">recent rows · hover any tag for its meaning</span></h2>
        <div class="body tall" id="journal"></div>
    </section>

    <div id="tip"><div class="tip-h"></div><div class="tip-b"></div></div>

    <script>
    (function () {
        const POLL_MS = 5000;
        let paused = false, timer = null, lastData = null;

        // ── tooltip ──────────────────────────────────────────
        const tip = document.getElementById('tip');
        const tipH = tip.querySelector('.tip-h');
        const tipB = tip.querySelector('.tip-b');
        document.addEventListener('mouseover', e => {
            const t = e.target.closest('[data-tip]');
            if (!t) return;
            tipH.textContent = t.getAttribute('data-tiph') || '';
            tipB.textContent = t.getAttribute('data-tip') || '';
            tipH.style.display = tipH.textContent ? 'block' : 'none';
            tip.style.display = 'block';
            requestAnimationFrame(() => tip.style.opacity = 1);
        });
        document.addEventListener('mousemove', e => {
            if (tip.style.display !== 'block') return;
            let x = e.clientX + 14, y = e.clientY + 16;
            const r = tip.getBoundingClientRect();
            if (x + r.width > innerWidth - 10) x = e.clientX - r.width - 12;
            if (y + r.height > innerHeight - 10) y = e.clientY - r.height - 12;
            tip.style.left = x + 'px'; tip.style.top = y + 'px';
        });
        document.addEventListener('mouseout', e => {
            if (e.target.closest('[data-tip]')) { tip.style.opacity = 0; tip.style.display = 'none'; }
        });

        // ── helpers ──────────────────────────────────────────
        const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        const el = id => document.getElementById(id);
        function money(v, opts={}) {
            if (v === null || v === undefined) return `<span class="unverified" data-tip="Not verifiable from broker truth right now — shown as unknown rather than a confident zero.">unverified</span>`;
            const n = Number(v);
            const abs = Math.abs(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const str = (n < 0 ? '-$' : (opts.signed && n > 0 ? '+$' : '$')) + abs;
            if (opts.signed) return `<span class="${n>0?'pos':(n<0?'neg':'')}">${str}</span>`;
            return str;
        }
        const num = (v, d=0) => (v === null || v === undefined) ? '—' : Number(v).toLocaleString(undefined, {minimumFractionDigits:d, maximumFractionDigits:d});
        function ageStr(s) {
            if (s === null || s === undefined) return '';
            s = Math.round(s);
            if (s < 60) return s + 's';
            if (s < 3600) return Math.floor(s/60) + 'm';
            if (s < 86400) return Math.floor(s/3600) + 'h';
            return Math.floor(s/86400) + 'd';
        }
        function clock(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            return d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false});
        }
        // term -> chip
        function chip(term, opts={}) {
            if (!term || term.label === undefined) return '';
            const tone = 't-' + (term.tone || 'neutral');
            const unk = term.known === false ? ' unknown' : '';
            const dot = opts.dot ? ' dotled' : '';
            return `<span class="chip ${tone}${unk}${dot}" data-tiph="${esc(term.label)}" data-tip="${esc(term.description||'')}">${esc(term.label)}</span>`;
        }
        function stat(k, term, sub) {
            const tone = 't-' + (term.tone || 'neutral');
            return `<div class="stat ${tone}" data-tiph="${esc(term.label)}" data-tip="${esc(term.description||'')}">
                <span class="k">${esc(k)}</span>
                <span class="v toned">${esc(term.label)}</span>
                ${sub ? `<span class="sub">${sub}</span>` : ''}
            </div>`;
        }

        // ── render ───────────────────────────────────────────
        function render(d) {
            lastData = d;
            el('acct').textContent = '#' + (d.status_bar.account || '—');

            // status strip
            const sb = d.status_bar;
            const credSub = sb.broker_credits.pct !== undefined ? sb.broker_credits.pct + '% of budget' : '';
            const mkt = sb.market.et_time || '';
            el('statusbar').innerHTML =
                stat('Mode', sb.mode) +
                stat('Kill switch', sb.kill_switch) +
                stat('Options lane', sb.options_lane) +
                stat('Market', sb.market, mkt) +
                stat('OAuth', sb.oauth) +
                stat('Broker credits', sb.broker_credits, credSub) +
                stat('Data plane', sb.data_plane, sb.data_plane.age!=null?('checked '+ageStr(sb.data_plane.age)+' ago'):'') +
                stat('Snapshot', sb.snapshot, 'cache: ' + (sb.snapshot.cache||'—')) +
                stat('Monitor', sb.monitor);

            // snapshot errors
            const errs = d.snapshot_errors || {};
            const ekeys = Object.keys(errs);
            el('errors').innerHTML = ekeys.length
                ? `<div class="errbar"><strong>Snapshot read failures:</strong> ${ekeys.map(k => esc(k)+' ('+esc(errs[k])+')').join(' · ')}. Affected panels show last-known or unverified.</div>`
                : '';

            // account cards
            const a = d.account;
            el('cards').innerHTML =
                cardMoney('Account value', a.account_value, 'cash + positions') +
                cardMoney('Cash', a.cash) +
                cardMoney('Buying power', a.buying_power) +
                settledCard(a.settled_cash) +
                cardPnl('Realized P&L (today)', a.pnl.realized_today) +
                cardPnl('Unrealized P&L (open)', a.pnl.unrealized_open) +
                cardCount('Open positions', a.open_positions);

            // caps
            el('caps').innerHTML = (d.caps||[]).map(c => {
                const used = c.used === null ? '—' : (c.unit === '$' ? '$'+num(c.used,2) : num(c.used));
                const lim = c.unit === '$' ? '$'+num(c.limit,0) : num(c.limit);
                return `<div class="capbar t-${c.tone}" data-tip="${esc(c.note)}">
                    <div class="top"><span class="lbl">${esc(c.label)}</span><span class="val">${used} / ${lim}</span></div>
                    <div class="track"><div class="fill" style="width:${Math.min(100,c.pct)}%"></div></div>
                </div>`;
            }).join('');

            // scorecard
            renderScorecard(d);

            // decisions feed
            renderFeed('decisions', d.decisions, 'No decisions recorded yet today. The producer and autopilot run every minute during the session.');
            el('decisions-badge').textContent = (d.decisions||[]).length + ' today';

            // monitor
            renderMonitor(d.monitor);

            // why not
            renderWhy(d.why_not_trading);

            // positions
            renderPositions(d.positions);
            const pcount = (d.positions.option||[]).length + (d.positions.equity||[]).length;
            el('positions-badge').textContent = pcount + ' held';

            // orders
            renderOrders(d.open_orders);
            el('orders-badge').textContent = (d.open_orders||[]).length + ' working';

            // reconciliation
            renderRecon(d.reconciliation);

            // events + mcp health
            renderEvents(d.system_events);
            const h = d.mcp_health || {};
            el('mcp-badge').textContent = `${h.total||0} calls · ${h.errors||0} err`;

            // journal
            renderFeed('journal', d.journal, 'Journal is empty.');
        }

        function cardMoney(k, obj, note) {
            const v = obj && obj.value;
            return `<div class="card"><div class="k">${esc(k)}</div>
                <div class="big">${money(v)}</div>
                ${note ? `<div class="note">${esc(note)}</div>` : ''}</div>`;
        }
        function cardPnl(k, v) {
            return `<div class="card"><div class="k">${esc(k)}</div>
                <div class="big">${money(v, {signed:true})}</div></div>`;
        }
        function settledCard(s) {
            const uns = s.unsettled ? `unsettled ${money(s.unsettled)}` : 'fully settled';
            const src = s.source ? esc(s.source) : '';
            return `<div class="card" data-tip="Settled cash is a hard placement gate. Source: ${src}. Unsettled sell proceeds cannot be recycled in a cash account.">
                <div class="k">Settled cash</div>
                <div class="big">${money(s.value)}</div>
                <div class="note">${uns}</div></div>`;
        }
        function cardCount(k, op) {
            return `<div class="card"><div class="k">${esc(k)}</div>
                <div class="big">${num(op.option)} <span style="font-size:12px;color:var(--muted)">opt</span> · ${num(op.equity)} <span style="font-size:12px;color:var(--muted)">eq</span></div></div>`;
        }

        function renderScorecard(d) {
            // Authoritative counts from the backend (whole-day, not the capped feed).
            const sc = d.scorecard || {};
            const order = [
                ['candidates','Candidates','neutral'], ['reviewed','Reviewed','info'],
                ['placed','Placed','active'], ['filled','Filled','good'],
                ['deferred','Deferred','info'], ['blocked','Blocked','warn'], ['rejected','Rejected','bad'],
            ];
            el('scorecard').innerHTML = order.map(([k,label,tone]) =>
                `<div class="score"><div class="n t-${tone}" style="color:var(--tone)">${sc[k]||0}</div><div class="l">${label}</div></div>`
            ).join('');
        }

        function renderFeed(target, rows, emptyMsg) {
            if (!rows || !rows.length) { el(target).innerHTML = `<div class="empty">${esc(emptyMsg)}</div>`; return; }
            el(target).innerHTML = rows.map(r => {
                const struct = (r.structure||[]).map(s => chip(s.term)).join(' ');
                const nums = [];
                if (r.limit_price != null) nums.push(`lmt ${'$'+num(r.limit_price,2)}`);
                if (r.fill_price != null) nums.push(`fill ${'$'+num(r.fill_price,2)}`);
                if (r.est_cost != null) nums.push(`est ${'$'+num(r.est_cost,2)}`);
                if (r.realized_pnl != null) nums.push(`P&L ${signed(r.realized_pnl)}`);
                const line2 = [
                    r.intent ? chip(r.intent) : '',
                    r.reason ? chip(r.reason, {dot:true}) : '',
                    struct,
                    nums.length ? `<span class="nums">${nums.join(' · ')}</span>` : '',
                    r.thesis ? `<span data-tip="${esc(r.thesis)}" style="color:var(--faint);cursor:default">📝</span>` : ''
                ].filter(Boolean).join(' ');
                return `<div class="feed-row">
                    <div class="time" data-tip="${esc(r.time||'')}">${ageStr(r.age) || clock(r.time)}</div>
                    <div class="main">
                        <div class="line1"><span class="sym">${esc(r.contract||r.symbol)}</span> ${chip(r.status)}</div>
                        ${line2 ? `<div class="line2">${line2}</div>` : ''}
                    </div>
                </div>`;
            }).join('');
        }
        const signed = v => { const n=Number(v); return `<span class="${n>0?'pos':(n<0?'neg':'')}">${(n>0?'+':'')}$${Math.abs(n).toFixed(2)}</span>`; };

        function renderMonitor(m) {
            el('monitor-badge').textContent = m && m.age != null ? ageStr(m.age) + ' ago' : '';
            if (!m || !m.present) {
                el('monitor').innerHTML = `<div class="empty">No monitor run yet today. It runs every 30 min, 9:30–noon ET, on weekdays.</div>`;
                return;
            }
            const alerts = (m.alerts||[]).map(a => {
                const tone = a.severity === 'critical' ? 'bad' : (a.severity === 'warn' ? 'warn' : 'neutral');
                return `<div class="why-row t-${tone}"><div class="cnt" style="color:var(--tone);font-size:13px">${a.severity==='critical'?'●':'⚠'}</div><div class="meat">${esc(a.message)}</div></div>`;
            }).join('');
            const lines = (m.lines||[]).map(l => `<div style="color:var(--muted);font-size:11.5px;padding:1px 0">${esc(l)}</div>`).join('');
            el('monitor').innerHTML =
                `<div class="feed-row"><div class="main"><div class="line1">${chip(m.severity)} <span style="color:var(--muted)">${esc(m.summary||'')}</span></div></div></div>`
                + alerts
                + (lines ? `<div style="padding:8px 14px;border-top:1px solid var(--border)">${lines}</div>` : '');
        }

        function renderWhy(rows) {
            if (!rows || !rows.length) { el('whynot').innerHTML = `<div class="empty">No blocks recorded today — nothing is being gated.</div>`; return; }
            el('whynot').innerHTML = rows.map(r => {
                const dets = (r.details||[]).map(d => `${esc(d.label)} ×${d.count}`).join(' · ');
                return `<div class="why-row t-${r.reason.tone}">
                    <div class="cnt" style="color:var(--tone)">${r.count}</div>
                    <div class="meat">
                        ${chip(r.reason)}
                        ${dets ? `<div class="dets">${dets}</div>` : ''}
                    </div>
                </div>`;
            }).join('');
        }

        function renderPositions(p) {
            const rows = [...(p.option||[]), ...(p.equity||[])];
            if (!rows.length) { el('positions').innerHTML = `<div class="empty">No open positions.</div>`; return; }
            el('positions').innerHTML = `<table><thead><tr>
                <th>Contract</th><th class="num">Qty</th><th class="num">Avg</th><th class="num">Mark</th><th class="num">Value</th><th class="num">Unreal.</th>
                </tr></thead><tbody>${rows.map(r => `<tr>
                    <td class="sym">${esc(r.contract)} ${r.option_type?chip(r.option_type):''}</td>
                    <td class="num">${num(r.quantity)}</td>
                    <td class="num">${r.average_price!=null?'$'+num(r.average_price,2):'—'}</td>
                    <td class="num">${r.current_price!=null?'$'+num(r.current_price,2):'—'}</td>
                    <td class="num">${r.market_value!=null?'$'+num(r.market_value,2):'—'}</td>
                    <td class="num">${r.unrealized_pnl!=null?signed(r.unrealized_pnl):'—'}</td>
                </tr>`).join('')}</tbody></table>`;
        }

        function renderOrders(o) {
            if (!o || !o.length) { el('orders').innerHTML = `<div class="empty">No working orders.</div>`; return; }
            el('orders').innerHTML = `<table><thead><tr>
                <th>Contract</th><th>Side</th><th class="num">Qty</th><th class="num">Limit</th><th>Status</th><th class="num">Age</th>
                </tr></thead><tbody>${o.map(r => `<tr>
                    <td class="sym">${esc(r.contract)}</td>
                    <td>${r.side?chip(r.side):''} ${r.position_effect?chip(r.position_effect):''}</td>
                    <td class="num">${num(r.quantity)}</td>
                    <td class="num">${r.limit_price!=null?'$'+num(r.limit_price,2):'—'}</td>
                    <td>${chip(r.classification)}</td>
                    <td class="num">${ageStr(r.age)}</td>
                </tr>`).join('')}</tbody></table>`;
        }

        function renderRecon(rec) {
            const caption = `<div class="empty" style="padding:6px 14px 0;color:var(--muted)">Broker's standing option-order ledger (since options went live) — <strong>not today</strong>. See the Scorecard for today's activity.</div>`;
            if (!rec || !rec.rows || !rec.rows.length) { el('reconciliation').innerHTML = `<div class="empty">No broker orders on the ledger.</div>`; el('recon-badge').textContent = ''; return; }
            const conf = rec.conflicts ? `<div class="errbar" style="margin:8px 14px">${rec.conflicts} label conflict(s): a row claims filled but broker truth disagrees. Trace the producer.</div>` : '';
            el('reconciliation').innerHTML = `${caption}<div class="pillrow" style="padding:12px 14px">${rec.rows.map(r =>
                `${chip(r.classification, {dot:true})} <span class="mono" style="color:var(--muted);margin-right:6px">×${r.count}</span>`
            ).join(' ')}</div>${conf}`;
            el('recon-badge').textContent = rec.rows.reduce((s,r)=>s+r.count,0) + ' lifetime';
        }

        function renderEvents(ev) {
            if (!ev || !ev.length) { el('events').innerHTML = `<div class="empty">No system events today.</div>`; return; }
            el('events').innerHTML = ev.map(e => `<div class="feed-row">
                <div class="time" data-tip="${esc(e.time||'')}">${ageStr(e.age)}</div>
                <div class="main"><div class="line1">${chip(e.event)} ${e.symbol?`<span class="sym">${esc(e.symbol)}</span>`:''} ${e.classification?chip(e.classification):''}</div>
                ${e.detail?`<div class="line2"><span style="color:var(--bad)">${esc(e.detail)}</span></div>`:''}</div>
            </div>`).join('');
        }

        // ── poll loop ────────────────────────────────────────
        function setConn(state, label) {
            const c = el('conn'); c.className = 'dot ' + state;
            el('conn-label').textContent = label;
        }
        async function tick() {
            try {
                const res = await fetch('/cockpit/data', {headers: {'Accept':'application/json'}, cache:'no-store'});
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                render(data);
                setConn('live', 'live');
                el('updated').textContent = 'updated ' + new Date().toLocaleTimeString([], {hour12:false});
            } catch (e) {
                setConn(lastData ? 'stale' : 'down', lastData ? 'stale' : 'offline');
                el('updated').textContent = 'last error: ' + e.message;
            }
        }
        function schedule() { clearTimeout(timer); if (!paused) timer = setTimeout(loop, POLL_MS); }
        async function loop() { await tick(); schedule(); }

        el('pause').onclick = () => {
            paused = !paused;
            el('pause').textContent = paused ? '▶ Resume' : '⏸ Pause';
            if (!paused) loop(); else { clearTimeout(timer); setConn('stale','paused'); }
        };
        el('refresh').onclick = () => loop();

        loop();
    })();
    </script>
</body>
</html>
