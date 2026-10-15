
The Land Titling prototype — local code
Location: D:\LandTitling\prototype\
- prototype/index.html — officer back-office
- prototype/citizen/index.html — citizen portal PWA
- prototype/citizen/manifest.json — PWA manifest

It is NOT really python-based. It's pure HTML/CSS/JS (same as our current project). The only Python file is a small helper:
- prototype/build/gen_qr.py — generates QR codes for title parcels

That was it for Python — no Python web framework, no Python backend.

Its database
None. The prototype is demo-only — no database at all (AGENTS.md confirms: "demo-only, no backend, no database"). There is no Land Titling prototype DB on apis.com.la.

Databases that actually exist on apis.com.la
| Database | Purpose                                                                                  |
|----------|------------------------------------------------------------------------------------------|
| landfee  | Our current project (Land Fee Payment MVP) — 15 districts, 1,029 villages, 60 zone rates |
| postgres | System database (normal PostgreSQL default)                                              |

That's it — only those two.

So to summarize
- The Land Titling prototype lives in prototype/ under the same repo, was static HTML/CSS/JS (one Python QR helper), and had no database.
- The current live system (Land Fee MVP) is a separate codebase in api/, lib/, public/, sql/, deployed to https://landfee.apis.com.la, backed by the landfee PostgreSQL DB.

 move the old prototype/ into an archive/ or legacy/ folder so the repo is clean for the real project
