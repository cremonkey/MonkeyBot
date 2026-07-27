#!/usr/bin/env python3
"""SPEC-31 live suite through the real webchat path (widget bound to profile 6).
Each case uses a FRESH session so no poisoned history primes the model."""
import json, time, urllib.request, urllib.parse, uuid, sys

URL = 'https://bot.cremonkey.com/webchat/send'
WIDGET = '0b9ac3dc1b62303001d29ca9'

def send(session, msg):
    data = urllib.parse.urlencode({
        'widget_key': WIDGET, 'session_key': session, 'message': msg
    }).encode()
    req = urllib.request.Request(URL, data=data, headers={
        'User-Agent': 'spec31-suite', 'X-Requested-With': 'XMLHttpRequest'})
    with urllib.request.urlopen(req, timeout=120) as r:
        body = r.read().decode('utf-8', 'replace')
    try:
        j = json.loads(body)
    except Exception:
        return '<<NON-JSON>> ' + body[:300]
    return j.get('reply') or j.get('message') or json.dumps(j, ensure_ascii=False)[:300]

CASES = [
    ('صور الجناح بالاسم',      ['ممكن أشوف صور الفاميلي سويت؟']),
    ('صور بكلمة عامية',        ['ابعتلي صور الدبل بول فيو']),
    ('صور من غير تحديد',       ['ابعتلي صور']),
    ('غرفة مش موجودة',         ['عايز صور الفيلا']),
    ('صور المكان/الداي يوز',   ['ممكن صور المكان؟']),
    ('خدمات الغرفة',           ['الجونيور سويت فيها إيه؟']),
    ('صور بعد سؤال سعر (سياق)', ['عايز إقامة لفردين', 'ابعتلي صور التانية']),
    ('regression: داي يوز',    ['كام سعر الداي يوز؟']),
    ('regression: لفردين',     ['عايز إقامة لفردين']),
    ('regression: طفل 13',     ['احنا فردين وطفل 13 سنة']),
]

if __name__ == '__main__':
    only = int(sys.argv[1]) if len(sys.argv) > 1 else None
    for i, (label, msgs) in enumerate(CASES):
        if only is not None and i != only:
            continue
        s = uuid.uuid4().hex
        print('=' * 70)
        print('#%d %s' % (i, label))
        for m in msgs:
            print('  Q: ' + m)
            t0 = time.time()
            print('  A: ' + send(s, m).replace('\n', '\n     '))
            print('  (%.1fs)' % (time.time() - t0))
        sys.stdout.flush()
        time.sleep(9)   # stay under the 8-new-sessions/min IP limit
