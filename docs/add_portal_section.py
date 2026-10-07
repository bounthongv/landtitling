#!/usr/bin/env python3
"""
Update LandTitling.docx — add Citizen Portal (ປ່ອງບໍລິການສາທາລະນະ) section
to the existing back-office document.
"""
import sys
from pathlib import Path

try:
    from docx import Document
    from docx.shared import Pt, Inches
    from docx.enum.text import WD_ALIGN_PARAGRAPH
except ImportError:
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "python-docx", "-q"])
    from docx import Document
    from docx.shared import Pt, Inches
    from docx.enum.text import WD_ALIGN_PARAGRAPH

docx_path = Path(r"D:\LandTitling\docs\LandTitling.docx")
doc = Document(docx_path)

# --- Helper: find paragraph index by partial text ---
def find_para_idx(text, start=0):
    for i, p in enumerate(doc.paragraphs[start:], start=start):
        if text in p.text:
            return i
    return None

# --- Helper: add a heading paragraph after the given index ---
def add_heading_after(idx, text, level=2):
    new_para = doc.add_paragraph()
    # Move the paragraph right after idx
    # python-docx doesn't support direct insertion, so we'll use XML manipulation
    # Actually, the simplest approach: add at the end, then reorder later
    # For now, we append new content at the end of the document
    run = new_para.add_run(text)
    run.bold = True
    if level == 2:
        run.font.size = Pt(13)
    elif level == 3:
        run.font.size = Pt(11)
    return new_para

def add_para(text, bold=False, size=Pt(10)):
    p = doc.add_paragraph()
    run = p.add_run(text)
    run.bold = bold
    run.font.size = size
    return p

def add_bullet(text, size=Pt(10)):
    p = doc.add_paragraph()
    run = p.add_run('• ' + text)
    run.font.size = size
    return p

# Find the last paragraph index (before which we want to insert)
last_idx = len(doc.paragraphs) - 1

# ============================================================
# Add Citizen Portal section at the end of the document
# ============================================================

add_para("")  # blank separator
add_para("ປ່ອງບໍລິການສາທາລະນະ — Citizen Portal", bold=True, size=Pt(14))
add_para("")

add_para("ແນວຄິດ (Concept)", bold=True, size=Pt(11))
add_para(
    "ປ່ອງບໍລິການສາທາລະນະເປັນແອັບພລິເຄຊັນມືຖື (PWA) ທີ່ອະນຸຍາດໃຫ້ປະຊາຊົນເຂົ້າເຖິງບໍລິການດິນໄດ້ໂດຍກົງຈາກໂທລະສັບ ໂດຍບໍ່ຈຳເປັນຕ້ອງມາທີ່ຫ້ອງການ. "
    "ລະບົບນີ້ຊ່ວຍຫຼຸດຂັ້ນຕອນ, ປະຢັດເວລາ, ແລະ ເພີ່ມຄວາມໂປ່ງໃສ ໃນການບໍລິການດິນ.",
    size=Pt(10)
)
add_para(
    "The Citizen Portal is a mobile-first PWA that allows citizens to access land services directly from their phone, without visiting the office. "
    "It reduces steps, saves time, and increases transparency in land services.",
    size=Pt(9)
)
add_para("")

add_para("ບໍລິການຫຼັກ (Core Services)", bold=True, size=Pt(11))
add_para("")

# Service 1
add_para("1. ໃບແຈ້ງມີ (Land Availability Certificate)", bold=True, size=Pt(10))
add_para(
    "ຢັ້ງຢືນວ່າທີ່ດິນບໍ່ມີການຈົດທະບຽນຄ້ຳປະກັນ ຫຼື ການດຳເນີນການທາງກົດໝາຍ. "
    "ຈຳເປັນສຳລັບການຂໍເງິນກູ້, ຄ້ຳປະກັນ, ຫຼື ການທາງກົດໝາຍ.",
    size=Pt(10)
)
add_para(
    "Confirms the parcel has no registered mortgage or legal proceedings. "
    "Needed for loan applications, mortgages, or legal transactions.",
    size=Pt(9)
)
add_para("")

# Service 2
add_para("2. ຂໍເອກະສານ (Document Requests)", bold=True, size=Pt(10))
add_para(
    "ສຳເນົາໃບຕາດິນ, ແຜນທີ່ຕອນດິນ, ປະຫວັດການດຳເນີນງານ. ຊ່ວຍໃຫ້ປະຊາຊົນສາມາດຂໍເອກະສານໄດ້ຈາກໂທລະສັບ ໂດຍບໍ່ຕ້ອງມາຫ້ອງການ.",
    size=Pt(10)
)
add_para("")

# Service 3
add_para("3. ລາຍງານບັນຫາ (Issue Reporting)", bold=True, size=Pt(10))
add_para(
    "ຂໍ້ຂັດເຂດທີ່ດິນ, ການລັກລອບບຸກລຸກ, ຂໍ້ມູນຜິດພາດ. ປະຊາຊົນສາມາດຖ່າຍຮູບ + GPS ແລະ ສົ່ງລາຍງານໄດ້ທັນທີ.",
    size=Pt(10)
)
add_para("")

# Service 4
add_para("4. ຕິດຕາມສະຖານະ (Status Tracking)", bold=True, size=Pt(10))
add_para(
    "ເບິ່ງຄວາມຄືບໜ້າຂອງຄຳຮ້ອງທັງໝົດ. ມີການແຈ້ງເຕືອນ SMS ເມື່ອຄຳຮ້ອງຖືກຮັບ, ກວດສອບ, ແລະ ອະນຸມັດ.",
    size=Pt(10)
)
add_para("")

# Architecture
add_para("ສະຖາປັດຕະຍາກຳ (Architecture)", bold=True, size=Pt(11))
add_para("")

add_para("ປ່ອງບໍລິການສາທາລະນະ ແລະ ຫ້ອງການຫຼັງ ແບ່ງກັນໃຊ້ຖານຂໍ້ມູນດຽວກັນ:", bold=False, size=Pt(10))
add_bullet("ຕອນດິນ ແລະ ເອກະສານ — Parcel and document data")
add_bullet("ຜູ້ຖືສິດ — Right holder records")
add_bullet("ຂະບວນການ — Workflow states")
add_bullet("ເອກະສານ ແລະ ຄ່າບໍລິການ — Documents and fees")
add_bullet("ການແຈ້ງເຕືອນ — Notifications")
add_para("")

add_para("ການເຊື່ອມຕໍ່ລະຫວ່າງລະບົບ (Integration)", bold=True, size=Pt(10))
add_bullet("ປະຊາຊົນ → ຫ້ອງການ: ຄຳຮ້ອງໃໝ່ປາກົດຢູ່ໃນ Dashboard ຂອງພະນັກງານເປັນ \"ກໍລະນີໃໝ່\"")
add_bullet("ຫ້ອງການ → ປະຊາຊົນ: ການປ່ຽນແປງສະຖານະສົ່ງ SMS / Push Notification")
add_bullet("ຫ້ອງການ → ປະຊາຊົນ: ການສ້າງເອກະສານ PDF → ປະຊາຊົນດາວໂຫຼດໄດ້")
add_bullet("ຫ້ອງການ ↔ GIS: ຂໍ້ມູນເຂດທີ່ດິນເຊື່ອມກັບແຜນທີ່ໃນປ່ອງບໍລິການສາທາລະນະ")
add_para("")

# Technical details
add_para("ຂໍ້ມູນດ້ານເຕັກນິກ (Technical Details)", bold=True, size=Pt(11))
add_para("")
add_bullet("Platform: PWA (Progressive Web App) — ແອັບມືຖືທີ່ສາມາດຕິດຕັ້ງໄດ້ໂດຍກົງ, ບໍ່ຈຳເປັນ App Store")
add_bullet("Auth: ບັດປະຈຳຕົວ + OTP SMS (ບໍ່ຕ້ອງລະຫັດຜ່ານ)")
add_bullet("Payment: ອັບໂຫຼດສະລິບເງິນ → BCEL One QR → ການເຊື່ອມຕໍ່ທະນາຄານ")
add_bullet("Notifications: SMS gateway (ສຳລັບໂທລະສັບພື້ນຖານ) + push (ສຳລັບ smartphone)")
add_bullet("Hosting: ຕັ້ງຢູ່ລັດຖະບານ cloud / VPS, HTTPS ບັງຄັບບັນຊາ")
add_bullet("GIS: Leaflet/MapLibre ສຳລັບແຜນທີ່, parcel boundaries ຈາກຫ້ອງການຫຼັງ")
add_para("")

# Phased rollout
add_para("ແຜນການຈັດຕັ້ງປະຕິບັດ (Implementation Phases)", bold=True, size=Pt(11))
add_para("")
add_bullet("Phase 1 (2-3 ເດືອນ): ໃບແຈ້ງມີ, ຕິດຕາມສະຖານະ, PDF ພ້ອມ QR, SMS ແຈ້ງເຕືອນ")
add_bullet("Phase 2 (+2 ເດືອນ): ລາຍງານບັນຫາ (ຮູບ+GPS), ຂໍເອກະສານ, ຄ່າບໍລິການ")
add_bullet("Phase 3 (+3 ເດືອນ): ການໂອນ/ຄ້ຳປະກັນ, ດິຈິຕອນລາຍເຊັນ, BCEL payment gateway, native app")
add_para("")

# Benefits for citizens
add_para("ຜົນປະໂຫຍດສຳລັບປະຊາຊົນ (Benefits for Citizens)", bold=True, size=Pt(11))
add_para("")
add_bullet("📱 ເຂົ້າເຖິງບໍລິການໄດ້ທຸກບ່ອນ — Access from anywhere (ບໍ່ຕ້ອງມາຫ້ອງການ)")
add_bullet("⏱️ ປະຢັດເວລາ — Save time (ບໍລິການທີ່ເຄີຍໃຊ້ເວລາຫຼາຍມື້, ສາມາດເຮັດໄດ້ພາຍໃນ 1 ມື້)")
add_bullet("💰 ຄ່າບໍລິການໂປ່ງໃສ — Transparent fees (ຄ່າບໍລິການຖືກກຳນົດໄວ້ຊັດເຈນ)")
add_bullet("📍 ຕິດຕາມໄດ້ຕະຫຼອດເວລາ — Track anytime (ເບິ່ງສະຖານະແບບ real-time)")
add_bullet("🔔 ແຈ້ງເຕືອນທັນທີ — Instant notifications (SMS ແຈ້ງເຕືອນເມື່ອຄຳຮ້ອງຖືກຮັບ, ກວດສອບ, ແລະ ອະນຸມັດ)")
add_bullet("📸 ລາຍງານບັນຫາໄດ້ງ່າຍ — Easy issue reporting (ຖ່າຍຮູບ + GPS ແລະ ສົ່ງລາຍງານໄດ້ທັນທີ)")
add_para("")

# Expected outcome
add_para("ຜົນລັບທີ່ຄາດຫມາຍ (Expected Outcome)", bold=True, size=Pt(11))
add_para(
    "ຈາກ \"ໃບຕາດິນເປັນເອກະສານ\" ສູ່ \"ຂໍ້ມູນຕອນດິນແບບດິຈິຕອນ\" ທີ່ສາມາດຄົ້ນຫາ, ກວດສອບ, ຈັດການ, "
    "ຕິດຕາມປະຫວັດ ແລະ ເຊື່ອມໂຍງກັບ GIS ໄດ້ຢ່າງເປັນລະບົບ. ປະຊາຊົນສາມາດເຂົ້າເຖິງບໍລິການດິນຜ່ານໂທລະສັບ ໂດຍບໍ່ຕ້ອງມາຫ້ອງການ.",
    size=Pt(10)
)

# Save
out_path = docx_path.parent / "LandTitling_v2.docx"
doc.save(out_path)
print(f"Saved: {out_path}")
print(f"Paragraphs: {len(doc.paragraphs)}")