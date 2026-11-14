# Agent Cash Payment — User Manual

Compact guide for **village cash-collection agents** using the citizen app's
Agent mode, and for the **district officer** who receives the cash.
Companion spec: `docs/19-agent-cash-payment.md`.

The interface is Lao by default; Lao labels below are quoted exactly as they
appear on screen.

---

## 1. Before you start

- The district admin creates your account once (back-office **Users** page):
  role **officer**, tick **Cash collection agent** (ຜູ້ບໍລິການເກັບເງິນ),
  choose your **village**, your formal role (village head / secretary / agent)
  and optionally a licence number.
- You get a **username + password**. Test account: `demo_agent` / `demo1234`
  (village ນ້ຳອ່າງ, district ທຸລະຄົມ, Vientiane).
- The app must be online (MVP is online-only; no offline queue yet).
- You can only collect for **your assigned village** — the server enforces it.

## 2. Sign in

1. Open the app → sign-in screen.
2. Switch to the **Agent sign-in** tab — `ເຂົ້າສູ່ລະບົບຜູ້ບໍລິການ`
   (hint: *ສຳລັບຜູ້ບໍລິການ / ນາຍບ້ານ / ເລຂານຸການ*).
3. Enter **ຊື່ໃຊ້ງານ** (username) and **ລະຫັດຜ່ານ** (password) → sign in.
   - If the account is not flagged as agent you get
     `ບັນຊີນີ້ບໍ່ແມ່ນຜູ້ບໍລິການເກັບເງິນ` — ask your district admin.

You land on the **collector dashboard** — `ໜ້າຜູ້ບໍລິການເກັບເງິນ`, with your
village name under the title.

## 3. Collect cash

1. The dashboard lists every **unpaid land-fee obligation in your village**
   (current year): parcel card with owner, phone, village/district, outstanding
   amount, penalty (red, when overdue) and days overdue.
   - Empty list = `ບໍ່ມີຕອນດິນທີ່ຕ້ອງເກັບເງິນ` (pull to refresh).
2. Tap **Collect cash** — `ເກັບເງິນສົດ` on the parcel card.
3. On **Record a cash payment** (`ບັນທຶກການເກັບເງິນສົດ`) verify and fill:
   - **Payer name** `ຊື່ຜູ້ຊຳລະ` — pre-filled with the owner; edit if someone
     else pays.
   - **Payer phone** `ເບີໂທຜູ້ຊຳລະ` and **Payer ID card no.**
     `ເລກບັດປະຊາຊົນ` — both required.
   - **Amount** `ຈຳນວນເງິນ` — pre-filled with the full outstanding
     (tax + live 10% late surcharge, or less if the officer waived it).
     You may record a smaller amount (partial payment).
4. Tap **Confirm collection** — `ຢືນຢັນການເກັບເງິນ`.
5. Success dialog `ບັນທຶກການເກັບເງິນສຳເລັດ` shows the payment reference
   (`LFE-{year}-{seq6}`) and the reminder:
   `ເງິນຈະຖືກສົ່ງໃຫ້ເມືອງເມື່ອທ່ານສ້າງຊຸດສົ່ງເງິນ`
   ("the money goes to the district when you create a handover").
   - **View receipt** — `ເບິ່ງໃບຮັບເງິນ`: the citizen's receipt (parcel,
     payer, amount, your name + role, "Received by …"). Copy the reference so
     the citizen can quote it.

The payment is now **SUBMITTED** — cash received, not yet settled. The parcel
disappears from your list; no one (you or another agent) can collect the same
parcel again while this collection is open.

## 4. Hand over the cash to the district office

When you visit the office (usually weekly):

1. From the dashboard tap the gold button **Hand over to district**
   — `ສົ່ງເງິນໃຫ້ເມືອງ` (or the list icon top-right).
2. Tab **Collected, not yet handed over** — `ເງິນທີ່ຍັງບໍ່ໄດ້ສົ່ງ`:
   each collection (reference, parcel, payer, date, amount). Tick what you
   are handing over, or **Select all** — `ເລືອກທັງໝົດ`; the footer shows the
   running total.
3. Tap **Create handover** — `ສ້າງຊຸດສົ່ງເງິນ` → confirm the count + total.
   You get a **batch reference** `AH-{year}-{seq6}` — the officer reads this
   off your phone at the counter.
4. Tab **Handovers** — `ການສົ່ງເງິນໃຫ້ເມືອງ`: your batch history with status
   chips: `ລໍຖ້າກວດສອບ` (awaiting review) → `ກວດສອບແລ້ວ` (verified) or
   `ຖືກປະຕິເສດ` (rejected). Tap a batch to see its payments.

## 5. What the district officer does (back-office)

Screen **Agent Handovers** (`/admin/agent_handovers.php`):
- Lists pending batches (agent, village, count, total, batch reference).
- **Verify**: counts the cash, marks the batch verified — every payment in it
  becomes **PAID** and the obligations are settled (late surcharge stamped).
  Individual payments can be rejected in the same action (they return to your
  pending list as ISSUED).
- **Reject** (whole batch, with a reason) also returns all payments to you.

You, the agent, **cannot** verify anything yourself — the app blocks it.

## 6. The citizen's view

If the land owner has a citizen account, the payment appears on their parcel
as **Cash (via agent)** — `ເງິນສົດ (ຜ່ານຜູ້ບໍລິການ)` — and they can pull the
same receipt. No action needed from them.

## 7. Rules & troubleshooting

| Situation | What it means |
|---|---|
| Parcel not in your list | Paid, waived, or not in your village |
| "Already collected" error | Another agent has an open collection for that parcel |
| Amount smaller than expected | Officer waived/reduced the late surcharge — the app prices it in |
| Batch `ຖືກປະຕິເສດ` | Cash disputed — the payments are back in your pending tab; re-hand them over with the officer's notes in mind |
| App shows `ບັນຊີນີ້ບໍ່ແມ່ນຜູ້ບໍລິການເກັບເງິນ` | Account not flagged as agent — district admin must tick the agent checkbox |

Security notes (for the officer): every collection is audit-logged
(agent, payer, reference, timestamps); one open collection per parcel is
enforced by the database; receipts are immutable once generated.
