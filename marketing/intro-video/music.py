"""Original background music + UI sound cues for the intro video, synthesised from scratch
(no samples, nothing licensed). Plain Python, no packages.

    python3 marketing/intro-video/music.py [out.wav]

Calm 96 bpm bed in F major: Fmaj7 – Am7 – Cmaj7 – G6. Sparse under the hook, a whoosh as the
features fall away, the groove arrives on "So I built my own", and it settles on the last chord.
Cue times match the timeline in index.html.
"""
import math
import random
import struct
import sys
import wave

SR = 44100
DUR = 57.0
N = int(SR * DUR)
L = [0.0] * N
R = [0.0] * N
SEND = [0.0] * N          # to the reverb
random.seed(7)

BPM = 96
BEAT = 60 / BPM
BAR = 4 * BEAT
GROOVE_IN = 10.95          # "So I built my own."
GROOVE_OUT = 54.4
END_CHORD = 50.3


def hz(midi):
    return 440.0 * 2 ** ((midi - 69) / 12)


def add(t0, samples, gain=1.0, pan=0.0, send=0.0):
    """Mix a mono buffer in at time t0 (s), panned -1..1, with some sent to the reverb."""
    i0 = int(t0 * SR)
    gl = gain * math.cos((pan + 1) * math.pi / 4)
    gr = gain * math.sin((pan + 1) * math.pi / 4)
    for k, v in enumerate(samples):
        i = i0 + k
        if i < 0:
            continue
        if i >= N:
            break
        L[i] += v * gl
        R[i] += v * gr
        if send:
            SEND[i] += v * gain * send


def env(n, attack, release, total):
    a, r = int(attack * SR), int(release * SR)
    out = []
    for k in range(n):
        e = 1.0
        if k < a:
            e = k / max(1, a)
        if k > total - r:
            e *= max(0.0, (total - k) / max(1, r))
        out.append(e)
    return out


def pad(freq, dur, amp=0.05):
    """Soft, slowly breathing pad: three slightly detuned voices with a little 2nd harmonic."""
    n = int((dur + 1.2) * SR)
    e = env(n, 0.6, 1.4, n)
    out = []
    for k in range(n):
        t = k / SR
        wob = 1 + 0.0025 * math.sin(2 * math.pi * 0.3 * t)
        s = 0.0
        for d in (-0.004, 0.0, 0.0045):
            f = freq * (1 + d) * wob
            s += math.sin(2 * math.pi * f * t) + 0.18 * math.sin(4 * math.pi * f * t)
        out.append(s * amp * e[k] * (0.85 + 0.15 * math.sin(2 * math.pi * 0.25 * t)))
    return out


def pluck(freq, amp=0.16, length=1.4):
    """Karplus–Strong pluck: a soft, glassy piano-ish note."""
    p = max(2, int(SR / freq))
    buf = [random.uniform(-1, 1) for _ in range(p)]
    out = []
    n = int(length * SR)
    prev = 0.0
    for k in range(n):
        v = buf[k % p]
        nv = 0.497 * (v + prev)
        prev = v
        buf[k % p] = nv
        out.append(v * amp * (1 - k / n) ** 1.5)
    # soften the attack
    for k in range(min(60, n)):
        out[k] *= k / 60
    return out


def bell(freq, amp=0.07, length=2.2):
    n = int(length * SR)
    return [amp * math.exp(-3.2 * k / SR) * (math.sin(2 * math.pi * freq * k / SR) + 0.35 * math.sin(2 * math.pi * freq * 2.76 * k / SR) * math.exp(-6 * k / SR)) for k in range(n)]


def bass(freq, dur, amp=0.16):
    n = int(dur * SR)
    out = []
    for k in range(n):
        t = k / SR
        s = math.sin(2 * math.pi * freq * t) + 0.25 * math.sin(4 * math.pi * freq * t)
        e = min(1, k / 300) * math.exp(-1.6 * t) * (1 - k / n) ** 0.5
        out.append(math.tanh(1.4 * s) * amp * e)
    return out


def kick(amp=0.55):
    n = int(0.42 * SR)
    out, ph = [], 0.0
    for k in range(n):
        t = k / SR
        f = 45 + 95 * math.exp(-t * 28)
        ph += 2 * math.pi * f / SR
        out.append(math.sin(ph) * math.exp(-t * 9) * amp)
    return out


def noise_burst(length, decay, amp, hp=0.0):
    n = int(length * SR)
    out, last, lp = [], 0.0, 0.0
    for k in range(n):
        x = random.uniform(-1, 1)
        if hp:
            lp += hp * (x - lp)
            x = x - lp       # crude high-pass
        out.append(x * amp * math.exp(-k / SR / decay))
    return out


def snap():
    tone = [0.25 * math.sin(2 * math.pi * 1800 * k / SR) * math.exp(-k / SR / 0.02) for k in range(int(0.15 * SR))]
    nz = noise_burst(0.15, 0.045, 0.22, hp=0.3)
    return [a + b for a, b in zip(tone, nz)]


def hat(amp=0.05):
    return noise_burst(0.06, 0.012, amp, hp=0.85)


def whoosh(length=1.2, amp=0.12, down=True):
    n = int(length * SR)
    out, lp = [], 0.0
    for k in range(n):
        x = k / n
        cutoff = 0.004 + 0.06 * ((1 - x) if down else x)   # a soft, airy sweep, not hiss
        lp += cutoff * (random.uniform(-1, 1) - lp)
        out.append(lp * amp * 9 * math.sin(math.pi * x) ** 2)
    return out


def tick(freq=2400, amp=0.12):
    n = int(0.05 * SR)
    return [(amp * math.sin(2 * math.pi * freq * k / SR) + random.uniform(-1, 1) * amp * 0.5) * math.exp(-k / SR / 0.008) for k in range(n)]


def pop(amp=0.22):
    """Soft 'thock' for a card landing."""
    n = int(0.22 * SR)
    out, ph = [], 0.0
    for k in range(n):
        t = k / SR
        f = 220 + 320 * math.exp(-t * 40)
        ph += 2 * math.pi * f / SR
        out.append(math.sin(ph) * math.exp(-t * 22) * amp)
    return out


# ---------------- the music ----------------
# chords (MIDI): Fmaj7, Am7, Cmaj7/E, G6 — voiced warm and close
CHORDS = [
    ([53, 57, 60, 64], 41),   # F A C E, bass F
    ([52, 57, 60, 64], 45),   # E A C E (Am7 feel), bass A
    ([52, 55, 60, 64], 48),   # E G C E, bass C
    ([50, 55, 59, 64], 43),   # D G B E (G6), bass G
]
bars = int(DUR / BAR) + 1
for b in range(bars):
    t0 = b * BAR
    if t0 >= END_CHORD:
        break
    notes, root = CHORDS[b % 4]
    for i, m in enumerate(notes):
        add(t0, pad(hz(m), BAR, 0.035), pan=(-0.5, -0.15, 0.15, 0.5)[i], send=0.5)
    if t0 >= GROOVE_IN - 0.01:
        # bass: root on 1, a lighter note on the "and" of 3
        add(t0, bass(hz(root), BEAT * 2.4))
        add(t0 + BEAT * 2.5, bass(hz(root + 7), BEAT * 1.2, 0.1))

# final chord rings out under the call to action
for i, m in enumerate([53, 57, 60, 64, 69]):
    add(END_CHORD, pad(hz(m), DUR - END_CHORD, 0.034), pan=(-0.6, -0.3, 0, 0.3, 0.6)[i], send=0.6)
add(END_CHORD, bass(hz(41), 4.0, 0.16))
add(END_CHORD, bell(hz(81), 0.06, 4.0), pan=0.2, send=0.6)

# arpeggio: sparse under the hook (one note per beat, high), 8ths once the groove starts
ARP = [[65, 69, 72, 76], [64, 69, 72, 76], [64, 67, 72, 76], [62, 67, 71, 74]]
t = 0.0
step = 0
while t < GROOVE_OUT:
    b = int(t / BAR)
    pattern = ARP[b % 4]
    grooving = t >= GROOVE_IN
    if grooving or (t > 0.4 and int(round(t / BEAT)) % 2 == 0):
        note = pattern[step % 4] + (12 if not grooving else 0)
        add(t, pluck(hz(note), 0.11 if grooving else 0.07), pan=(-0.35 if step % 2 else 0.35), send=0.45)
    step += 1
    t += BEAT / 2 if grooving else BEAT

# drums from the turning point on
t = GROOVE_IN
beat = 0
while t < GROOVE_OUT:
    if beat % 4 in (0, 2):
        add(t, kick(0.5 if t > 15 else 0.35))
    if beat % 4 in (1, 3) and t > 15:
        add(t, snap(), gain=0.6, pan=0.05, send=0.25)
    add(t, hat(0.045), pan=0.25)
    add(t + BEAT / 2, hat(0.03), pan=-0.25)
    beat += 1
    t += BEAT

# ---------------- sound cues (match index.html) ----------------
add(2.1, bell(hz(84), 0.05), pan=0.15, send=0.6)           # "My team used 4."
add(7.55, whoosh(1.5, 0.11), send=0.3)                    # features fall away
add(10.95, whoosh(0.9, 0.07, down=False), send=0.4)       # into "So I built my own"
add(11.75, bell(hz(77), 0.055), pan=-0.1, send=0.6)       # logo lands
for s in (15.1, 23.4, 32.0, 41.1, 46.1, 50.3):
    add(s - 0.25, whoosh(0.6, 0.04, down=False), send=0.2)  # scene changes
add(16.7, tick(1600, 0.08), pan=-0.2)                       # pick up the card
add(19.05, pop(0.2), pan=0.1, send=0.3)                     # card lands
add(19.1, bell(hz(88), 0.03), pan=0.1, send=0.5)
slash_at = 23.4 + 4.6 + 41 / 22 + 0.25
add(slash_at, tick(2200, 0.09))                             # "/"
add(slash_at + 1.1, tick(1900, 0.1))                        # Enter → task chip
add(slash_at + 1.12, bell(hz(86), 0.03), send=0.5)
for at in (0.8, 0.86):
    add(32.0 + at, tick(2400 if at < 0.83 else 2000, 0.09))  # ⌘ K
for at in (2.55, 3.05):
    add(32.0 + at, tick(2100, 0.07))                         # ↓ ↑
add(32.0 + 3.6, tick(1900, 0.1))                             # ↵ opens the task
send_at = 32.0 + 4.5 + 32 / 24 + 0.4
for k in range(32):                                           # typing the comment, soft
    if k % 2 == 0:
        add(32.0 + 4.5 + k / 24, tick(3000 + random.randint(-300, 300), 0.025), pan=random.uniform(-0.2, 0.2))
add(send_at - 0.1, tick(2400, 0.09))                         # ⌘ ↵
add(send_at, pop(0.12), send=0.3)
add(42.2, tick(1700, 0.1))                                    # Download click
for i in range(6):
    add(42.55 + i * 0.16, tick(3200 + i * 120, 0.03))         # rows stream in
for i in range(4):
    add(46.5 + i * 0.22, pluck(hz((72, 76, 79, 84)[i]), 0.08), pan=(-0.3, -0.1, 0.1, 0.3)[i], send=0.5)  # values

# ---------------- reverb (Schroeder: 4 combs + 2 all-passes) on the send ----------------
def reverb(x):
    out = [0.0] * len(x)
    for d, g in ((1557, 0.80), (1617, 0.79), (1491, 0.81), (1422, 0.80)):
        buf = [0.0] * d
        lp = 0.0
        for i, v in enumerate(x):
            y = buf[i % d]
            lp = y * 0.6 + lp * 0.4          # damp the highs
            buf[i % d] = v + lp * g
            out[i] += y
    for d, g in ((225, 0.5), (556, 0.5)):
        buf = [0.0] * d
        for i in range(len(out)):
            y = buf[i % d]
            v = out[i] + y * g
            buf[i % d] = v
            out[i] = y - v * g
    return out


wet = reverb(SEND)
for i in range(N):
    L[i] += wet[i] * 0.22
    R[i] += wet[max(0, i - 220)] * 0.22   # a little width

# fade in/out, normalise, soft-clip
fade_in, fade_out = int(0.25 * SR), int(2.2 * SR)
peak = max(max(abs(v) for v in L), max(abs(v) for v in R)) or 1
g = 0.9 / peak
out_path = sys.argv[1] if len(sys.argv) > 1 else 'music.wav'
with wave.open(out_path, 'wb') as w:
    w.setnchannels(2)
    w.setsampwidth(2)
    w.setframerate(SR)
    frames = bytearray()
    for i in range(N):
        f = 1.0
        if i < fade_in:
            f = i / fade_in
        if i > N - fade_out:
            f = (N - i) / fade_out
        a = math.tanh(L[i] * g * 1.1) * f
        b = math.tanh(R[i] * g * 1.1) * f
        frames += struct.pack('<hh', int(a * 32000), int(b * 32000))
    w.writeframes(bytes(frames))
print('Wrote', out_path)
