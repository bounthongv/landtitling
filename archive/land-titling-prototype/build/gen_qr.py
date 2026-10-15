#!/usr/bin/env python3
# Regenerate QR codes with geo: URIs for Google Maps navigation
import segno, base64, io, re, os

# parcel key -> (lat, lon, label)
# Approximate Savannakhet Province coordinates for each demo parcel
parcels = {
    'P1': (16.5500, 104.7500, '0130.001.02.011.0033 - Dongthep'),
    'P2': (16.5600, 104.7600, '0130.002.03.021.0344 - Xiengthonton'),
    'P3': (16.5700, 104.7700, '0130.003.05.013.0009 - Nonsavang'),
    'P4': (16.5400, 104.7400, '0130.004.01.032.0765 - Thahae'),
    'P5': (16.5800, 104.7800, '0130.005.04.041.0211 - Hadsaykham'),
    'P6': (16.5300, 104.7300, '0130.006.02.052.0688 - Chansavang'),
}

out_dir = os.path.join(os.path.dirname(__file__), 'qr')
os.makedirs(out_dir, exist_ok=True)

b64 = {}
for key, (lat, lon, label) in parcels.items():
    # geo: URI - opens in Google Maps / Apple Maps / any maps app
    payload = f'geo:{lat},{lon}?q={lat},{lon}({label})'
    img = segno.make(payload, error='m')
    buf = io.BytesIO()
    img.save(buf, kind='png', scale=8, dark='#0b3d2e', light=None, border=1)
    b64[key] = base64.b64encode(buf.getvalue()).decode()
    with open(os.path.join(out_dir, key + '.b64'), 'w') as f:
        f.write(b64[key])
    print(key, payload, '->', len(b64[key]), 'chars')

# Inject into index.html
html_path = os.path.join(os.path.dirname(__file__), '..', 'index.html')
html = open(html_path, encoding='utf-8').read()

def replace_qr(m):
    key = m.group(1)
    return f'{key}:"data:image/png;base64,{b64[key]}"'

new, n = re.subn(r'(P[1-6]):"data:image/png;base64,[^"]*"', replace_qr, html)
assert n == 6, f'expected 6 QR replacements, got {n}'
assert 'base64,data:image' not in new, 'DOUBLE PREFIX AGAIN'
open(html_path, 'w', encoding='utf-8').write(new)

print('QR lines replaced:', n, '| double-prefix present:', 'base64,data:image' in new)