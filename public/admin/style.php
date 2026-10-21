<style>
  :root { --green: #1b5e20; --gold: #f9a825; --bg: #eef3ea; --card: #ffffff; --text: #1a2e1a; --muted: #5f6b5f; --border: #cbd5c0; --accent: #2e7d32; }
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', 'Noto Sans Lao', sans-serif; background: var(--bg); color: var(--text); margin: 0; }
  .app { display: flex; min-height: 100vh; }
  .side { width: 230px; background: var(--green); color: #fff; display: flex; flex-direction: column; }
  .side .brand { padding: 14px 20px 6px; font-size: 15px; font-weight: 700; letter-spacing: .3px; }
  .side .lang-toggle { padding: 10px 20px; font-size: 12px; border-bottom: 1px solid rgba(255,255,255,.15); margin-bottom: 4px; }
  .side .lang-toggle a { color: #d8e6d2; text-decoration: none; padding: 2px 6px; border-radius: 3px; }
  .side .lang-toggle a:hover { background: rgba(255,255,255,.15); color: #fff; }
  .side .lang-toggle a.active { background: #ffd54f; color: #1b5e20; font-weight: 700; }
  .side .lang-toggle span { color: #8fae8f; margin: 0 2px; }
  .side-nav { list-style: none; margin: 0; padding: 12px 0; }
  .side-nav li { border-bottom: 1px solid rgba(255,255,255,.18); }
  .side-nav a { display: block; padding: 11px 20px; color: #d8e6d2; text-decoration: none; font-size: 14px; }
  .side-nav a:hover { background: rgba(255,255,255,.15); color: #fff; }
  .side-nav li.has-sub > a { font-weight: 600; color: #fff; }
  /* Nested submenu (e.g. Basic Data -> Province / District / Village / Zone) */
  .side-nav .sub-nav { list-style: none; margin: 0; padding: 0 0 6px; background: rgba(0,0,0,.12); }
  .side-nav .sub-nav li { border-bottom: none; }
  .side-nav .sub-nav a { padding: 8px 20px 8px 34px; font-size: 13px; color: #bcd4b8; border-left: 3px solid transparent; }
  .side-nav .sub-nav a:hover { background: rgba(255,255,255,.12); }
  .side-nav .sub-nav a.active { background: rgba(255,255,255,.18); color: #fff; border-left-color: #ffd54f; font-weight: 600; }
  .side-foot { margin-top: auto; padding: 14px 20px; font-size: 12px; color: #bfd6c2; }
  .lang-switch { margin-top: 8px; font-size: 12px; }
  .lang-switch a { color: #d8e6d2; text-decoration: none; padding: 2px 6px; border-radius: 3px; }
  .lang-switch a:hover { background: rgba(255,255,255,.15); color: #fff; }
  .lang-switch a.active { background: #ffd54f; color: #1b5e20; font-weight: 700; }
  .lang-switch span { color: #8fae8f; margin: 0 2px; }
  .main { flex: 1; padding: 24px 28px; overflow: auto; }
  h1 { font-size: 22px; margin: 0 0 6px; }
  .sub { color: var(--muted); font-size: 13px; margin-bottom: 16px; }
  .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 22px; }
  .card { background: var(--card); border: 1px solid var(--border); border-radius: 6px; padding: 16px; }
  .card h3 { font-size: 13px; color: var(--muted); margin: 0 0 4px; text-transform: uppercase; letter-spacing: .5px; }
  .card .num { font-size: 24px; font-weight: 700; color: var(--green); }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
  th { background: #f0f4ee; color: var(--muted); font-weight: 600; }
  /* Sortable column headers: the whole label is a link that toggles asc/desc. */
  th a.th-sort { color: inherit; text-decoration: none; white-space: nowrap; }
  th a.th-sort:hover { color: #1565c0; text-decoration: underline; }
  th a.th-sort .arrow { color: #1565c0; font-size: 11px; }
  tr:hover { background: #f7faf6; }
  .btn { display: inline-block; padding: 7px 12px; border-radius: 4px; font-size: 13px; cursor: pointer; border: none; text-decoration: none; color: #fff; }
  .btn-green { background: var(--green); } .btn-green:hover { background: #144016; }
  .btn-gold { background: var(--gold); } .btn-gold:hover { background: #d49a0a; }
  .btn-blue { background: #1565c0; } .btn-blue:hover { background: #0d47a1; }
  .btn-red { background: #c62828; } .btn-red:hover { background: #9f1239; }
  .btn-sm { padding: 4px 8px; font-size: 12px; }
  .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .pill-issued { background: #fff3cd; color: #856404; }
  .pill-paid { background: #d4edda; color: #155724; }
  .pill-rej { background: #f8d7da; color: #721c24; }
  .pill-pending { background: #e8f0fe; color: #1565c0; }
  .pill-unpaid { background: #f8d7da; color: #721c24; }
  .pill-partial { background: #fff3cd; color: #856404; }
  .pill-overpaid { background: #e1f5fe; color: #01579b; }
  .pill-waived, .pill-refunded, .pill-disputed { background: #eceff1; color: #546e7a; }
  .pill-confirmed, .pill-active { background: #d4edda; color: #155724; }
  .pill-draft { background: #eceff1; color: #607d8b; }
  .flash { padding: 10px 14px; border-radius: 4px; font-size: 13px; margin-bottom: 14px; }
  .flash.ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
  .flash.err { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
  .form-box { background: var(--card); border: 1px solid var(--border); border-radius: 6px; padding: 18px; margin-bottom: 18px; }
  .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
  .form-group { margin-bottom: 14px; }
  .form-group label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 4px; }
  .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 4px; font-size: 14px; }
  .modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); align-items: center; justify-content: center; z-index: 100; }
  .modal-bg.show { display: flex; }
  .modal { background: #fff; border-radius: 8px; padding: 22px; max-width: 560px; width: 94%; }
  .modal h2 { margin-top: 0; }
  .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }
  .empty { color: var(--muted); text-align: center; padding: 24px 0; }
  .conf-badge { background: #d4edda; color: #155724; border-radius: 999px; font-size: 12px; padding: 2px 8px; }
  .conf { display: inline-block; border-radius: 999px; font-size: 11px; padding: 1px 7px; font-weight: 600; }
  .conf-high { background: #d4edda; color: #155724; }
  .conf-mid  { background: #fff3cd; color: #856404; }
  .conf-low  { background: #f8d7da; color: #721c24; }
  .conf-none { background: #eceff1; color: #607d8b; }
  .parsed-table td { padding: 5px 8px; font-size: 13px; }
  .parsed-table td:first-child { color: var(--muted); white-space: nowrap; }
  .owner-row { display: grid; grid-template-columns: 2fr 1.2fr 2fr auto; gap: 8px; align-items: end; margin-bottom: 8px; }
  .owner-row .form-group { margin-bottom: 0; }
  .ocr-box { background: #f4f4f4; border: 1px solid var(--border); border-radius: 4px; padding: 10px; font-family: monospace; font-size: 12px; white-space: pre-wrap; max-height: 220px; overflow: auto; }
  @media (max-width: 720px) { .side { width: 60px; } .side .brand, .side-nav a span, .side-foot { display: none; } .side { padding: 8px 0; } .main { padding: 16px; } }
</style>
