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

### ລະບົບສາມາດໃຊ້ງານໄດ້ສອງຮູບແບບ (Two-App Architecture)

ລະບົບນີ້ປະກອບມີສອງສ່ວນຫຼັກ ເຊິ່ງຮ່ວມກັນໃຊ້ຖານຂໍ້ມູນດຽວກັນ:

The system consists of two main parts sharing a common database:

| ສ່ວນ | ຜູ້ໃຊ້ | ເປົ້າໝາຍ |
|------|---------|----------|
| **ຫ້ອງການຫຼັງ (Back-Office)** | ພະນັກງານດິນ, ເມືອງ, ແຂວງ | ຄຸ້ມຄອງກໍລະນີ, ອອກໃບຕາດິນ, ການໂອນ/ຄ້ຳປະກັນ, ລາຍງານ |
| **ປ່ອງບໍລິການສາທາລະນະ (Citizen Portal)** | ເຈົ້າຂອງທີ່ດິນ, ປະຊາຊົນ | ຂໍໃບແຈ້ງມີ, ລາຍງານບັນຫາ, ຂໍເອກະສານ, ຕິດຕາມສະຖານະ |

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

## ປ່ອງບໍລິການສາທາລະນະ (Citizen Portal)
 
 ປ່ອງບໍລິການສາທາລະນະເປັນແອັບພລິເຄຊັນມືຖື (PWA) ທີ່ອະນຸຍາດໃຫ້ປະຊາຊົນເຂົ້າເຖິງບໍລິການດິນໄດ້ໂດຍກົງຈາກໂທລະສັບ, ໂດຍບໍ່ຈໍາເປັນຕ້ອງມາທີ່ຫ້ອງການ.

The Citizen Portal is a mobile-first PWA that allows citizens to access land services directly from their phone, without visiting the office.

### ບໍລິການຫຼັກ (Core Services)

| # | ບໍລິການ | ລາຍລະອຽດ |
|---|---------|------------|
| 1 | **ໃບແຈ້ງມີ** (Land Availability Certificate) | ກວດສອບ ແລະ ຢັ້ງຢືນວ່າທີ່ດິນບໍ່ມີການຈົດທະບຽນຄ້ຳປະກັນ — ຈໍາເປັນສຳລັບການຂໍເງິນກູ້, ຄ້ຳປະກັນ, ຫຼື ການທາງກົດໝາຍ |
| 2 | **ຂໍເອກະສານ** (Document Requests) | ສຳເນົາໃບຕາດິນ, ແຜນທີ່ຕອນດິນ, ປະຫວັດການດຳເນີນງານ |
| 3 | **ລາຍງານບັນຫາ** (Issue Reporting) | ຂໍ້ຂັດເຂດທີ່ດິນ, ການລັກລອບບຸກລຸກ, ຂໍ້ມູນຜິດພາດ (ພ້ອມຮູບ ແລະ GPS) |
| 4 | **ຕິດຕາມສະຖານະ** (Status Tracking) | ເບິ່ງຄວາມຄືບໜ້າຂອງຄຳຮ້ອງທັງໝົດ |

### ຂັ້ນຕອນການຂໍໃບແຈ້ງມີ (How to Request a Land Availability Certificate)

ນີ້ແມ່ນຂັ້ນຕອນຫຼັກທີ່ປະຊາຊົນໃຊ້ງານຫຼາຍທີ່ສຸດ:

This is the most commonly used citizen flow:

```
ປະຊາຊົນປ້ອນລະຫັດຕອນດິນ → ລະບົບກວດສອບສິດ → ສະແດງຜົນ
     ↓
  ມີສິດ (Eligible)              ບໍ່ມີສິດ (Not Eligible)
     ↓                                ↓
  ຊຳລະຄ່າບໍລິການ              ປະຕິເສດ (ຕົວຢ່າງ: ຢູ່ໃນຄ້ຳປະກັນ)
     ↓
  ສົ່ງຄຳຮ້ອງ → ພະນັກງານກວດສອບ → ອະນຸມັດ → ດາວໂຫຼດ PDF ພ້ອມ QR
```

**ເງື່ອນໄຂການກວດສອບ (Eligibility Rules):**

| ສະຖານະທີ່ດິນ | ສາມາດຂໍໄດ້ບໍ່? | ເຫດຜົນ |
|----------------|-------------------|---------|
| ACTIVE (ປົກກະຕິ) | ✅ ໄດ້ | ບໍ່ມີອຸປະສັກ |
| MORTGAGED (ຄ້ຳປະກັນ) | ❌ ບໍ່ໄດ້ | ຕອນດິນຢູ່ໃນການຄ້ຳປະກັນ |
| PENDING (ກຳລັງດຳເນີນການ) | ❌ ບໍ່ໄດ້ | ມີການດຳເນີນການອື່ນຢູ່ |

### ຂັ້ນຕອນການຂໍເອກະສານ (Document Request Flow)

```
ເລືອກປະເພດເອກະສານ → ປ້ອນລະຫັດຕອນດິນ → ສະແດງຄ່າບໍລິການ
     ↓
  ອັບໂຫລດສະລິບເງິນ → ສົ່ງຄຳຮ້ອງ → ພະນັກງານກວດສອບ → ອະນຸມັດ → ດາວໂຫຼດ
```

**ຄ່າບໍລິການຕາມປະເພດ (Fee Schedule):**

| ເອກະສານ | ຄ່າບໍລິການ |
|----------|------------|
| ສຳເນົາໃບຕາດິນ (Title Copy) | 50,000 ກີບ |
| ແຜນທີ່ຕອນດິນ (Parcel Map) | 30,000 ກີບ |
| ປະຫວັດການດຳເນີນງານ (Transaction History) | 20,000 ກີບ |

### ການລາຍງານບັນຫາ (Issue Reporting)

ປະຊາຊົນສາມາດລາຍງານບັນຫາກ່ຽວກັບທີ່ດິນໄດ້ໂດຍກົງ:

Citizens can report land issues directly:

```
ເລືອກປະເພດບັນຫາ → ລະບຸຕອນດິນ → ອະທິບາຍລາຍລະອຽດ
     ↓
  ຖ່າຍຮູບ (ສູງສຸດ 3) → ກຳນົດຕຳແໜ່ງ GPS → ສົ່ງລາຍງານ
     ↓
  ລະບົບສົ່ງໄປຫາພະນັກງານເມືອງ → ກວດສອບ → ແຈ້ງຜົນກັບ
```

**ປະເພດບັນຫາທີ່ສາມາດລາຍງານໄດ້:**

- ຂໍ້ຂັດເຂດທີ່ດິນ (Boundary Dispute)
- ການລັກລອບບຸກລຸກ (Encroachment)
- ຂໍ້ມູນຜິດພາດໃນລະບົບ (Data Error)
- ຄວາມເສຍຫາຍຕໍ່ທີ່ດິນ (Land Damage)

### ການເຊື່ອມຕໍ່ລະຫວ່າງລະບົບ (System Integration)

| ທິດທາງ | ການເຊື່ອມຕໍ່ |
|---------|---------------|
| ປະຊາຊົນ → ຫ້ອງການ | ຄຳຮ້ອງໃໝ່ປາກົດຢູ່ໃນ Dashboard ຂອງພະນັກງານເປັນ "ກໍລະນີໃໝ່" |
| ຫ້ອງການ → ປະຊາຊົນ | ການປ່ຽນແປງສະຖານະສົ່ງ SMS / Push Notification |
| ຫ້ອງການ → ປະຊາຊົນ | ການສ້າງເອກະສານ PDF → ປະຊາຊົນດາວໂຫຼດໄດ້ |
| ຫ້ອງການ ↔ GIS | ຂໍ້ມູນເຂດທີ່ດິນເຊື່ອມກັບແຜນທີ່ໃນພ໋ອຕໍລະບົບ |

### ການຢັ້ງຢືນຕົວຕົນ ແລະ ການຊຳລະເງິນ (Authentication & Payment)

**ການຢັ້ງຢືນຕົວຕົນ:** ປະຊາຊົນໃຊ້ເລກບັດປະຈໍາຕົວ + OTP SMS (ບໍ່ຕ້ອງໃສ່ລະຫັດຜ່ານ). ລະບົບຈະຊອກຫາຂໍ້ມູນຜູ້ຖືສິດຈາກຖານຂໍ້ມູນອັດຕະໂນມັດ.

**Authentication:** Citizen enters national ID + OTP SMS (no password). System auto-links to holder record.

**ການຊຳລະເງິນ:** Phase 1 ໃຊ້ການອັບໂຫລດສະລິບເງິນ, Phase 2 ໃຊ້ QR Code ຂອງ BCEL One, Phase 3 ຊຳລະໂດຍກົງຜ່ານທະນາຄານ.

**Payment:** Phase 1 uses bank slip upload, Phase 2 adds BCEL One QR, Phase 3 adds direct bank integration.

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

### ສຳລັບຫ້ອງການ (For Officers)

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

### ສຳລັບປະຊາຊົນ (For Citizens)

7. **📱 ເຂົ້າເຖິງບໍລິການໄດ້ທຸກບ່ອນ — Access from anywhere**
   ບໍ່ຈໍາເປັນຕ້ອງມາທີ່ຫ້ອງການ — ສາມາດຂໍໃບແຈ້ງມີ, ຂໍເອກະສານ, ແລະ ຕິດຕາມສະຖານະຜ່ານໂທລະສັບ.
   No need to visit the office — request certificates, documents, and track status from your phone.

8. **⏱️ ປະຢັດເວລາ — Save time**
   ບໍລິການທີ່ເຄີຍໃຊ້ເວລາຫຼາຍມື້ ສາມາດດໍາເນີນການໄດ້ພາຍໃນບໍ່ເຖິງ 1 ມື້.
   Services that used to take days can be completed in under a day.

9. **💰 ຄ່າບໍລິການໂປ່ງໃສ — Transparent fees**
   ຄ່າບໍລິການຖືກກໍານົດໄວ້ຊັດເຈນ, ບໍ່ມີການເກັບເງິນເພີ່ມເຕີ່ມ.
   Fees are clearly stated, no hidden charges.

10. **📍 ຕິດຕາມໄດ້ຕະຫຼອດເວລາ — Track anytime**
    ເບິ່ງສະຖານະຄຳຮ້ອງແບບ real-time, ບໍ່ຕ້ອງໂທລະສັບຖາມ.
    Real-time request status — no need to call and ask.

11. **🔔 ແຈ້ງເຕືອນທັນທີ — Instant notifications**
    SMS ແຈ້ງເຕືອນເມື່ອຄຳຮ້ອງຖືກຮັບ, ກວດສອບ, ແລະ ອະນຸມັດ.
    SMS alerts when requests are received, reviewed, and approved.

12. **📸 ລາຍງານບັນຫາໄດ້ງ່າຍ — Easy issue reporting**
    ຖ່າຍຮູບ + GPS ແລະ ສົ່ງລາຍງານໄດ້ທັນທີ, ບໍ່ຕ້ອງຂຽນໜັງສື.
    Photo + GPS and submit reports instantly, no paperwork needed.

7. **🔄 ຮອງຮັບການພັດທະນາໃນອະນາຄົດ — Future-ready**
   ລະບົບສາມາດຕໍ່ຍອດໄປສູ່ — The system can extend to:
   - 📱 Citizen Portal (PWA) — ປ່ອງບໍລິການສາທາລະນະ ✅ ສໍາເລັດແລ້ວ
   - ແອັບພລິເຄຊັນມືຖື native (Android / iOS) — Native mobile app
   - GIS / Spatial Portal — ພອຕ໌ລ GIS / ຂໍ້ມູນພື້ນທີ່
   - QR / Smart Land Title — ໃບຕາດິນ QR / Smart
   - ການເຊື່ອມຕໍ່ກັບລະບົບຂອງຫນ່ວຍງານອື່ນ — Integration with other agencies' systems
   - Digital Government Services — ບໍລິການລັດຖະບານດິຈິຕອນ
   - Online Payment Gateway (BCEL One) — ຊ່ອງທາງການຊຳລະເງິນອອນລາຍ

---

## ຜົນລັບທີ່ຄາດຫມາຍ (Expected Results)

ຈາກ **"ໃບຕາດິນເປັນເອກະສານ"** ສູ່ **"ຂໍ້ມູນຕອນດິນແບບດິຈິຕອນ"**

From **"land title as a document"** to **"digital land parcel data"**

ເຊິ່ງສາມາດຄົ້ນຫາ, ກວດສອບ, ຈັດການ, ຕິດຕາມປະຫວັດ ແລະ ເຊື່ອມໂຍງກັບ GIS ໄດ້ຢ່າງເປັນລະບົບ.

Searchable, verifiable, manageable, with traceable history and GIS integration — all systematically.

### ຜົນລັບຕາມພາກສ່ວນ (Results by Stakeholder)

| ພາກສ່ວນ | ຜົນລັບ |
|---------|--------|
| **ພະນັກງານ** | ຫຼຸດຂັ້ນຕອນການເຮັດວຽກດ້ວຍມື, ຄົ້ນຫາຂໍ້ມູນໄວ, ສ້າງລາຍງານອັດຕະໂນມັດ |
| **ປະຊາຊົນ** | ບໍ່ຕ້ອງມາຫ້ອງການເລື້ອຍໆ, ສາມາດຕິດຕາມຄຳຮ້ອງໄດ້, ຄ່າບໍລິການໂປ່ງໃສ |
| **ການບໍລິຫານ** | ມີຂໍ້ມູນຕົວຈິງເພື່ອວາງແຜນ, ກວດສອບການທຸລະກໍາໄດ້ |
| **ລັດຖະບານ** | ເພີ່ມຄວາມໂປ່ງໃສ, ຫຼຸດການສໍ້ລາດບັງຫຼວງ, ເພີ່ມຄວາມໄວ້ວາງໃຈ |

### ຂັ້ນຕອນການຈັດຕັ້ງປະຕິບັດ (Implementation Phases)

| Phase | ເວລາ | ຂັ້ນຕອນ |
|-------|-------|---------|
| **Phase 1** | 2-3 ເດືອນ | ຫ້ອງການ: Dashboard, ຄົ້ນຫາ, ການໂອນ · ປະຊາຊົນ: ໃບແຈ້ງມີ, ຕິດຕາມ |
| **Phase 2** | +2 ເດືອນ | ຫ້ອງການ: ຄ້ຳປະກັນ, ລາຍງານ · ປະຊາຊົນ: ຂໍເອກະສານ, ລາຍງານບັນຫາ |
| **Phase 3** | +3 ເດືອນ | Payment Gateway, ດິຈິຕອນລາຍເຊັນ, ແອັບ native, GIS portal |