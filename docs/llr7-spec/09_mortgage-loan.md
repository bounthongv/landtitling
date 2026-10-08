# EP9 — Mortgage / loan contract registration

- **Lao title:** LLR7 ການຈົດທະບຽນເຄື່ອນໄຫວສັນຍາຄ້ຳປະກັນເງິນກູ້ຢືມ EP.9
- **YouTube:** https://youtu.be/Qe-M0fjP0PQ (8m 55s)
- **Group:** Part 1 · Core workflows
- **Transcript status:** Whisper medium (int8, CPU) local transcription, raw/audio/Qe-M0fjP0PQ.whisper.txt
- **Confidence:** MEDIUM — Thai ASR of a Lao video; some terms garbled. Steps marked [verify] where unclear.

## Transcript (ASR)

See raw/audio/Qe-M0fjP0PQ.whisper.txt (3 chunks x 3 min, Thai detected).

## Workflow steps (from ASR)

**Case:** register a **loan / mortgage contract** (ສັນຍາຄຳປະກັນເງິນກູ້) against a parcel so the encumbrance is on record.

1. **Enter the loan data** on the form, then **add the request** (ເພີ່ມຄຳສຳເລັດ / ຂໍ້ມູນ) and go to the **land-use rights (ສິດນຳໃຊ້ທີ່ດິນ)** step.
2. **Find the target parcel** — search by the sub-parcel number (video example: sub-parcel 2). The request-set loads the relevant parcels.
3. **Add the co-owners / parties** — where there are several sub-parcels, add the applicable parties (video: "Vai Viet" part is on another page, not this title, so not selected [verify]).
4. **Set the request type** — go to **type of registration (ປະເພດທະບຽນ)**:
   - "record the movement" (ຈຶດທະບຽນເຄື່ອນໄພ) → **record the loan-contract registration** (ຈຶດທະບຽນສັນຍາຄຳປະກັນເງິນກູ້).
5. **Enter the loan amount** — e.g. authority/limit of the contract (ຄຳກິ່ນກ້ານ…); video example: loan of **600,000 kip** (the "500,000 kip limit" example is used). [verify — amounts are demo values]
6. **Record the receipt** — the line where the document was received (ລາຍການທີ່ຮັບເອກະສານ). Status "request submitted" → close the edit.
7. **Add a tracking copy** (ໃບຕິດຕາມ) — print the follow-up copy; the party to hand over is the **title owner who came to stand** (ຜູ້ມາຢືນ / ເຈົ້າທີ່ດິນ).
8. **Go to the registration panel** — "record and register" (ຈຶດທະບຽນ) → the movement → open the **change edit** (ເບີ່ງແປັດແກ້) and select the **register form** (ສາກຜົນລົງທະບຽນ) → the request set "add" (the added request appears).
9. **Select the request set, add the movement** (ເພີ່ມສິດເຄື່ອນໄພ) into the request set, then return to the request.
10. **Confirm the change** — select "confirm the change" (ຍື່ນການປ່ຽນແປ້ນ) → **"registration land" confirm** (ຢື່ນ… ຈັດທະບຽນ) → OK.
11. **Set status = main / complete** — choose "main" (ເມນ) for the status, then press **"complete all requests" (ສຳເລັດຄຳສຳເລັດທັງໝົດ)** → OK.
12. **Close the app** — the **status dot must turn green** (from blue). If it's still red, open the other edit form and close it; once green, close the edit → done.
13. **The loan contract now sits in the request set** on this parcel.
14. **Calculate the fees** (ຄິດໄລ່ຄ່າທຳນຽມ) — to **issue the fee document** (ອອກບັນທຶກ…): try **barcode scan** (ອີງ… ໄບໂກດ [verify]) or manually enter the barcode number (ຕົວລະຫັດບາໂກດ). Return to "fee calculation → register the movement → land-use rights".
15. **Barcode / document** — print or scan the barcode into the request envelope (ຊອງຄຳສຳເລັດ), press "agree / receive" (ຮັບ) → OK → the **movement list** appears.
16. **Fee amount** — video example: land value **600,000 kip**; service fee (ຄ່າບໍລິການ) adds a **stamp duty** (ໃບໄຊທາຂອງ [verify]) line; "for this period" (ຈຸດ… ແດ່ນ [verify]); leave at **50,000 kip**. Press **create document** (ສ້າງບັນທຶກ).
17. **The document is a "loan-contract registration fee"** (ບັນທຶກການຈັດທະບຽນ… ສັນຍາ… ) — land value 600,000, service fee 50,000 → **stamp 50,000** [verify — demo numbers].
18. **Digital system behaviour** — the system stores the loan-contract type on the parcel; a future movement would **create a new request set** rather than delete. (The video ends here.)

## Extracted screens / fields

| Screen / form | Fields & controls | Notes |
|---|---|---|
| Loan data form | loan amount, authority/limit, receipt line | demo values [verify] |
| Type of registration | "record movement" → "record loan-contract registration" | the mortgage type |
| Request-set / movement | request set, add movement (ເພີ່ມສິດເຄື່ອນໄພ), confirm change, status dot (green) | completion gate |
| Fee calculation | barcode (scan or type), fee document creation, stamp duty line | fee engine link |
| Fee document | land value, service fee, stamp amount, "create document" | output doc |

## Open questions for review

- [ ] Does a registered mortgage **block** other transactions (sale, subdivision) on the parcel? (video ends before this; the portal prototype currently **blocks** ໃບແຈ້ງມີ on mortgaged parcels — confirm this matches legacy)
- [ ] How is a mortgage **released / discharged** (loan repaid) recorded?
- [ ] Which **parties / documents** must be entered (lender, borrower, notary, contract copy)?
- [ ] Is the **stamp duty / service fee** computed by the valuation/fee engine (EP6) or entered manually here?
- [ ] Exact meaning of the garbled **"stamp" (ໃບໄຊທາ / ສ່ວນແດ່ນ)** line [verify].

## Notes

- This maps directly to the **existing mortgage feature in the citizen-portal prototype** — good alignment check.
- The fee portion (steps 14–18) overlaps EP6 (valuation + fee calc) — the mortgage triggers a fee document via the same barcode/stamp path.
