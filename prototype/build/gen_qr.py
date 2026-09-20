#!/usr/bin/env python3
# Regenerate QR codes with real-style Lao parcel codes (5-part code + title no.)
import segno, base64, io, re, os

# parcel key -> (parcel code, title no, village)
parcels = {
    'P1': ('0130.001.02.011.0033', '833', 'DONGTHEP'),
    'P2': ('0130.002.03.021.0344', '512', 'XIENGTHONTON'),
    'P3': ('0130.003.05.013.0009', '1468', 'NONSAVANG'),
    'P4': ('0130.004.01.032.0765', '977', 'THAHAE'),
    'P5': ('0130.005.04.041.0211', '1204', 'HADXAYKHAM'),
    'P6': ('0130.006.02.052.0688', '611', 'CHANSAVANG'),
}

out_dir = os.path.join(os.path.dirname(__file__), 'qr')
os.makedirs(out_dir, exist_ok=True)

b64 = {}
for key, (code, tno, vill) in parcels.items():
    payload = f'LTSVK|{code}|{tno}|{vill}'
    img = segno.make(payload, error='m')
    buf = io.BytesIO()
    img.save(buf, kind='png', scale=8, dark='#0b3d2e', light=None, border=1)
    b64[key] = base64.b64encode(buf.getvalue()).decode()
    with open(os.path.join(out_dir, key + '.b64'), 'w') as f:
        f.write(b64[key])
    print(key, payload, '->', len(b64[key]), 'chars')

# Inject into index.html by replacing the QR const lines (single correct prefix this time)
html_path = os.path.join(os.path.dirname(__file__), '..', 'index.html')
html = open(html_path, encoding='utf-8').read()

def replace_qr(m):
    key = m.group(1)
    return f'{key}:"data:image/png;base64,{b64[key]}"'

new, n = re.subn(r'(P[1-6]):"data:image/png;base64,[^"]*"', replace_qr, html)
assert n == 6, f'expected 6 QR replacements, got {n}'
open(html_path, 'w', encoding='utf-8').write(new)

# verify no double prefix crept in
assert 'base64,data:image' not in new, 'DOUBLE PREFIX AGAIN'
print('QR lines replaced:', n, '| double-prefix present:', 'base64,data:image' in new)