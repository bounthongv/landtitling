<style>
  @font-face {
    font-family: 'Noto Sans Lao';
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url('fonts/NotoSansLao-Regular.ttf') format('truetype');
  }
  @font-face {
    font-family: 'Noto Sans Lao';
    font-style: normal;
    font-weight: 700;
    font-display: swap;
    src: url('fonts/NotoSansLao-Bold.ttf') format('truetype');
  }
  :root {
    --green: #1b5e20;
    --gold: #f9a825;
    --bg: #eef3ea;
    --card: #ffffff;
    --text: #1a2e1a;
    --muted: #5f6b5f;
    --border: #cbd5c0;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: 'Phetsarath OT', 'Noto Sans Lao', 'Segoe UI', sans-serif;
    background: var(--bg);
    color: var(--text);
    -webkit-font-smoothing: antialiased;
  }
  /* form controls don't inherit font-family by default (browsers force Arial/system) */
  button, input, select, textarea, label { font-family: inherit; }
  .app { max-width: 480px; margin: 0 auto; min-height: 100vh; }
  header.app-header {
    background: var(--green);
    color: #fff;
    padding: 14px 16px;
    position: sticky;
    top: 0;
    z-index: 10;
    box-shadow: 0 2px 8px rgba(0,0,0,.18);
  }
  header.app-header .title { font-size: 17px; font-weight: 700; }
  header.app-header .sub { font-size: 12px; opacity: .85; }
  main { padding: 14px 16px 96px; }
  .card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 14px;
  }
  .card h2 { font-size: 17px; margin: 0 0 4px; color: var(--green); }
  .card .sub { color: var(--muted); font-size: 13px; margin-bottom: 14px; }
  .form-group { margin-bottom: 14px; }
  .form-group label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
  .form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 11px; border: 1px solid var(--border); border-radius: 6px;
    font-size: 15px; font-family: inherit;
  }
  .form-group input:focus, .form-group select:focus { outline: 2px solid var(--gold); border-color: var(--green); }
  .btn {
    display: inline-block; background: var(--green); color: #fff;
    padding: 12px 18px; border: none; border-radius: 6px; font-size: 15px;
    cursor: pointer; text-decoration: none; width: 100%;
  }
  .btn:hover { background: #2e7d32; }
  .btn:disabled { background: #9e9e9e; cursor: not-allowed; }
  .btn-gold { background: var(--gold); color: #1a2e1a; }
  .btn-gold:hover { background: #f59e0b; }
  .btn-block { width: 100%; }
  .row { display: flex; gap: 12px; }
  .row > * { flex: 1; }
  .grid { display: grid; grid-template-columns: 1fr; gap: 14px; }
  @media (min-width: 600px) { .grid { grid-template-columns: 1fr 1fr; } .row { flex-direction: row; } }
  .pill {
    display: inline-block; padding: 3px 10px; border-radius: 999px;
    font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
  }
  .pill-isued { background: #fff3cd; color: #856404; }
  .pill-paid { background: #d4edda; color: #155724; }
  .pill-rej { background: #f8d7da; color: #721c24; }
  .pill-pending { background: #e8f0fe; color: #1565c0; }
  .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
  @media (min-width: 600px) { .stats { grid-template-columns: 1fr 1fr 1fr 1fr; } }
  .stat {
    background: var(--card); border: 1px solid var(--border); border-radius: 8px;
    padding: 14px; text-align: center;
  }
  .stat .num { font-size: 22px; font-weight: 700; color: var(--green); }
  .stat .lbl { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
  .qr-box { background: #f4f4f4; border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
  .qr-box img { max-width: 140px; height: auto; }
  .zone-bar { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
  .zone-bar .bar { flex: 1; height: 22px; background: #e0e0e0; border-radius: 4px; overflow: hidden; }
  .zone-bar .fill { height: 100%; border-radius: 4px; }
  .list { list-style: none; margin: 0; padding: 0; }
  .list li { border-bottom: 1px solid var(--border); padding: 12px 0; }
  .list li:last-child { border-bottom: none; }
  .list .name { font-weight: 600; }
  .empty { text-align: center; color: var(--muted); padding: 30px 0; }
  .tab-nav { display: flex; gap: 8px; margin: 12px 0 16px; background: #f0f4ee; border-radius: 8px; padding: 4px; }
  .tab-nav button { flex: 1; padding: 10px; border: none; background: transparent; font-size: 14px; border-radius: 6px; cursor: pointer; color: var(--muted); }
  .tab-nav button.active { background: #fff; color: var(--green); font-weight: 700; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
  .slip-container { max-width: 400px; margin: 0 auto; background: #fff; border: 1px solid var(--border); border-radius: 8px; padding: 16px; }
  .slip-table { width: 100%; border-collapse: collapse; font-size: 14px; }
  .slip-table td { padding: 8px 4px; border-bottom: 1px solid var(--border); }
  .slip-table td:first-child { font-weight: 600; color: var(--muted); width: 42%; }
  .slip-table .amount { font-size: 18px; font-weight: 700; text-align: right; }
  .foot-note { font-size: 11px; color: var(--muted); margin-top: 12px; text-align: center; }
  .hidden { display: none; }
  .toast {
    position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(80px);
    background: #333; color: #fff; padding: 12px 20px; border-radius: 8px;
    font-size: 14px; z-index: 200; opacity: 0; transition: all .3s; max-width: 90%;
  }
  .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
  .alert { padding: 10px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
  .alert.err { background: #fdecea; color: #b71c1c; border: 1px solid #f5c6cb; }
  .alert.ok  { background: #eaf4ea; color: #1b5e20; border: 1px solid #bbdefb; }
  /* Obligation statuses (annual fee, not payment status) */
  .pill-unpaid   { background: #fdecea; color: #b71c1c; }
  .pill-partial  { background: #fff3cd; color: #856404; }
  .pill-overpaid { background: #e1f5fe; color: #01579b; }
  .pill-waived, .pill-refunded, .pill-disputed { background: #eceff1; color: #546e7a; }
  .ref-box { font-family: monospace; font-size: 15px; letter-spacing: .5px; }
</style>
