<?php
require 'config.php';
require_admin(); // require admin logged in for running scheduler

// Config: week to schedule (next Monday) and hours
$startTs = strtotime('next Monday');
$endTs = strtotime('+7 days', $startTs);
$slot_hours = range(8, 18); // 8..18 inclusive -> last slot 18:00-19:00
$group_size = intval($_GET['group_size'] ?? 2); // default 2, or pass ?group_size=3

// Big-brothers list: prioritized actives
$big_brothers_names = [
"Aidan Agudelo-Petrini","Alex Villegas","Andres Valdes","Anirudh Rao","Anna Gutierrez","Ashtyn Meidinger","Aykhan Mammadli","Beau DeGennaro","Bobby Kuehler","Brooke Kubosh","Caymon Winnick","Chaitra Daggubati","Daniela Granados","Enzo Castro","Evan Swarup","Fletcher Emmott","Gianna Aragones","Jack Ballard","Jack Kristof","Jensy Perez","Joshua Peoples","Kyler Eenhuis","Kyra Rivera","Mateo Triana","Matthew Heinsen","Maya Agarwal","Om Bamane","Pari Pandey","Pranav Sharma","Ritwik Aggarwal","Robert Morones","Sonny Diaz","Sriya Munaga","Ty Landrowski","Vibhas Novli"
];

// build set of big brother user_ids
$big_ids = [];
if (!empty($big_brothers_names)) {
    $in = str_repeat('?,', count($big_brothers_names)-1) . '?';
    $stmt = $db->prepare("SELECT id, name FROM users WHERE name IN ($in) AND role='active'");
    $stmt->execute($big_brothers_names);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $big_ids[] = $r['id'];
}

// build slots list, skipping blocked Tue/Thu 18:00+
$slots = [];
for ($d = 0; $d < 7; $d++) {
    for ($hIndex = 0; $hIndex < count($slot_hours); $hIndex++) {
        $h = $slot_hours[$hIndex];
        $s = strtotime("+$d day $h:00", $startTs);
        // block Tue (2) and Thu (4) from 18:00 onward
        $w = (int)date('N', $s);
        if (($w === 2 || $w === 4) && $h >= 18) continue;
        $slots[] = $s;
    }
}

// load availabilities into per-slot arrays (only slots we care about)
$slotAvailActives = [];
$slotAvailPledges = [];
foreach ($slots as $s) {
    $iso = date('c', $s);
    $slotAvailActives[$iso] = [];
    $slotAvailPledges[$iso] = [];
}

$stmt = $db->prepare("SELECT a.user_id, a.slot_start, u.role FROM availabilities a JOIN users u ON u.id = a.user_id WHERE a.slot_start >= ? AND a.slot_start < ?");
$stmt->execute([date('c', $startTs), date('c', $endTs)]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $slot = date('c', strtotime($r['slot_start']));
    if (!isset($slotAvailActives[$slot])) continue; // skip blocked slots or slots outside the week
    if ($r['role'] === 'active') $slotAvailActives[$slot][] = (int)$r['user_id'];
    else $slotAvailPledges[$slot][] = (int)$r['user_id'];
}

// Exclude users who have no availability at all for the week
$hasAvail = [];
foreach ($db->query("SELECT DISTINCT user_id FROM availabilities WHERE slot_start >= '".date('c',$startTs)."' AND slot_start < '".date('c',$endTs)."'")->fetchAll(PDO::FETCH_COLUMN) as $uid) $hasAvail[(int)$uid] = true;

// Load pair history and counts (as before)
$pairHistory = [];
foreach ($db->query("SELECT active_id, pledge_id, COUNT(*) as cnt FROM completed_interviews GROUP BY active_id, pledge_id") as $row) {
    $pairHistory[$row['active_id'] . '|' . $row['pledge_id']] = (int)$row['cnt'];
}
$activeCount = []; $pledgeCount = [];
foreach ($db->query("SELECT active_id, COUNT(*) as c FROM completed_interviews GROUP BY active_id") as $r) $activeCount[$r['active_id']] = (int)$r['c'];
foreach ($db->query("SELECT pledge_id, COUNT(*) as c FROM completed_interviews GROUP BY pledge_id") as $r) $pledgeCount[$r['pledge_id']] = (int)$r['c'];

// Scheduler: sort slots by potential (desc) to fill high-availability slots first
$slotOrder = $slots;
usort($slotOrder, function($a,$b) use($slotAvailActives,$slotAvailPledges){
    $ka = min(count($slotAvailActives[date('c',$a)]), count($slotAvailPledges[date('c',$a)]));
    $kb = min(count($slotAvailActives[date('c',$b)]), count($slotAvailPledges[date('c',$b)]));
    return $kb - $ka;
});

$scheduled = [];

foreach ($slotOrder as $sTs) {
    $slot = date('c', $sTs);
    $actives = array_values(array_unique(array_filter($slotAvailActives[$slot], fn($u)=>isset($hasAvail[$u]))));
    $pledges = array_values(array_unique(array_filter($slotAvailPledges[$slot], fn($u)=>isset($hasAvail[$u]))));

    while (count($actives) >= $group_size && count($pledges) >= $group_size) {
        // Candidate selection (prefer big brothers and low counts)
        $N = 10;
        // custom sort: lower effectiveCount is preferred. For bigs we subtract a small bias so they're favored.
        usort($actives, function($a,$b) use($activeCount,$big_ids){
            $ca = ($activeCount[$a] ?? 0);
            $cb = ($activeCount[$b] ?? 0);
            // big bias
            $ba = in_array($a, $big_ids) ? -1 : 0;
            $bb = in_array($b, $big_ids) ? -1 : 0;
            $va = $ca + $ba;
            $vb = $cb + $bb;
            if ($va === $vb) return $a <=> $b;
            return $va <=> $vb;
        });
        usort($pledges, function($a,$b) use($pledgeCount){ $ca = ($pledgeCount[$a] ?? 0); $cb = ($pledgeCount[$b] ?? 0); return $ca <=> $cb; });

        $aCandidates = array_slice($actives, 0, min($N, count($actives)));
        $pCandidates = array_slice($pledges, 0, min($N, count($pledges)));

        $aCombs = combos($aCandidates, $group_size);
        $pCombs = combos($pCandidates, $group_size);
        $best = null; $bestScore = PHP_INT_MAX;

        foreach ($aCombs as $ac) {
            foreach ($pCombs as $pc) {
                $pen = 0;
                foreach ($ac as $a) foreach ($pc as $p) $pen += $pairHistory[$a.'|'.$p] ?? 0;
                // additional penalty for non-bigs (we prefer bigs): compute big_count missing
                $bigCount = 0; foreach ($ac as $a) if (in_array($a, $big_ids)) $bigCount++;
                $bigPenalty = ($group_size - $bigCount); // fewer bigs -> higher penalty
                $sumCounts = 0; foreach ($ac as $a) $sumCounts += ($activeCount[$a] ?? 0); foreach ($pc as $p) $sumCounts += ($pledgeCount[$p] ?? 0);
                $score = $pen * 1000 + $sumCounts * 10 + $bigPenalty * 50;
                if ($score < $bestScore) { $bestScore = $score; $best = ['actives'=>$ac,'pledges'=>$pc,'pen'=>$pen,'bigMissing'=>$bigPenalty]; }
            }
        }

        if ($best === null) break;

        // write interview and participants
        $ins = $db->prepare("INSERT INTO interviews (slot_start, slot_end, group_size, generated_run_id) VALUES (?, ?, ?, ?)");
        $ins->execute([date('c', $sTs), date('c', $sTs + 3600), $group_size, 'run_'.date('Ymd_His')]);
        $interview_id = $db->lastInsertId();
        $insP = $db->prepare("INSERT INTO interview_participants (interview_id, user_id, role) VALUES (?, ?, ?)");
        foreach ($best['actives'] as $a) { $insP->execute([$interview_id, $a, 'active']); $activeCount[$a] = ($activeCount[$a] ?? 0) + 1; }
        foreach ($best['pledges'] as $p) { $insP->execute([$interview_id, $p, 'pledge']); $pledgeCount[$p] = ($pledgeCount[$p] ?? 0) + 1; }

        foreach ($best['actives'] as $a) foreach ($best['pledges'] as $p) $pairHistory[$a.'|'.$p] = ($pairHistory[$a.'|'.$p] ?? 0) + 1;

        // remove scheduled users from current slot lists
        foreach ($best['actives'] as $a) { $actives = array_values(array_diff($actives, [$a])); $slotAvailActives[$slot] = array_values(array_diff($slotAvailActives[$slot], [$a])); }
        foreach ($best['pledges'] as $p) { $pledges = array_values(array_diff($pledges, [$p])); $slotAvailPledges[$slot] = array_values(array_diff($slotAvailPledges[$slot], [$p])); }

        $scheduled[] = ['interview_id'=>$interview_id, 'slot'=>date('Y-m-d H:i',$sTs), 'actives'=>$best['actives'], 'pledges'=>$best['pledges'], 'pen'=>$best['pen']];
    }
}

// combos helper
function combos($items, $k) {
    $n = count($items);
    if ($k > $n) return [];
    $results = [];
    $indexes = range(0, $k-1);
    while (true) {
        $res = []; foreach ($indexes as $i) $res[] = $items[$i];
        $results[] = $res;
        $i = $k-1;
        while ($i >= 0 && $indexes[$i] == $n - $k + $i) $i--;
        if ($i < 0) break;
        $indexes[$i]++;
        for ($j = $i+1; $j < $k; $j++) $indexes[$j] = $indexes[$j-1] + 1;
    }
    return $results;
}

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Scheduler Run</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>.navbar-dark { background:#000; }</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <div class="container">
    <a class="navbar-brand" href="index.php">Interview Scheduler</a>
    <div>
      <a class="btn btn-outline-light btn-sm" href="admin.php">Back to Admin</a>
    </div>
  </div>
</nav>
<div class="container py-4">
  <h1>Scheduler run</h1>
  <?php if (empty($scheduled)): ?>
    <div class="alert alert-warning">No interviews could be scheduled for the chosen week/group size given availabilities.</div>
  <?php else: ?>
    <div class="list-group">
      <?php foreach ($scheduled as $s): ?>
        <div class="list-group-item">
          <strong>Interview #<?=$s['interview_id']?> @ <?=$s['slot']?></strong>
          <div>Actives:
            <?php foreach ($s['actives'] as $a) echo h($db->query("SELECT name FROM users WHERE id=".intval($a))->fetchColumn()) . ', '; ?>
          </div>
          <div>Pledges:
            <?php foreach ($s['pledges'] as $p) echo h($db->query("SELECT name FROM users WHERE id=".intval($p))->fetchColumn()) . ', '; ?>
          </div>
          <div>Penalty: <?=$s['pen']?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <hr>
  <p><a class="btn btn-primary" href="admin.php">Return to Admin</a></p>
</div>
</body>
</html>
