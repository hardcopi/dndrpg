<?php
/**
 * A name for somebody who does not have one yet.
 *
 * The creator's first field is a blank box, and a blank box is the slowest
 * question on the page: a player who has not decided what to call anybody yet
 * cannot get to the part of character creation they actually came for. This
 * offers one per people, out of tables, and nothing more — it does not choose,
 * it suggests, and the field stays typeable.
 *
 * PURE STATIC AND FREE OF THE DATABASE, for DungeonGen's reason and with the
 * same benefit: tools/test_names.php sweeps every table thousands of times with
 * no MySQL behind it. Whether a rolled name is already somebody's is a question
 * about rows, so it is asked in the route where the PDO already is, not here.
 *
 * WRITTEN, NOT BORROWED. The 5e SRD contains no name tables — those live in the
 * Player's Handbook, which this project has no licence to — so every list below
 * was written for this valley. That is the same reason `races.description` is
 * ours by construction, and it is why the tables lean on Rivermark rather than
 * on the generic: the humans are named for a river town with a priory and a
 * quarry, the half-orcs carry the epithets a watch-house would give them, and
 * the Sarsen, who are ours outright, are named for cut stone and the haul-road.
 *
 * KEYED BY RACE, WITH SUBRACES AS EXCEPTIONS. The race plate takes the same
 * line — a Wood Elf and a High Elf are both elves and there is one painting of
 * elves — and naming mostly follows it. `SUBRACE` is for the rows where that is
 * untrue rather than merely coarse, which at present is the Dark Elf: a people
 * who parted from the others long enough ago to have parted from their names.
 * A subrace with no entry falls back to its race, so adding a row costs nothing.
 *
 * A RACE WITH NO TABLE GETS NO BUTTON. `roll()` answers null rather than
 * inventing something out of the nearest list, and `meta/races` ships
 * `races()` so the creator can hide the offer. That is the honesty rule
 * `Rules::RACE_FEATURES` states for traits, applied to names — but names,
 * unlike Darkvision, are a gap somebody can close by writing a list, so
 * tools/test_names.php asserts every row in the `races` table has one. Adding
 * a race fails a test rather than quietly losing a feature.
 */

declare(strict_types=1);

final class NameGen
{
    /**
     * The given-name pools a caller may ask for by name.
     *
     * Anything else — null, an empty string, a word nobody wrote a pool for —
     * means "either", and that is deliberate rather than lax: a request the
     * generator cannot honour should hand back a usable name, because the only
     * thing downstream of it is a suggestion in a text field.
     */
    public const GENDERS = ['masculine', 'feminine'];

    /**
     * Given names and family names, per race.
     *
     * Both halves are always used and always in that order, which is a
     * simplification worth naming: real naming customs differ more than this —
     * a dragonborn would put the clan first among their own, and plenty of
     * half-orcs have no surname at all, only what the gate guard writes down.
     * One shape keeps the tables legible and the test simple, and every pairing
     * below was read to check it makes a name somebody would answer to.
     *
     * Given names come in two pools and family names in one, which is a claim
     * about naming customs and not about people: these cultures happen to
     * gender the first name and not the surname. `GENDERS` is the list of pools
     * a caller may ask for, and asking for NEITHER is a first-class answer
     * rather than a fallback — `roll()` with no gender draws from both pools at
     * once, so a player who does not want the question does not get it, and a
     * character whose name sits outside the two lists is a name somebody types.
     */
    private const NAMES = [
        // The valley: a river town with a priory, a quarry and a watch.
        'Human' => [
            'masculine' => [
                'Aldis', 'Bram', 'Cerin', 'Doryn', 'Faran', 'Halric', 'Joris', 'Lowen',
                'Nolan', 'Perrin', 'Sadric', 'Ulric', 'Wyn',
            ],
            'feminine' => [
                'Alys', 'Edda', 'Gerta', 'Hesta', 'Imma', 'Kesta', 'Maribel', 'Morwen',
                'Orla', 'Quenna', 'Roslin', 'Tessa',
            ],
            'family' => [
                'Ashford', 'Barrowe', 'Colefield', 'Danby', 'Elmsley', 'Fenwick',
                'Garrow', 'Haldane', 'Ingle', 'Marchett', 'Norrey', 'Prewitt',
                'Quarrell', 'Sedgely', 'Thorne', 'Winnow',
            ],
        ],
        // Hard consonants, and a surname that says what the family does.
        'Dwarf' => [
            'masculine' => [
                'Bardin', 'Dorlan', 'Falki', 'Grunwald', 'Hakkon', 'Hendrik', 'Morrik',
                'Nurin', 'Orvi', 'Skarl', 'Ulgrim', 'Yorri',
            ],
            'feminine' => [
                'Brenna', 'Durga', 'Gorra', 'Hilda', 'Karda', 'Marta', 'Rhunda', 'Sigrun',
                'Thruda', 'Torvi', 'Vanda', 'Zurma',
            ],
            'family' => [
                'Anvilkin', 'Blackseam', 'Coalbraid', 'Deepfast', 'Emberhold',
                'Flintgrave', 'Gravelbeard', 'Ironmarch', 'Oreheart', 'Stonewake',
                'Thickhame', 'Umberdelve',
            ],
        ],
        // Long vowels, and a surname built out of two quiet words.
        'Elf' => [
            'masculine' => [
                'Aelthin', 'Berevel', 'Calathil', 'Delloryn', 'Faeloth', 'Ithuriel',
                'Melisar', 'Naeryn', 'Orinel', 'Serathil', 'Thalendrin', 'Vaeryn',
            ],
            'feminine' => [
                'Aeliane', 'Caeliel', 'Eanwyn', 'Illiane', 'Lariel', 'Meliora', 'Nuvaen',
                'Perianth', 'Quenaviel', 'Sylveth', 'Ualiel', 'Ysolde',
            ],
            'family' => [
                'Ashmourn', 'Dawnwhisper', 'Evenreach', 'Fallowlight', 'Gladewarden',
                'Highbranch', 'Moonhollow', 'Riverglass', 'Silverbough', 'Thornsong',
                'Winterleaf', 'Yarrowmere',
            ],
        ],
        // Homely, and named for what grows behind the house.
        'Halfling' => [
            'masculine' => [
                'Ansel', 'Barnaby', 'Corrin', 'Emmett', 'Gomer', 'Jorrin', 'Kettle',
                'Marlow', 'Nib', 'Podder', 'Rowan', 'Tobin',
            ],
            'feminine' => [
                'Bettony', 'Dilly', 'Fenna', 'Hattie', 'Isby', 'Lettie', 'Marigold',
                'Opal', 'Poppy', 'Sella', 'Tuppence', 'Wren',
            ],
            'family' => [
                'Applewick', 'Barleymow', 'Cobbleside', 'Duckett', 'Farthingale',
                'Hollyhock', 'Kettleby', 'Merrifield', 'Nettlebed', 'Pennywhistle',
                'Thistledown', 'Warrenby',
            ],
        ],
        // Bright and clicky, and a surname off the workbench.
        'Gnome' => [
            'masculine' => [
                'Bibble', 'Deppo', 'Ellwick', 'Hobrin', 'Izzik', 'Jindle', 'Kepper',
                'Nack', 'Orlo', 'Prink', 'Ruskin', 'Zeb',
            ],
            'feminine' => [
                'Bristle', 'Cazrin', 'Fizzet', 'Linnet', 'Mimsy', 'Nissa', 'Pipkin',
                'Quilby', 'Sprocket', 'Tanny', 'Widdy', 'Wimble',
            ],
            'family' => [
                'Bellowsby', 'Cogwhistle', 'Dabbler', 'Emberkin', 'Fiddlepot',
                'Gearspar', 'Hushwick', 'Lampwright', 'Nimblecatch', 'Pinwhirl',
                'Sparkbottle', 'Tinderfoot',
            ],
        ],
        // Between two lists, which is the whole of what a half-elf's name is.
        'Half-Elf' => [
            'masculine' => [
                'Alder', 'Bryn', 'Caelor', 'Corwin', 'Dain', 'Fenlin', 'Garrelth', 'Iolen',
                'Kaevar', 'Ossian', 'Renwyn', 'Tarion',
            ],
            'feminine' => [
                'Alenna', 'Briony', 'Elowen', 'Elsaren', 'Hesper', 'Lisenne', 'Maren',
                'Neriel', 'Sable', 'Seryn', 'Talia', 'Yvane',
            ],
            'family' => [
                'Ashenvale', 'Brightwater', 'Coldharbour', 'Duskmere', 'Fairholm',
                'Greyhaven', 'Larkspur', 'Mistbourne', 'Norwind', 'Ravenmoor',
                'Softstep', 'Vellamont',
            ],
        ],
        // Blunt, and a second name that is really something people say about you.
        'Half-Orc' => [
            'masculine' => [
                'Dosk', 'Durgh', 'Gharl', 'Grokk', 'Hrun', 'Krudd', 'Margh', 'Nazh',
                'Rhukk', 'Skarn', 'Vosk', 'Wharg',
            ],
            'feminine' => [
                'Braga', 'Ekka', 'Hazga', 'Ilga', 'Lokta', 'Murna', 'Ogra', 'Shessa',
                'Tammur', 'Ugra', 'Yarga', 'Zell',
            ],
            'family' => [
                'Anvil-Handed', 'Bonecount', 'Ditchborn', 'Gatebreaker', 'Halftoll',
                'Ironjaw', 'Nine-Fingers', 'Oathkept', 'Roadwarden', 'Stonefist',
                'Two-Winters', 'Unbowed',
            ],
        ],
        // The clan is the part that matters, and it is named for its hoard.
        'Dragonborn' => [
            'masculine' => [
                'Azrekh', 'Corrivax', 'Draxen', 'Fharion', 'Halvix', 'Kavrin', 'Lozhek',
                'Mordrax', 'Ozrik', 'Sivrath', 'Tazrin', 'Vaskir',
            ],
            'feminine' => [
                'Arrashi', 'Essikar', 'Irrath', 'Kelvara', 'Myrrhax', 'Nessivar',
                'Pyrrhax', 'Rhessa', 'Sethra', 'Ukhal', 'Vezrith', 'Zhaleth',
            ],
            'family' => [
                'Ashenscale', 'Brightcoil', 'Cinderwing', 'Dawnhoard', 'Emberclutch',
                'Flintmaw', 'Goldsear', 'Hollowfang', 'Ironclutch', 'Stormtalon',
                'Sunderscale', 'Wyrmwatch',
            ],
        ],
        // An old given name and a word somebody chose to be answerable for.
        'Tiefling' => [
            'masculine' => [
                'Ashvel', 'Belorin', 'Carnith', 'Davrel', 'Fael', 'Hasrin', 'Lazuel',
                'Malachar', 'Nirash', 'Pyrell', 'Rhavan', 'Zerith',
            ],
            'feminine' => [
                'Aleth', 'Carrow', 'Ezriel', 'Iskra', 'Kesril', 'Mavris', 'Nyx', 'Ombra',
                'Seraphel', 'Sorrell', 'Thessaly', 'Vashti',
            ],
            'family' => [
                'Candour', 'Consequence', 'Debt', 'Emberkept', 'Forbearance',
                'Gratitude', 'Hazard', 'Ledger', 'Mercy', 'Patience', 'Reckoning',
                'Temperance', 'Vigil', 'Waited',
            ],
        ],
        // Ours. Named off the mason's bench and the cut they were quarried from —
        // see the race's own note: they are made of the haul-road, the boundary
        // stones and the Old City's masonry, and so are their names. `Setfast`
        // is a family named for the trait, which is the way round it happened.
        'Sarsen' => [
            'masculine' => [
                'Barrow', 'Chert', 'Corbel', 'Delve', 'Gable', 'Jamb', 'Kerb', 'Lintel',
                'Plumb', 'Quoin', 'Tread', 'Wedge',
            ],
            'feminine' => [
                'Batten', 'Cairn', 'Coping', 'Grit', 'Hone', 'Marl', 'Reveal', 'Sill',
                'Skew', 'Slate', 'Tessera', 'Vault',
            ],
            'family' => [
                'Deepcut', 'Drystone', 'Farquarry', 'Haulroad', 'Longcut', 'Marklaid',
                'Overburden', 'Riprap', 'Setfast', 'Stonelaid', 'Undercut', 'Waterline',
            ],
        ],
    ];

    /**
     * Where a subrace's naming is a different tradition and not a dialect.
     *
     * Only the rows that have genuinely parted. Everything else falls back to
     * its race, which is what keeps this from becoming a second copy of the
     * table above with three names changed.
     */
    private const SUBRACE = [
        'Dark Elf' => [
            'masculine' => [
                'Auressin', 'Erruvel', 'Ghaunad', 'Ilvraen', 'Malvane', 'Sszaren',
                'Tyrevel', 'Ulvarn', 'Vaerith', 'Zarrek',
            ],
            'feminine' => [
                'Charneth', 'Chessarin', 'Dossalyn', 'Ilsandra', 'Khessyr', 'Nyrissa',
                'Qualith', 'Velmyra', 'Xhalya', 'Zerrindra',
            ],
            'family' => [
                'Blackglass', 'Coldspindle', 'Duskvein', 'Everdark', 'Nightbriar',
                'Shadeholt', 'Spiderfall', 'Umbershade', 'Voidmere', 'Wyrmshade',
            ],
        ],
    ];

    /**
     * The table a row would draw from, subrace first.
     *
     * @return array{masculine: list<string>, feminine: list<string>, family: list<string>}|null
     */
    public static function tableFor(string $race, ?string $subrace = null): ?array
    {
        if ($subrace !== null && $subrace !== '' && isset(self::SUBRACE[$subrace])) {
            return self::SUBRACE[$subrace];
        }
        return self::NAMES[$race] ?? null;
    }

    /** Whether this people has names written for it. */
    public static function has(string $race, ?string $subrace = null): bool
    {
        return self::tableFor($race, $subrace) !== null;
    }

    /**
     * The given names on offer for a people, for a gender or for either.
     *
     * Merged rather than picked-between when no gender is asked for, so "any"
     * draws from the whole culture instead of tossing a coin and then drawing
     * from half of it. The two are not the same: the pools are different
     * lengths, and a coin toss would over-weight whichever is shorter.
     *
     * @return list<string>
     */
    public static function given(array $table, ?string $gender = null): array
    {
        if ($gender !== null && in_array($gender, self::GENDERS, true)) {
            return $table[$gender];
        }
        return array_merge(...array_map(static fn ($g) => $table[$g], self::GENDERS));
    }

    /**
     * One name, or null for a people nobody has written names for.
     *
     * `random_int` rather than a seeded stream: a suggestion in a form is not
     * part of the world, nothing reproduces it, and nothing stores the roll.
     */
    public static function roll(string $race, ?string $subrace = null, ?string $gender = null): ?string
    {
        $table = self::tableFor($race, $subrace);
        if ($table === null) {
            return null;
        }
        $given = self::given($table, $gender);
        return $given[random_int(0, count($given) - 1)]
            . ' ' . $table['family'][random_int(0, count($table['family']) - 1)];
    }

    /**
     * Every race and subrace with a table, for the creator to hide the offer
     * where there is none — and for the test to check nothing has been left out.
     *
     * @return list<string>
     */
    public static function races(): array
    {
        return array_values(array_merge(array_keys(self::NAMES), array_keys(self::SUBRACE)));
    }
}
