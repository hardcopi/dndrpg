<?php
/**
 * Portraits rendered from the 3D creator, filed where the 2D ones live.
 *
 * The creator in the browser knows exactly what a character looks like; the
 * server does not, and cannot — there is no renderer on this host and a
 * character is a recipe of part names, not a picture. So the picture is made
 * where the character is: the embed renders a bust and a face, the page posts
 * them, and this writes them into the same namespace Paperdoll uses.
 *
 * That namespace is the whole point. A sprite_key is four files —
 * `<key>.png` and `<key>_sheet.png` for the map, `<key>_bust.png` and
 * `<key>_face.png` for portraits — and every consumer in the game builds a URL
 * out of one and expects the rest to be there. Writing two of the four would
 * give a character a face in conversation and a 404 on the map, so the walk
 * sprite is copied from whatever they would have used otherwise. The map still
 * shows the class figure; everywhere a portrait appears now shows them.
 *
 * Nothing here trusts the bytes it is given. They arrive from a browser, they
 * are written under the web root, and the two facts together are the whole
 * reason this re-encodes through GD rather than saving what it was sent: what
 * comes out is a PNG this process drew, at a size this file chose, whatever
 * went in. The name is built from the character id, never from the request.
 */

declare(strict_types=1);

class ModelPortrait
{
    /** Same folder and prefix as the 2D bake, so one sprite_key means one thing. */
    private const OUT = 'assets/images/npcs';

    /** The largest base64 payload worth decoding, before it is even a picture. */
    private const MAX_BYTES = 4 * 1024 * 1024;

    /**
     * Widest or tallest a submitted image may be.
     *
     * Generous against the 512 the embed sends, tight enough that a decompression
     * bomb is refused before GD allocates anything for it.
     */
    private const MAX_EDGE = 2048;

    /**
     * Write a character's portraits and return the sprite key.
     *
     * Two pictures in, two out. They arrive at the same size and are framed
     * differently — the bust is head and shoulders, the face is a head — and
     * each is cropped and scaled here with Paperdoll's own functions, so a
     * rendered portrait and a drawn one are cut to the character the same way
     * and sit at the same size beside each other in the party rail.
     *
     * @param int    $characterId Whose portraits these are. The only source of the filename.
     * @param string $bustB64     Base64 PNG, head and shoulders, front-on, transparent.
     * @param string $faceB64     Base64 PNG, the same character framed on the head.
     * @param string $fallback    The sprite_key they would otherwise have used, for the walk sheet.
     */
    public static function bake(int $characterId, string $bustB64, string $faceB64, string $fallback): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD is not available, so a portrait cannot be saved.');
        }
        if ($characterId <= 0) {
            throw new RuntimeException('A portrait needs a character to belong to.');
        }

        $key = Paperdoll::keyFor($characterId);
        $out = APP_ROOT . '/' . self::OUT;

        $bust = self::decode($bustB64);
        $face = self::decode($faceB64);
        if (!$bust || !$face) {
            if ($bust) { imagedestroy($bust); }
            if ($face) { imagedestroy($face); }
            throw new RuntimeException('That portrait was not a readable PNG.');
        }

        // Decoded before either is written: a character with a bust and no face
        // is a half-changed portrait that shows in some panels and not others.
        foreach ([['_bust', $bust, Paperdoll::BUST_PX], ['_face', $face, Paperdoll::FACE_PX]] as [$suffix, $im, $cap]) {
            $scaled = Paperdoll::fit($im, $cap);
            imagepng($scaled, "{$out}/{$key}{$suffix}.png");
            imagedestroy($scaled);
            imagedestroy($im);
        }

        self::inheritWalkSprite($key, $fallback, $out);
        self::recordBustCount($key);

        return $key;
    }

    /**
     * Base64 in, a GD image cropped to the character, or null.
     *
     * Every check is on the way in rather than after: the string is measured
     * before it is decoded, the decoded bytes are measured before they are
     * parsed, the dimensions are read from the bytes before an image exists,
     * and only then is anything allocated.
     *
     * Trimmed with Paperdoll's own function rather than one that does nearly
     * the same thing. The embed renders into a square frame with the character
     * somewhere in the middle of it; a 2D bust is cropped to the character.
     * Skipping this step made a rendered portrait sit at about a third of the
     * frame next to a drawn one filling two thirds, which reads as the 3D
     * character standing further away.
     *
     * Scaling is left to the caller, which needs the same crop at two caps.
     */
    private static function decode(string $b64)
    {
        $b64 = trim($b64);
        // A data: URL is what a browser hands you if nobody strips it.
        if (str_starts_with($b64, 'data:')) {
            $comma = strpos($b64, ',');
            if ($comma === false) {
                return null;
            }
            $b64 = substr($b64, $comma + 1);
        }
        if ($b64 === '' || strlen($b64) > self::MAX_BYTES) {
            return null;
        }

        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) > self::MAX_BYTES) {
            return null;
        }
        // PNG and only PNG. getimagesizefromstring would happily accept a GIF,
        // and a GIF in a file called .png is a mismatch somebody will chase.
        if (substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }

        $size = @getimagesizefromstring($raw);
        if (!$size || $size[2] !== IMAGETYPE_PNG) {
            return null;
        }
        if ($size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_EDGE || $size[1] > self::MAX_EDGE) {
            return null;
        }

        $im = @imagecreatefromstring($raw);
        if (!$im) {
            return null;
        }
        imagesavealpha($im, true);
        imagealphablending($im, false);

        $trimmed = Paperdoll::trim($im);
        imagedestroy($im);

        return $trimmed;
    }

    /**
     * Give the new key the walk sprite of the one it replaces.
     *
     * Copied rather than symlinked, and rather than left out. The map asks for
     * `<key>.png` and `<key>_sheet.png` by name; a missing file there is a hole
     * in the world where a character should be standing. Two small PNGs per
     * character is a cheap price for every other screen working unchanged.
     *
     * A fallback that does not exist on disk is not an error — the character
     * still gets their portraits, and the map falls back the way it already
     * does for anybody whose art is missing.
     */
    private static function inheritWalkSprite(string $key, string $fallback, string $out): void
    {
        $fallback = preg_replace('/[^a-z0-9_\-]/i', '', $fallback) ?? '';
        if ($fallback === '' || $fallback === $key) {
            return;
        }
        foreach (['', '_sheet'] as $suffix) {
            $from = "{$out}/{$fallback}{$suffix}.png";
            $to   = "{$out}/{$key}{$suffix}.png";
            if (is_file($from) && !is_file($to)) {
                @copy($from, $to);
            }
        }
    }

    /**
     * One bust, said out loud.
     *
     * DialogEngine reads busts.json to know how many expressions an actor has;
     * without an entry it offers expressions this character does not own and
     * the conversation shows a broken image.
     */
    private static function recordBustCount(string $key): void
    {
        $path = APP_ROOT . '/' . self::OUT . '/busts.json';
        $counts = [];
        if (is_file($path)) {
            $counts = json_decode((string) file_get_contents($path), true) ?: [];
        }
        $counts[$key] = 1;
        @file_put_contents($path, json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
