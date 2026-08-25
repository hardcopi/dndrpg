<?php
/**
 * JSON API front controller.
 * Routes: ?r=resource/action or PATH_INFO style via r param.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

cors_apply();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$route = $_GET['r'] ?? '';
if ($route === '' && isset($_SERVER['PATH_INFO'])) {
    $route = trim($_SERVER['PATH_INFO'], '/');
}
$route = trim($route, '/');
$parts = $route !== '' ? explode('/', $route) : [];
$resource = $parts[0] ?? '';
$action = $parts[1] ?? 'index';

/**
 * Routes reachable without signing in.
 *
 * A deny-by-default list rather than an allow-by-default one, so the failure
 * mode of adding a route and forgetting about permissions is a route nobody can
 * reach — noticed immediately — instead of one everybody can. Every entry here
 * is a deliberate exception and has to justify itself:
 *
 *   auth/*           the way in; requiring a session to sign in cannot work
 *   session/status   the client asks this before it knows whether it has a
 *                    session, and it answers with nothing but that fact
 */
const PUBLIC_ROUTES = [
    'auth'    => ['login', 'register', 'logout', 'status'],
    'session' => ['status'],
];

try {
    $publicActions = PUBLIC_ROUTES[$resource] ?? [];
    if (!in_array($action, $publicActions, true)) {
        auth()->requireUser();
    }

    switch ($resource) {
        case 'meta':
            handle_meta($action);
            break;
        case 'character':
            handle_character($action);
            break;
        case 'location':
            handle_location($action);
            break;
        case 'combat':
            handle_combat($action);
            break;
        case 'quest':
            handle_quest($action);
            break;
        case 'inventory':
            handle_inventory($action);
            break;
        case 'dialogue':
            handle_dialogue($action);
            break;
        case 'check':
            handle_check($action);
            break;
        case 'levelup':
            handle_levelup($action);
            break;
        case 'session':
            handle_session($action);
            break;
        case 'auth':
            handle_auth($action);
            break;
        case 'admin':
            handle_admin($action);
            break;
        case 'content':
            handle_content($action);
            break;
        default:
            json_error('Unknown API resource', 404);
    }
} catch (AuthRequiredException $e) {
    // 401 and a flag the client can act on: not signed in, so send them to the
    // login form. Distinct from 403 below, which a login would not fix.
    json_error($e->getMessage(), 401, ['auth_required' => true]);
} catch (AuthForbiddenException $e) {
    json_error($e->getMessage(), 403, ['forbidden' => true]);
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500, [
        'type' => get_class($e),
    ]);
}

/**
 * Signing in, out, and up.
 *
 * These are the only routes reachable without a session, which is why they are
 * the only ones that talk to Auth directly rather than through requireUser().
 */
function handle_auth(string $action): void
{
    $auth = auth();

    // What the client needs to render its chrome: who you are, whether you may
    // see the editor, and whether the register link should exist at all.
    if ($action === 'status') {
        $user = $auth->currentUser();
        json_response([
            'ok'                 => true,
            'signed_in'          => $user !== null,
            'user'               => $user ? [
                'id'       => (int) $user['id'],
                'username' => $user['username'],
                'role'     => $user['role'],
            ] : null,
            'is_admin'           => $auth->isAdmin(),
            'registration_open'  => $auth->registrationOpen(),
            'via'                => $user === null ? null : ($auth->usingToken() ? 'token' : 'session'),
        ]);
    }

    if ($action === 'login') {
        require_method('POST');
        $body = read_json_body();
        $user = $auth->login((string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''));
        $issued = $auth->issueToken((int) $user['id'], (string) ($body['client'] ?? 'api'));
        json_response([
            'ok'         => true,
            'user'       => ['id' => (int) $user['id'], 'username' => $user['username'], 'role' => $user['role']],
            'token'      => $issued['token'],
            'expires_at' => $issued['expires_at'],
        ]);
    }

    if ($action === 'register') {
        require_method('POST');
        if (!$auth->registrationOpen()) {
            json_error('Registration is closed on this server.', 403);
        }
        $body = read_json_body();
        $user = $auth->register(
            (string) ($body['username'] ?? ''),
            (string) ($body['password'] ?? ''),
            (string) ($body['email'] ?? '')
        );
        // Signed in straight away: making somebody type the password they just
        // chose, twice, to reach the thing they registered for is friction with
        // nothing on the other side of it.
        $auth->login((string) $body['username'], (string) $body['password']);
        $issued = $auth->issueToken((int) $user['id'], (string) ($body['client'] ?? 'api'));
        json_response([
            'ok'         => true,
            'user'       => ['id' => (int) $user['id'], 'username' => $user['username'], 'role' => $user['role']],
            'token'      => $issued['token'],
            'expires_at' => $issued['expires_at'],
        ]);
    }

    if ($action === 'logout') {
        require_method('POST');
        $auth->logout();
        json_response(['ok' => true]);
    }

    json_error('Unknown auth action', 404);
}

/**
 * Managing accounts. Administrators only, checked on every action.
 */
function handle_admin(string $action): void
{
    $auth = auth();
    $auth->requireAdmin();
    $db = db();

    if ($action === 'users') {
        $rows = $db->query(
            'SELECT u.id, u.username, u.email, u.role, u.is_active, u.created_at, u.last_login_at,
                    u.password_hash IS NOT NULL AS has_password,
                    (SELECT COUNT(*) FROM characters c WHERE c.user_id = u.id) AS characters
               FROM users u ORDER BY u.id'
        )->fetchAll();
        json_response([
            'ok'                => true,
            'users'             => $rows,
            'registration_open' => $auth->registrationOpen(),
            'registration_locked_by_env' => $auth->registrationLockedByEnv(),
        ]);
    }

    if ($action === 'create_user') {
        require_method('POST');
        $body = read_json_body();
        json_response(['ok' => true, 'user' => $auth->register(
            (string) ($body['username'] ?? ''),
            (string) ($body['password'] ?? ''),
            (string) ($body['email'] ?? ''),
            (string) ($body['role'] ?? Auth::ROLE_USER)
        )]);
    }

    if ($action === 'set_password') {
        require_method('POST');
        $body = read_json_body();
        $auth->setPassword((int) ($body['user_id'] ?? 0), (string) ($body['password'] ?? ''));
        json_response(['ok' => true]);
    }

    if ($action === 'set_role') {
        require_method('POST');
        $body = read_json_body();
        $userId = (int) ($body['user_id'] ?? 0);
        $role = (string) ($body['role'] ?? '');
        if (!in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_USER], true)) {
            json_error('Unknown role.', 400);
        }
        admin_assert_not_last_admin($userId, $role === Auth::ROLE_ADMIN, true);
        $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
        json_response(['ok' => true]);
    }

    if ($action === 'set_active') {
        require_method('POST');
        $body = read_json_body();
        $userId = (int) ($body['user_id'] ?? 0);
        $active = (bool) ($body['is_active'] ?? true);
        admin_assert_not_last_admin($userId, $active, false);
        $db->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $userId]);
        if (!$active) {
            $auth->revokeAllTokens($userId);
        }
        json_response(['ok' => true]);
    }

    // Write the database back out to content/ and maps/.
    //
    // The database is the source of truth, so this is how authored work leaves
    // the volume it lives in and becomes something that can be committed and
    // deployed. Offered here rather than only as a CLI tool because the person
    // who just edited a conversation is in a browser, and a step you have to
    // remember to run in a terminal is a step that does not get run.
    if ($action === 'export') {
        require_method('POST');
        $exporter = new ContentExporter($db);
        if (!$exporter->writable()) {
            json_error(
                'Cannot write to ' . $exporter->root() . '. The web user needs write access to content/.',
                500
            );
        }
        $result = $exporter->exportAll();
        json_response([
            'ok'      => true,
            'counts'  => $result['counts'],
            'written' => $result['written'],
        ]);
    }

    if ($action === 'set_registration') {
        require_method('POST');
        $body = read_json_body();
        if ($auth->registrationLockedByEnv()) {
            json_error('RPG_REGISTRATION is set on this server; the switch here cannot override it.', 409);
        }
        $auth->setRegistrationOpen((bool) ($body['open'] ?? false));
        json_response(['ok' => true, 'registration_open' => $auth->registrationOpen()]);
    }

    json_error('Unknown admin action', 404);
}

/**
 * Editing the world: NPCs, their conversations, quests, and places.
 *
 * Administrators only, checked once here rather than per action — every route
 * below writes shared content, so there is no reader/writer split to make.
 *
 * Places are here because the tile grid is gone: a location is a row of prose
 * and a handful of exits, which is a form rather than a canvas, so it belongs
 * beside the other content editors instead of in a separate map tool.
 */
function handle_content(string $action): void
{
    auth()->requireAdmin();
    $ed = new ContentEditor(db());

    if ($action === 'npcs') {
        $moduleId = isset($_GET['module_id']) && $_GET['module_id'] !== ''
            ? (int) $_GET['module_id'] : null;
        json_response(['ok' => true, 'npcs' => $ed->listNpcs($moduleId)]);
    }

    if ($action === 'npc') {
        json_response(['ok' => true, 'npc' => $ed->getNpc((int) ($_GET['id'] ?? 0))]);
    }

    if ($action === 'save_npc') {
        require_method('POST');
        $body = read_json_body();
        json_response(['ok' => true, 'npc' => $ed->saveNpc((int) ($body['id'] ?? 0), $body)]);
    }

    if ($action === 'place_npc') {
        require_method('POST');
        $body = read_json_body();
        // An explicit null location means "take them out of the world", which
        // is not the same as a missing parameter — a companion who has been
        // recruited stands nowhere, on purpose.
        $locationId = isset($body['location_id']) && $body['location_id'] !== null
            && $body['location_id'] !== ''
            ? (int) $body['location_id'] : null;
        json_response(['ok' => true, 'npc' => $ed->placeNpc((int) ($body['id'] ?? 0), $locationId)]);
    }

    if ($action === 'dialogue') {
        json_response(['ok' => true, 'dialogue' => $ed->getDialogue((int) ($_GET['id'] ?? 0))]);
    }

    if ($action === 'save_dialogue') {
        require_method('POST');
        $body = read_json_body();
        json_response([
            'ok'       => true,
            'dialogue' => $ed->saveDialogue(
                (int) ($body['id'] ?? 0),
                array_key_exists('dialogue', $body) ? $body['dialogue'] : null
            ),
        ]);
    }

    // What is wrong with a conversation, without trying to save it.
    //
    // `save_dialogue` refuses on the first error and reports a handful; the
    // graph wants the whole list at once, including the warnings a save is happy
    // to let through, so it can colour the nodes. Read-only, so it can be called
    // on every render without the author wondering what it just wrote.
    if ($action === 'lint_dialogue') {
        json_response([
            'ok'   => true,
            'lint' => $ed->lintDialogue((int) ($_GET['id'] ?? 0)),
        ]);
    }

    if ($action === 'quests') {
        $moduleId = isset($_GET['module_id']) && $_GET['module_id'] !== ''
            ? (int) $_GET['module_id'] : null;
        json_response(['ok' => true, 'quests' => $ed->listQuests($moduleId)]);
    }

    if ($action === 'quest') {
        json_response(['ok' => true, 'quest' => $ed->getQuest((int) ($_GET['id'] ?? 0))]);
    }

    if ($action === 'save_quest') {
        require_method('POST');
        $body = read_json_body();
        json_response(['ok' => true, 'quest' => $ed->saveQuest((int) ($body['id'] ?? 0), $body)]);
    }

    if ($action === 'save_stage') {
        require_method('POST');
        $body = read_json_body();
        json_response(['ok' => true, 'quest' => $ed->saveStage((int) ($body['id'] ?? 0), $body)]);
    }

    if ($action === 'regions') {
        json_response(['ok' => true, 'regions' => $ed->listRegions()]);
    }

    if ($action === 'location') {
        json_response(['ok' => true, 'location' => $ed->getLocation((int) ($_GET['id'] ?? 0))]);
    }

    if ($action === 'save_location') {
        require_method('POST');
        $body = read_json_body();
        json_response(['ok' => true, 'location' => $ed->saveLocation($body)]);
    }

    if ($action === 'save_position') {
        require_method('POST');
        $body = read_json_body();
        json_response([
            'ok'       => true,
            'location' => $ed->savePosition(
                (int) ($body['id'] ?? 0),
                (float) ($body['map_x'] ?? 0),
                (float) ($body['map_y'] ?? 0)
            ),
        ]);
    }

    if ($action === 'save_exit') {
        require_method('POST');
        $body = read_json_body();
        json_response(['ok' => true, 'location' => $ed->saveExit($body)]);
    }

    if ($action === 'delete_exit') {
        require_method('POST');
        $body = read_json_body();
        json_response(['ok' => true, 'location' => $ed->deleteExit((int) ($body['id'] ?? 0))]);
    }

    if ($action === 'delete_location') {
        require_method('POST');
        $body = read_json_body();
        json_response(['ok' => true, 'result' => $ed->deleteLocation((int) ($body['id'] ?? 0))]);
    }

    // The lists the editor's dropdowns are built from. Locations come flat as
    // well as grouped: the exit and quest-marker selects need one list of
    // everywhere, and building it in the client from the grouped copy is how
    // the two come to be sorted differently on two screens.
    if ($action === 'modules') {
        json_response(['ok' => true, 'modules' => $ed->listModules()]);
    }

    if ($action === 'module') {
        json_response(['ok' => true, 'module' => $ed->getModule((int) ($_GET['id'] ?? 0))]);
    }

    if ($action === 'chart') {
        $hereId = isset($_GET['here_id']) && $_GET['here_id'] !== ''
            ? (int) $_GET['here_id'] : null;
        $regionId = isset($_GET['region_id']) && $_GET['region_id'] !== ''
            ? (int) $_GET['region_id'] : null;
        json_response([
            'ok'    => true,
            'chart' => $ed->moduleChart((int) ($_GET['module_id'] ?? 0), $hereId, $regionId),
        ]);
    }

    if ($action === 'create_module') {
        require_method('POST');
        json_response(['ok' => true, 'created' => $ed->createModule(read_json_body())]);
    }

    if ($action === 'delete_module') {
        require_method('POST');
        $body = read_json_body();
        json_response(['ok' => true, 'result' => $ed->deleteModule((int) ($body['id'] ?? 0))]);
    }

    if ($action === 'save_plan') {
        require_method('POST');
        $body = read_json_body();
        json_response([
            'ok'   => true,
            'saved'=> $ed->savePlan((int) ($body['region_id'] ?? 0), $body['plan'] ?? []),
        ]);
    }

    if ($action === 'create_region') {
        require_method('POST');
        json_response(['ok' => true, 'region' => $ed->createRegion(read_json_body())]);
    }

    if ($action === 'create_npc') {
        require_method('POST');
        json_response(['ok' => true, 'npc' => $ed->createNpc(read_json_body())]);
    }

    if ($action === 'create_quest') {
        require_method('POST');
        json_response(['ok' => true, 'quest' => $ed->createQuest(read_json_body())]);
    }

    if ($action === 'add_stage') {
        require_method('POST');
        $body = read_json_body();
        json_response([
            'ok'    => true,
            'quest' => $ed->addStage((int) ($body['quest_id'] ?? 0), $body),
        ]);
    }

    if ($action === 'delete_stage') {
        require_method('POST');
        $body = read_json_body();
        json_response([
            'ok'    => true,
            'quest' => $ed->deleteStage((int) ($body['id'] ?? 0)),
        ]);
    }

    if ($action === 'create_encounter') {
        require_method('POST');
        json_response(['ok' => true, 'encounter' => $ed->createEncounter(read_json_body())]);
    }

    if ($action === 'items') {
        json_response(['ok' => true, 'items' => $ed->listItems()]);
    }

    if ($action === 'item') {
        json_response(['ok' => true, 'item' => $ed->getItem((int) ($_GET['id'] ?? 0))]);
    }

    if ($action === 'save_item') {
        require_method('POST');
        json_response(['ok' => true, 'item' => $ed->saveItem(read_json_body())]);
    }

    if ($action === 'delete_item') {
        require_method('POST');
        $body = read_json_body();
        json_response(['ok' => true, 'result' => $ed->deleteItem((int) ($body['id'] ?? 0))]);
    }

    if ($action === 'add_item') {
        require_method('POST');
        $body = read_json_body();
        json_response([
            'ok'       => true,
            'location' => $ed->addLocationItem(
                (int) ($body['location_id'] ?? 0),
                (string) ($body['item_key'] ?? '')
            ),
        ]);
    }

    if ($action === 'remove_item') {
        require_method('POST');
        $body = read_json_body();
        json_response([
            'ok'       => true,
            'location' => $ed->removeLocationItem((int) ($body['id'] ?? 0)),
        ]);
    }

    if ($action === 'save_art') {
        require_method('POST');
        $body = read_json_body();
        json_response([
            'ok'  => true,
            'art' => $ed->saveArt(
                (string) ($body['kind'] ?? ''),
                (string) ($body['key'] ?? ''),
                (string) ($body['image'] ?? ''),
                isset($body['npc_id']) && $body['npc_id'] !== '' && $body['npc_id'] !== null
                    ? (int) $body['npc_id'] : null
            ),
        ]);
    }

    if ($action === 'set_active') {
        require_method('POST');
        $body = read_json_body();
        json_response([
            'ok'     => true,
            'module' => $ed->setModuleActive(
                (int) ($body['id'] ?? 0),
                !empty($body['is_active'])
            ),
        ]);
    }

    if ($action === 'run') {
        json_response([
            'ok'    => true,
            'beats' => $ed->moduleRun((int) ($_GET['module_id'] ?? 0)),
        ]);
    }

    if ($action === 'bundle') {
        json_response([
            'ok'     => true,
            'bundle' => $ed->packModule((int) ($_GET['module_id'] ?? 0)),
        ]);
    }

    if ($action === 'import_bundle') {
        require_method('POST');
        $body = read_json_body();
        json_response([
            'ok'     => true,
            'imported' => $ed->importBundle($body['bundle'] ?? []),
        ]);
    }

    if ($action === 'refs') {
        json_response([
            'ok'        => true,
            'regions'   => $ed->listRegions(),
            'locations' => db()->query(
                'SELECT l.id, l.location_key, l.name, l.location_type,
                        l.region_id, r.name AS region_name
                   FROM locations l
                   INNER JOIN regions r ON r.id = l.region_id
                  ORDER BY r.sort_order, r.name, l.sort_order, l.id'
            )->fetchAll(),
            'location_types' => ['square', 'street', 'gate', 'building', 'room', 'site', 'camp'],
            'poses'     => ['sleeping', 'kneel'],
            'monsters'  => db()->query(
                'SELECT monster_key, name, challenge_rating
                   FROM monsters ORDER BY name'
            )->fetchAll(),
            'items'     => db()->query(
                'SELECT item_key, name, item_type, rarity FROM items ORDER BY name'
            )->fetchAll(),
            'sprites'    => $ed->listSprites(),
            'companions' => db()->query(
                'SELECT companion_key, name FROM companions ORDER BY name'
            )->fetchAll(),
        ]);
    }

    json_error('Unknown content action', 404);
}

/**
 * Refuse to remove the last way into the admin screens.
 *
 * Demoting or deactivating the only remaining active administrator locks
 * everybody out of user management, the editor and the content tools, with no
 * way back except tools/set_password.php on the host. Cheap to prevent, and the
 * kind of mistake that is only ever made once.
 */
function admin_assert_not_last_admin(int $userId, bool $stillAdmin, bool $roleChange): void
{
    if ($stillAdmin) {
        return;
    }
    $stmt = db()->prepare('SELECT role, is_active FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || $row['role'] !== Auth::ROLE_ADMIN || (int) $row['is_active'] !== 1) {
        return; // Not an active admin; nothing is being taken away.
    }
    $count = (int) db()->query(
        "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
    )->fetchColumn();
    if ($count <= 1) {
        json_error(
            $roleChange
                ? 'That is the only administrator; make somebody else an admin first.'
                : 'That is the only administrator; deactivating it would lock everyone out.',
            409
        );
    }
}

function require_character_id(): int
{
    $id = session_character_id();
    if (!$id) {
        json_error('No active character. Create or select a character first.', 401);
    }
    return $id;
}

/**
 * The party this session is playing.
 *
 * Quests, flags and companions all belong to the party rather than to one
 * character, so the routes that touch them need this and not a character id.
 * Falls back to the active character's party because the session may predate
 * the party being created.
 */
function require_party_id(): int
{
    $partyId = session_party_id();
    if (!$partyId) {
        $partyId = WorldState::partyIdFor(db(), require_character_id());
    }
    if (!$partyId) {
        json_error('No active party.', 401);
    }
    $_SESSION['party_id'] = $partyId;
    return $partyId;
}

/**
 * A caster's slots, level by level: how many they have and how many are gone.
 *
 * `characters.spell_slots_json` records only what has been SPENT, and a long
 * rest clears the column outright — so an absent or empty value means a full
 * set rather than none, which is the reading a client writing its own
 * arithmetic is most likely to get backwards.
 *
 * Returns an empty list for anyone with no slots at all, so a Fighter's sheet
 * simply has no such section rather than a row of zeroes.
 *
 * The sum itself moved to Rules::slotState() when the printed character sheet
 * needed the same answer without going through the API. Kept as a name here
 * because the route reads better for it, and because this is where the note
 * above about a client doing its own arithmetic belongs.
 *
 * @return list<array{level:int, have:int, used:int, left:int}>
 */
function spell_slot_state(array $character): array
{
    return Rules::slotState($character);
}

/**
 * Does this character belong to whoever is signed in?
 *
 * The foundation both checks below stand on. Every route that takes a character
 * id from the request reaches one of those two, which is why the rule is not
 * repeated seventy-one times — a check spread that thin is a check with a hole
 * in it.
 *
 * The rule itself now lives in Ownership, because the printed character sheet
 * needs the same answer without travelling through a route. These two remain
 * because the routes below read better for them, and because the API is where
 * anyone looking for the ownership gate will look first.
 */
function character_is_owned(int $id): bool
{
    return Ownership::character($id);
}

function party_is_owned(int $partyId): bool
{
    return Ownership::party($partyId);
}

/**
 * Is this character part of the game currently being played?
 *
 * The right question for anything that acts *within* a game — changing a rank,
 * re-dressing somebody, reading a sheet. Not the right question for managing the
 * character list, which spans games: see assert_character_manageable.
 *
 * Being in the active party is no longer sufficient on its own. It used to be,
 * and that was the hole: the session's party is whatever the session says it is,
 * so the test asked "is this character in the party you claim to be playing"
 * rather than "is this character yours". Ownership is now checked first and the
 * party membership test narrows it further.
 */
function assert_character_accessible(int $id): void
{
    if (!character_is_owned($id)) {
        json_error('Character not accessible from this session.', 403);
    }
    if ($id === session_character_id()) {
        return;
    }
    $partyId = session_party_id();
    if ($partyId) {
        $stmt = db()->prepare('SELECT 1 FROM character_party WHERE party_id = ? AND character_id = ?');
        $stmt->execute([$partyId, $id]);
        if ($stmt->fetchColumn()) {
            return;
        }
    }
    json_error('Character not accessible from this session.', 403);
}

/**
 * Is this a character the player is allowed to retire or bring back?
 *
 * Deliberately wider than assert_character_accessible, because retiring happens
 * from the character list rather than from inside a game. Once "Start a New Game"
 * began making a separate party, the party-scoped check meant a character from
 * any other game could never be retired — the list would show it, offer it, and
 * refuse to remove it. Companions are excluded here as they are everywhere: they
 * are dismissed at camp, not retired.
 *
 * Wider, but no longer unbounded: before ownership existed this checked only
 * that the row was a character and not a companion, so any id at all could be
 * retired by anyone who could name it.
 */
function assert_character_manageable(int $id): void
{
    if (!empty(assert_character_readable($id)['companion_key'])) {
        json_error('Companions are dismissed at camp, not retired.', 403);
    }
}

/**
 * Is this a character the player is allowed to LOOK at?
 *
 * The half of assert_character_manageable that is about ownership, without the
 * half that is about companions — because a companion is somebody you may read
 * and not somebody you may retire, and those two facts were welded together
 * while only retiring ever asked. The picker's party tabs put Brother Aldric's
 * sheet one press away from Sera's, which is a reasonable thing to want and not
 * a way to do anything to him.
 *
 * Returns the row, so a caller that needs the companion flag does not go back
 * for it — assert_character_manageable is exactly that caller.
 *
 * 404 rather than 403 for somebody else's character: a 403 would confirm the id
 * exists, which turns either route into a way of counting other people's saves.
 *
 * @return array<string, mixed>
 */
function assert_character_readable(int $id): array
{
    $stmt = db()->prepare('SELECT id, companion_key FROM characters WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    // The row's existence is checked before ownership, not after: an admin
    // passes the ownership test on any id at all, including one that was never
    // a character.
    if (!$row || !character_is_owned($id)) {
        json_error('No such character.', 404);
    }
    return $row;
}


function handle_meta(string $action): void
{
    $db = db();
    if ($action === 'races') {
        json_response([
            'ok'    => true,
            'races' => (new CharacterGenerator($db))->listRaces(),
            // Which of them have name tables written, so the creator can offer
            // the dice on those rows and not on the others. Shipped beside the
            // catalogue rather than folded into it: listRaces() is a SELECT *,
            // and a computed column on a row is a column somebody will later
            // look for in the database.
            'name_tables' => NameGen::races(),
        ]);
    }
    if ($action === 'classes') {
        json_response([
            'ok'      => true,
            'classes' => (new CharacterGenerator($db))->listClasses(),
            // Which abilities each class wants, best first, for the creator to
            // mark up its grid. Beside the catalogue rather than folded into a
            // row, for the reason `name_tables` is: listClasses() is a SELECT *,
            // and the secondary is our advice rather than a column anybody
            // should go looking for in the database.
            'key_abilities' => Rules::CLASS_ABILITIES,
        ]);
    }
    if ($action === 'avatars') {
        json_response(['ok' => true, 'avatars' => (new CharacterGenerator($db))->listAvatars()]);
    }
    // The origin shelf of the feat catalogue, for the creation wizard's gift
    // step. Served flat rather than through Feats::offerTo() because there is
    // no character yet to qualify — origin feats carry no prerequisites by
    // design, which is what makes offering them here sound.
    if ($action === 'feats') {
        $origin = [];
        foreach (Feats::all() as $key => $feat) {
            if (($feat['category'] ?? '') !== 'origin') {
                continue;
            }
            $origin[] = [
                'key'         => $key,
                'name'        => $feat['name'],
                'description' => $feat['description'],
                'partial'     => $feat['partial'] ?? null,
            ];
        }
        json_response(['ok' => true, 'feats' => $origin]);
    }
    // The rules tables the client needs to draw a turn: how many slots a class
    // has at a level, and what it may spend a bonus action on.
    //
    // Served rather than duplicated into JavaScript. Both were copied there,
    // with a comment admitting it, and the failure mode is silent — a caster
    // shown fewer slot dots than the server will let them spend, or a bonus
    // action offered that the engine refuses.
    if ($action === 'rules') {
        $slots = [];
        foreach ((new CharacterGenerator($db))->listClasses() as $class) {
            $name = (string) $class['name'];
            for ($level = 1; $level <= Rules::MAX_LEVEL; $level++) {
                $slots[$name][$level] = Rules::slotTable($name, $level);
            }
        }
        json_response([
            'ok'            => true,
            'max_level'     => Rules::MAX_LEVEL,
            'slots'         => $slots,
            'bonus_actions' => CombatEngine::BONUS_ACTIONS,
            'skills'        => Rules::SKILLS,
            // What every condition actually does, in the words the rules layer
            // already holds. The combat board drew a condition as the first two
            // letters of its key in a box — "BL", "RE" — with the raw key as the
            // tooltip, so a player could see that something had happened to them
            // and had no way at all to find out what. Served for the same reason
            // the slot table is: it is rules, and the one copy of it belongs on
            // the server.
            'conditions'    => Conditions::forClient(),
        ]);
    }

    // The character creator's wardrobe: every layer that can be worn, with the
    // preview frame the browser stacks for the live view.
    if ($action === 'paperdoll') {
        json_response(['ok' => true] + Paperdoll::catalogue());
    }

    // Roll 4d6-drop-lowest six times, and remember it.
    //
    // The creator used to roll these in JavaScript as a "preview" and
    // resolveAbilities threw the numbers away and rolled again, so the scores a
    // player watched appear were never the scores they got. That was survivable
    // while the roll was a button and a number changing; it is not once there are
    // twenty-four dice tumbling across the screen to announce a result.
    //
    // So the server rolls, hands back the individual dice for the animation to
    // replay, and stashes the six totals in the session for character/create to
    // consume. Rerolling is unlimited and simply overwrites — what is being
    // guaranteed is that the dice on screen are the dice that count, not that you
    // only get one go.
    if ($action === 'roll_abilities') {
        require_method('POST');
        $sets = [];
        foreach (CharacterGenerator::ABILITIES as $i => $_) {
            $roll = Dice::abilityScore();
            $sets[] = [
                'index'   => $i,
                'kept'    => $roll['rolls'],
                'dropped' => $roll['dropped'],
                // Every die thrown, so the animation has four to show and does not
                // have to reconstruct the fourth from the total.
                'dice'    => array_merge($roll['rolls'], [$roll['dropped']]),
                'total'   => $roll['total'],
            ];
        }
        $_SESSION['rolled_abilities'] = array_column($sets, 'total');
        json_response(['ok' => true, 'sets' => $sets]);
    }

    // Is this name still going spare? Asked by the creator's first step so a
    // collision surfaces where the name is typed rather than four steps later
    // when the character is finally submitted. Not a reservation — the UNIQUE
    // index is still what guarantees it, and create/rename still check.
    /**
     * A suggested name for a people, already checked against the ones taken.
     *
     * The rolling is NameGen's and the checking is not: whether a name is
     * somebody's is a question about rows, so it is asked here, where the PDO
     * is, and NameGen stays pure. Twelve tries and then give up and answer
     * anyway — the client re-checks whatever lands in the field through
     * `name_free` regardless, so the worst case is the note saying it is taken
     * and the player pressing again.
     */
    if ($action === 'roll_name') {
        $race = trim((string) ($_GET['race'] ?? ''));
        $subrace = trim((string) ($_GET['subrace'] ?? ''));
        $except = isset($_GET['except']) ? (int) $_GET['except'] : null;
        // Unvalidated on purpose: NameGen treats anything it does not recognise
        // as "either", which is the right answer to a gender it has no pool for.
        $gender = trim((string) ($_GET['gender'] ?? ''));

        if (!NameGen::has($race, $subrace !== '' ? $subrace : null)) {
            json_response(['ok' => true, 'name' => null]);
        }
        $gen = new CharacterGenerator($db);
        $name = null;
        for ($try = 0; $try < 12; $try++) {
            $candidate = NameGen::roll(
                $race,
                $subrace !== '' ? $subrace : null,
                $gender !== '' ? $gender : null
            );
            $name = $candidate;
            if ($candidate !== null && $gen->nameTaken($candidate, $except) === null) {
                break;
            }
        }
        json_response(['ok' => true, 'name' => $name]);
    }

    if ($action === 'name_free') {
        $name = trim((string) ($_GET['name'] ?? ''));
        $except = isset($_GET['except']) ? (int) $_GET['except'] : null;
        if ($name === '') {
            json_response(['ok' => true, 'free' => false, 'reason' => 'A name is required.']);
        }
        $why = (new CharacterGenerator($db))->nameTaken($name, $except);
        json_response(['ok' => true, 'free' => $why === null, 'reason' => $why]);
    }

    if ($action === 'point_buy') {
        json_response([
            'ok' => true,
            'budget' => CharacterGenerator::POINT_BUY_BUDGET,
            'costs' => CharacterGenerator::POINT_BUY_COST,
            'standard_array' => CharacterGenerator::STANDARD_ARRAY,
        ]);
    }
    json_error('Unknown meta action', 404);
}

/**
 * Which of a party's characters you resume as when you play the party.
 *
 * Whoever the session already had active, if they are in this party — coming
 * back to a game should put you behind the same person you left it as. Failing
 * that the leader, and failing that the first slot; the same ORDER BY the party
 * rail, the job board and the location's people list already sort by, so the
 * one the page shows first is the one this picks.
 *
 * Companions are excluded for the reason `session/select` excludes them: they
 * travel with you and are not characters you may be. A party of nothing but
 * companions cannot be played and says so rather than selecting one.
 */
function session_party_lead(int $partyId): int
{
    if (!party_is_owned($partyId)) {
        json_error('No such party.', 404);
    }
    $stmt = db()->prepare(
        'SELECT c.id
           FROM character_party cp
           JOIN characters c ON c.id = cp.character_id
           JOIN parties p ON p.id = cp.party_id
          WHERE cp.party_id = ? AND c.is_active = 1 AND c.companion_key IS NULL
          ORDER BY c.id = ? DESC, c.id = p.leader_character_id DESC, cp.slot, c.id
          LIMIT 1'
    );
    $stmt->execute([$partyId, session_character_id() ?? 0]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        json_error('That party has nobody you can play as.', 404);
    }
    return (int) $id;
}

function handle_session(string $action): void
{
    if ($action === 'status') {
        $cid = session_character_id();
        $pid = session_party_id();
        $data = ['ok' => true, 'character_id' => $cid, 'party_id' => $pid];
        if ($cid) {
            $gen = new CharacterGenerator(db());
            try {
                $data['character'] = $gen->getCharacter($cid);
                if ($pid) {
                    $data['party'] = $gen->getPartyCharacters($pid);
                }
                $combat = new CombatEngine(db());
                $data['combat'] = $combat->getActiveSession($cid);
            } catch (Throwable $e) {
                $data['character'] = null;
            }
        }
        json_response($data);
    }
    if ($action === 'select') {
        require_method('POST');
        $body = read_json_body();
        $cid = (int) ($body['character_id'] ?? 0);
        // A party is the unit of a playthrough, so "play this" is asked about a
        // party far more often than about one of its members — the character
        // list offers one button per party, not one per person. The session
        // still plays a single character, though, so somebody has to decide
        // which; that decision lives here rather than in the page, beside the
        // `leader_character_id` it reads and the ownership rule it needs.
        if (!$cid && isset($body['party_id'])) {
            $cid = session_party_lead((int) $body['party_id']);
        }
        // Before anything is read about them. `select` is the route that decides
        // whose game the session is playing, so an unchecked id here would hand
        // over somebody else's save wholesale — every later route would then be
        // asking its questions about a session that is legitimately theirs.
        if (!character_is_owned($cid)) {
            json_error('No such character.', 404);
        }
        $gen = new CharacterGenerator(db());
        $char = $gen->getCharacter($cid);
        // Same rule as session/list: a companion is a party member, not a
        // character you may be. Checked here as well as hidden in the UI,
        // because the id arrives in a request body and the UI is not a guard.
        if (!empty($char['companion_key'])) {
            json_error('Companions travel with you; they are not characters you can play as.', 403);
        }
        $stmt = db()->prepare('SELECT party_id FROM character_party WHERE character_id = ? LIMIT 1');
        $stmt->execute([$cid]);
        $pid = $stmt->fetchColumn();
        set_active_character($cid, $pid ? (int) $pid : null);
        json_response(['ok' => true, 'character' => $char, 'party_id' => $pid ? (int) $pid : null]);
    }
    if ($action === 'list') {
        // Yours, and only yours. This query had no owner filter at all, which
        // was invisible while one seeded account owned every row and would have
        // shown the second player everybody else's saves the moment there was a
        // second player.
        //
        // An admin sees the lot, which is the decision taken when roles were
        // added: being able to open a player's save is how a broken one gets
        // diagnosed. Their own characters still sort first so the list is not a
        // wall of other people's.
        $auth = auth();
        $userId = $auth->userId();
        if ($auth->isAdmin()) {
            $stmt = db()->prepare(
                'SELECT c.id, c.name, c.race, c.subrace, c.class, c.level,
                        c.current_hp, c.max_hp, cp.party_id, c.user_id,
                        u.username AS owner, m.module_key, m.name AS module_name,
                        p.name AS party_name,
                        ' . CharacterGenerator::SPRITE_SELECT . '
                 FROM characters c
                 LEFT JOIN character_party cp ON cp.character_id = c.id
                 LEFT JOIN parties p ON p.id = cp.party_id
                 LEFT JOIN modules m ON m.id = p.module_id
                 LEFT JOIN users u ON u.id = c.user_id
                 LEFT JOIN classes cl ON cl.name = c.class
                 ' . CharacterGenerator::SPRITE_JOIN . '
                 WHERE c.is_active = 1 AND c.companion_key IS NULL
                 ORDER BY (c.user_id = ?) DESC, c.id DESC LIMIT 200'
            );
            $stmt->execute([$userId]);
        } else {
            $stmt = db()->prepare(
                'SELECT c.id, c.name, c.race, c.subrace, c.class, c.level,
                        c.current_hp, c.max_hp, cp.party_id,
                        m.module_key, m.name AS module_name,
                        p.name AS party_name,
                        -- The one rule about which art a character wears, taken
                        -- from where it is written rather than restated. Both
                        -- halves travel together; see the constants.
                        ' . CharacterGenerator::SPRITE_SELECT . '
                 FROM characters c
                 LEFT JOIN character_party cp ON cp.character_id = c.id
                 LEFT JOIN parties p ON p.id = cp.party_id
                 LEFT JOIN modules m ON m.id = p.module_id
                 LEFT JOIN classes cl ON cl.name = c.class
                 ' . CharacterGenerator::SPRITE_JOIN . '
                 -- Recruited companions live in `characters` too, because combat,
                 -- inventory and levelling all want one kind of row. They are not
                 -- yours to play as, though, so they have no business in the list
                 -- you pick a character from — Brother Aldric was showing up
                 -- offering to be your protagonist.
                 WHERE c.is_active = 1 AND c.companion_key IS NULL AND c.user_id = ?
                 ORDER BY c.id DESC LIMIT 50'
            );
            $stmt->execute([$userId]);
        }
        json_response(['ok' => true, 'characters' => $stmt->fetchAll()]);
    }
    if ($action === 'sheet') {
        // The whole sheet for one of YOUR characters, whichever game they are
        // in. The picker on the front page draws it beside the list.
        //
        // Deliberately not `character/sheet`, and the difference is the gate
        // rather than the payload. That route asks assert_character_accessible
        // — "is this somebody in the game you are playing" — which is the right
        // question inside a game and the wrong one here: the whole point of the
        // picker is to read a character from a game you are NOT currently in.
        // This is the same widening sheet_print.php made for the same reason,
        // and one step further: a companion may be READ here, because the tabs
        // across the top of the sheet draw the whole marching party and a tab
        // that cannot open is worse than no tab. Retiring one is still refused,
        // in the gate that is about retiring.
        //
        // The numbers are CharacterSheet's, which are Rules'. Nothing on this
        // page does arithmetic — a second rules engine in the front-page script
        // would be wrong in exactly the places nobody checks.
        $id = (int) ($_GET['character_id'] ?? 0);
        if ($id <= 0) {
            json_error('character_id required', 400);
        }
        assert_character_readable($id);

        // Which game they are in, who they march with, and where they are
        // standing — the three things the sheet's Play button has to be able to
        // say and the sheet itself does not carry. A character with no party
        // has no module either (a module is a property of the party), so every
        // one of these is legitimately null.
        $stmt = db()->prepare(
            'SELECT p.id AS party_id, p.name AS party_name,
                    m.module_key, m.name AS module_name,
                    (SELECT COUNT(*) FROM character_party x WHERE x.party_id = p.id) AS party_size
               FROM character_party cp
               LEFT JOIN parties p ON p.id = cp.party_id
               LEFT JOIN modules m ON m.id = p.module_id
              WHERE cp.character_id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $context = $stmt->fetch() ?: [];

        $sheet = (new CharacterSheet(db()))->build($id);
        $locId = (int) ($sheet['character']['current_location_id'] ?? 0);
        if ($locId > 0) {
            $where = db()->prepare(
                'SELECT l.name AS location_name, r.name AS region_name
                   FROM locations l LEFT JOIN regions r ON r.id = l.region_id
                  WHERE l.id = ? LIMIT 1'
            );
            $where->execute([$locId]);
            $context += $where->fetch() ?: [];
            // Whether the party may sleep where it is standing, asked of the
            // engine rather than worked out from the location row here. It is
            // the same question `location/camp` will ask when the button is
            // pressed, through the same method, so the button cannot offer a
            // rest the route then refuses — and "allow_camp, or an inn" stays
            // one rule in one place.
            $context['can_camp'] = (new LocationEngine(db()))->canCampHere($id);
        }

        // Who marches with them, for the tabs across the top of the sheet.
        //
        // From the server rather than filtered out of `session/list`, because
        // that list is the characters you may PLAY and deliberately has no
        // companions in it — and a party's fourth member missing from its own
        // row of tabs is the sort of quiet omission that reads as a bug. This
        // is the party as the game holds it: slot order, companions among them,
        // marked as such.
        //
        // Cut down to what a tab draws. The full rows are a kilobyte each of
        // spell slots and feats that nothing up here reads.
        $party = [];
        if (!empty($context['party_id'])) {
            foreach ((new CharacterGenerator(db()))->getPartyCharacters((int) $context['party_id']) as $m) {
                $party[] = [
                    'id'         => (int) $m['id'],
                    'name'       => $m['name'],
                    'class'      => $m['class'],
                    'level'      => (int) $m['level'],
                    'current_hp' => (int) $m['current_hp'],
                    'max_hp'     => (int) $m['max_hp'],
                    'sprite_key' => $m['sprite_key'] ?? null,
                    'companion'  => !empty($m['companion_key']),
                ];
            }
        }

        json_response([
            'ok'      => true,
            'sheet'   => $sheet,
            'context' => $context,
            'party'   => $party,
            // What a fight can be asked for at, easiest first. Shipped from the
            // constant rather than written into the page, for the reason the
            // printed book reads the pit's odds out of the engine: a second
            // copy of a difficulty table is one that can quietly stop matching
            // the fights it names. The page draws a button per row of this and
            // sends the `tier` back untouched.
            'tiers'   => array_map(
                static fn (string $key): array => [
                    'tier'  => $key,
                    'label' => PitEngine::TIERS[$key]['label'],
                    'blurb' => PitEngine::TIERS[$key]['blurb'],
                ],
                array_keys(PitEngine::TIERS)
            ),
        ]);
    }
    if ($action === 'modules') {
        // What the picker draws: every module you can start, and how much of
        // one you already have going. Answered in one route rather than joined
        // client-side against session/list, because "how many parties do I have
        // in this game" is a question about the module, and the alternative is
        // the landing page reimplementing the ownership rule to work it out.
        //
        // Withdrawn modules (is_active = 0) are left out of the catalogue but
        // NOT out of the counts — a party already inside one keeps showing up
        // in the character list and has to remain playable. Hiding a module is
        // for stopping new games in it, not for confiscating running ones.
        // Nothing at all while the adventures are hidden. The shelf is drawn
        // from this, so an empty answer is what makes it not exist rather than
        // exist and be empty — and it means a page that has not been updated
        // still cannot start a game in a module nobody can see.
        if (!ADVENTURES_ENABLED) {
            json_response(['ok' => true, 'modules' => []]);
        }

        $auth = auth();
        $stmt = db()->prepare(
            'SELECT m.id, m.module_key, m.name, m.blurb, m.attribution,
                    m.level_min, m.level_max,
                    (SELECT COUNT(*) FROM parties p
                      WHERE p.module_id = m.id
                        AND (p.user_id = :owner OR :is_admin = 1)) AS party_count
               FROM modules m
              WHERE m.is_active = 1
              ORDER BY m.sort_order, m.id'
        );
        $stmt->execute([
            ':owner'    => $auth->userId(),
            ':is_admin' => $auth->isAdmin() ? 1 : 0,
        ]);
        json_response(['ok' => true, 'modules' => $stmt->fetchAll()]);
    }
    json_error('Unknown session action', 404);
}

function handle_character(string $action): void
{
    $db = db();
    $gen = new CharacterGenerator($db);

    if ($action === 'create') {
        require_method('POST');
        $body = read_json_body();
        // An absent party_id means a NEW party — a new game — not the one the
        // session happens to be playing.
        //
        // It used to fall back to session_party_id(), which made "Create
        // Character" on the main menu silently add a fourth member to whatever
        // party you were in the middle of, with no way to start over. Joining an
        // existing party is a real thing to want, but it is the thing you say
        // explicitly: the party rail's Recruit link passes the id.
        $partyId = isset($body['party_id']) ? (int) $body['party_id'] : null;
        if ($partyId === 0) {
            $partyId = null;
        }
        // Joining a party you do not own would put a character of yours inside
        // somebody else's game — and party membership is one of the things
        // assert_character_accessible() accepts as proof, so it would widen from
        // there.
        if ($partyId && !party_is_owned($partyId)) {
            json_error('No such party.', 404);
        }
        // Which game a NEW party is for. Ignored outright when joining one, and
        // not merely overridden: the party's module decides, and letting a
        // request name a different one would be a way to plant a character in a
        // world their party cannot reach. CharacterGenerator::resolveModule()
        // is where that precedence actually lives; this only stops the value
        // travelling far enough to be confusing.
        if ($partyId) {
            unset($body['module']);
        }
        // Party size limit 4
        if ($partyId) {
            $cnt = $db->prepare('SELECT COUNT(*) FROM character_party WHERE party_id = ?');
            $cnt->execute([$partyId]);
            if ((int) $cnt->fetchColumn() >= 4) {
                json_error('Party is full (max 4 characters).');
            }
        }
        // The rolled scores come from the session, never from the request: the
        // client says which ability each total goes on, the server says what the
        // totals were. Taking `rolled` off the body would make the whole check
        // circular.
        unset($body['rolled']);
        if (($body['method'] ?? '') === 'random') {
            // No dice in the session means nothing was rolled — or the roll has
            // already been spent on a character. CharacterGenerator would happily
            // roll its own at this point and ignore the arrangement that was
            // submitted, which is precisely the silent mismatch this whole change
            // exists to remove, so refuse instead and say why.
            if (empty($_SESSION['rolled_abilities'])) {
                json_error('Roll for your ability scores before creating the character.');
            }
            $body['rolled'] = $_SESSION['rolled_abilities'];
        }

        $char = $gen->create($body, $partyId);
        // Spent. Leaving them behind would let the next character created in this
        // session inherit somebody else's dice.
        unset($_SESSION['rolled_abilities']);
        set_active_character((int) $char['id'], (int) $char['party_id']);
        json_response(['ok' => true, 'character' => $char, 'party_id' => (int) $char['party_id']]);
    }

    if ($action === 'get') {
        $id = require_character_id();
        $char = $gen->getCharacter($id);
        $partyId = session_party_id();
        $party = $partyId ? $gen->getPartyCharacters($partyId) : [$char];
        $inv = (new InventoryService($db))->list($id);
        $spells = $db->prepare(
            'SELECT s.* FROM spells s
             INNER JOIN character_spells cs ON cs.spell_id = s.id
             WHERE cs.character_id = ?'
        );
        $spells->execute([$id]);
        json_response([
            'ok' => true,
            'character' => $char,
            'party' => $party,
            'inventory' => $inv,
            'spells' => $spells->fetchAll(),
        ]);
    }

    if ($action === 'avatar') {
        require_method('POST');
        $body = read_json_body();
        $id = (int) ($body['character_id'] ?? session_character_id() ?? 0);
        if ($id <= 0) {
            json_error('character_id required', 400);
        }
        assert_character_accessible($id);
        json_response([
            'ok' => true,
            'character' => $gen->setAvatar($id, trim((string) ($body['sprite_key'] ?? ''))),
        ]);
    }

    // Healing magic at the campfire. Combat casting goes through combat/act —
    // this route exists so a Cure Wounds is not stranded inside fights, and it
    // refuses anything but a mending (Spellbook says why).
    if ($action === 'cast') {
        require_method('POST');
        $body = read_json_body();
        $casterId = (int) ($body['character_id'] ?? session_character_id() ?? 0);
        if ($casterId <= 0) {
            json_error('character_id required', 400);
        }
        assert_character_accessible($casterId);
        $targetId = (int) ($body['target_character_id'] ?? $casterId);
        assert_character_accessible($targetId);

        // Mid-fight, the fight owns the spell slots and the action economy.
        $inFight = $db->prepare(
            'SELECT COUNT(*) FROM combat_sessions WHERE character_id = ? AND is_active = 1'
        );
        $inFight->execute([$casterId]);
        if ((int) $inFight->fetchColumn() > 0) {
            json_error('There is a fight on — cast through the battle screen.', 409);
        }

        // The target has to be standing at the same fire. Two parties are two
        // games, and magic does not carry between them.
        if ($targetId !== $casterId
            && WorldState::partyIdFor($db, $casterId) !== WorldState::partyIdFor($db, $targetId)) {
            json_error('They are not travelling with you.', 400);
        }

        try {
            $result = (new Spellbook($db))->castOutOfCombat(
                $gen->getCharacter($casterId),
                (int) ($body['spell_id'] ?? 0),
                $gen->getCharacter($targetId)
            );
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 400);
        }
        json_response($result + [
            'character' => $gen->getCharacter($targetId),
            'caster'    => $gen->getCharacter($casterId),
        ]);
    }

    // Names are unique, and the migration that made them so renamed existing
    // duplicates to "Name (2)". This is where a player puts that right.
    if ($action === 'rename') {
        require_method('POST');
        $body = read_json_body();
        $id = (int) ($body['character_id'] ?? session_character_id() ?? 0);
        if ($id <= 0) {
            json_error('character_id required', 400);
        }
        assert_character_accessible($id);
        json_response([
            'ok' => true,
            'character' => $gen->rename($id, (string) ($body['name'] ?? '')),
        ]);
    }

    // Soft delete. Frees the name and drops them from the party; the row stays
    // because quests, flags and the roll log reference it by id.
    if ($action === 'retire') {
        require_method('POST');
        $body = read_json_body();
        $id = (int) ($body['character_id'] ?? 0);
        if ($id <= 0) {
            json_error('character_id required', 400);
        }
        assert_character_manageable($id);

        // Not while their party is on a battlefield.
        //
        // The route had no UI at all until the picker grew a Retire button, so
        // this could not happen before and can now: a fight holds its
        // combatants in `state_json` and would go on swinging for somebody the
        // character list has stopped showing, and the session would be pointing
        // at a retired row. The check is on the PARTY rather than on this
        // character because the session belongs to whoever leads it — a fight
        // is the party's, not one member's. Same answer as casting mid-fight,
        // and the same 409.
        $theirParty = WorldState::partyIdFor($db, $id);
        if ($theirParty) {
            $fighting = $db->prepare(
                'SELECT COUNT(*) FROM combat_sessions cs
                   INNER JOIN character_party cp ON cp.character_id = cs.character_id
                  WHERE cp.party_id = ? AND cs.is_active = 1'
            );
            $fighting->execute([$theirParty]);
            if ((int) $fighting->fetchColumn() > 0) {
                json_error('There is a fight on. Finish it before retiring anybody.', 409);
            }
        }

        $result = $gen->retire($id);
        // If they retired whoever the session was playing, the session is now
        // pointing at somebody who is gone. Move it to a survivor rather than
        // leaving the next request to fail on a character that cannot load.
        if ($id === session_character_id()) {
            $next = $db->prepare(
                'SELECT c.id FROM characters c
                 INNER JOIN character_party cp ON cp.character_id = c.id
                 -- A companion must not inherit the session: they are not a
                 -- character you may play, so handing them the protagonist slot
                 -- puts the game in a state the character list cannot show.
                 WHERE cp.party_id = ? AND c.is_active = 1 AND c.companion_key IS NULL
                 ORDER BY cp.slot LIMIT 1'
            );
            $next->execute([session_party_id()]);
            $nextId = $next->fetchColumn();
            if ($nextId) {
                set_active_character((int) $nextId, session_party_id());
                $result['active_character_id'] = (int) $nextId;
            } else {
                unset($_SESSION['character_id']);
            }
        }
        json_response($result);
    }

    // Undo a retirement. Retiring is a soft delete so that this can exist.
    if ($action === 'restore') {
        require_method('POST');
        $body = read_json_body();
        $id = (int) ($body['character_id'] ?? 0);
        if ($id <= 0) {
            json_error('character_id required', 400);
        }
        assert_character_manageable($id);
        // Restoring without saying where puts them back in their own party rather
        // than in whatever game happens to be loaded — bringing a character back
        // into somebody else's party is a stranger thing to do by default than
        // returning them to their own.
        $partyId = isset($body['party_id'])
            ? (int) $body['party_id']
            : (WorldState::partyIdFor($db, $id) ?? session_party_id());
        json_response($gen->restore($id, $partyId ?: null));
    }

    // Which line they stand in when a fight starts. Refused mid-combat, where
    // rank is an action rather than a setting.
    if ($action === 'rank') {
        require_method('POST');
        $body = read_json_body();
        $id = (int) ($body['character_id'] ?? session_character_id() ?? 0);
        if ($id <= 0) {
            json_error('character_id required', 400);
        }
        assert_character_accessible($id);
        json_response([
            'ok' => true,
            'character' => $gen->setCombatRank($id, (string) ($body['rank'] ?? '')),
        ]);
    }

    /**
     * Bake a chosen appearance onto a character.
     *
     * Used by the creator and by re-dressing later, which are the same operation
     * — the layers are validated, composited into `pc_<id>` art, and the choice
     * itself is stored so it can be changed again.
     */
    if ($action === 'appearance') {
        require_method('POST');
        $body = read_json_body();
        $id = (int) ($body['character_id'] ?? session_character_id() ?? 0);
        if ($id <= 0) {
            json_error('character_id required', 400);
        }
        assert_character_accessible($id);

        $sex = (string) ($body['sex'] ?? 'male');
        $picks = (array) ($body['layers'] ?? []);
        $key = Paperdoll::bake($sex, $picks, Paperdoll::keyFor($id));

        $db->prepare('UPDATE characters SET sprite_key = ?, appearance_json = ? WHERE id = ?')
            ->execute([
                $key,
                json_encode(['sex' => $sex] + $picks, JSON_UNESCAPED_SLASHES),
                $id,
            ]);

        json_response([
            'ok'         => true,
            'sprite_key' => $key,
            'character'  => $gen->getCharacter($id),
        ]);
    }

    /**
     * Keep the portraits the 3D creator rendered.
     *
     * `portrait`, not `model`: `character/model` stores the recipe — the list
     * of part names that says what somebody looks like — and this stores the
     * picture of it. The recipe is the truth and the picture is a cache of it,
     * which is why either can be posted without the other and why re-dressing
     * posts both.
     *
     * The pictures come from the browser because that is the only place in this
     * system that can draw them; there is no renderer on this host. Everything
     * that follows from that is in ModelPortrait: the bytes are re-encoded
     * rather than saved, the filename comes from the character id and never
     * from the request, and the walk sprite is inherited so the four files a
     * sprite_key promises all exist.
     */
    if ($action === 'portrait') {
        require_method('POST');
        $body = read_json_body();
        $id = (int) ($body['character_id'] ?? session_character_id() ?? 0);
        if ($id <= 0) {
            json_error('character_id required', 400);
        }
        assert_character_accessible($id);

        // What they would have shown without a portrait, which is what the map
        // keeps showing. Read before the update, or it reads back its own answer.
        $before = $gen->getCharacter($id);
        $fallback = (string) ($before['sprite_key'] ?? '') ?: 'fighter';

        try {
            $key = ModelPortrait::bake(
                $id,
                (string) ($body['bust'] ?? ''),
                (string) ($body['face'] ?? ''),
                $fallback
            );
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 422);
        }

        $db->prepare('UPDATE characters SET sprite_key = ? WHERE id = ?')->execute([$key, $id]);

        json_response([
            'ok'         => true,
            'sprite_key' => $key,
            'character'  => $gen->getCharacter($id),
        ]);
    }

    if ($action === 'sheet') {
        $body = read_json_body();
        $id = (int) ($body['character_id'] ?? $_GET['character_id'] ?? session_character_id() ?? 0);
        if ($id <= 0) {
            json_error('character_id required', 400);
        }
        assert_character_accessible($id);
        $char = $gen->getCharacter($id);
        $inv = (new InventoryService($db))->list($id);
        $spells = $db->prepare(
            'SELECT s.* FROM spells s
             INNER JOIN character_spells cs ON cs.spell_id = s.id
             WHERE cs.character_id = ?'
        );
        $spells->execute([$id]);
        json_response([
            'ok' => true,
            'character' => $char,
            'inventory' => $inv,
            'spells' => $spells->fetchAll(),
            // What is left to cast with, decided here rather than in the
            // browser. The slot table is rules, and the one time it was copied
            // into JavaScript it drifted — a caster was shown fewer dots than
            // the engine would let them spend. The client draws this and does
            // no arithmetic of its own.
            'spell_slots' => spell_slot_state($char),
        ]);
    }

    if ($action === 'party') {
        $id = require_character_id();
        $stmt = $db->prepare('SELECT party_id FROM character_party WHERE character_id = ?');
        $stmt->execute([$id]);
        $pid = (int) $stmt->fetchColumn();
        json_response(['ok' => true, 'party_id' => $pid, 'party' => $gen->getPartyCharacters($pid)]);
    }

    // The companion roster, which is not the same list as the party: approval,
    // romance and camp status live on `party_companions` and have no column on
    // the `characters` row the party rail is otherwise drawn from. A companion
    // card cannot show how they feel about you without this.
    if ($action === 'companions') {
        json_response([
            'ok'         => true,
            'companions' => (new CompanionService($db))->forParty(require_party_id()),
        ]);
    }

    // Who marches and who waits. The party holds four, so recruiting a fifth
    // leaves somebody at the fire — and until these existed, there at the fire
    // is where they stayed, because CompanionService could swap them and
    // nothing ever asked it to. Both roads run through the service, which
    // enforces the cap itself and refuses rather than silently declining.
    if ($action === 'companion_activate' || $action === 'companion_dismiss') {
        require_method('POST');
        $partyId = require_party_id();
        $body = read_json_body();
        $key = trim((string) ($body['companion_key'] ?? ''));
        if ($key === '') {
            json_error('companion_key required', 400);
        }
        $svc = new CompanionService($db);
        $result = $action === 'companion_activate'
            ? $svc->activate($partyId, $key)
            : $svc->dismiss($partyId, $key);
        json_response([
            'ok'         => true,
            'result'     => $result,
            'companions' => $svc->forParty($partyId),
            'party'      => (new CharacterGenerator($db))->getPartyCharacters($partyId),
        ]);
    }

    /**
     * The 3D look, for the Unity client and for the character embed on the web.
     *
     * `model`, not `appearance`: `character/appearance` is the 2D paperdoll —
     * it bakes PNG layers into a sprite — and the two are different pictures of
     * the same person rather than two versions of one route. A client asking
     * for one and getting the other would get a valid answer to a question it
     * did not ask, which is the worst kind of wrong.
     *
     * GET reads it; POST writes it. Both take an explicit `character_id`,
     * because the embed is often on a page that is not "the game" — a profile,
     * a party roster, the creator opened from a link — and there may be no
     * active character in the session at all. Falls back to the active one when
     * none is named, which is what the in-game client always wants.
     *
     * assert_character_readable is the whole permission check, and it is the
     * right one for both directions: it proves the character is yours, and
     * re-dressing somebody you own is not a privileged act. It 404s rather than
     * 403s on somebody else's id, so this is not a way to count other people's
     * characters.
     */
    if ($action === 'model') {
        $write = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
        $body = $write ? read_json_body() : [];

        $id = (int) ($_GET['character_id'] ?? $body['character_id'] ?? 0);
        if ($id <= 0) {
            $id = require_character_id();
        }
        $row = assert_character_readable($id);

        // The race travels with the character, not with the recipe.
        //
        // A character who has never been dressed has no recipe at all, and the
        // creator has to open on something. Opening every one of them on a
        // human made the race the first thing every player had to correct,
        // including the ones whose sheet already said Dragonborn. Sending it
        // here lets the creator open as who they already are.
        $who = $db->prepare('SELECT name, race FROM characters WHERE id = ?');
        $who->execute([$id]);
        $found = $who->fetch(PDO::FETCH_ASSOC) ?: [];
        $character = [
            'id'   => $id,
            'name' => (string) ($found['name'] ?? ''),
            'race' => (string) ($found['race'] ?? ''),
        ];

        try {
            if (!$write) {
                json_response([
                    'ok'         => true,
                    'character'  => $character,
                    'appearance' => SidekickAppearance::load($db, $id),
                ]);
            }

            $look = SidekickAppearance::normalise($body['appearance'] ?? null);
            if ($look === null) {
                json_error('That appearance has no parts in it.', 422);
            }
            json_response([
                'ok'         => true,
                'character'  => $character,
                'appearance' => SidekickAppearance::save($db, $id, $look),
            ]);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            // The migration has not been run. Say which one.
            json_error($e->getMessage(), 500);
        }
    }

    json_error('Unknown character action', 404);
}

function handle_location(string $action): void
{
    $db = db();
    $engine = new LocationEngine($db);

    // The scene: prose, people, loot, exits, the region chart, and whatever
    // else the client needs to draw where the party is standing.
    if ($action === 'state' || $action === 'current') {
        $id = require_character_id();
        $state = $engine->getState($id, session_party_id());
        $state['ok'] = true;
        $state['combat'] = (new CombatEngine($db))->getActiveSession($id);
        json_response($state);
    }

    // Walk to any reachable location, however many hops away. The server
    // finds the path, narrates the hops, and may interrupt the trip with a
    // random encounter on dangerous ground.
    if ($action === 'travel') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        json_response($engine->travel($id, (int) ($body['location_id'] ?? 0)));
    }

    // Look the place over: surfaces ground loot, reveals hidden exits.
    if ($action === 'search') {
        require_method('POST');
        $id = require_character_id();
        json_response($engine->search($id));
    }

    // A generated locked door, in the check ceremony's two phases: see the DC
    // and the boosts, then commit. Mirrors dialogue's request/resolve split —
    // the DC, the flag and the outcome all live server-side between the calls.
    if ($action === 'force_door') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        json_response($engine->forceDoor($id, (int) ($body['exit_id'] ?? 0)));
    }

    if ($action === 'force_door_resolve') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        $boosts = $body['boosts'] ?? [];
        json_response($engine->forceDoorResolve(
            $id,
            (string) ($body['check_id'] ?? ''),
            is_array($boosts) ? $boosts : []
        ));
    }

    // The other way through a locked door, for a party carrying tools. Same
    // two phases as forcing; a different skill, a harder DC and no noise.
    if ($action === 'pick_lock') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        json_response($engine->pickLock($id, (int) ($body['exit_id'] ?? 0)));
    }

    if ($action === 'pick_lock_resolve') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        $boosts = $body['boosts'] ?? [];
        json_response($engine->pickLockResolve(
            $id,
            (string) ($body['check_id'] ?? ''),
            is_array($boosts) ? $boosts : []
        ));
    }

    // --- the chest in the room ----------------------------------------------
    // Same shape as the doors below, and for the same reason: what a party may
    // do to a fastened, possibly rigged box depends on what they are carrying
    // and what they have found, so the menu is asked for rather than assembled.
    if ($action === 'furnishing_menu') {
        require_method('POST');
        json_response((new FurnishingEngine($db))->menu(require_character_id()));
    }

    if ($action === 'furnishing_inspect') {
        require_method('POST');
        json_response((new FurnishingEngine($db))->inspect(require_character_id()));
    }

    if ($action === 'furnishing_open') {
        require_method('POST');
        json_response((new FurnishingEngine($db))->open(require_character_id()));
    }

    // Forcing and picking are one ceremony with two first phases — a different
    // skill and a different DC, and the same thing happens when the lid gives.
    if ($action === 'furnishing_force' || $action === 'furnishing_pick') {
        require_method('POST');
        $id = require_character_id();
        $eng = new FurnishingEngine($db);
        json_response($action === 'furnishing_force' ? $eng->force($id) : $eng->pick($id));
    }

    if ($action === 'furnishing_force_resolve' || $action === 'furnishing_pick_resolve') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        $boosts = $body['boosts'] ?? [];
        json_response((new FurnishingEngine($db))->openResolve(
            $id,
            (string) ($body['check_id'] ?? ''),
            is_array($boosts) ? $boosts : []
        ));
    }

    if ($action === 'furnishing_disarm') {
        require_method('POST');
        json_response((new FurnishingEngine($db))->disarm(require_character_id()));
    }

    if ($action === 'furnishing_disarm_resolve') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        $boosts = $body['boosts'] ?? [];
        json_response((new FurnishingEngine($db))->disarmResolve(
            $id,
            (string) ($body['check_id'] ?? ''),
            is_array($boosts) ? $boosts : []
        ));
    }

    // --- doors, as things you do something to -------------------------------
    // The menu is asked for rather than assembled by the client: what a party
    // may do at a threshold depends on what they are carrying and what they
    // have found, and neither is a browser's business.
    if ($action === 'door_menu') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        json_response((new DoorEngine($db))->menu($id, (int) ($body['exit_id'] ?? 0)));
    }

    if ($action === 'door_inspect') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        json_response((new DoorEngine($db))->inspect($id, (int) ($body['exit_id'] ?? 0)));
    }

    if ($action === 'door_disarm') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        json_response((new DoorEngine($db))->disarm($id, (int) ($body['exit_id'] ?? 0)));
    }

    if ($action === 'door_disarm_resolve') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        $boosts = $body['boosts'] ?? [];
        json_response((new DoorEngine($db))->disarmResolve(
            $id, (string) ($body['check_id'] ?? ''), is_array($boosts) ? $boosts : []
        ));
    }

    if ($action === 'door_barricade') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        json_response((new DoorEngine($db))->barricade($id, (int) ($body['exit_id'] ?? 0)));
    }

    if ($action === 'door_barricade_resolve') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        $boosts = $body['boosts'] ?? [];
        json_response((new DoorEngine($db))->barricadeResolve(
            $id, (string) ($body['check_id'] ?? ''), is_array($boosts) ? $boosts : []
        ));
    }

    if ($action === 'door_clear') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        json_response((new DoorEngine($db))->clearBarricade($id, (int) ($body['exit_id'] ?? 0)));
    }

    if ($action === 'loot') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        json_response($engine->lootItem($id, (int) ($body['location_item_id'] ?? 0)));
    }

    // The innkeeper's room, at the location's own price.
    if ($action === 'rest') {
        require_method('POST');
        $id = require_character_id();
        json_response($engine->restAtInn($id));
    }

    // Camping is free where the location allows it. What it costs is that it
    // is where a companion whose approval has bottomed out walks away.
    if ($action === 'camp') {
        require_method('POST');
        $id = require_character_id();
        if (!$engine->canCampHere($id)) {
            json_error('This is no place to camp. Find open ground or an inn.');
        }
        json_response($engine->longRest($id, 0, 'camp'));
    }

    // An hour rather than a night: a hit die each and the features whose SRD
    // text says short rest. Allowed anywhere camping is, because the thing that
    // makes a place safe enough to sleep makes it safe enough to sit down.
    if ($action === 'short_rest') {
        require_method('POST');
        $id = require_character_id();
        json_response($engine->shortRest($id));
    }

    // The stair. `descend` both begins a delve and goes a floor deeper —
    // DelveEngine decides which from whether the party already has one, so the
    // client does not have to hold that state or get it wrong.
    if ($action === 'descend') {
        require_method('POST');
        $id = require_character_id();
        $partyId = require_party_id();
        json_response((new DelveEngine($db))->descend($id, $partyId));
    }

    // Climbing out, from wherever they are. The floor they were on stops
    // existing, which is why this is a route rather than an exit: an exit would
    // have to be written into every room.
    if ($action === 'leave_dungeon') {
        require_method('POST');
        require_character_id();
        $partyId = require_party_id();
        (new DelveEngine($db))->end($partyId);
        json_response(['ok' => true, 'message' => 'You climb back up into the daylight.']);
    }

    json_error('Unknown location action', 404);
}

function handle_combat(string $action): void
{
    $db = db();
    $engine = new CombatEngine($db);

    if ($action === 'status') {
        $id = require_character_id();
        $session = $engine->getActiveSession($id);
        json_response(['ok' => true, 'combat' => $session]);
    }

    if ($action === 'start') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        $char = (new LocationEngine($db))->getCharacterPosition($id);

        // An arrival event names an encounter by row id because that is what
        // the placement stores; authored content names it by key, because
        // Effects::startCombat is handed `{"start_combat": "goblin_scouts"}`
        // and a numeric id must never appear in a JSON file. Both resolve to
        // an id here rather than making the client guess which the server wants.
        $encounterId = (int) ($body['encounter_id'] ?? 0);
        $encounterKey = trim((string) ($body['encounter_key'] ?? ''));
        if ($encounterId <= 0 && $encounterKey !== '') {
            $stmt = $db->prepare('SELECT id FROM encounters WHERE encounter_key = ?');
            $stmt->execute([$encounterKey]);
            $encounterId = (int) ($stmt->fetchColumn() ?: 0);
            if ($encounterId <= 0) {
                json_error("No encounter with key: {$encounterKey}", 400);
            }
        }

        $session = $engine->startEncounter($id, $encounterId, (int) $char['current_location_id']);
        json_response(['ok' => true, 'combat' => $session]);
    }

    // A bout in the fighting pit: built to the party's size and level, then
    // started in one round trip. Deliberately not two — an encounter generated
    // by one request and started by another can be inspected, re-rolled and
    // cherry-picked until the pit offers something soft.
    if ($action === 'pit') {
        require_method('POST');
        $id = require_character_id();
        $partyId = require_party_id();
        $body = read_json_body();
        $char = (new LocationEngine($db))->getCharacterPosition($id);
        $locationId = (int) $char['current_location_id'];

        // Only in the pit. The route takes no location, so without this the
        // fight could be called for from anywhere the client felt like.
        $type = $db->prepare('SELECT location_type FROM locations WHERE id = ?');
        $type->execute([$locationId]);
        if ((string) $type->fetchColumn() !== 'arena') {
            json_error('There is nowhere to fight here.', 400);
        }

        $encounterId = (new PitEngine($db))->bout($partyId, (string) ($body['tier'] ?? 'fair'));
        json_response([
            'ok'     => true,
            // A map seed of its own. PitEngine::bout() rewrites one scratch
            // encounter row per party, so the id it hands back is the same id
            // every time — leave the seed to be derived from it and a player
            // fights every bout of their career on one identical battlefield.
            'combat' => $engine->startEncounter($id, $encounterId, $locationId, random_int(0, PHP_INT_MAX)),
        ]);
    }

    // A fight found on the road: the pit's match, taken wherever the party is
    // standing. Offered beside Play on the front page, so a player who wants a
    // fight for its own sake can have one without walking to the arena in the
    // Quarry Wilds — which is one location, in one module of four.
    //
    // Same sizing and same payout as a bout at the arena; see PitEngine.
    if ($action === 'random') {
        require_method('POST');
        $id = require_character_id();
        $partyId = require_party_id();
        $body = read_json_body();

        // Already fighting: hand back the fight they are in rather than
        // refusing or, worse, starting a second one. The button means "take me
        // to a fight", and the one they are already in is a fight — a party
        // interrupted mid-bout by a reload presses this and lands back on the
        // sand. CombatEngine allows one active session per character, so the
        // alternative to answering here is an error the front page cannot act
        // on.
        $active = $engine->getActiveSession($id);
        if ($active) {
            json_response(['ok' => true, 'combat' => $active, 'resumed' => true]);
        }

        // Nobody on their feet. The engine would happily deal a fight to four
        // unconscious characters and end it on the first monster turn, which
        // reads as the button being broken rather than as the party needing a
        // rest.
        $up = $db->prepare(
            'SELECT COUNT(*) FROM characters c
             INNER JOIN character_party cp ON cp.character_id = c.id
             WHERE cp.party_id = ? AND c.is_active = 1 AND c.current_hp > 0'
        );
        $up->execute([$partyId]);
        if ((int) $up->fetchColumn() === 0) {
            json_error('Nobody in this party is in any state to fight. Rest first.', 400);
        }

        $tier = trim((string) ($body['tier'] ?? 'fair'));
        if (!isset(PitEngine::TIERS[$tier])) {
            $tier = 'fair';
        }

        // Who is coming, if the player said. The picker draws a tick on every
        // party tab and sends the ones still ticked; anything else — an
        // authored fight, an ambush on the road — sends nothing and everybody
        // goes, because a fight that happens TO you does not take requests.
        //
        // Checked against this party rather than trusted: the ids arrive in a
        // request body, and an unchecked list is a way to deploy somebody
        // else's character onto your battlefield.
        $only = null;
        if (isset($body['members']) && is_array($body['members'])) {
            $mine = $db->prepare(
                'SELECT c.id, c.companion_key FROM characters c
                 INNER JOIN character_party cp ON cp.character_id = c.id
                 WHERE cp.party_id = ? AND c.is_active = 1 AND c.current_hp > 0'
            );
            $mine->execute([$partyId]);
            $standing = [];
            foreach ($mine->fetchAll() as $row) {
                $standing[(int) $row['id']] = empty($row['companion_key']);
            }

            $only = [];
            foreach ($body['members'] as $member) {
                $memberId = (int) $member;
                // Silently dropped rather than refused: somebody who was
                // knocked out between the page being drawn and the button being
                // pressed is not an error, they are simply not coming.
                if (isset($standing[$memberId]) && !in_array($memberId, $only, true)) {
                    $only[] = $memberId;
                }
            }
            if (!$only) {
                json_error('Choose somebody to take the fight.', 400);
            }

            // Somebody has to be the one you are playing as, and a companion
            // cannot be: `session/select` refuses them, the character list
            // cannot show them, and finalizeCombat pays the gold to whoever the
            // session says. So a party of companions alone is refused here
            // rather than starting a fight nobody is in.
            $leads = array_values(array_filter($only, static fn ($m) => $standing[$m]));
            if (!$leads) {
                json_error('One of your own characters has to take the field.', 400);
            }
            // The session follows the choice. Whoever the picker made active is
            // usually the party's lead, and leaving them behind would otherwise
            // start a fight the player is watching from outside it.
            if (!in_array($id, $leads, true)) {
                $id = $leads[0];
                set_active_character($id, $partyId);
            }
        }

        $char = (new LocationEngine($db))->getCharacterPosition($id);
        $encounterId = (new PitEngine($db))->skirmish($partyId, $tier, $only);
        json_response([
            'ok'     => true,
            // A seed of its own, for the reason the pit passes one: the scratch
            // encounter keeps the same row id for the life of the party, and a
            // battlefield derived from that id alone would be the same twelve
            // rocks every single time.
            'combat' => $engine->startEncounter(
                $id,
                $encounterId,
                (int) $char['current_location_id'],
                random_int(0, PHP_INT_MAX),
                $only
            ),
        ]);
    }

    // Finishes an attack the player asked to roll themselves. The die is thrown
    // by CheckService; this resumes the turn it interrupted.
    if ($action === 'resolve_check') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        json_response(['ok' => true, 'combat' => (new CombatEngine($db))->resolveAttackCheck(
            $id,
            (string) ($body['check_id'] ?? ''),
            (array) ($body['boosts'] ?? [])
        )]);
    }

    if ($action === 'action') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        $session = $engine->action($id, $body);
        json_response(['ok' => true, 'combat' => $session]);
    }

    json_error('Unknown combat action', 404);
}

function handle_quest(string $action): void
{
    $db = db();
    $qs = new QuestService($db);

    if ($action === 'list') {
        $partyId = require_party_id();
        // Markers are routed from wherever the party is standing, so each one
        // arrives knowing whether it is here or which way leads toward it.
        $here = (new LocationEngine($db))->getCharacterPosition(require_character_id());
        json_response([
            'ok' => true,
            'board' => $qs->listAvailable($partyId),
            'mine' => $qs->listForParty($partyId),
            'markers' => $qs->markers(
                $partyId,
                (int) $here['current_location_id'],
                require_character_id()
            ),
        ]);
    }

    // Reading the board. Only where there is one — the notices are on a wall in
    // a room, and a party that has not stood in front of it has not read it.
    if ($action === 'board') {
        require_method('POST');
        $partyId = require_party_id();
        $here = (new LocationEngine($db))->getCharacterPosition(require_character_id());
        $stmt = $db->prepare('SELECT has_job_board FROM locations WHERE id = ?');
        $stmt->execute([(int) $here['current_location_id']]);
        if (!$stmt->fetchColumn()) {
            json_error('There is no job board here.', 400);
        }
        json_response(['ok' => true, 'board' => $qs->readBoard($partyId)]);
    }

    if ($action === 'accept') {
        require_method('POST');
        $partyId = require_party_id();
        $body = read_json_body();
        // The job board sends a row id because that is what it was given;
        // everything authored names a quest by key. Both resolve to a key,
        // because start() takes the stable name and not the number the import
        // happened to assign.
        $key = (string) ($body['quest_key'] ?? '');
        if ($key === '' && !empty($body['quest_id'])) {
            $key = (string) $qs->questById((int) $body['quest_id'])['quest_key'];
        }
        if ($key === '') {
            json_error('No quest named.');
        }
        $result = $qs->start($partyId, $key);
        json_response($result + ['message' => implode(' ', $result['messages'] ?: $result['notes'])]);
    }

    if ($action === 'track') {
        require_method('POST');
        $partyId = require_party_id();
        $body = read_json_body();
        json_response($qs->track($partyId, (string) ($body['quest_key'] ?? '')));
    }

    json_error('Unknown quest action', 404);
}

/**
 * Whose bag a request is about.
 *
 * The sheet can be opened on any party member — `character/sheet` takes an id
 * and checks ownership — and it has been posting that id to `inventory/equip`
 * and `inventory/use` since it was written. Both routes threw it away and used
 * the session's active character instead, so pressing Equip on a companion's
 * sheet asked the wrong bag for the row and came back "Item not in inventory."
 * The button looked broken; the sheet had been right all along.
 *
 * Defaulting to the session keeps every caller that sends nothing working —
 * the combat bar's Use, among others.
 */
function inventory_owner(array $body): int
{
    $id = (int) ($body['character_id'] ?? 0);
    if ($id <= 0) {
        return require_character_id();
    }
    if (!character_is_owned($id)) {
        json_error('No such character.', 404);
    }
    return $id;
}

function handle_inventory(string $action): void
{
    $db = db();
    $inv = new InventoryService($db);

    // The pack, for a character the account owns rather than only for the one
    // the session is playing. `equip`, `use` and `give` have always taken a
    // character_id and checked it; reading, buying and selling did not, which
    // was invisible while the only door to any of them was inside the game and
    // is not now — the picker equips and buys for whoever is on screen, and
    // that is not the session's character.
    if ($action === 'list') {
        $id = inventory_owner($_GET);
        json_response(['ok' => true, 'inventory' => $inv->list($id)]);
    }

    if ($action === 'equip') {
        require_method('POST');
        $body = read_json_body();
        $id = inventory_owner($body);
        json_response($inv->equip($id, (int) $body['inventory_id'], (bool) ($body['equip'] ?? true)));
    }

    if ($action === 'use') {
        require_method('POST');
        $body = read_json_body();
        $id = inventory_owner($body);
        json_response($inv->usePotion($id, (int) $body['inventory_id']));
    }

    // Hand something to somebody else in the party. Both ids are checked here
    // and the party rule is checked in the service: this route decides who the
    // request may speak for, `give()` decides what the world allows.
    if ($action === 'give') {
        require_method('POST');
        $body = read_json_body();
        $from = inventory_owner($body);
        $to = (int) ($body['to_character_id'] ?? 0);
        if (!character_is_owned($to)) {
            json_error('No such character.', 404);
        }
        $qty = isset($body['quantity']) ? max(1, (int) $body['quantity']) : null;
        json_response($inv->give($from, (int) $body['inventory_id'], $to, $qty));
    }

    // The general store: the same counter, with nobody behind it.
    //
    // GeneralStore writes its own `shop_inventory` rows under a reserved key,
    // so everything past this line is the ordinary shop path — `shopFor()`,
    // `buy()`, `sell()` — and the picker's store is a shop in every way except
    // that it has no shopkeeper and stands nowhere. Re-stocked on every visit
    // rather than on a schedule: the shelf is derived from `items`, and the
    // moment `items` changes is exactly the moment nobody remembers to re-stock
    // a shop.
    if ($action === 'store') {
        $id = inventory_owner($_GET);
        $store = new GeneralStore($db);
        $store->stock();
        $char = (new CharacterGenerator($db))->getCharacter($id);
        json_response([
            'ok'        => true,
            'npc_key'   => GeneralStore::NPC_KEY,
            'items'     => $inv->shopFor(GeneralStore::NPC_KEY),
            'sellables' => $inv->sellablesFor($id),
            'gold'      => (int) ($char['gold'] ?? 0),
            'character' => ['id' => (int) $char['id'], 'name' => $char['name']],
        ]);
    }

    if ($action === 'shop') {
        // A shop is a merchant's, not the world's: the key names whose counter
        // you are standing at, and the sell list rides along so the panel can
        // show both directions of the trade at once.
        $npcKey = (string) ($_GET['npc'] ?? '');
        if ($npcKey === '') {
            json_error('Which merchant? Pass ?npc=<npc_key>.', 400);
        }
        $id = require_character_id();
        json_response([
            'ok'        => true,
            'items'     => $inv->shopFor($npcKey),
            'sellables' => $inv->sellablesFor($id),
        ]);
    }

    if ($action === 'buy') {
        require_method('POST');
        $body = read_json_body();
        $id = inventory_owner($body);
        json_response($inv->buy($id, (int) $body['item_id'], (string) ($body['npc_key'] ?? '')));
    }

    if ($action === 'sell') {
        require_method('POST');
        $body = read_json_body();
        $id = inventory_owner($body);
        json_response($inv->sell($id, (int) $body['inventory_id']));
    }

    json_error('Unknown inventory action', 404);
}

/**
 * The d20 check, in the two calls the ceremony needs.
 *
 * `request` describes a check and what may be spent on it, `resolve` rolls it.
 * They are two routes rather than one because the player is meant to see the DC
 * and decide before the die moves, and the die has to move on the server.
 */
function handle_check(string $action): void
{
    $db = db();
    $svc = new CheckService($db);

    if ($action === 'request') {
        require_method('POST');
        $id = require_character_id();
        json_response(['ok' => true, 'check' => $svc->request(check_party_id($id), read_json_body(), $id)]);
    }

    if ($action === 'resolve') {
        require_method('POST');
        require_character_id();
        $body = read_json_body();
        // The check id is the only thing carried across; the DC, the modifier
        // and the legal boosts all stay on the server between the two calls.
        $boosts = $body['boosts'] ?? [];
        json_response([
            'ok'     => true,
            'result' => $svc->resolve((string) ($body['check_id'] ?? ''), is_array($boosts) ? $boosts : []),
        ]);
    }

    if ($action === 'history') {
        $id = require_character_id();
        json_response(['ok' => true, 'rolls' => $svc->history(check_party_id($id), 30)]);
    }

    json_error('Unknown check action', 404);
}

/**
 * Claiming a level.
 *
 * Every route here is server-authoritative in the same way CheckService is: the
 * client asks for a hit die to be rolled and is told what it showed. It never
 * sends a number, and the choice routes re-derive what was legal rather than
 * trusting the list the client was given to render.
 */
function handle_levelup(string $action): void
{
    $svc = new LevelUpService(db());

    // What the party is owed. Polled by the client after anything that could
    // have granted experience — a fight ending, a conversation, a quest turned
    // in — because all three grant XP and none of them can stop to ask.
    if ($action === 'pending') {
        json_response(['ok' => true, 'pending' => $svc->pendingForParty(require_party_id())]);
    }

    // The full ceremony for one owed level: what it offers and what is left.
    if ($action === 'ceremony') {
        $ceremony = $svc->ceremony(levelup_pending_id());
        assert_character_accessible((int) $ceremony['character']['id']);
        json_response(['ok' => true, 'ceremony' => $ceremony]);
    }

    if ($action === 'roll_hp') {
        require_method('POST');
        $pendingId = levelup_pending_id();
        assert_character_accessible((int) $svc->ceremony($pendingId)['character']['id']);
        json_response($svc->rollHp($pendingId));
    }

    if ($action === 'choose_asi') {
        require_method('POST');
        $body = read_json_body();
        $pendingId = levelup_pending_id();
        assert_character_accessible((int) $svc->ceremony($pendingId)['character']['id']);
        $asi = $body['asi'] ?? null;
        $feat = $body['feat'] ?? null;
        json_response($svc->chooseAsi(
            $pendingId,
            is_array($asi) ? $asi : null,
            is_string($feat) && $feat !== '' ? $feat : null
        ));
    }

    if ($action === 'choose_spells') {
        require_method('POST');
        $body = read_json_body();
        $pendingId = levelup_pending_id();
        assert_character_accessible((int) $svc->ceremony($pendingId)['character']['id']);
        $spells = $body['spells'] ?? [];
        json_response($svc->chooseSpells($pendingId, is_array($spells) ? $spells : []));
    }

    json_error('Unknown levelup action', 404);
}

/**
 * The pending level-up being acted on.
 *
 * Read from the query string for GETs and the body for POSTs, because the
 * client sends it both ways and a route that only looked at one would fail
 * silently on the other — as a 'no such level-up' rather than as a missing
 * parameter, which is a considerably worse error to debug.
 */
function levelup_pending_id(): int
{
    $body = $_SERVER['REQUEST_METHOD'] === 'POST' ? read_json_body() : [];
    $id = (int) ($_GET['pending_id'] ?? $body['pending_id'] ?? 0);
    if ($id < 1) {
        json_error('Which level-up?', 400);
    }
    return $id;
}

/**
 * The party a check belongs to.
 *
 * Falls back to the character's own party when the session has no party id,
 * because a session selected before parties existed still has a character, and
 * a roll logged against no party would never appear in the journal again.
 */
function check_party_id(int $characterId): int
{
    $partyId = session_party_id() ?? WorldState::partyIdFor(db(), $characterId);
    if (!$partyId) {
        json_error('No active party for this character.', 400);
    }
    return $partyId;
}

function handle_dialogue(string $action): void
{
    $db = db();
    $map = new LocationEngine($db);

    if ($action === 'npc') {
        $npcId = (int) ($_GET['npc_id'] ?? read_json_body()['npc_id'] ?? 0);
        $charId = session_character_id();
        $npc = $map->getNpc($npcId);
        if ($charId && $npcId > 0) {
            $map->markNpcKnown((int) $charId, $npcId);
        }
        json_response([
            'ok' => true,
            'npc' => $npc,
            'known_npc_ids' => $charId ? $map->knownNpcIds((int) $charId) : [],
        ]);
    }

    // The branching engine. `npc` and `act` below are the pre-stage routes and
    // are kept so seeded dialogue with hardcoded verbs still answers; authored
    // content goes through these three.
    if ($action === 'node') {
        $id = require_character_id();
        $partyId = require_party_id();
        $body = read_json_body();
        $npcKey = (string) ($body['npc_key'] ?? $_GET['npc_key'] ?? '');
        $nodeKey = $body['node_key'] ?? $_GET['node_key'] ?? null;
        if ($npcKey === '') {
            json_error('npc_key required', 400);
        }
        // Meeting someone is what puts their name on the map, and it happens
        // by talking to them rather than by walking past.
        $npcId = (int) (db()->query(
            'SELECT id FROM npcs WHERE npc_key = ' . db()->quote($npcKey)
        )->fetchColumn() ?: 0);
        if ($npcId > 0) {
            $map->markNpcKnown($id, $npcId);
        }
        json_response([
            'ok'   => true,
            'node' => (new DialogEngine($db))->node($partyId, $npcKey, $nodeKey === null ? null : (string) $nodeKey),
        ]);
    }

    if ($action === 'choose') {
        require_method('POST');
        require_character_id();
        $partyId = require_party_id();
        $body = read_json_body();
        json_response(['ok' => true] + (new DialogEngine($db))->choose(
            $partyId,
            (string) ($body['npc_key'] ?? ''),
            (string) ($body['node_key'] ?? ''),
            (int) ($body['choice'] ?? -1)
        ));
    }

    // Finishes a reply that was waiting on the die. The roll itself is made by
    // CheckService; this resumes the conversation it interrupted.
    if ($action === 'resolve_check') {
        require_method('POST');
        require_character_id();
        $partyId = require_party_id();
        $body = read_json_body();
        json_response(['ok' => true] + (new DialogEngine($db))->resolveCheck(
            $partyId,
            (string) ($body['check_id'] ?? ''),
            (array) ($body['boosts'] ?? [])
        ));
    }

    if ($action === 'act') {
        require_method('POST');
        $id = require_character_id();
        $body = read_json_body();
        $act = (string) ($body['action'] ?? '');
        $npcId = (int) ($body['npc_id'] ?? 0);
        if ($npcId > 0) {
            $map->markNpcKnown($id, $npcId);
        }
        switch ($act) {
            case 'rest':
                json_response($map->restAtInn($id));
            case 'heal_partial':
                json_response($map->healPartial($id));
            case 'job_board':
                $qs = new QuestService($db);
                $partyId = require_party_id();
                json_response([
                    'ok' => true,
                    'action' => 'job_board',
                    'board' => $qs->listAvailable($partyId),
                    'mine' => $qs->listForParty($partyId),
                ]);
            case 'shop':
                // Just the marker. The client opens the shop panel itself,
                // which fetches this merchant's stock from inventory/shop —
                // the item list that used to ride along here was the global
                // everything-catalog, and it was never read.
                json_response([
                    'ok' => true,
                    'action' => 'shop',
                    'character' => (new CharacterGenerator($db))->getCharacter($id),
                ]);
            case 'close':
                json_response(['ok' => true, 'action' => 'close']);
            default:
                json_error('Unknown dialogue action');
        }
    }

    json_error('Unknown dialogue action', 404);
}
