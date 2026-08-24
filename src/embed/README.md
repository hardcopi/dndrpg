# /embed — the character creator and viewer

Nothing in this folder is written by hand. It is where the Unity build lands.

## Putting it here

From the Unity project:

```bash
cd /home/richard/code/rpg-unity
./build-embed.sh /home/richard/code/rpg/src/embed
```

That produces `index.html`, `Build/` and `bundles/`. Nginx already knows about
this path — see the `location ^~ /embed/` block in `docker/nginx/default.conf`,
which sets the `Content-Encoding` headers a Unity WebGL build needs and the
cache lifetimes its file names have earned. **Production needs the same block**;
without it the player either fails to start or reloads its whole 30 MB on every
visit.

## Using it

```html
<script type="module">
  import { mountCharacter } from '/assets/js/rivermark-character.js';
  mountCharacter(document.querySelector('#creator'), {
    mode: 'create',
    character: 42,
    onSave: ({ recipe }) => console.log('saved', recipe),
  });
</script>
```

or, with no script at all:

```html
<rivermark-character mode="view" character="42" background="none"></rivermark-character>
```

or, with no JavaScript at all:

```html
<iframe src="/embed/?mode=view&character=42" width="360" height="480"></iframe>
```

`embed_demo.php` is a working page with all three on it.

## Where the pieces are

| | |
|---|---|
| The embed itself | `rpg-unity/Assets/Scripts/Embed/` |
| How it is built | `rpg-unity/build-embed.sh`, `rpg-unity/EMBED.md` |
| The page-side API | `assets/js/rivermark-character.js` |
| What is stored | `app/lib/SidekickAppearance.php`, `characters.sidekick_json` |
| The route | `character/model` — GET reads, POST writes |
