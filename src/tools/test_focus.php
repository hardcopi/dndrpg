<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/lib/Rules.php';
require dirname(__DIR__) . '/app/lib/Conditions.php';
require dirname(__DIR__) . '/app/lib/Dice.php';

// Only the scorer is under test, so it is copied in via reflection-free include
// of the class file. CombatEngine needs a PDO for other methods but not this.
require dirname(__DIR__) . '/app/lib/CombatEngine.php';

$pcs = [
  ['cid'=>'pc_1','name'=>'Wren','hp'=>9,'max_hp'=>9,'ac'=>11,'is_caster'=>true],
  ['cid'=>'pc_2','name'=>'Kessa','hp'=>15,'max_hp'=>15,'ac'=>12],
  ['cid'=>'pc_3','name'=>'Aldric','hp'=>10,'max_hp'=>10,'ac'=>13,'is_healer'=>true],
];
$focus = [];
$picks = [];
foreach (['mon_a','mon_b','mon_c','mon_d'] as $cid) {
    $actor = ['cid'=>$cid,'name'=>'Giant Rat','hp'=>7,'max_hp'=>7,'rank'=>'front'];
    $best = null; $bestScore = 0.0;
    foreach ($pcs as $t) {
        $opt = ['kind'=>'attack','target'=>$t,'reach'=>'melee',
                'expected_damage'=>4.5,'already_targeted'=>$focus[$t['cid']] ?? 0];
        $s = CombatEngine::scoreOption($actor, $opt, []);
        if ($s > $bestScore) { $bestScore = $s; $best = $t; }
    }
    $focus[$best['cid']] = ($focus[$best['cid']] ?? 0) + 1;
    $picks[] = $best['name'];
}
echo "four rats target: " . implode(', ', $picks) . "\n";
echo "spread: " . json_encode(array_count_values($picks)) . "\n";
