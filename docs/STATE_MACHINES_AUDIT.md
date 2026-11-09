# Implicit state machines: audit (Rivermark Chronicles, hardcopi/dndrpg @ 5794f9a)

Audited 9 Nov 2026. Each entry covers where the state lives, its states, who writes it, what can go wrong, what players see, how risky a change is, and what I decided.
**Made explicit in this PR:** 1–5. **Documented only:** 6–16. The tables for 1–5 are in `src/app/lib/{CompanionRoster,LifeState,QuestStatus,CombatStatus,UiMode}.php`, and `docs/STATE_MACHINES.md` is generated from them.

---

## 1. Companion life state (up / dying / stable / dead): MADE EXPLICIT (`life_state`)
- **Stored:** in combat it is spread over four combatant fields in `combat_sessions.state_json`: `alive`, `hp`, `stable`, and the `unconscious` condition. Out of combat nothing stored it; the only trace was `characters.current_hp`.
- **States (inferred):** conscious, dying, stable, dead. Nothing named them.
- **Writers:** these `CombatEngine` methods: `dropCombatant`, `deathSave`, `failDeathSave`, `stabilise`, `reviveAtOne`, `killOutright`, `heal`, `relentlessEndurance` and `finalizeCombat`. Also `LocationEngine::longRest` / `restAtInn` (HP back to max) and the defeat path (HP 1).
- **Illegal states you could reach:**
  - A companion who bled out was written back to **1 HP** by `finalizeCombat` (`DEATH_IS_PERMANENT=false`), so they were awake.
  - A companion who was dying or stable when the fight ended was left on **0 HP**, and nothing marked them as down.
  - `heal()` on a dead PC took away Unconscious and raised their HP while `alive` stayed false. The result was a body standing up that could not act.
- **What players saw:** the Nov 1 playtest note. You could sit with and talk to companions who had "died" or were down at camp (camp seats and the Talk button ignored HP), and the server answered with dialogue.
- **Fix:** an explicit table. State is derived, not stored in a new column: `LifeState::of()` works on combatants and `ofCharacter()` on rows. Out of combat, 0 HP means unconscious (stable), and healing or a long rest wakes them.
  - At the end of a fight, the dying and the bled-out are carried off unconscious on 0 HP.
  - Camp greys out their seat and hides Talk.
  - `dialogue/node` returns a 409 for a companion who is down.
  - Healing the dead is refused and logged.
  - Permadeath (status `dead`) is built and tested but switched **off**, behind `CombatEngine::COMPANION_PERMADEATH`. It is Rich's story call.
- **Risk:** medium. The change touches combat and camp. It is covered by the new tests plus test_dying, test_combat, test_grid_combat and test_skirmish, all unchanged.

## 2. Companion roster status: MADE EXPLICIT (`companion_roster`)
- **Stored:** `party_companions.status` ENUM, with values unmet, met, active, camp, left, hostile, dead.
- **Writers:** `CompanionService::meet/recruit/dismiss/activate/leave/turnHostile`, each with its own `in_array` check and raw UPDATE.
- **Illegal moves that were possible:** `recruit()` from `dead`, `turnHostile()` from `dead`, and `leave()` racing `dismiss()` (no expected-from in the WHERE). The `dead` value was in the ENUM but nothing wrote it.
- **Fix:** a table, plus one `setStatus()` that guards each change and puts the expected `from` in the WHERE. Recruiting the dead becomes a note instead of a resurrection. `markDead()` was added for the permadeath option.
- **Risk:** low. test_companions and test_romance are unchanged.

## 3. Quest status: MADE EXPLICIT (`quest_status`)
- **Stored:** `party_quests.status` (available, active, completed, failed) alongside `current_stage_id`, `resolution` and `ended_at`. The stage graph in `quest_stages` was already explicit content.
- **Writers:** `QuestService::start/advance/fail/enterStage/finish`. Each re-derived "is it over?" with its own `in_array`.
- **Illegal moves that were possible:** if a terminal stage's own effects jumped to another terminal stage, the second ending overwrote the first. The guards were scattered, so a new caller of `enterStage` could reopen a finished quest.
- **Fix:** a table. Writes go through `guard()`. The first ending stands, and a later one is logged rather than written. Players see no change.
- **Risk:** low. test_quest_module, test_quest_marks, test_quest_advance_level and test_delve_quest are all unchanged (test_delve_quest also fails on main).

## 4. Combat encounter lifecycle: MADE EXPLICIT (`combat_status`)
- **Stored:** `state_json.status` (active, victory, defeat, fled, parley, ended), mirrored by the `combat_sessions.is_active` column.
- **Writers:** `checkEnd` (victory/defeat), `doFlee`, `doParley`, `closeStaleSession` (ended), then `finalizeCombat`, which sets is_active to 0.
- **Illegal moves that were possible:** none found in practice. The risk was that a terminal status could be overwritten, for example a victory overwritten by a later defeat check.
- **Fix:** a table, with `CombatStatus::transition()` used at all 5 write sites. It never throws mid-turn; it logs and keeps the old status.
- **Turn phases** (`action_used`, `bonus_used`, `move_used`, `reaction_used`, `turn_forfeit`, `pending_check`) are left as flags on purpose. They are independent resources, not one state, so a state machine is the wrong shape for them.
- **Risk:** low.

## 5. Client screen mode: MADE EXPLICIT (`ui_mode`)
- **Stored:** `G().state.mode` in game.js.
- **Bug:** game.js wrote `'location'` and ui-combat.js wrote `'explore'` for the same state. It only worked because every reader compared against `'combat'`.
- **Fix:** named constants from the generated `assets/js/state-machines.js`, with literal fallbacks so the preview pages, which don't load it, keep working.
- **Risk:** trivial.

---

## 6. Trap state (doors and furnishings): DOCUMENTED. Recommended next.
- **Stored:** `world_flags` under `DelveEngine::trapFlag(key)`, with values (unset), `found`, `sprung` (disarmed also writes `sprung`).
- **Writers:** `DoorEngine` (lines ~174, 233, 236) and `FurnishingEngine` (~227, 379, 382, 424) duplicate the same lifecycle.
- **Recommendation:** a `TrapState` table (hidden → found → disarmed|sprung) and one writer. Keep the stored value `sprung` for "disarmed" for back-compat, or add `disarmed` as a new value. Medium value, low risk.

## 7. Content "fate" flags (`survey_fate`, `kessa_debt_fate`, `tutorial_fate`, `act_two_open`, `found_exit_*`): DOCUMENTED
- **Stored:** `world_flags` strings set and read only by content JSON (`set_flag` + `value`, `flag` + `equals`). Examples:
  - `survey_fate`: took, burned, sold, warned.
  - `kessa_debt_fate`: fought, paid, sold.
  - `tutorial_fate`: taken, done, skipped.
- **Risk:** reading a value that is never set (a typo) fails silently. As of today every `equals` matches a value that is set.
- **Recommendation:** don't refactor (it's content). Add a lint to `trace_content.py` / `DialogueLint` that checks every `equals` against the values set for that flag, and optionally declare the enums in `content/flags.json`. High DX/AX value, zero runtime risk.

## 8. Romance track: DOCUMENTED
- `party_companions.romance_stage` (0/1/2) plus `romance_locked`, managed by `ROMANCE_COMMIT_STAGE` and `closeOtherRomances()`.
- It is already one owner with clear rules. Leave it.

## 9. Approval bands (`leave_at`, `hostile_at`, `romance_at` thresholds): DOCUMENTED
- Derived from numbers at every long rest (`checkThresholds`), and they drive roster moves 2→left/hostile, now guarded.
- Nothing more to do.

## 10. NPC presence / place conditions (`npcs.place_conditions_json`, migration 2026-11-01): DOCUMENTED
- This is condition-driven presence, not a lifecycle, and it is covered by test_npc_presence. Leave it.

## 11. Delve lifecycle (`dungeon_delves` row means a delve is in progress; `end()` drops the generated region): DOCUMENTED
- **States:** none → delving(level n) → ended.
- **Implicit, with one known fix in place:** defeat ends the delve after the haven move.
- **Recommendation:** a small table if the delve grows more exits. Low priority.

## 12. Character lifecycle (`characters.is_active` + `deleted_at`; retire / delete / restore): DOCUMENTED
- **States:** active → retired (is_active 0) → restored, and active → deleted (is_active 0 + deleted_at), owner-only.
- The new rules from #50 are well tested by test_char_delete (32/32 here).
- **Recommendation:** name the states (`active`, `retired`, `deleted`) if a fourth is ever added. Leave it for now.

## 13. Character creation wizard (create.js, `state.step` indexing a declarative `STEPS` array): DOCUMENTED
- It is already a data-driven step machine. Leave it.

## 14. Login / session (`$_SESSION` character_id, party_id, pending_checks, rolled_abilities; API tokens): DOCUMENTED
- `rolled_abilities` is a one-shot ("spent"), already enforced. `pending_checks` has `created_at` but no expiry sweep, so it can only grow within one session. That is harmless, but worth a TTL.
- Security-sensitive, so I didn't touch it.

## 15. Tutorial / act progression (first_night quest stages, `tutorial_fate`, act_one_epilogue → `act_two_open`): DOCUMENTED
- These are quest stages plus flags, so the content graph plus #3 and #7 cover them. The endings C5–C9 are terminal quest stages with `resolution`.

## 16. Client sub-modes (scene / map / first-person, camp/sheet overlays, spell aiming `ui.aiming` {mode: enemy|ally|any|self|auto|area}, hover/touch hotspots): DOCUMENTED
- `ui.aiming` clears itself when the actor changes or combat is no longer active (ui-combat.js:448), which is good.
- Overlays use a stack (`overlays[]`).
- I would not refactor this without screenshots for Rich, so it is documented only.

## Deploy / migrations
- `deploy.sh` (dry run → `echo deploy | --apply`) and `migrate.sh` (baseline/apply/status) are already explicit two-step flows.
- This PR has **no migration**: life state is derived, and `dead` already exists in the ENUM.
