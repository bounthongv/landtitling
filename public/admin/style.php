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

  .pill { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 999px; font-size: 16px; font-weight: 600; letter-spacing: 0.3px; }
  .pill-issued { background: #fff8e1; color: #f57f17; }
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

  .empty { color: var(--muted); text-align: center; padding: 40px 0; }
  .conf-badge { background: #e8f5e9; color: #2e7d32; border-radius: 999px; font-size: 11px; padding: 3px 10px; font-weight: 600; }
  .ocr-box { background: #f4f4f4; border: 1px solid var(--border); border-radius: 8px; padding: 14px; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 12px; white-space: pre-wrap; max-height: 260px; overflow: auto; }

  @media (max-width: 720px) {
    .side { width: 70px; }
    .side .brand, .side-nav a span, .side-foot { display: none; }
    .side .lang-toggle a { padding: 6px 8px; font-size: 11px; }
    .main { padding: 20px; }
    .cards { grid-template-columns: 1fr; }
    .grid { grid-template-columns: 1fr; }
  }
</style>