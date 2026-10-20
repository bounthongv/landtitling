# Lao Land Title OCR Architecture (Python & PHP Integration)

This document outlines the complete technical strategy, open-source setup, and PHP-to-Python integration pipeline to extract structured data from Lao Land Titles (**ໃບຕາດິນ**).

---

## 1. System Architecture

To avoid heavy processing inside PHP, PHP acts strictly as the web client, while a self-hosted **Python Microservice (FastAPI)** handles image pre-processing, text detection, and key-value matching.

```
+--------------------------+                     +-----------------------------------+
|     PHP Web Application  |  1. POST /ocr (Img) |     Python Microservice (FastAPI) |
|                          | ------------------> |                                   |
| - Handles uploads        |                     | - Image Deskew & Contrast Adjustment|
| - Saves data to DB       | 2. JSON Response    | - Background Dotted Line Removal  |
| - Renders UI             | <------------------ | - EasyOCR / PaddleOCR Processing  |
+--------------------------+                     | - Key-Value Regex Data Extractor  |
                                                 +-----------------------------------+

```

---

## 2. Python Microservice Implementation

### Prerequisites & Dependencies

Create a file named `requirements.txt`:

```txt
fastapi
uvicorn
opencv-python
numpy
easyocr
pydantic
python-multipart

```

Install the dependencies:

```bash
pip install -r requirements.txt

```

---

### OpenCV Image Cleaning & FastAPI Endpoint (`app.py`)

Save the following script as `app.py`. It cleans background dotted lines, increases character contrast, performs OCR, and returns key land title fields.

```python
import cv2
import numpy as np
import easyocr
from fastapi import FastAPI, File, UploadFile
from fastapi.responses import JSONResponse
import re

app = FastAPI(title="Lao Land Title OCR API")

# Initialize EasyOCR for Lao ('lo') and English/Numbers ('en')
reader = easyocr.Reader(['lo', 'en'], gpu=False)

def preprocess_image(image_bytes: bytes) -> np.ndarray:
    """Pre-processes document to remove background noise and dotted lines."""
    # Convert byte array to OpenCV image matrix
    nparr = np.frombuffer(image_bytes, np.uint8)
    img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)

    # 1. Convert to Grayscale
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    # 2. Morphological operations to remove dotted lines
    # Vertical and horizontal kernel to smooth background guide dots
    kernel = np.ones((2, 2), np.uint8)
    cleaned = cv2.morphologyEx(gray, cv2.MORPH_CLOSE, kernel)

    # 3. Adaptive Thresholding to sharpen Lao text against background map
    binary = cv2.adaptiveThreshold(
        cleaned, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, 
        cv2.THRESH_BINARY, 11, 2
    )

    return binary

def parse_land_title_fields(ocr_results):
    """Maps recognized OCR bounding boxes to target database fields."""
    full_text = " ".join([text for _, text, prob in ocr_results if prob > 0.3])
    
    # Extract structural patterns using Regular Expressions
    data = {
        "land_title_no": None,   # ເລກທີ
        "location_code": None,   # ທີ່ຕັ້ງຕອນດິນ
        "parcel_no": None,       # ຕອນດິນເລກທີ
        "owner_name": None,      # ອອກໃຫ້ແກ່
        "raw_text": full_text
    }

    # Regex patterns for standard Lao document fields
    title_match = re.search(r'ເລກທີ\s*[:\.]?\s*(\d+)', full_text)
    if title_match:
        data["land_title_no"] = title_match.group(1)

    location_match = re.search(r'ທີ່ຕັ້ງຕອນດິນ\s*[:\.]?\s*([\d\.\s]+)', full_text)
    if location_match:
        data["location_code"] = location_match.group(1).replace(" ", "")

    parcel_match = re.search(r'ຕອນດິນເລກທີ\s*[:\.]?\s*(\d+)', full_text)
    if parcel_match:
        data["parcel_no"] = parcel_match.group(1)

    return data

@app.post("/ocr")
async def process_land_title(file: UploadFile = File(...)):
    try:
        contents = await file.read()
        processed_img = preprocess_image(contents)
        
        # Run OCR on preprocessed image matrix
        ocr_results = reader.readtext(processed_img)
        
        # Structure and map extracted text
        extracted_data = parse_land_title_fields(ocr_results)
        
        return JSONResponse(content={"status": "success", "data": extracted_data})
    except Exception as e:
        return JSONResponse(status_code=500, content={"status": "error", "message": str(e)})

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000)

```

---

## 3. PHP Integration Client

Add this standard cURL execution block into your existing PHP script to transmit uploaded images to the Python service and parse the output.

```php
<?php

function processLandTitleOCR($imageFilePath) {
    $pythonApiUrl = 'http://127.0.0.1:8000/ocr';

    if (!file_exists($imageFilePath)) {
        return ['error' => 'File not found'];
    }

    // Prepare multipart form payload
    $cFile = new CURLFile($imageFilePath, mime_content_type($imageFilePath), basename($imageFilePath));
    $postData = array('file' => $cFile);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $pythonApiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $result = json_decode($response, true);
        return $result['data'];
    } else {
        return [
            'error' => 'Failed to reach Python OCR service',
            'details' => $response
        ];
    }
}

// Example Execution in PHP:
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['land_title_image'])) {
    $uploadedFile = $_FILES['land_title_image']['tmp_name'];
    
    // Call Python API
    $extractedData = processLandTitleOCR($uploadedFile);

    // Save extracted parameters directly to Database
    $titleNo = $extractedData['land_title_no'] ?? null;
    $locationCode = $extractedData['location_code'] ?? null;
    $parcelNo = $extractedData['parcel_no'] ?? null;

    /*
     * Example PDO Database Insertion:
     * $stmt = $pdo->prepare("INSERT INTO land_titles (title_no, location_code, parcel_no) VALUES (?, ?, ?)");
     * $stmt->execute([$titleNo, $locationCode, $parcelNo]);
     */

    header('Content-Type: application/json');
    echo json_encode([
        'message' => 'OCR Processed successfully',
        'extracted' => $extractedData
    ]);
}
?>

```

---

## 4. Execution Steps

1. **Start the Python API**:
```bash
python app.py

```


*(The server starts locally at `[http://127.0.0.1:8000](http://127.0.0.1:8000)`)*
2. **Trigger from PHP**:
Send an image upload request to your PHP script, which proxies the binary data to the local FastAPI port, extracts clean JSON text, and writes the structured records directly into your database.
