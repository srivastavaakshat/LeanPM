# LeanPM intro video (4:3, 1440×1080, 57 s)

- `index.html`: the video itself, built with the app's own CSS. Open it in a browser and add `#t=18` to the URL to see any moment.
- `render.mjs`: draws every frame in headless Chrome and encodes with ffmpeg.
- `music.py`: the soundtrack, an original piece synthesised from scratch (no samples, nothing licensed), with sound cues timed to the video.

```bash
/opt/homebrew/opt/node@24/bin/node marketing/intro-video/render.mjs marketing/intro-video/LeanPM-intro-4x3.mp4
python3 marketing/intro-video/music.py /tmp/leanpm-music.wav
ffmpeg -y -i marketing/intro-video/LeanPM-intro-4x3.mp4 -i /tmp/leanpm-music.wav -map 0:v -map 1:a -c:v copy \
  -af "loudnorm=I=-16:TP=-1.5:LRA=11" -c:a aac -b:a 192k -ar 48000 -shortest -movflags +faststart \
  marketing/intro-video/LeanPM-intro-4x3-music.mp4
```

If you change a timing in `index.html`, move the matching cue in `music.py`.
