# ລະບົບຄຸ້ມຄອງໃບຕາດິນ

**Land Title Management System**

---

## ແນວຄິດຂອງລະບົບ (System Concept)

ລະບົບຄຸ້ມຄອງໃບຕາດິນ ແມ່ນລະບົບສູນກາງສໍາລັບຈັດເກັບ, ຄຸ້ມຄອງ ແລະ ກວດສອບຂໍ້ມູນກ່ຽວກັບ:

A central system for storing, managing and verifying data about:

- ໃບຕາດິນ ແລະ ເອກະສານດິນ — Land titles and land documents
- ເຈົ້າຂອງ ແລະ ຜູ້ຖືສິດ — Owners and right holders
- ຕອນດິນ ແລະ ຂໍ້ມູນພື້ນທີ່ — Land parcels and spatial data
- ສິດ ແລະ ກໍາມະສິດໃນຕອນດິນ — Rights and ownership over parcels
- ການໂອນ ແລະ ການປ່ຽນແປງສິດ — Transfers and changes of rights
- ການນໍາຕອນດິນໄປຄ້ໍາປະກັນ — Using parcels as mortgage/security
- ປະຫວັດຂອງຕອນດິນ ແລະ ກໍາມະສິດ — History of parcels and ownership

## ເປົ້າຫມາຍ (Goal)

ປ່ຽນຈາກການຈັດເກັບໃບຕາດິນ ແລະ ເອກະສານແບບເຈ້ຽ ໄປສູ່ ຖານຂໍ້ມູນດິຈິຕອນທີ່ຄົບຖ້ວນ, ເຊື່ອມໂຍງຂໍ້ມູນດິນ, ຜູ້ຖືສິດ, ເອກະສານ ແລະ GIS ເຂົ້າໄວ້ໃນລະບົບດຽວ.

Transform from paper-based land title storage into a complete digital database, linking land data, right holders, documents and GIS in one system.

## ຫຼັກການສໍາຄັນ (Key Principle)

### One Land Parcel → One Digital Record

ແຕ່ລະຕອນດິນຈະມີຂໍ້ມູນດິຈິຕອນຂອງຕົນເອງ ເຊື່ອມໂຍງກັບໃບຕາດິນ, ເອກະສານ, ຜູ້ຖືສິດ, ສິດຕ່າງໆ, ປະຫວັດ ແລະ ຕໍາແຫນ່ງທາງ GIS.

Each land parcel gets its own digital record linked to its title, documents, right holders, rights, history and GIS location.

---

## ຂອບເຂດຫຼັກຂອງລະບົບ (Core Functions)

### 1. ຈັດການເອກະສານດິນ (Document Management)

- ສະແກນ ແລະ ນໍາເຂົ້າເອກະສານເຂົ້າລະບົບ — Scan and import documents into the system
- ຈັດເກັບໃບຕາດິນ ແລະ ເອກະສານປະກອບ — Store land titles and supporting documents
- ຄົ້ນຫາ ແລະ ເບິ່ງເອກະສານແບບດິຈິຕອນ — Search and view documents digitally
- ກໍານົດສິດການເຂົ້າເຖິງເອກະສານ — Control document access rights

### 2. ຈັດການຜູ້ຖືສິດ (Right Holder Management)

- ຂໍ້ມູນບຸກຄົນ / ນິຕິບຸກຄົນ — Individual / legal entity data
- ຜູ້ຖືໃບຕາດິນ — Land title holders
- ຜູ້ຖືສິດຮ່ວມ — Co-holders of rights
- ຄວາມສໍາພັນລະຫວ່າງຜູ້ຖືສິດ ແລະ ຕອນດິນ — Relationships between holders and parcels

### 3. ຈັດການຕອນດິນ (Parcel Management)

- ເລກທີຕອນດິນ — Parcel number
- ເນື້ອທີ່, ຂອບເຂດ ແລະ ທີ່ຕັ້ງ — Area, boundaries and location
- ປະເພດການນໍາໃຊ້ດິນ — Land use type
- ຂໍ້ມູນໃບຕາດິນ — Title data
- ສະຖານະປັດຈຸບັນຂອງຕອນດິນ — Current parcel status

### 4. ຈັດການສິດ ແລະ ກໍາມະສິດ (Rights & Ownership Management)

ຮອງຮັບສິດປະເພດຕ່າງໆ ເຊັ່ນ — Supports various right types such as:

- ສິດນໍາໃຊ້ — Usage rights
- ສິດເຊົ່າ — Lease rights
- ສິດສໍາປະທານ — Concession rights
- ສິດອື່ນໆຕາມລະບຽບການ — Other rights per regulations

### 5. ການຄ້ໍາປະກັນ (Mortgage / Security)

- ລົງທະບຽນຕອນດິນເປັນຫຼັກຊັບຄ້ໍາປະກັນ — Register parcels as collateral
- ບັນທຶກຜູ້ຮັບຄ້ໍາປະກັນ — Record the mortgagee/security recipient
- ສະຖານະການຄ້ໍາປະກັນ — Mortgage status
- ປົດການຄ້ໍາປະກັນເມື່ອດໍາເນີນການແລ້ວ — Release the mortgage once settled

---

## ການບໍລິຫານທຸລະກໍາ ແລະ GIS (Transactions & Spatial Information)

### ການໂອນ ແລະ ປ່ຽນແປງສິດ (Transfers & Changes of Rights)

ລະບົບສາມາດບັນທຶກ ແລະ ຈັດການຂະບວນການຕ່າງໆ ເຊັ່ນ — The system records and manages processes such as:

```
ຄໍາຮ້ອງ → ກວດສອບ → ອະນຸມັດ → ປັບປຸງສິດ → ບັນທຶກປະຫວັດ
Request → Verify → Approve → Update rights → Record history
```

ຕົວຢ່າງ — Examples:

- ການໂອນກໍາມະສິດ / ສິດນໍາໃຊ້ — Transfer of ownership / usage rights
- ການປ່ຽນແປງຜູ້ຖືສິດ — Change of right holder
- ການແບ່ງ ຫຼື ລວມຕອນດິນ — Subdivision or consolidation of parcels
- ການປ່ຽນແປງຂໍ້ມູນຕອນດິນ — Change of parcel data
- ການຈົດທະບຽນ ແລະ ປົດການຄ້ໍາປະກັນ — Mortgage registration and release

### ປະຫວັດຂອງຕອນດິນ (Land History)

ທຸກການປ່ຽນແປງສໍາຄັນສາມາດຖືກບັນທຶກເປັນ Land History ເພື່ອໃຫ້ສາມາດກວດສອບຍ້ອນຫຼັງໄດ້ — Every significant change is recorded as Land History for later audit:

- ໃຜເຄີຍຖືສິດ — Who previously held rights
- ໃຜເປັນຜູ້ຮັບສິດຕໍ່ມາ — Who received the rights next
- ມີການໂອນເມື່ອໃດ — When transfers happened
- ມີການຄ້ໍາປະກັນ ຫຼື ປົດຄ້ໍາປະກັນເມື່ອໃດ — When mortgages were created or released
- ເອກະສານໃດເປັນຫຼັກຖານຂອງແຕ່ລະລາຍການ — Which documents evidence each entry

### GIS Integration

ລະບົບຈະເຊື່ອມໂຍງຂໍ້ມູນຕອນດິນກັບ GIS ເພື່ອໃຫ້ສາມາດ — The system links parcel data with GIS to enable:

- ເບິ່ງຕໍາແຫນ່ງຕອນດິນໃນແຜນທີ່ — View parcel locations on a map
- ຄົ້ນຫາຕອນດິນຈາກແຜນທີ່ — Search parcels from the map
- ເລືອກຕອນດິນໃນແຜນທີ່ເພື່ອເບິ່ງຂໍ້ມູນ — Select parcels on the map to view data
- ເຊື່ອມຂໍ້ມູນ GIS ກັບໃບຕາດິນ ແລະ ເອກະສານ — Link GIS data with titles and documents
- ສະແດງຂໍ້ມູນຕອນດິນຕາມເຂດ, ເມືອງ ຫຼື ບ້ານ — Show parcel data by province, district or village

### ການນໍາໃຊ້ Chip / Smart Information

ໃນອະນາຄົດ ສາມາດນໍາໃຊ້ Chip / QR Code / Smart Identifier ກັບໃບຕາດິນ ເພື່ອເຊື່ອມໂຍງໃບຕາດິນແບບກາຍະພາບກັບຂໍ້ມູນດິຈິຕອນໃນລະບົບ.

In the future, Chip / QR Code / Smart Identifiers on physical titles can link them to the digital records.

---

## ຜົນປະໂຫຍດຈາກລະບົບ (Benefits)

1. **🗂️ ຈັດເກັບຂໍ້ມູນຢ່າງເປັນລະບົບ — Organized storage**
   ຂໍ້ມູນໃບຕາດິນ, ຕອນດິນ, ຜູ້ຖືສິດ ແລະ ເອກະສານ ຖືກເຊື່ອມໂຍງໄວ້ໃນລະບົບດຽວ.
   Title, parcel, holder and document data linked in one system.

2. **🔎 ຄົ້ນຫາຂໍ້ມູນໄດ້ໄວ — Fast search**
   ຫຼຸດເວລາໃນການຄົ້ນຫາເອກະສານ ແລະ ກວດສອບຂໍ້ມູນຕອນດິນ.
   Reduces time searching documents and verifying parcel data.

3. **🔐 ເພີ່ມຄວາມປອດໄພ — More security**
   ມີການກໍານົດສິດຜູ້ໃຊ້, ບັນທຶກການເຂົ້າໃຊ້ ແລະ ປະຫວັດການປ່ຽນແປງ.
   User access rights, usage logs and change history.

4. **📋 ເພີ່ມຄວາມໂປ່ງໃສ — More transparency**
   ການໂອນ, ການປ່ຽນແປງສິດ ແລະ ທຸລະກໍາຕ່າງໆ ສາມາດມີຂັ້ນຕອນ ແລະ ປະຫວັດກວດສອບໄດ້.
   Transfers, right changes and transactions have auditable steps and history.

5. **🗺️ ເຫັນຂໍ້ມູນດິນໃນແຜນທີ່ — See land on a map**
   ການເຊື່ອມກັບ GIS ຊ່ວຍໃຫ້ການຄຸ້ມຄອງດິນມີທັງຂໍ້ມູນດ້ານເອກະສານ ແລະ ຂໍ້ມູນດ້ານພື້ນທີ່.
   GIS integration combines document data with spatial data.

6. **📊 ສະຫນັບສະຫນູນການບໍລິຫານ — Management support**
   ສາມາດສ້າງລາຍງານ, ສະຖິຕິ ແລະ Dashboard ເພື່ອຊ່ວຍຜູ້ບໍລິຫານໃນການຕິດຕາມ ແລະ ວາງແຜນ.
   Reports, statistics and dashboards help management monitor and plan.

7. **🔄 ຮອງຮັບການພັດທະນາໃນອະນາຄົດ — Future-ready**
   ລະບົບສາມາດຕໍ່ຍອດໄປສູ່ — The system can extend to:
   - Online Land Services — ບໍລິການດິນອອນລາຍ
   - Mobile Application — ແອັບພລິເຄຊັນມືຖື
   - GIS / Spatial Portal — ພອຕ໌ລ GIS / ຂໍ້ມູນພື້ນທີ່
   - QR / Smart Land Title — ໃບຕາດິນ QR / Smart
   - ການເຊື່ອມຕໍ່ກັບລະບົບຂອງຫນ່ວຍງານອື່ນ — Integration with other agencies' systems
   - Digital Government Services — ບໍລິການລັດຖະບານດິຈິຕອນ

---

## ຜົນລັບທີ່ຄາດຫມາຍ (Expected Results)

ຈາກ **"ໃບຕາດິນເປັນເອກະສານ"** ສູ່ **"ຂໍ້ມູນຕອນດິນແບບດິຈິຕອນ"**

From **"land title as a document"** to **"digital land parcel data"**

ເຊິ່ງສາມາດຄົ້ນຫາ, ກວດສອບ, ຈັດການ, ຕິດຕາມປະຫວັດ ແລະ ເຊື່ອມໂຍງກັບ GIS ໄດ້ຢ່າງເປັນລະບົບ.

Searchable, verifiable, manageable, with traceable history and GIS integration — all systematically.