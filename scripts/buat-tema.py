"""Membuat resources/css/tema.css: palet slate (permukaan) & aksen sebagai variabel CSS, tema terang,
dan penyesuaian teks status untuk tema terang. Dijalankan sekali; hasilnya di-commit."""
import re, glob, os

os.chdir(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
SH = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]


def hx(h):
    h = h.lstrip('#')
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def trip(t):
    return '%d %d %d' % t


def mix(a, b, t):  # t = porsi b
    return tuple(round(a[i] * (1 - t) + b[i] * t) for i in range(3))


def lum(c):
    f = lambda v: (v / 255 / 12.92) if v / 255 <= 0.03928 else (((v / 255) + 0.055) / 1.055) ** 2.4
    r, g, b = c
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)


def cr(a, b):
    la, lb = lum(a), lum(b)
    if la < lb:
        la, lb = lb, la
    return (la + 0.05) / (lb + 0.05)


# ── Permukaan ────────────────────────────────────────────────────────────
SLATE_GELAP = dict(zip(SH, [hx(x) for x in ['f8fafc', 'f1f5f9', 'e2e8f0', 'cbd5e1', '94a3b8', '8696ad', '475569', '334155', '1e293b', '0f172a', '020617']]))
SLATE_NAVY = dict(zip(SH, [hx(x) for x in ['f6f9fc', 'ecf1f7', 'dde6f0', 'c5d3e2', 'a3b8cf', '86a0bd', '34506c', '1f3a56', '13283f', '0a1a2e', '040f1c']]))
SLATE_TERANG = dict(zip(SH, [hx(x) for x in ['020617', '0f172a', '1e293b', '334155', '475569', '526077', '94a3b8', 'cbd5e1', 'e2e8f0', 'ffffff', 'f8fafc']]))

# Kontras teks sekunder harus lolos AA di atas permukaan.
assert cr(SLATE_GELAP[500], SLATE_GELAP[800]) >= 4.5, 'gelap 500/800'
assert cr(SLATE_NAVY[500], SLATE_NAVY[800]) >= 4.5, 'navy 500/800'
assert cr(SLATE_NAVY[400], SLATE_NAVY[700]) >= 4.5, 'navy 400/700'
assert cr(SLATE_TERANG[500], SLATE_TERANG[950]) >= 4.5, 'terang 500/950'
assert cr(SLATE_TERANG[400], SLATE_TERANG[800]) >= 4.5, 'terang 400/800'

# ── Aksen ─────────────────────────────────────────────────────────────────
VIOLET = dict(zip(SH, [(245, 243, 255), (237, 233, 254), (221, 214, 254), (196, 181, 253), (167, 139, 250), (139, 92, 246), (124, 58, 237), (109, 40, 217), (91, 33, 182), (76, 29, 149), (46, 16, 101)]))
TEAL = dict(zip(SH, [(240, 253, 250), (204, 251, 241), (153, 246, 228), (94, 234, 212), (45, 212, 191), (20, 184, 166), (13, 148, 136), (15, 118, 110), (17, 94, 89), (19, 78, 74), (4, 47, 46)]))


def ramp(base600):
    b = hx(base600)
    W, K = (255, 255, 255), (0, 0, 0)
    return {50: mix(b, W, .94), 100: mix(b, W, .86), 200: mix(b, W, .72), 300: mix(b, W, .55), 400: mix(b, W, .38),
            500: mix(b, W, .14), 600: b, 700: mix(b, K, .18), 800: mix(b, K, .36), 900: mix(b, K, .52), 950: mix(b, K, .68)}


AKSEN_BIRU = ramp('#0072BC')  # biru merek Sedaya Realty (teks putih di atasnya 5,2:1)
AKSEN = {'biru': AKSEN_BIRU}

for nama, r in AKSEN.items():  # teks aksen (300/400) harus terbaca di atas permukaan gelap
    assert cr(r[400], SLATE_GELAP[900]) >= 4.5, 'aksen 400 ' + nama
    assert cr(r[300], SLATE_NAVY[800]) >= 4.5, 'aksen 300 ' + nama
    assert cr(r[700], SLATE_TERANG[900]) >= 4.5, 'aksen 700 terang ' + nama


def blok(prefix, ramp_):
    return ''.join('    --%s-%d: %s;\n' % (prefix, k, trip(v)) for k, v in ramp_.items())


def aksen_terang(r):
    """Tema terang: teks aksen (50–400) dipakai sebagai TEKS di atas tint terang, jadi dibalik ke nuansa gelap."""
    return {50: r[950], 100: r[950], 200: r[900], 300: r[800], 400: r[700]}


css = ['/* DIBUAT OTOMATIS oleh scripts/buat-tema.py (jalankan: python scripts/buat-tema.py) — palet permukaan (--s-*) & aksen (--a-*) sebagai variabel CSS.\n'
       '   Tailwind: `slate` = permukaan, `violet` = aksen (nama kelas dipertahankan agar kode lama tetap jalan; nilainya BIRU merek).\n'
       '   Bawaan = tema gelap (navy merek). Tema terang aktif lewat atribut <html data-tema="terang">. */\n']

css.append(':root {\n    color-scheme: dark;\n' + blok('s', SLATE_NAVY) + blok('a', AKSEN_BIRU) + '}\n')
css.append("html[data-tema='terang'] {\n    color-scheme: light;\n" + blok('s', SLATE_TERANG)
           + ''.join('    --a-%d: %s;\n' % (k, trip(v)) for k, v in aksen_terang(AKSEN_BIRU).items()) + '}\n')

# ── Teks putih & teks status di tema terang ───────────────────────────────
SOLID_WARNA = ['violet', 'rose', 'emerald', 'blue', 'sky', 'amber', 'orange', 'indigo', 'teal', 'fuchsia', 'red', 'green', 'cyan', 'pink', 'purple', 'yellow']
solid = []
for w in SOLID_WARNA:
    for s in (400, 500, 600, 700):
        solid.append('[class*="bg-%s-%d"]' % (w, s))
solid += ['[class*="bg-gradient"]', '[class*="from-"]']
SOLID = ',\n    '.join(solid)
T = "html[data-tema='terang']"
css.append("%s .text-white,\n%s .hover\\:text-white:hover { color: rgb(var(--s-50)); }" % (T, T))
css.append("%s :is(\n    %s\n).text-white,\n%s :is(\n    %s\n) .text-white { color: #fff; }\n" % (T, SOLID, T, SOLID))

css.append("/* Lencana status berwarna dinamis (warna dari Pengaturan > Warna Status, dipasang inline lewat --w): teksnya digelapkan di tema terang. */\n"
           "html[data-tema='terang'] [style*='--w'] { color: color-mix(in srgb, var(--w) 55%, #000) !important; }\n")

HEX = {
    'emerald': ('047857', '065f46', '064e3b'), 'rose': ('be123c', '9f1239', '881337'), 'amber': ('b45309', '92400e', '78350f'),
    'sky': ('0369a1', '075985', '0c4a6e'), 'blue': ('1d4ed8', '1e40af', '1e3a8a'), 'teal': ('0f766e', '115e59', '134e4a'),
    'orange': ('c2410c', '9a3412', '7c2d12'), 'fuchsia': ('a21caf', '86198f', '701a75'), 'indigo': ('4338ca', '3730a3', '312e81'),
    'red': ('b91c1c', '991b1b', '7f1d1d'), 'green': ('15803d', '166534', '14532d'), 'yellow': ('a16207', '854d0e', '713f12'),
    'cyan': ('0e7490', '155e75', '164e63'), 'pink': ('be185d', '9d174d', '831843'), 'purple': ('7e22ce', '6b21a8', '581c87'),
    'lime': ('4d7c0f', '3f6212', '365314'),
}
pakai = set()
for f in glob.glob('resources/js/**/*.vue', recursive=True):
    s = open(f, encoding='utf-8').read()
    for m in re.finditer(r'(?<![\w:-])((?:hover:|group-hover:)?)text-(%s)-(200|300|400|500)(/\d+)?(?![\w/])' % '|'.join(HEX), s):
        pakai.add((m.group(1), m.group(2), int(m.group(3)), (m.group(4) or '')[1:]))
        # varian tanpa prefiks tetap dibuat bila hanya hover yang dipakai
pakai = sorted(pakai)
rules = []
for pref, warna, shade, alfa in pakai:
    idx = {500: 0, 400: 0, 300: 1, 200: 2}[shade]
    nilai = '#' + HEX[warna][idx]
    if alfa:
        nilai += '%02X' % round(int(alfa) * 255 / 100)
    cls = 'text-%s-%d' % (warna, shade) + ('\\/' + alfa if alfa else '')
    if pref == '':
        sel = '%s .%s' % (T, cls)
    elif pref == 'hover:':
        sel = '%s .hover\\:%s:hover' % (T, cls)
    else:
        sel = '%s .group:hover .group-hover\\:%s' % (T, cls)
    rules.append('%s { color: %s; }' % (sel, nilai))
css.append('/* Teks status (200–400) dibuat lebih gelap di tema terang — %d aturan sesuai pemakaian di kode. */\n' % len(rules) + '\n'.join(rules) + '\n')

open('resources/css/tema.css', 'w', encoding='utf-8', newline='\n').write('\n'.join(css))
print('tema.css ditulis;', len(rules), 'aturan teks status;', len(open('resources/css/tema.css', encoding='utf-8').read()), 'karakter')
