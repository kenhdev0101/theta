<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Convex desk · Theta</title>
    <style>
        :root{--bg:#0b1016;--panel:#121a24;--line:#263342;--text:#e7edf5;--muted:#9aaac0;--green:#76d9b5;--amber:#f5c878;--red:#f59b9b;--blue:#9cbff5}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        a{color:var(--blue);text-decoration:none}a:hover{text-decoration:underline}button,select{font:inherit;color:var(--text);background:#192332;border:1px solid var(--line);border-radius:7px;padding:7px 12px;cursor:pointer}
        button:focus-visible,select:focus-visible,a:focus-visible,summary:focus-visible{outline:2px solid var(--green);outline-offset:4px}
        .shell{max-width:1440px;margin:auto;padding:28px 32px 48px}.top,.heading,.section-head,.controls,.status-row{display:flex;align-items:center;justify-content:space-between;gap:16px}
        .top{padding-bottom:22px;border-bottom:1px solid var(--line)}.brand{font-size:18px;font-weight:650;letter-spacing:.04em}.brand span{color:var(--green);margin-right:10px}
        .eyebrow{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.14em}.heading{margin:28px 0 24px}h1{font-size:32px;line-height:1.2;letter-spacing:-.035em;margin:6px 0 9px}h2{font-size:16px;margin:0;font-weight:600}
        p{margin:0}.muted{color:var(--muted)}.small{font-size:12px}.mono{font-variant-numeric:tabular-nums}.read-only{border:1px solid var(--line);border-radius:99px;padding:4px 11px;font-size:11px;letter-spacing:.07em;text-transform:uppercase;color:var(--muted)}
        .controls{justify-content:flex-end;flex-wrap:wrap}.status-row{flex-wrap:wrap;justify-content:flex-start;margin-bottom:22px;font-size:12px}.dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);margin-right:7px}
        .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:24px}.card,.panel{background:var(--panel);border:1px solid var(--line);border-radius:11px}.card{padding:18px 20px}.value{font-size:30px;letter-spacing:-.025em;font-weight:600;margin:8px 0 4px}.card .label{font-size:12px;color:var(--muted)}
        .layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;align-items:start;gap:20px}.main,.aside{display:grid;gap:20px;min-width:0}.panel{overflow:hidden}.section-head{padding:18px 20px;border-bottom:1px solid var(--line);align-items:flex-start}.section-head p{margin-top:4px}.body{padding:18px 20px}
        .scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;text-align:left}th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:500;padding:12px 16px;background:#101822}td{padding:14px 16px;border-top:1px solid var(--line);vertical-align:top;font-size:13px}td:first-child,th:first-child{padding-left:20px}td:last-child,th:last-child{padding-right:20px}
        .symbol{font-weight:650;font-size:14px}.chip{display:inline-block;border:1px solid currentColor;border-radius:5px;font-size:10px;line-height:1.5;letter-spacing:.045em;padding:3px 7px;white-space:nowrap}.good{color:var(--green)}.warn{color:var(--amber)}.bad{color:var(--red)}.neutral{color:var(--muted)}.blue{color:var(--blue)}
        .signal-note{max-width:260px;margin-top:5px}.kv{display:flex;justify-content:space-between;gap:16px;padding:9px 0;font-size:13px}.kv+.kv{border-top:1px solid #223040}.kv span:first-child{color:var(--muted)}.kv span:last-child{text-align:right;overflow-wrap:anywhere}.aside .body{padding-top:10px;padding-bottom:10px}
        .meter{height:5px;background:#233244;border-radius:99px;overflow:hidden;margin:12px 0}.meter>span{display:block;background:var(--green);height:100%;width:0}.note{padding:12px 20px;color:var(--muted);font-size:12px;border-top:1px solid var(--line)}
        .warning{padding:12px 16px;border:1px solid #755f39;background:#251f17;color:var(--amber);border-radius:8px;margin-bottom:14px;font-size:13px}.empty{padding:24px 20px;color:var(--muted);font-size:13px}
        .timeline{list-style:none;margin:0;padding:0}.timeline li{position:relative;padding:13px 20px 13px 35px;border-bottom:1px solid var(--line)}.timeline li:last-child{border:0}.timeline li:before{content:"";position:absolute;left:19px;top:21px;width:5px;height:5px;border-radius:50%;background:var(--blue)}
        .event-title{font-size:13px;margin:3px 0}.timeline time{font-size:11px;color:var(--muted)}details{margin-top:8px;font-size:12px}summary{cursor:pointer;color:var(--blue);width:fit-content}.steps{min-width:220px;padding-top:8px}.steps .kv{font-size:11px;padding:4px 0}.footer{margin-top:24px;font-size:12px;color:var(--muted);max-width:1000px}.filter{font-size:12px;max-width:135px}.banner{display:none}.banner.visible{display:block}.loading{opacity:.6}
        @media(max-width:1050px){.layout{grid-template-columns:1fr}.aside{grid-template-columns:1fr 1fr}.aside .events{grid-column:1/-1}}
        @media(max-width:640px){.shell{padding:18px 14px 32px}.heading{align-items:flex-start;flex-direction:column;gap:18px}h1{font-size:28px}.cards{grid-template-columns:1fr 1fr;gap:10px}.card{padding:14px}.value{font-size:26px}.aside{grid-template-columns:1fr}.controls{justify-content:flex-start}.section-head{padding:15px}.body{padding:15px}.top{padding-bottom:16px}th,td{padding:12px}.status-row{gap:10px}.heading p{max-width:40ch}}
    </style>
</head>
<body>
<div class="shell">
    <nav class="top" aria-label="Desk navigation">
        <a class="brand" href="{{ route('cockpit.convex') }}"><span>θ</span> THETA</a>
        <div class="controls"><span class="read-only">Read-only view</span><a href="{{ route('cockpit') }}" class="small">Account cockpit ↗</a></div>
    </nav>
    <header class="heading">
        <div><div class="eyebrow">Thesis observatory / Convex</div><h1>Convex desk</h1><p class="muted">From surface signal to broker-confirmed outcome.</p></div>
        <div class="controls"><span id="updated" class="small muted">Loading saved evidence…</span><button id="pause" type="button">Pause updates</button><button id="refresh" type="button">Refresh</button></div>
    </header>
    <div id="connection" class="warning banner" role="status"></div>
    <div id="warnings" aria-live="polite"></div>
    <div class="status-row" id="posture"><span class="muted">Reading deployment posture…</span></div>
    <div class="cards" id="metrics" aria-label="Session overview"></div>
    <div class="layout">
        <main class="main">
            <section class="panel" aria-labelledby="signals-title">
                <div class="section-head"><div><h2 id="signals-title">Market observations</h2><p class="muted small">A qualifying signal still needs fresh quotes, capital, and execution checks.</p></div><span id="coverage" class="chip neutral">—</span></div>
                <div class="scroll" tabindex="0" aria-label="Scrollable evidence table"><table><thead><tr><th>Underlying</th><th>Tenor / Δ</th><th>Signal</th><th>Evidence</th></tr></thead><tbody id="signals"><tr><td colspan="4" class="muted">Loading observations…</td></tr></tbody></table></div>
                <div class="note">Signals older than 15 minutes are stale. Most recent tenors are shown for each configured underlying.</div>
            </section>
            <section class="panel" aria-labelledby="runs-title">
                <div class="section-head"><div><h2 id="runs-title">Probe lifecycle</h2><p class="muted small">Active probes, submissions from the last 24h, and the 40 latest records. New York time.</p></div><select id="filter" class="filter" aria-label="Filter probe records"><option value="all">All records</option><option value="active">Active</option><option value="staged">Staged</option><option value="closed">Closed</option></select></div>
                <div class="scroll" tabindex="0" aria-label="Scrollable evidence table"><table><thead><tr><th>Contract / probe</th><th>State</th><th>Entry → exit</th><th>Result / blocker</th></tr></thead><tbody id="runs"><tr><td colspan="4" class="muted">Loading probe records…</td></tr></tbody></table></div>
                <div class="note">Exposure comes from recorded fills. Working or ambiguous orders may still carry risk until the worker confirms broker state. P&L excludes fees; unrealized P&L is unavailable here.</div>
            </section>
        </main>
        <aside class="aside" aria-label="Operational evidence">
            <section class="panel">
                <div class="section-head"><h2>Operating limits</h2><span class="chip neutral">CONFIGURED</span></div>
                <div class="body" id="limits"></div>
                <div class="note" id="entry-window"></div>
            </section>
            <section class="panel">
                <div class="section-head"><h2>Connection & feed</h2></div>
                <div class="body" id="health"></div>
                <div class="note">Last recorded activity is evidence, not a worker heartbeat. A quiet or flat loop may have no recent stewardship event.</div>
            </section>
            <section class="panel events">
                <div class="section-head"><div><h2>Lifecycle events</h2><p class="small muted">Retained observations from the last 24 hours.</p></div></div>
                <ol id="timeline" class="timeline"><li class="muted small">Loading events…</li></ol>
            </section>
        </aside>
    </div>
    <p class="footer" id="evidence-note">This page reads saved worker evidence. It makes no broker requests and exposes no trading controls. The account cockpit can perform broker reads when its snapshot is stale.</p>
</div>
<script>
(() => {
    'use strict';
    const el = id => document.getElementById(id);
    const escape = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const money = v => v === null || v === undefined ? 'Unknown' : new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(v);
    const count = v => v === null || v === undefined ? '—' : escape(v);
    const time = v => !v ? 'Not recorded' : new Intl.DateTimeFormat('en-US',{timeZone:'America/New_York',month:'short',day:'numeric',hour:'numeric',minute:'2-digit',second:'2-digit'}).format(new Date(v)) + ' ET';
    const ago = v => { if(!v) return 'Not recorded'; const s=Math.max(0,Math.round((Date.now()-Date.parse(v))/1000)); return s<60 ? s+'s ago' : s<3600 ? Math.floor(s/60)+'m ago' : s<86400 ? Math.floor(s/3600)+'h ago' : Math.floor(s/86400)+'d ago'; };
    const chip = (label,tone='neutral') => `<span class="chip ${tone}">${escape(label)}</span>`;
    const kv = (k,v) => `<div class="kv"><span>${escape(k)}</span><span class="mono">${escape(v)}</span></div>`;
    const state = s => ({staged:['STAGED','neutral'],entry_pending:['ENTRY WORKING','warn'],held:['HELD','blue'],exit_pending:['EXIT WORKING','warn'],ambiguous:['NEEDS ATTENTION','bad'],closed:['CLOSED','neutral']}[s] || ['UNKNOWN','warn']);
    const active = s => ['entry_pending','held','exit_pending','ambiguous'].includes(s);
    let data=null, paused=false, busy=false, timer=null, lastSuccess=null;

    function renderRuns() {
        if(!data) return;
        const expanded=new Set([...document.querySelectorAll('details[open]')].map(n=>n.dataset.run));
        const filter=el('filter').value;
        const rows=data.runs.filter(r=>filter==='all'||(filter==='active'?active(r.status):r.status===filter));
        el('runs').innerHTML=rows.length ? rows.map(r=>{
            const [label,tone]=state(r.status);
            const contract=r.strike!==null ? `${r.expiration} · $${r.strike} call` : 'Contract not selected';
            const milestones=[['Staged',r.created_at],['Entry submitted',r.submitted_at],['Cancellation requested',r.cancel_requested_at],['Fill owned',r.opened_at],['Thesis expired',r.expired_at],['Exit submitted',r.exit_submitted_at],['Closed',r.closed_at],['Last broker readback',r.reconciled_at]].filter(([,t])=>t);
            const result=r.gross_pnl!==null ? `<span class="${r.gross_pnl>=0?'good':'bad'}">${money(r.gross_pnl)} gross</span>` : r.status==='closed'&&r.entry_filled===0 ? 'No fill' : r.status==='closed' ? 'P&L awaiting complete fills' : '';
            return `<tr><td><span class="symbol">${escape(r.symbol)}</span><div class="small muted">${escape(contract)}</div><div class="small muted">Probe #${escape(r.id)}</div><details data-run="${escape(r.id)}"${expanded.has(String(r.id))?' open':''}><summary>Lifecycle</summary><div class="steps">${milestones.map(([k,v])=>kv(k,time(v))).join('')}${r.cancel_reason?kv('Cancel reason',r.cancel_reason):''}${r.exit_reason?kv('Exit reason',r.exit_reason):''}</div></details></td>
            <td>${chip(label,tone)}<div class="small muted" title="${escape(time(r.updated_at))}">${ago(r.updated_at)}</div></td>
            <td class="mono">${count(r.entry_filled)} → ${count(r.exit_filled)}<div class="small muted">${r.entry_limit!==null?'Limit '+money(r.entry_limit):'No entry limit'}</div><div class="small muted">${r.entry_average!==null?'Buy '+money(r.entry_average):''}${r.exit_average!==null?' / sell '+money(r.exit_average):''}</div></td>
            <td>${result}<div class="signal-note small ${r.status==='ambiguous'?'bad':'muted'}">${escape(r.blocker||(!r.submitted_at?'Awaiting entry checks':''))}</div></td></tr>`;
        }).join('') : '<tr><td colspan="4" class="empty">No recorded probes in this view.</td></tr>';
    }
    function render(d) {
        const s=d.summary,p=d.posture,b=d.broker;
        el('updated').textContent='Updated '+time(d.generated_at);
        el('warnings').innerHTML=d.warnings.map(w=>`<div class="warning">${escape(w)}</div>`).join('');
        el('posture').innerHTML=chip(p.kill_switch?'KILL SWITCH ENGAGED':p.entry_enabled?'LIVE ENTRY ENABLED':'NEW ENTRIES DISABLED',p.kill_switch?'bad':p.entry_enabled?'good':'neutral')+chip(p.ingest_enabled?'INGEST ENABLED':'INGEST DISABLED',p.ingest_enabled?'neutral':'warn')+`<span class="muted">Session ${escape(d.session_date)} · New York</span>`;
        const metrics=[
            ['Active probes',count(s.active_runs),`${count(s.held_contracts)} recorded contracts held`],
            ['Open premium at cost',money(s.open_premium_at_cost),'Filled inventory only; excludes working orders'],
            ['Gross realized · session',money(s.gross_realized_today),s.pnl_pending_runs?`${s.pnl_pending_runs} closed runs need complete fills`:`${count(s.closed_filled_today)} closed, filled probes · before fees`],
            ['Submitted · session',count(s.submissions_today),'Includes cancelled and unfilled entries']
        ];
        el('metrics').innerHTML=metrics.map(([label,value,note])=>`<div class="card"><div class="label">${escape(label)}</div><div class="value mono">${value}</div><div class="small muted">${note}</div></div>`).join('');
        el('coverage').textContent=`${s.fresh_tickers} / ${s.configured_tickers} FRESH`;
        el('coverage').className='chip '+(s.configured_tickers>0&&s.fresh_tickers===s.configured_tickers?'good':'warn');
        el('signals').innerHTML=d.signals.map(g=>{
            if(!g.rows.length)return `<tr><td class="symbol">${escape(g.symbol)}</td><td class="muted">—</td><td>${chip('NO SAVED SIGNAL','warn')}</td><td class="small muted">${escape(g.last_attempt)}<div>${escape(time(g.last_attempt_at))}</div></td></tr>`;
            return g.rows.map((r,i)=>`<tr><td><span class="symbol">${escape(g.symbol)}</span>${i===0?`<div class="small muted signal-note">${escape(g.last_attempt)}</div>`:''}</td><td class="mono">${r.dte} days<div class="small muted">Δ ${r.delta===null?'unknown':r.delta.toFixed(3)}</div></td><td>${chip(!r.fresh?'STALE · '+r.verdict:r.verdict==='ADMIT'?(r.admissible?'QUALIFIES':'INCOMPLETE'):r.verdict,!r.fresh?'warn':r.admissible?'good':r.verdict==='ADMIT'?'warn':'neutral')}<div class="signal-note small muted">${r.reasons.map(escape).join('<br>')||(r.admissible?'Surface gates passed; entry checks still apply.':!r.fresh?'Historical observation; current admission unavailable.':'Gate or delta evidence is incomplete.')}</div></td><td class="small muted" title="${escape(time(r.as_of))}">${ago(r.as_of)}<div>${escape(time(r.as_of))}</div></td></tr>`).join('');
        }).join('')||'<tr><td colspan="4" class="empty">No underlyings configured.</td></tr>';
        el('limits').innerHTML=kv('Per play',money(p.caps.per_play))+kv('Per day',money(p.caps.per_day))+kv('Per underlying',money(p.caps.per_underlying));
        el('entry-window').textContent=p.entry_window;
        const pct=b.used_this_minute===null||b.limit_per_minute<=0?0:Math.min(100,b.used_this_minute/b.limit_per_minute*100);
        el('health').innerHTML=kv('Authentication',d.auth.state)+kv('Grant expiry',time(d.auth.expires_at))+kv('Last successful broker call',ago(b.last_success_at))+kv('Last ingest event',ago(s.last_ingest_at))+kv('Last stewardship readback',ago(s.last_stewardship_at))+kv('Request budget',b.used_this_minute===null?'Unknown':`${b.used_this_minute} / ${b.limit_per_minute} this minute`)+`<div class="meter" aria-hidden="true"><span style="width:${pct}%;background:${pct>=80?'var(--amber)':'var(--green)'}"></span></div>`+kv('Reserved for stewardship',`${b.reserved_for_stewardship} requests / minute`)+kv('Failed reads · retained 15m',`${b.failed_calls_15m} / ${b.observed_calls_15m}`)+Object.entries(b.failure_reasons).map(([k,v])=>kv(k,v)).join('');
        el('timeline').innerHTML=d.timeline.length?d.timeline.map(e=>`<li><time>${escape(time(e.at))}</time><div class="event-title"><strong>${escape(e.symbol)}</strong> · ${escape(e.label)}</div>${e.reason?`<p class="small muted">${escape(e.reason)}</p>`:''}</li>`).join(''):'<li class="muted small">No lifecycle events in the retained window.</li>';
        el('evidence-note').textContent=d.evidence_note+' Updates every 15 seconds while visible. The account cockpit can perform broker reads when its snapshot is stale.';
        renderRuns();
    }
    async function refresh() {
        clearTimeout(timer);
        if(busy) return;
        if(document.hidden){timer=setTimeout(refresh,15000);return;}
        busy=true;el('refresh').disabled=true;
        const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),12000);
        try {
            const response=await fetch(@json(route('cockpit.convex.data', [], false)),{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal});
            if(!response.ok)throw new Error('read failed');
            data=await response.json();render(data);lastSuccess=data.generated_at;
            el('connection').className='warning banner';
        } catch(_) {
            el('connection').textContent='Saved telemetry could not be refreshed.'+(lastSuccess?' Displaying the last successful view from '+time(lastSuccess)+'.':' No current evidence is available.');
            el('connection').className='warning banner visible';
        } finally {
            clearTimeout(timeout);busy=false;el('refresh').disabled=false;
            if(!paused)timer=setTimeout(refresh,15000);
        }
    }
    el('refresh').addEventListener('click',refresh);
    el('pause').addEventListener('click',()=>{paused=!paused;el('pause').textContent=paused?'Resume updates':'Pause updates';el('pause').setAttribute('aria-pressed',String(paused));clearTimeout(timer);if(!paused)refresh();});
    el('filter').addEventListener('change',renderRuns);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden&&!paused)refresh();});
    refresh();
})();
</script>
</body>
</html>
