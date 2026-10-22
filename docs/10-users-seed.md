# Seeded Staff Accounts — Savannakhet Land Tax MVP

Live on `https://landtax.apis.com.la` (database: `landfee`, schema `landfee`).

- **Password for every account below: `demo1234`** — change each one after first real use.
- Province for all accounts: **Savannakhet (ສະຫວັນນະເຂດ)**.
- Seeded by `sql/migrations/003_seed_staff_accounts.sql` (district + province admins) and `sql/migrations/004_super_admin.sql` (super admin flag).

## Super admin

| Username | Password | Role | Level | District | Note |
|----------|----------|------|-------|----------|------|
| sav_officer | demo1234 | admin | district (super admin) | 1301 Nakhone Kaysone Phomvihane | `is_super = TRUE` — sees and edits records and accounts in EVERY district |

## District admins (one per district)

Role `admin`, level `district` — full record CRUD in own district + can create officer/manager accounts in own district.

| Username | Password | Role | District code | District (EN) | District (LO) |
|----------|----------|------|---------------|----------------|----------------|
| nakhone_admin | demo1234 | admin | 1301 | Nakhone Kaysone Phomvihane | ນະຄອນໄກສອນພົມວິຫານ |
| outoumphone_admin | demo1234 | admin | 1302 | Outoumphone | ອຸທຸມພອນ |
| atsaphangthong_admin | demo1234 | admin | 1303 | Atsaphangthong | ອາດສະພັງທອງ |
| phine_admin | demo1234 | admin | 1304 | Phine | ພີນ |
| seponh_admin | demo1234 | admin | 1305 | Seponh | ເຊໂປນ |
| nong_admin | demo1234 | admin | 1306 | Nong | ນອງ |
| thapangthong_admin | demo1234 | admin | 1307 | Thapangthong | ທ່າປາງທອງ |
| songkhone_admin | demo1234 | admin | 1308 | Songkhone | ສອງຄອນ |
| champhone_admin | demo1234 | admin | 1309 | Champhone | ຈຳພອນ |
| xonnabouly_admin | demo1234 | admin | 1310 | Xonnabouly | ຊົນນະບູລີ |
| xaybouly_admin | demo1234 | admin | 1311 | Xaybouly | ໄຊບູລີ |
| vilabouly_admin | demo1234 | admin | 1312 | Vilabouly | ວິລະບູລີ |
| atsaphone_admin | demo1234 | admin | 1313 | Atsaphone | ອາດສະພອນ |
| xayphouthong_admin | demo1234 | admin | 1314 | Xayphouthong | ໄຊພູທອງ |
| phalanxay_admin | demo1234 | admin | 1315 | Phalanxay | ພະລານໄຊ |

## Province-level accounts

Level `province` (no district) — see all records in the province; only the admin manages accounts; nobody at province level can add/edit records (data entry is a district responsibility).

| Username | Password | Role | Level | District | Note |
|----------|----------|------|-------|----------|------|
| snnk_admin | demo1234 | admin | province | — | Manages all staff accounts province-wide; records read-only |
| snnk_manager | demo1234 | manager | province | — | Read-only everything, no accounts |
| snnk_officer | demo1234 | officer | province | — | Read-only everything, no accounts |

## Permission summary

| Role | Level | Records | Accounts | Scope |
|------|-------|---------|----------|-------|
| admin | district | add/edit/delete | create officer/manager | own district |
| officer | district | add/edit/delete | — | own district |
| manager | district | read-only | — | own district |
| admin | province | read-only | create/edit/deactivate any | whole province |
| officer / manager | province | read-only | — | whole province |
| super admin (sav_officer) | — | add/edit/delete | full | every district |

## Adding more staff

- District admins: Admin → Users → New staff account (own district only).
- Province admin: same page, can pick any district or province level.
- Or re-run `sql/migrations/003_seed_staff_accounts.sql` on the server (idempotent — `ON CONFLICT DO NOTHING`).