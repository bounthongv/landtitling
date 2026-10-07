# DBRST — Create database + restore database

- **Lao title:** ການສ້າງຖານຂໍ້ມູນ ແລະ Restore ຖານຂໍ້ມູນ
- **YouTube:** https://youtu.be/UqtkHpv6PJk (6m 26s)
- **Group:** Part 2 · GIS tools & admin
- **Transcript status:** Lao ASR transcript captured (50 cues)

## Transcript (ASR)

<!-- Auto-generated captions: ASR quality, verify proper nouns/field names. -->

ເນາະສຳລັບຄລິບນີ້ຈະເປັນອ່າຄລິບກ່ຽວກັບການຖານຂໍ້ມູນຈາກ
backກລະກະເຮົາຈະໄປ
restore
ເຂົ້າຖານຂໍ້ມູນຖານໃໝ່ເນາະເນາະນີ້ກໍຈະເປັນຖານຂໍ້ມູນທີ່ເຮົາແບກັບອອກມາຈາກຄອມໜ່ວຍອື່ນແລ້ວເຮົາຈະມາຕເຂົ້າຄອມຂອງເຮົາ
ເຮົາເອງເນາະອັນນີ້ຈະເປັນຟileແ
backກ SQL ຫັ້ນເນາະຈະບໍ່ແມ່ນຈະບໍ່ແມ່ນ
lລເນາະຈະເປັນຟຍແບກຈາກອາໂປແກຣມໂພສກຫຼືວ່າ
PG admin 4
ຫັ້ນເນາະອັນນີ້ແມ່ນຟຍທີ່ເຮົາກ້າກຽມໄວ້ອ່າຈາກນັ້ນກໍໃຫ້ໄປທີ່
PGmin c ເນາະເປີດ pgມmin ຂຶ້ນມາ
ອ່າກໍໃຫ້ເຂົ້າໄປທີ່ເຂດ
15
ອ່າຖ້າວ່າຂອງຜູ້ໃດເນາະຖ້າວ່າມັນມີລະຫັດຜ່ານຖ້າວ່າເຮົາຈື່ລະຫັດຜ່ານກໍໃຫ້ອ່າລ໋ອກອິເຂົ້າເນາະໂຕນີ້ກໍສຳຄັນອີກອັນໜຶ່ງເນາະທີ່ວ່າເຮົາອ່າໃຊ້ລະຫັດ
[ເພງ]
ຜ່ານເຂົ້າທຸກຄັ້ງຫັ້ນແຕ່ວ່າບາງທ່ານໄດ້
sa
ເຊveພາເວີດແລ້ວລະກະຈະບໍ່ມັນຈະບໍ່ຖາມຫາລະຫັດເນາະມັນຈະລິ້ງເຂົ້າໄປເລີຍເນາະອັນນີ້ຂອງຂ້າພະເຈົ້າຈະໃຊ້ລະຫັດທຸກຄັ້ງເນາະເວລາເຮົາເຂົ້າລ໋ອກອິເຂົ້າມາກໍຈະເຫັນກ້ອນຖານຂໍ້ມູນອັນນີ້ເປັນກ້ອນທີ່ເຮົານຳໃຊ້ໃນຂໍ້ມູນອື່ນໆບາດນີ້ເຮົາຈະມາຕມາໝວດ
ໃໝ່ເນາະຫຼືວ່າອ່າຄອມໜ່ວຍໃໝ່ເຮົາມີຄອມໜ່ວຍໃໝ່ແລ້ວເຮົາຊິເອົາຟແ
[ເພງ]
backກັບໂຕເກົ່າມາລເຂົ້າຄືນຊິເຮັດຈັ່ງໃດເນາະອ່າກ່ອນອື່ນໃຫ້ມາຄິກຂວາໃສ່
databas
ແລ້ວຄate databas
ຂຶ້ນມາໃໝ່ເນາະໂຕນີ້ຈະເປັນຊື່ຖານຂໍ້ມູນຊື່ຖານຂໍ້ມູນ
ຍົກຕົວຢ່າງຖານຂໍ້ມູນຂອງເມືອງເນາະຂອງຂອງບັນດາທ່ານຢູ່ເມືອງໃດກໍໃຫ້ໃສ່ຊື່ເມືອງຂອງເຮົາເນາະໃຫ້ມັນຮູ້ວ່າເປັນຖານຂໍ້ມູນຂອງເມືອງຫຼືວ່າເປັນຂອງແຂວງກໍໄດ້ເນາະຫຼືວ່າເປັນອື່ນໆເນາະໂຕນີ້ຕົວຢ່າງຂ້າພະເຈົ້າຈະໃສ່ເປັນຄຳວ່າດຕາລເປັນຊື່ເຖິງວ່າເປັນຂໍ້ມູນຊື່ດາ
[ເພງ]
databas ກະໃຫ້ຕັ້ງ
ຕັ້ງເປັນຊື່ພາສາອັງກິດເນາະບໍ່ໃຫ້ຕັ້ງເປັນຊື່ພາສາລາວແລະກໍໂຕນ້ອຍໂຕໃຫຍ່ໄດ້ໝົດເນາະສຳລັບອເນີມັນກໍຈະໃຫ້ໃຊ້ສອງອັນເນາະຈະໃຫ້ໃຊ້ໂພສກແລະ
dbປerເລຕເນາະເຮົາສາມາດນຳໃຊ້ໂຕໃດໂຕໜຶ່ງກໍໄດ້ເນາະສຳລັບໂຕໂອນເນີນີ້ສຳລັບຄລິບນີ້ຂ້າພະເຈົ້າຈະຕັ້ງເປັນ
ເປັນກເນາະຈາກນັ້ນໃຫ້ກົດ
save
ຍັບໂຕນີ້ເຂົ້າກ່ອນເນາະຫຼັງຈາກທີ່ເຮົາສ້າງອ່າຖານຂໍ້ມູນຂຶ້ນມາໃໝ່ແລ້ວກໍໃຫ້ຄິກຂວາຄິກຂວາແລ້ວ
restore
ແລ້ວເຮົາຈະໄປເອີ້ນເອົາຟຍທີ່ເຮົາກ້າກຽມໄວ້ກໍຄືຟນີ້ເນາະຟທີ່ເຮົາແບັກອັບມາຈາກຄອມໜ່ວຍອື່ນຫຼືວ່າຄອມໜ່ວຍເກົ່າເຮົາຫັ້ນໃຫ້ເຮົາມາທີ່ຟເນມເນາະລະກະກົດອ່າຮູບໂຟoldເດີນີ້ກະໄປບ່ອນທີ່ເຮົາຈັດເກັບຂໍ້ມູນໄວ້ຄືຂ້າພະເຈົ້າຈັດເກັບໄວ້ຢູ່ໃນໜ້າເດop
ເນາະກະໃຫ້ເລືອກບ່ອນຢູ່ລະກະເລືອກທີ່ຟຍຂໍ້ມູນຫັ້ນແລ້ວກົດ
open
ໂຕຕໍ່ມາໂຕເນນີ້ແມ່ນໃຫ້ເລືອກເປັນອາໂພກດຄືກັນເນາະເພາະວ່າເວລາເຮົາສ້າງຖານຂໍ້ມູນເຮົາກໍໃສ່ເຮົາຈະໃສ່ປກຢູ່ບ່ອນນີ້ເຮົາກໍຕ້ອງໃສ່ປກດຄືກັນຖ້າວ່າ
ວ່າຕອນສ້າງຖານຂໍ້ມູນເປັນ
DB operatຕີກະໃຫ້ເລືອກເປັນ dB
ເນາະແຕ່ວ່າໂຕນີ້ຂ້າພະເຈົ້າໃສ່ເປັນ
pocດຫຼັງຈາກນັ້ນກໍໃຫ້ກົດ
restore
ເລີຍລະກະຖ້າເຮົາຢາກເບິ່ງມັນແລ່ນຫຼືວ່າມັນກຳລັງໂຫຼດຂໍ້ມູນແມ່ນໃຫ້ກົດທີ່ຮູບອ່າຮູບເອກະສານນີ້ວິວ
restore
ຂໍ້ມູນເຂົ້າກະລໍຖ້າມັນລເຂົ້າເນາະອັນນີ້ກໍຈະເປັນກໍລະນີທີ່ມັນ
rest ສຳເລັດແລ້ວມັນຈະຂຶ້ນອ່າຂໍ້ຄວາມມາວ່າ
[ເພງ]
successfully compພete
ເນາະຈະເປັນສີຂຽວແຕ່ຖ້າວ່າມັນເປັນສີແດງໝາຍຄວາມວ່າ
ວ່າອ່າມັນບໍ່ຜ່ານເນາະຫຼືວ່າຂໍ້ມູນມັນມີບັນຫາຖ້າມັນມີບັນຫາແບບນັ້ນກໍໃຫ້ອ່າລົບລົບຖານຂໍ້ມູນແລ້ວໄປແກ້ໄຂຂໍ້ມູນໃໝ່ຫຼືວ່າໄປແບັກອັບອອກມາໃໝ່ລະກະໃໝ່ເນາະອ່າການລົບກໍມີແຕ່ຄິກຂວາໃສ່ກະກົດ
ເນາະເນາະສຳລັບຄລິບກ່ຽວກັບການລຂໍ້ມູນກະມີສ່ຳນີ້ເນາະ

## Extracted screens / fields

_Reconstructed from ASR transcript; uncertain items marked [verify]._

| Screen / form | Fields & controls | Notes |
|---|---|---|
| pgAdmin login | Password (each time) — or auto-login if the password was saved ("saເຊveພາເວີດ" [verify] = "save password") | "ຖ້າເຮົາຈື່ລະຫັດຜ່ານກໍໃຫ້ລ໊ອກອິເຂົ້າ" |
| Server tree | "databas" node (PostgreSQL 15 — "ເຂດ 15" [verify]); "ກ້ອນຖານຂໍ້ມູນ" (database block/group) | Right-click for actions |
| Create database dialog ("create databas" — "ຄate databas" [verify]) | Name: English only, case allowed ("ຕັ້ງເປັນຊື່ພາສາອັງກິດ... ບໍ່ໃຫ້ຕັ້ງເປັນຊື່ພາສາລາວ... ໂຕນ້ອຍໂຕໃຫຍ່ໄດ້ໝົດ"); example: district/province name ("ຕົວຢ່າງ... ຊື່ເມືອງ", ASR "ຕາຕາ" [verify — "data"?]); owner: choose between "postgres" ("ໂພສກ"/"pocດ" [verify]) and "dbperlet" ("dbປerເລຕ" [verify]); "save" | |
| Restore dialog | Right-click the new DB → "restore"; "file name" — folder browse icon ("ຮູບໂຟoldເດີ"), file location (example: desktop — "ໜ້າເດop" [verify]), "open"; owner selection ("ອາໂພກ" [verify — "owner"]) must match the creation user; "restore" button | Backup file = SQL dump from another PC's Postgres/PGAdmin4 ("ຟy back SQL") |
| Restore log view | Document icon ("ຮູບເອກະສານນີ້ວິວ" [verify]) shows progress/loading; green "successfully complete" message vs red failure | "ສີຂຽວ" = success; "ສີແດງ" = data problem |
| Delete database | Right-click → delete ("ຄິກຂວາໃສ່ກະກົດ" [verify]) | Recovery: delete the DB, fix/re-back up the data, retry |

## Workflow steps (from transcript)

_Reconstructed from ASR transcript; uncertain items marked [verify]._

1. Take a backup SQL file ("ຟy back SQL") created by Postgres or PGAdmin4 ("PG admin 4") from another machine ("ແບກອອກມາຈາກຄອມໜ່ວຍອື່ນ") and bring it to this machine.
2. Open pgAdmin ("PGmin c" [verify]); go to PostgreSQL 15 ("ເຂດ 15" [verify]); log in with the password — if it was saved ("saເຊveພາເວີດ" [verify = "save password"]), it links straight in without asking.
3. Right-click the database ("databas") → "Create database" ("ຄate databas" [verify]).
4. Set the database name: use the district/province name ("ຮູ້ວ່າເປັນຖານຂໍ້ມູນຂອງເມືອງ ຫຼື ແຂວງ" — data of the district or province); "the name must be in English, not Lao — 'no Lao, English only' — uppercase/lowercase allowed" ("ຕັ້ງເປັນຊື່ພາສາອັງກິດ... ບໍ່ໃຫ້ຕັ້ງເປັນຊື່ພາສາລາວ... ໂຕນ້ອຍໂຕໃຫຍ່ໄດ້ໝົດ"). Example name used: "ຕາຕາ" [verify].
5. Pick the owner ("ອເນີ" [verify]): choose between "postgres" ("ໂພສກ"/"pocດ") and "dbperlet" ("dbປerເລຕ") — either one works; this clip uses "postgres" [verify].
6. Click "save".
7. Right-click the new database → "restore"; in the "file name" field, click the folder icon ("ຮູບໂຟoldເດີ") and browse to where the backup file is stored (e.g. the desktop — "ໜ້າເດop" [verify]); select the file; "open".
8. In the owner field, pick the same user as at creation ("ເລືອກເປັນ... ຄືກັນ" — if the DB was created with "db", pick "db"; here "pocດ"/postgres [verify]).
9. Click "restore". To watch progress, click the document icon ("ຮູບເອກະສານນີ້ວິວ" [verify]) — the restore view shows the data loading.
10. Result: green "successfully complete" message ("ສີຂຽວ" = success). If red ("ສີແດງ") = failure or data problem: delete the database ("ລົບຖານຂໍ້ມູນ" — right-click → "ຄິກຂວາໃສ່ກະກົດ" [verify]), fix the data or make a fresh backup, and retry.

## Open questions for review

- [ ] Confirm the exact database name convention — ASR example "ຕາຕາ" [verify: "data"?] — is it always the district/province name?
- [ ] Which owner is standard in production — "postgres" or "dbperlet" ("dbປerເລຕ" [verify])?
- [ ] What is the backup file extension/format ("ຟy back SQL" [verify] — pg_dump .sql, or a custom format)?
- [ ] Is the "document icon" progress view ("ຮູບເອກະສານນີ້ວິວ" [verify]) standard pgAdmin restore logging?
- [ ] On red failure, is "delete the DB and retry" the only sanctioned recovery, or are there partial-restore options?
- [ ] Does restoring overwrite an existing DB of the same name, or must the DB be created empty first?
