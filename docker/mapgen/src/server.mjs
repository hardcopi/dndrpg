/**
 * The map service.
 *
 * One job: hand the game a generated floor. It exists because the generator is
 * TypeScript and the game is PHP, and both alternatives were worse — a port
 * would be a second copy of two thousand lines that can drift from the
 * original, and generating in the browser would let a client choose its own
 * dungeon.
 *
 * The generator's own files are vendored under src/dungeon UNMODIFIED. Anything
 * the game wants that they do not do belongs in the adapter on the PHP side,
 * never in a local edit here: the point of a sidecar is that upstream code
 * stays upstream code and can be replaced by dropping in a newer copy.
 *
 * Reachable only on the compose network. There is no auth because there is no
 * route in from outside — see docker-compose.yml, which publishes no port for
 * this service — and a shared secret would be a second thing to keep in step
 * for a door nobody can knock on.
 */

import { createServer } from 'node:http';
import { generateMap } from './dungeon/sites';

const PORT = Number(process.env.PORT || 8091);

/** Every kind the generator makes. The game asks for two; see MapService.php. */
const KINDS = new Set(['keep', 'caves', 'wilds', 'town', 'city', 'inn']);

/**
 * Bounds on what may be asked for.
 *
 * Not because a caller is hostile — nothing outside the compose network can
 * reach this — but because a 400x400 floor with 200 rooms takes long enough to
 * look like a hang, and the game's timeout would fire first and leave the work
 * orphaned with nobody waiting for it.
 */
const clamp = (n, lo, hi, fallback) => {
  const v = Number(n);
  return Number.isFinite(v) ? Math.min(hi, Math.max(lo, Math.round(v))) : fallback;
};

function generate(body) {
  const kind = KINDS.has(body.kind) ? body.kind : 'keep';
  const opts = {
    cols: clamp(body.cols, 20, 160, 64),
    rows: clamp(body.rows, 20, 160, 48),
    cell: clamp(body.cell, 4, 64, 16),
    roomCount: clamp(body.roomCount, 3, 40, 10),
    corridorWidth: clamp(body.corridorWidth, 1, 5, 2),
    extraLoops: clamp(body.extraLoops, 0, 12, 2),
    // The seed is the whole contract with the caller: the same number gives the
    // same floor for ever, which is what lets the game store two integers and
    // call that a dungeon.
    seed: clamp(body.seed, 1, 0x7fffffff, 1),
    kind,
    doors: body.doors !== false,
    windy: body.windy === true,
    roomPad: clamp(body.roomPad, 0, 6, 1),
    margin: clamp(body.margin, 0, 12, 2),
  };

  const keep = generateMap(kind, opts);

  // The floor bitmap is a Uint8Array and JSON has no such thing. Sent as a
  // plain array of 0/1: the caller is PHP, and base64 or a run-length encoding
  // would be a format to agree on and to get wrong for a payload that is a few
  // tens of kilobytes either way.
  return {
    seed: keep.seed,
    kind,
    levels: keep.levels.map((level) => ({
      id: level.id,
      name: level.name,
      dungeon: { ...level.dungeon, floor: Array.from(level.dungeon.floor) },
    })),
  };
}

const server = createServer((req, res) => {
  const send = (code, payload) => {
    const body = JSON.stringify(payload);
    res.writeHead(code, {
      'content-type': 'application/json',
      'content-length': Buffer.byteLength(body),
    });
    res.end(body);
  };

  if (req.method === 'GET' && req.url === '/health') {
    return send(200, { ok: true });
  }
  if (req.method !== 'POST' || !req.url.startsWith('/generate')) {
    return send(404, { ok: false, error: 'POST /generate' });
  }

  let raw = '';
  req.on('data', (chunk) => {
    raw += chunk;
    // A body this big is a mistake or a loop; either way it is not a map
    // request, and reading it to the end would be the only slow thing here.
    if (raw.length > 64 * 1024) req.destroy();
  });
  req.on('end', () => {
    try {
      send(200, { ok: true, ...generate(raw ? JSON.parse(raw) : {}) });
    } catch (e) {
      // Said out loud rather than swallowed. The caller falls back to its own
      // generator on any failure, so a broken map service is a plainer dungeon
      // rather than a broken game — but it should still be findable in a log.
      console.error('generate failed:', e);
      send(500, { ok: false, error: String(e && e.message ? e.message : e) });
    }
  });
});

server.listen(PORT, () => console.log(`mapgen listening on ${PORT}`));
