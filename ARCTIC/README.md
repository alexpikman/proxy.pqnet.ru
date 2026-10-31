# CFCP Protocol — interactive concept site

Static, dependency-free website in Russian. Open `index.html` locally or publish the complete folder to any static web host.

## Files
- `index.html` — overview, architecture, cryptographic building blocks and standards.
- `protocol.html` — separate interactive animation page.
- `assets/styles.css` — responsive visual system and layout.
- `assets/main.js` — mobile navigation.
- `assets/protocol.js` — requestAnimationFrame canvas simulation and controls.

## Run locally
Open `index.html` in a modern browser. For a local web server, run from this directory:

```bash
python -m http.server 8080
```

Then visit `http://127.0.0.1:8080`.

## Important technical note
CFCP is presented as an author-created design concept, not an established IETF standard or a proven DPI-evasion protocol. The animation is illustrative. References point to existing IETF RFCs for TLS 1.3, QUIC, DTLS 1.3, ECH, HKDF, ChaCha20-Poly1305 and Merkle Tree structures.
