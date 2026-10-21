
Yes, the advice you received is largely accurate: multimodal AI models like Gemini 2.5 Flash perform drastically better on Lao script than Tesseract 5, and reaching 90%+ accuracy on printed Lao text is realistic.
However, because land titles (ໃບຕາດິນ) are legal documents where a single wrong digit or misspelled name has serious consequences, there are critical nuances and risks you should be aware of.
1. Why Tesseract 5 Stumbled on Lao Script
Tesseract relies heavily on segmented character boundary recognition. Lao script presents severe challenges for traditional OCR engines like Tesseract:
Complex vertical stacking: Lao has tone marks (ໄມ້ເອກ, ໄມ້ໂທ, etc.) and vowels placed above and below base consonants (e.g., ພ, ຟ, ຢ). Tesseract frequently cuts off or misreads these marks.
No word spaces: Words run together continuously without clear spaces, making lexical segmentation difficult.
Scan artifacts: Land titles often have stamps, background watermarks, signatures, folds, and discoloration, which confuse Tesseract’s binarization algorithms.
2. Can Gemini 2.5 Flash Achieve ~90% Accuracy?
Yes, for clear to moderately clean printed text.
Unlike Tesseract, Gemini is a multimodal language model. It does not just recognize visual patterns; it understands the grammar, vocabulary, and context of the Lao language. If a vowel or tone mark is slightly faded, Gemini can infer the correct word based on context.
The Big Caveat for Land Titles: Hallucination
While Gemini can easily achieve 90–95% accuracy on running text, you must watch out for:
Numbers & Coordinates: Land parcel numbers (ເລກຕອນດິນ), deed numbers (ເລກທີ), issue dates, and land area (ເນື້ອທີ່ ເປັນ ຕາແມັດ). Language models have a tendency to "hallucinate" or misread blurry digits because digits cannot be corrected by linguistic context.
Proper Nouns / Village & Personal Names: If a name is uncommon or handwritten, the model might guess a more common Lao name.
Handwritten Sections: If older land titles have handwritten annotations or surveyor signatures, accuracy will drop significantly (often below 70%).
3. How About Gemini 2.5 Flash-Lite?
Pros: Considerably cheaper and faster than standard Flash.
Cons for your use case: Flash-Lite has lower visual resolution and less parameter capacity. On high-density legal documents, it tends to:
Miss small sub/superscript vowels.
Omit small table cells.
Confuse visually similar Lao consonants (e.g., ດ vs. ຄ, ບ vs. ປ, ທ vs. ໄ).
Verdict: For a legal land titling project, stick to Gemini 2.5 Flash (or even test 2.5 Pro). The cost difference is negligible compared to the cost of erroneous land data.
4. Alternative & Recommended Industry Approaches
If you want the most robust pipeline for land title digitization, consider these alternatives:
Option A: Google Cloud Vision API (Document Text Detection)
How it works: Google Cloud Vision has built-in native support for Lao script. Unlike Gemini, it is a dedicated OCR engine that outputs exact word bounding boxes, coordinates, and text without generative hallucinations.
Best for: Raw text extraction without the risk of the model inventing data.
Option B: The "Hybrid Pipeline" (Best Practice)
Most enterprise document projects do not rely on raw OCR alone. The recommended workflow is:
Image Preprocessing: Deskew, crop margins, and normalize contrast using OpenCV or Python Pillow.
Structured Extraction with Gemini 2.5 Flash: Feed the document image directly to Gemini 2.5 Flash and instruct it to extract specific fields into a strict JSON schema (e.g., owner name, province, district, village, parcel number, area).
Validation & Confidence Flagging: Write validation scripts (e.g., check if the parcel number matches expected cadastral numbering formats; check if district/province match valid Lao administrative divisions).
Human-in-the-Loop (HITL): Any field with low resolution or unexpected format is flagged for manual review by a clerk.

---

## Addendum: LandTitling implementation notes & OCR improvement ideas (Dr. Vongxaya / Hermes)
*Added after live testing — no implementation yet, ideas only.*

### What we actually found in production (Oct 2026)
- The dual-method pipeline (Tesseract + Gemini 2.5 Flash) is live in `lib/ocr.php` / `api/v1/scans.php`. Both engines now converge on one code path and emit the same canonical `structured_fields` keys (title_no, province, district_name, village_name, owner_name, owner_id_number, parcel_no, area_sqm, area_lan, land_use_class, issued_date).
- Tesseract's honest ceiling on the low-res Vientiane sample is ~5 fields (province, village_name, area_sqm, village_id); it cannot read title_no, owner, parcel, or land_use from noisy text.
- Gemini 2.5 Flash extracts the full field set (~10 fields) when it responds — the OCR box then shows the complete structured JSON and every field reaches the Confirm/Edit form.
- **Critical bug found & fixed:** Gemini 2.5 Flash is a *thinking* model. Its chain-of-thought burned ~3,812 of 4,096 output tokens, truncating the JSON mid-field (`"land_use": "ກະສິກຳ-ລ້ຽງສັດ` with no closing quote). `json_decode` then failed and `structured_fields` came back null → nothing reached the form. Fixed with `thinkingConfig: {thinkingBudget: 0}` + `maxOutputTokens: 8192`.
- **Caveat for names/numbers:** Gemini's `province` is sometimes wrong (extracted ຈຳປາສັກ for a ວຽງຈັນ title). Numbers/coordinates and proper nouns are the hallucination risk the original doc warns about — confirmed live.
- **Geography gate:** district_id/village_id are only auto-set on a ≥90% `similar_text` match against the national DB (18 provinces/148 districts/8,828 villages). Unmatched names → null → officer picks in review. Prevents wrong-fee attribution.

### OCR improvement ideas (parked — do NOT implement yet)
1. **Number-field double-check (anti-hallucination):** For parcel_no / title_no / area_sqm / dates, ask Gemini to ALSO return the OCR-like raw token (e.g. "ເລກທີ ... : 210") alongside the parsed value, so a clerk can eyeball the digit source. Optionally cross-check against a regex (parcel format `\d{3}\.\d{3}\.\d{3,4}`).
2. **Model tier toggle:** Allow selecting Gemini 2.5 Flash vs Flash-Lite per upload; Flash-Lite is cheaper/faster but the original doc correctly warns it omits small cells and confuses similar consonants (ດ/ຄ, ບ/ປ). Default stays Flash.
3. **Google Cloud Vision as a Tesseract-free fallback:** Vision (Document Text Detection) has native Lao support and exact word bounding boxes with no generative hallucination — good as a third engine or a cross-check of Gemini's numbers.
4. **Two-pass Gemini prompt:** First pass = full raw text; second pass = feed that text (not the image) back and ask for strict JSON extraction. Reduces visual hallucination on numbers because the model reasons from clean text.
5. **Per-field confidence from Gemini:** Today we assign a flat 95 to Gemini fields. Gemini can return `confidence` per field (0–100) when asked; plumb it into `field_confidence` so the review screen flags low-confidence fields in color.
6. **Schema validation at the gate:** Beyond geography, validate issued_date format, area > 0 and < a sane ceiling, and title_no against the cadastral numbering pattern — reject or flag outliers before the form loads.
7. **Better image upscaling before Gemini:** The server preprocesses via GD. Deskew + crop margins (Pillow/OpenCV, or Gemini-side) improves small-cell reads on skewed scans. (Note: server CPU lacks AVX2/AVX512, so heavy OpenCV/PyTorch is blocked — keep it light or do it in the browser.)
8. **Preserve a per-scan raw-text audit trail:** Keep the full Tesseract raw text AND the Gemini `raw_text` in `ocr_raw` (don't overwrite with only the structured JSON) so any field can be re-verified against the source later.
