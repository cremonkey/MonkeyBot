#!/usr/bin/env python3
# SPEC-31 — surgical edits to ai_agent_profiles id 6 (sales_system_prompt).
# CRLF-safe: read/write with newline='' and verify char + CR counts.
import io, re, sys

SRC = '/tmp/claude-0/profile6.txt'
with io.open(SRC, 'r', encoding='utf-8', newline='') as f:
    t = f.read()
orig_len, orig_cr = len(t), t.count('\r')

# 1) room names -> the names printed on the website (the customer opens the link
#    and must see the same name). Old names survive as aliases in pricing_config.
RENAMES = [
    ('Royal Suite Pool View', 'Family Suite'),
    ('Royal Suite', 'Family Suite'),
    ('Junior Suite Pool View', 'Junior Suite with Pool View'),
    ('Double Garden View', 'Deluxe Double or Twin Room'),
    ('Double Pool View', 'Deluxe Double Room with Balcony'),
    ('Triple Garden View', 'Standard Triple Room'),
    ('Single Room', 'Standard Single Room'),
]
counts = {}
for a, b in RENAMES:
    counts[a] = t.count(a)
    t = t.replace(a, b)

# 2) owner correction: single is 3,510 not 3,500
n_price = t.count('3,500')
t = t.replace('3,500', '3,510')

# 3) the seventh room (same price as the twin room, bigger) — offered on demand,
#    never added to the two-person list (that would make the reply a dump).
anchor = '* **Deluxe Double or Twin Room** - فردان - 4,500 جنيه لليلة.\r\n'
if anchor not in t:
    anchor = anchor.replace('\r\n', '\n')
assert anchor in t, 'room-list anchor not found'
nl = '\r\n' if '\r\n' in t else '\n'
t = t.replace(
    anchor,
    anchor + '* **King Room with Garden View** - فردان - 4,500 جنيه لليلة (نفس السعر بس أوسع: 67 متر بسرير كينج وسريرين).' + nl,
    1)

ex_anchor = '✅ «لفردين عندنا Deluxe Double or Twin Room بـ4,500 جنيه لليلة، وDeluxe Double Room with Balcony بـ5,500 جنيه لليلة 😊 تحب أنهي واحدة فيهم؟»'
assert ex_anchor in t, 'two-person example not found'
t = t.replace(ex_anchor, ex_anchor + nl + nl +
    'العميل: «فيه غرفة أوسع بنفس السعر؟»' + nl + nl +
    '✅ «أيوه، King Room with Garden View بنفس الـ4,500 جنيه لليلة بس مساحتها 67 متر بسرير كينج وسريرين 😊 تحب دي؟»' + nl, 1)

# 4) photos section — appended at the end. NO URL appears anywhere in the prompt:
#    examples leak, and a leaked link is a customer-visible wrong link.
MEDIA = nl + nl + nl.join([
    '# صور الغرف والمكان',
    '',
    'عندك أداة اسمها get_room_media بترجّعلك لينك الصور الرسمي وخدمات كل غرفة أو جناح، وكمان صور المنتجع كله للداي يوز.',
    '',
    '**أي لينك بتبعته لازم يكون خارج من الأداة دي.** ممنوع تكتب لينك من عندك، وممنوع تعيد لينك اتكتب قبل كده في الشات، وممنوع تخمّن. لو بعتّ لينك مش من الأداة، الرسالة مش هتوصل للعميل أصلاً.',
    '',
    'العميل: «ممكن أشوف صور الجناح؟»',
    '',
    '✅ تنادي الأداة بالاسم اللي العميل قاله، وترد بجملة واحدة فيها اللينك زي ما رجع بالحرف + سؤال واحد. مثال للشكل: «اتفضل صور الجناح 😊 تحب أحجزلك فيه؟» واللينك جوه الجملة.',
    '',
    'العميل: «ابعتلي صور» - من غير ما يحدد',
    '',
    '✅ «تحب تشوف صور أنهي غرفة بالظبط؟ ولا أبعتلك صور المنتجع كله؟»',
    '',
    '❌ إنك تختار غرفة من عندك وتبعت لينكها.',
    '',
    'لو الأداة ردّت إن الاسم مش واضح أو مش متطابق: اسأل العميل يحدد، وماتبعتش أي لينك في الرسالة دي.',
    '',
    'ولو سأل «الغرفة دي فيها إيه؟» أو عن الخدمات: نفس الأداة بترجّعلك الخدمات - قولهم في جملة قصيرة عادية، من غير نقط ولا شرطات ولا خط عريض.',
])
t = t + MEDIA

with io.open('/tmp/claude-0/profile6_new.txt', 'w', encoding='utf-8', newline='') as f:
    f.write(t)

print('renames:', counts)
print('3,500 ->3,510 :', n_price)
print('len %d -> %d ; CR %d -> %d' % (orig_len, len(t), orig_cr, t.count('\r')))
