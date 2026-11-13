<style>
  @font-face {
    font-family: 'Noto Sans Lao';
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url('/fonts/NotoSansLao-Regular.ttf') format('truetype');
  }
  @font-face {
    font-family: 'Noto Sans Lao';
    font-style: normal;
    font-weight: 700;
    font-display: swap;
    src: url('/fonts/NotoSansLao-Bold.ttf') format('truetype');
  }
  :root {
    --green: #1b5e20;
    --green-light: #2e7d32;
    --green-dark: #144016;
    --gold: #f9a825;
    --bg: #eef3ea;
    --card: #ffffff;
    --text: #1a2e1a;
    --muted: #5f6b5f;
    --border: #cbd5c0;
    --accent: #2e7d32;
    --sidebar-bg: #144016;
    --sidebar-text: #ffffff;
    --sidebar-muted: #ffffff;
    --sidebar-hover: rgba(255,255,255,0.12);
    --sidebar-active: rgba(255,255,255,0.2);
    --sidebar-border: rgba(255,255,255,0.1);
  }
  * { box-sizing: border-box; }
  body { font-family: 'Phetsarath OT', 'Noto Sans Lao', 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; line-height: 1.5; font-size: 16px; }
  /* form controls don't inherit font-family by default (browsers force Arial/system) */
  button, input, select, textarea, label { font-family: inherit; }
  .app { display: flex; min-height: 100vh; }

  .side {
    width: 260px;
    background: linear-gradient(180deg, #0d3b10 0%, #2e7d32 100%);
    color: var(--sidebar-text);
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 20px rgba(0,0,0,0.08);
    position: relative;
    z-index: 10;
  }
  .side::before {
    content: '';
    position: absolute;
    top: 0; right: 0; bottom: 0; width: 1px;
    background: linear-gradient(180deg, transparent, rgba(255,255,255,0.15), transparent);
  }

  .side .lang-toggle {
    padding: 12px 20px;
    border-bottom: 1px solid var(--sidebar-border);
    text-align: center;
    background: rgba(0,0,0,0.08);
  }
  .side .lang-toggle a {
    color: var(--sidebar-text);
    text-decoration: none;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s ease;
    letter-spacing: 0.3px;
  }
  .side .lang-toggle a:hover {
    background: rgba(255,255,255,0.18);
    transform: translateY(-1px);
  }
  .side .lang-toggle a.active {
    background: var(--gold);
    color: var(--green);
    box-shadow: 0 2px 8px rgba(249,168,37,0.4);
  }
  .side .lang-toggle span {
    color: var(--sidebar-muted);
    margin: 0 6px;
    font-weight: 300;
  }

  .side .brand {
    padding: 20px;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 0.2px;
    line-height: 1.3;
    border-bottom: 1px solid var(--sidebar-border);
    background: rgba(0,0,0,0.05);
  }

  .side-nav { list-style: none; margin: 0; padding: 16px 8px; flex: 1; overflow-y: auto; }
  .side-nav li { margin-bottom: 4px; }
  .side-nav a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: var(--sidebar-muted);
    text-decoration: none;
    font-size: 16px;
    font-weight: 500;
    border-radius: 10px;
    transition: all 0.2s ease;
    letter-spacing: 0.1px;
  }
  .side-nav a:hover {
    background: var(--sidebar-hover);
    color: var(--sidebar-text);
    transform: translateX(2px);
  }
  .side-nav li.has-sub > a { font-weight: 600; }
  .side-nav .nav-icon {
    width: 20px; height: 20px;
    flex-shrink: 0;
    opacity: 0.8;
    transition: opacity 0.2s;
  }
  .side-nav a:hover .nav-icon { opacity: 1; }

  .side-nav li.active > a,
  .side-nav a.active {
    background: var(--sidebar-active);
    color: var(--sidebar-text);
    box-shadow: inset 3px 0 0 var(--gold);
  }

  .side-nav .sub-nav {
    list-style: none; margin: 4px 0 8px; padding: 0;
    background: rgba(0,0,0,0.1);
    border-radius: 8px;
    border-left: 3px solid transparent;
  }
  .side-nav .sub-nav li { margin: 2px 8px; }
  .side-nav .sub-nav a {
    padding: 10px 12px 10px 36px;
    font-size: 14px;
    font-weight: 400;
    color: var(--sidebar-muted);
    border-radius: 6px;
    border-left: none;
  }
  .side-nav .sub-nav a:hover {
    background: rgba(255,255,255,0.1);
    color: var(--sidebar-text);
    transform: translateX(4px);
  }
  .side-nav .sub-nav a.active {
    background: rgba(255,255,255,0.15);
    color: var(--sidebar-text);
    border-left-color: var(--gold);
  }

  .side-foot {
    margin-top: auto;
    padding: 16px 20px;
    font-size: 13px;
    color: var(--sidebar-muted);
    border-top: 1px solid var(--sidebar-border);
    background: rgba(0,0,0,0.08);
    font-weight: 500;
    letter-spacing: 0.2px;
    line-height: 1.6;
  }
  .side-foot-district { font-weight: 600; color: var(--sidebar-text); font-size: 14px; }
  .side-foot-province { font-size: 12px; opacity: 0.85; }

  .main { flex: 1; padding: 32px 40px; overflow: auto; }
  h1 { font-size: 16px; margin: 0 0 8px; font-weight: 700; letter-spacing: -0.3px; }
  .sub { color: var(--muted); font-size: 16px; margin-bottom: 24px; }

  .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 28px; }
  .card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: transform 0.2s, box-shadow 0.2s;
  }
  .card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,0.08); }
  .card h3 { font-size: 16px; color: var(--muted); margin: 0 0 8px; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 600; }
  .card .num { font-size: 16px; font-weight: 700; color: var(--green); letter-spacing: -0.5px; }

  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 20px; }

  table { width: 100%; border-collapse: collapse; font-size: 16px; }
  th, td { text-align: left; padding: 12px 14px; border-bottom: 1px solid var(--border); vertical-align: top; }
  th { background: #f5f8f5; color: var(--muted); font-weight: 600; font-size: 16px; text-transform: uppercase; letter-spacing: 0.5px; }
  tr { transition: background 0.15s; }
  tr:hover { background: #f7faf6; }

  .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 11px 18px; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; color: #fff; transition: all 0.2s; }
  .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
  .btn:active { transform: translateY(0); }
  .btn-sm { padding: 6px 12px; font-size: 12px; }
  .btn-green { background: var(--green); }
  .btn-green:hover { background: var(--green-dark); }
  .btn-gold { background: var(--gold); color: #1a2e1a; }
  .btn-gold:hover { background: #e6951a; }
  .btn-blue { background: #1565c0; }
  .btn-blue:hover { background: #0d47a1; }
  .btn-red { background: #c62828; }
  .btn-red:hover { background: #9f1239; }
  .btn-outline { background: #fff; color: var(--green); border: 1px solid var(--border); }
  .btn-outline:hover { background: #f3f8f1; }
  /* A class that sets display would otherwise beat the UA rule and un-hide
     elements the page marks [hidden] until their script decides to show them. */
  [hidden] { display: none !important; }

  .pill { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 999px; font-size: 16px; font-weight: 600; letter-spacing: 0.3px; }
  .pill-issued { background: #fff8e1; color: #f57f17; }
  .pill-submitted { background: #e8eaf6; color: #283593; }
  .pill-paid { background: #e8f5e9; color: #2e7d32; }
  .pill-rej { background: #fdecea; color: #c62828; }
  .pill-pending { background: #e3f2fd; color: #1565c0; }
  .pill-unpaid { background: #fdecea; color: #c62828; }
  .pill-partial { background: #fff8e1; color: #f57f17; }
  .pill-overpaid { background: #e0f2f1; color: #00695c; }
  .pill-waived, .pill-refunded, .pill-disputed { background: #eceff1; color: #546e7a; }
  .pill-confirmed, .pill-active { background: #e8f5e9; color: #2e7d32; }
  .pill-draft { background: #eceff1; color: #607d8b; }

  .flash { padding: 14px 18px; border-radius: 8px; font-size: 16px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px; }
  .flash.ok { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
  .flash.err { background: #fdecea; color: #c62828; border: 1px solid #f5c6cb; }

  .form-box { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.03); }
  .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
  .form-group { margin-bottom: 18px; }
  .form-group label { display: block; font-size: 16px; color: var(--muted); margin-bottom: 6px; font-weight: 500; }
  .form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 8px; font-size: 16px;
    background: #fff; transition: border-color 0.2s, box-shadow 0.2s;
  }
  .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline: none; border-color: var(--green); box-shadow: 0 0 0 3px rgba(27,94,32,0.12);
  }

  /* File inputs: the browser draws "Choose File / No file chosen" in its own
     locale, which ignores the page language. The native input is made
     invisible over our own button + filename text instead. */
  .file-field { position: relative; display: flex; align-items: center; gap: 10px; width: 100%; min-height: 46px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 8px; background: #fff; cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s; }
  .file-field:hover { border-color: var(--green-light); }
  .file-field:focus-within { border-color: var(--green); box-shadow: 0 0 0 3px rgba(27,94,32,0.12); }
  .file-field input[type="file"] { position: absolute; inset: 0; width: 100%; height: 100%; padding: 0; border: 0; opacity: 0; cursor: pointer; }
  .file-field .file-btn { flex: 0 0 auto; padding: 7px 14px; border-radius: 6px; background: var(--green); color: #fff; font-size: 15px; font-weight: 600; }
  .file-field .file-name { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); }
  .file-field .file-name.has-file { color: var(--text); }
  .entry-tools .file-field { flex: 1 1 220px; }

  .modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 100; backdrop-filter: blur(2px); }
  .modal-bg.show { display: flex; }
  .modal { background: #fff; border-radius: 16px; padding: 28px; max-width: 560px; width: 94%; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }

  /* Logout link — red tint to distinguish from nav items */
  .side-nav li.logout-li a { color: #ef9a9a; }
  .side-nav li.logout-li a:hover { color: #ffcdd2; background: rgba(198,40,40,0.15); }
  .modal h2 { margin-top: 0; font-size: 20px; font-weight: 700; }
  .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }

  /* The dialog scrolls its body so Cancel/Save stay reachable on long forms. */
  .modal { display: flex; flex-direction: column; max-height: 92vh; }
  .modal #modal-content { overflow-y: auto; }
  .modal .form-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
  .modal .form-group { margin-bottom: 12px; }
  .modal .form-group:last-child { margin-bottom: 0; }
  /* Long option lists (148 districts) scroll instead of covering the screen. */
  select { max-height: 260px; }
  .modal input[readonly] { background: #f2f4f1; color: var(--muted); cursor: not-allowed; }

  /* Page toolbar: section title on the left, primary action on the right. */
  .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
  .toolbar h2 { margin: 0; font-size: 18px; font-weight: 700; }
  .table-actions { white-space: nowrap; }
  .table-actions form { display: inline; }

  /* Reports: one heading band per level so a province or village is not
     repeated on every row, and a footer summing each group. */
  tr.band td { background: #e8f0ea; font-weight: 700; color: var(--ink, #144016); }
  tr.band .band-tag { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); font-weight: 600; margin-right: 8px; }
  tr.band-lvl-1 td { padding-left: 28px; background: #eef3ea; }
  tr.band-lvl-2 td { padding-left: 46px; background: #f4f7f1; }
  tr.subtotal td { background: #f7faf5; font-weight: 700; border-top: 2px solid #c9d6c5; }
  tr.subtotal .sub-tag { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); font-weight: 600; margin-right: 6px; }
  tr.subtotal .sub-count { font-weight: 400; color: var(--muted); margin-left: 6px; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; }

  .empty { color: var(--muted); text-align: center; padding: 40px 0; }
  .conf-badge { background: #e8f5e9; color: #2e7d32; border-radius: 999px; font-size: 11px; padding: 3px 10px; font-weight: 600; }
  /* Scan review: parsed-field table with a per-field OCR confidence badge. */
  .parsed-table { width: 100%; border-collapse: collapse; font-size: 16px; }
  .parsed-table td { padding: 5px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  .parsed-table td:first-child { width: 130px; color: #555; }
  .conf { display: inline-block; min-width: 38px; text-align: center; padding: 1px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; }
  .conf-high { background: #c8e6c9; color: #1b5e20; }
  .conf-mid  { background: #fff3c4; color: #7a5c00; }
  .conf-low  { background: #ffcdd2; color: #b71c1c; }
  .conf-none { background: #eee; color: #888; }
  .ocr-box { background: #f4f4f4; border: 1px solid var(--border); border-radius: 8px; padding: 14px; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 12px; white-space: pre-wrap; max-height: 260px; overflow: auto; }

  /* Payment cards: details on the left, actions on the right; stacked on phones. */
  .pay-split { display: grid; grid-template-columns: 1fr auto; gap: 12px 20px; align-items: start; }
  .pay-split > * { min-width: 0; }
  .pay-actions { display: flex; flex-direction: column; gap: 8px; align-self: start; }
  /* Parcel entry: map/lookup column beside the entry form. */
  .split-340 { display: grid; grid-template-columns: 340px 1fr; gap: 16px; }
  /* Reports: the period picker beside the printed title and signature boxes. */
  .per-split { display: grid; grid-template-columns: 440px 1fr; gap: 16px 24px; align-items: start; }
  .per-split > * { min-width: 0; }
  .per-head { font-size: 16px; font-weight: 700; color: var(--muted); letter-spacing: .04em; margin-bottom: 6px; }
  .per-hint { font-size: 16px; color: var(--muted); margin: -2px 0 8px; }
  .per-row { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 8px; padding: 5px 0; cursor: pointer; }
  .per-row .per-lbl { min-width: 96px; font-size: 16px; }
  .per-in { display: flex; align-items: center; gap: 6px; }
  .per-in select { width: auto; }
  .per-row input[type="radio"] { width: 16px; height: 16px; margin: 0; flex: 0 0 auto; accent-color: var(--green); }
  .per-in input, .per-in select { padding: 7px 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; background: #fff; }
  .per-in input[type="date"] { width: 140px; }
  .per-sep { font-size: 12px; color: var(--muted); }

  /* ---- Manager monitoring overview (monitor.php) ------------------------ */
  .monitor-head { margin-bottom: 16px; }
  .monitor-year { display: flex; align-items: center; gap: 8px; }
  .monitor-year label { font-size: 13px; font-weight: 600; color: var(--muted); }
  .monitor-year select {
    padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px;
    background: #fff; font-size: 15px;
  }
  .monitor-filter-chip { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 0 0 14px; }
  .monitor-filter-chip a { font-size: 14px; font-weight: 600; color: var(--green); text-decoration: none; }
  .monitor-filter-chip a:hover { text-decoration: underline; }

  .monitor-hero {
    background: linear-gradient(135deg, #0d3b10 0%, #2e7d32 62%, #3c8a41 100%);
    color: #fff; border-radius: 16px; padding: 22px 24px;
    box-shadow: 0 10px 28px rgba(20,64,22,0.25); margin-bottom: 16px;
  }
  .monitor-hero .mh-label {
    font-size: 12px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1px; color: rgba(255,255,255,0.75);
  }
  .monitor-hero .big {
    font-size: clamp(32px, 7vw, 46px); font-weight: 700; letter-spacing: -1px;
    line-height: 1.1; margin: 4px 0 2px; color: #fff;
  }
  .monitor-hero .mh-line { font-size: 15px; color: rgba(255,255,255,0.92); }
  .monitor-hero .mh-line b { color: #ffe082; }
  .monitor-hero .mh-sub {
    display: block; font-size: 12px; text-transform: uppercase;
    letter-spacing: 0.6px; color: rgba(255,255,255,0.6); margin-top: 2px;
  }
  .mh-meta {
    display: flex; flex-wrap: wrap; gap: 6px 18px; margin-top: 10px;
    font-size: 12.5px; color: rgba(255,255,255,0.8);
  }
  .mbar { height: 10px; border-radius: 999px; background: rgba(255,255,255,0.2); overflow: hidden; margin-top: 12px; }
  .mbar i { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #f9a825, #ffd54f); }

  .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
  .kpi {
    display: flex; flex-direction: column; gap: 3px;
    background: var(--card); border: 1px solid var(--border);
    border-left: 4px solid var(--green-light); border-radius: 12px;
    padding: 14px 16px; text-decoration: none; color: var(--text);
    box-shadow: 0 1px 4px rgba(0,0,0,0.04); transition: transform 0.2s, box-shadow 0.2s;
  }
  .kpi:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.08); }
  .kpi.alert { border-left-color: #c62828; }
  .kpi .label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--muted); }
  .kpi .value { font-size: 22px; font-weight: 700; color: var(--green); letter-spacing: -0.5px; line-height: 1.2; }
  .kpi .value.sm { font-size: 18px; }
  .kpi .value small { font-size: 12px; font-weight: 600; color: var(--muted); letter-spacing: 0; }
  .kpi .hint { font-size: 12.5px; color: var(--muted); }

  .monitor-section {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);
  }
  .monitor-section h2 { margin: 0 0 12px; font-size: 15px; font-weight: 700; }

  /* "Install app" prompt on the manager overview. Hidden until the browser
     offers an install (Android/desktop) or the phone is an iPhone, where the
     only way is Share > Add to Home Screen. */
  .install-banner {
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    background: #fffaf0; border: 1px solid #f0d9a0; border-left: 4px solid var(--gold);
    border-radius: 12px; padding: 12px 16px; margin-bottom: 16px;
  }
  .install-banner b { color: var(--green-dark); font-size: 15px; }
  .install-banner p { margin: 2px 0 0; font-size: 13.5px; color: var(--muted); }
  .install-actions { display: flex; gap: 8px; flex-wrap: wrap; }

  .pipeline { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
  .pc {
    display: flex; flex-direction: column; align-items: flex-start; gap: 4px;
    padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px;
    background: #fbfdfa; text-decoration: none; color: var(--text);
    transition: border-color 0.2s, background 0.2s;
  }
  .pc:hover { border-color: var(--green-light); background: #f3f8f1; }
  .pc .n { font-size: 22px; font-weight: 700; color: var(--green); line-height: 1.1; }
  .pc .hint { font-size: 12px; color: var(--muted); }

  .breakdown-row {
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px;
    padding: 10px 2px; border-bottom: 1px solid #eef1ec; text-decoration: none; color: var(--text);
  }
  .breakdown-row:last-of-type { border-bottom: none; }
  a.breakdown-row:hover .name { color: var(--green); }
  .breakdown-row .name { order: 1; flex: 1 1 auto; min-width: 90px; font-size: 14.5px; font-weight: 600; }
  .breakdown-row .nums { order: 2; flex: 0 1 auto; text-align: right; font-size: 13px; color: var(--muted); }
  .breakdown-row .nums b { color: var(--green); }
  .breakdown-row .bar {
    order: 3; flex: 1 1 100%; height: 7px; border-radius: 999px;
    background: #e8efe5; overflow: hidden;
  }
  .breakdown-row .bar i { display: block; height: 100%; border-radius: 999px; background: var(--green-light); }
  .nums-sub { display: block; font-size: 11.5px; color: var(--muted); }
  .link-more { margin: 12px 0 0; font-size: 14px; }
  .link-more a { color: var(--green); font-weight: 600; text-decoration: none; }
  .link-more a:hover { text-decoration: underline; }

  .aging-row { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 12px; padding: 8px 2px; border-bottom: 1px solid #eef1ec; }
  .aging-row:last-of-type { border-bottom: none; }
  .aging-row .ar-label { flex: 0 0 92px; font-size: 13.5px; font-weight: 600; }
  .aging-row .bar { flex: 1 1 60px; height: 7px; border-radius: 999px; background: #f5ecda; overflow: hidden; }
  .aging-row .bar i { display: block; height: 100%; border-radius: 999px; background: #e6951a; }
  .aging-row .ar-num { flex: 0 1 auto; text-align: right; font-size: 13px; color: var(--muted); }

  .receipt-row {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 12px; padding: 10px 2px; border-bottom: 1px solid #eef1ec;
  }
  .receipt-row:last-of-type { border-bottom: none; }
  .rr-left { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
  .rr-left a { color: var(--green); font-weight: 600; text-decoration: none; }
  .rr-right { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; text-align: right; flex: 0 0 auto; }
  .rr-right b { font-size: 14px; color: var(--green); }
  .receipt-row .sub { margin: 0; font-size: 12px; }

  @media (max-width: 720px) {
    /* The sidebar becomes a sticky, horizontally scrolling top bar with labels:
       icon-only rails are unusable for officers who do not know the screens. */
    .app { flex-direction: column; }
    .side {
      width: 100%;
      flex-direction: row;
      align-items: center;
      position: sticky;
      top: 0;
      z-index: 90;
      box-shadow: 0 2px 12px rgba(0,0,0,0.18);
    }
    .side::before { display: none; }
    .side .brand, .side-foot { display: none; }
    .side .lang-toggle { flex: 0 0 auto; padding: 6px 8px; border-bottom: none; background: transparent; }
    .side .lang-toggle a { padding: 5px 6px; font-size: 11px; }
    .side-nav {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 4px;
      padding: 6px 8px;
      overflow-x: auto;
      overflow-y: hidden;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
    }
    .side-nav::-webkit-scrollbar { display: none; }
    .side-nav li { margin: 0; flex: 0 0 auto; }
    /* Manager detail screens that only make sense on a desktop sidebar. */
    .side-nav li.nav-desktop-only { display: none; }
    .side-nav a { padding: 8px 10px; font-size: 13px; border-radius: 8px; white-space: nowrap; gap: 6px; }
    .side-nav a:hover { transform: none; }
    .side-nav .nav-icon { width: 18px; height: 18px; }
    .side-nav .sub-nav {
      display: flex; flex-direction: row; gap: 2px;
      margin: 0; padding: 0; background: transparent; border: none;
    }
    .side-nav .sub-nav li { margin: 0; }
    .side-nav .sub-nav a { padding: 8px; font-size: 12px; }
    .side-nav .sub-nav a:hover { transform: none; }

    .main { padding: 16px 12px 40px; }
    h1 { font-size: 18px; }
    .sub { font-size: 14px; margin-bottom: 16px; }
    .cards { grid-template-columns: 1fr 1fr; gap: 10px; }
    .card { padding: 14px; }
    .grid { grid-template-columns: 1fr; }
    .form-box { padding: 14px; }
    .form-grid { grid-template-columns: 1fr; }
    .pay-split { grid-template-columns: 1fr; }
    .pay-actions { flex-direction: row; flex-wrap: wrap; }
    .split-340 { grid-template-columns: 1fr; }
    .per-split { grid-template-columns: 1fr; }
    .per-row .per-lbl { min-width: 84px; }
    .per-in { flex-wrap: wrap; }
    .bank-strip { grid-template-columns: 1fr 1fr; }
    .pay-tabs { gap: 6px; }
    .pay-tabs a { padding: 10px 12px; font-size: 14px; flex: 1 1 auto; text-align: center; }
    .toolbar { flex-direction: column; align-items: stretch; }
    /* Monitoring overview: two KPI columns, full-width year picker, tighter cards. */
    .monitor-year { width: 100%; justify-content: space-between; }
    .monitor-year select { flex: 1 1 auto; }
    .install-banner { flex-direction: column; align-items: stretch; }
    .install-actions .btn { flex: 1 1 auto; }
    .monitor-hero { padding: 18px; border-radius: 14px; }
    .kpi-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
    .kpi { padding: 12px; }
    .kpi .value { font-size: 19px; }
    .kpi .value.sm { font-size: 16px; }
    .monitor-section { padding: 14px; }
    .pipeline { grid-template-columns: 1fr 1fr; }
    .breakdown-row .nums { flex: 1 1 100%; text-align: left; }
    .receipt-row { flex-wrap: wrap; }
    .rr-right { align-items: flex-start; text-align: left; }
    /* Wide registry tables scroll sideways instead of squashing their columns. */
    table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; white-space: nowrap; }
    th, td { padding: 10px 12px; font-size: 14px; }
    .modal { width: 100%; max-width: 100%; max-height: 100dvh; border-radius: 0; padding: 18px; }
    .modal-actions { flex-direction: column-reverse; }
    .modal-actions .btn { width: 100%; }
    .btn { min-height: 44px; }
    .btn-sm { min-height: 36px; }
    /* iOS zooms the whole page when a focused control is under 16px. */
    input, select, textarea { font-size: 16px; }
  }
</style>
<?php
// Managers install a second, read-only app: its own name, icon and start page,
// so a manager's phone shows "Tax Manager" beside the officer app instead of
// replacing it. The manifest id keeps the two installs apart on the same origin;
// both share this service worker (sw.js), which caches only icons and manifests.
$managerPwa = (isset($role) && $role === 'manager');
?>
<link rel="manifest" href="<?= $managerPwa ? 'manifest-manager.webmanifest' : 'manifest.webmanifest' ?>">
<link rel="icon" href="<?= $managerPwa ? 'icon-manager-192.png' : 'icon-192.png' ?>" sizes="192x192">
<link rel="apple-touch-icon" href="<?= $managerPwa ? 'apple-touch-icon-manager.png' : 'apple-touch-icon.png' ?>">
<meta name="theme-color" content="#1b5e20">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= $managerPwa ? 'Tax Manager' : 'Land Tax' ?>">
<script>
// Installable back-office. The worker caches only the manifest and icons; the
// officer's authenticated pages are never stored on a shared phone (see sw.js).
if ('serviceWorker' in navigator) {
  addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
}

// Installed app (the Tax Manager TWA, or a home-screen PWA): target="_blank"
// links (report preview/print, payment slips) open in a separate Custom Tab —
// i.e. a second browser window, which looks like the app restarted. Inside the
// installed app keep same-origin links in the app itself; the print pages carry
// their own "Back to reports" link, so nothing is lost. Normal browser tabs keep
// the new-tab behaviour.
(function () {
  var modes = ['standalone', 'minimal-ui', 'fullscreen'];
  var installed = modes.some(function (m) {
    return window.matchMedia && window.matchMedia('(display-mode: ' + m + ')').matches;
  }) || window.navigator.standalone === true;
  if (!installed) return;
  function run() {
    var links = document.querySelectorAll('a[target="_blank"]');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      try {
        if (new URL(a.href, location.href).origin === location.origin) a.removeAttribute('target');
      } catch (e) {}
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
})();

// Localize every file picker: the native caption follows the OS/browser
// locale, so the input sits invisibly over a translated button + file name.
(function () {
  var choose = <?= json_encode(t('Choose file'), JSON_UNESCAPED_UNICODE) ?>;
  var none   = <?= json_encode(t('No file chosen'), JSON_UNESCAPED_UNICODE) ?>;
  var files  = <?= json_encode(t('files'), JSON_UNESCAPED_UNICODE) ?>;
  function label(input, name) {
    var n = input.files ? input.files.length : 0;
    name.textContent = n === 0 ? none : (n === 1 ? input.files[0].name : n + ' ' + files);
    name.classList.toggle('has-file', n > 0);
  }
  function enhance(input) {
    if (input.closest('.file-field')) return;
    var wrap = document.createElement('span');
    wrap.className = 'file-field';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var btn = document.createElement('span');
    btn.className = 'file-btn';
    btn.textContent = choose;
    var name = document.createElement('span');
    name.className = 'file-name';
    wrap.appendChild(btn);
    wrap.appendChild(name);
    label(input, name);
    input.addEventListener('change', function () { label(input, name); });
  }
  function run() {
    var list = document.querySelectorAll('input[type="file"]');
    for (var i = 0; i < list.length; i++) enhance(list[i]);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
})();
</script>