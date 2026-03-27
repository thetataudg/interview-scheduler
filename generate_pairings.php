<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Increase execution time limit for complex pairing generation
ini_set('max_execution_time', 300); // 5 minutes

require 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require admin authentication
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

function getProgressFilePath($jobId) {
    $safeJobId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$jobId);
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "pairing_progress_{$safeJobId}.json";
}

function writeProgress($jobId, $explored, $total, $pairingsCreated, $status = 'running', $message = '') {
    if (empty($jobId)) {
        return;
    }

    $totalSafe = max(1, (int)$total);
    $exploredSafe = max(0, (int)$explored);
    $percent = min(100, round(($exploredSafe / $totalSafe) * 100, 2));

    $payload = [
        'job_id' => (string)$jobId,
        'explored' => $exploredSafe,
        'total' => $totalSafe,
        'percent' => $percent,
        'pairings' => max(0, (int)$pairingsCreated),
        'status' => $status,
        'message' => $message,
        'updated_at' => time()
    ];

    @file_put_contents(getProgressFilePath($jobId), json_encode($payload));
}

function combinationCount($n, $r) {
    if ($r < 0 || $n < $r) {
        return 0;
    }
    if ($r === 0 || $n === $r) {
        return 1;
    }
    if ($r === 1) {
        return $n;
    }
    if ($r === 2) {
        return (int)(($n * ($n - 1)) / 2);
    }
    if ($r === 3) {
        return (int)(($n * ($n - 1) * ($n - 2)) / 6);
    }

    $r = min($r, $n - $r);
    $result = 1;
    for ($i = 1; $i <= $r; $i++) {
        $result = ($result * ($n - $r + $i)) / $i;
    }
    return (int)round($result);
}

function cappedCombinations(array $ids, $size, $maxCombinations) {
    $combinations = [];
    $count = count($ids);
    if ($size === 2) {
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $combinations[] = [$ids[$i], $ids[$j]];
                if (count($combinations) >= $maxCombinations) {
                    return $combinations;
                }
            }
        }
    } elseif ($size === 3) {
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                for ($k = $j + 1; $k < $count; $k++) {
                    $combinations[] = [$ids[$i], $ids[$j], $ids[$k]];
                    if (count($combinations) >= $maxCombinations) {
                        return $combinations;
                    }
                }
            }
        }
    }

    return $combinations;
}

$week = $_GET['week'] ?? 'next'; // 'current' or 'next'
$debug_mode = $_GET['debug'] ?? false; // Add ?debug=1 to URL to show debug logs
$jobId = $_POST['job_id'] ?? ($_GET['job_id'] ?? '');

// Progress polling endpoint used by admin.php while generation is running.
if (isset($_GET['action']) && $_GET['action'] === 'progress') {
    header('Content-Type: application/json');

    if (empty($jobId)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Missing job_id',
            'explored' => 0,
            'total' => 1,
            'pairings' => 0,
            'percent' => 0
        ]);
        exit;
    }

    $progressFile = getProgressFilePath($jobId);
    if (!file_exists($progressFile)) {
        echo json_encode([
            'status' => 'pending',
            'message' => 'Waiting for generator to start...',
            'explored' => 0,
            'total' => 1,
            'pairings' => 0,
            'percent' => 0
        ]);
        exit;
    }

    $raw = @file_get_contents($progressFile);
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $decoded = [
            'status' => 'running',
            'message' => 'Generating pairings...',
            'explored' => 0,
            'total' => 1,
            'pairings' => 0,
            'percent' => 0
        ];
    }

    echo json_encode($decoded);
    exit;
}

// Release session lock so progress polling requests can run concurrently.
session_write_close();

// Individual participation limits (can be overridden by POST parameters)
$weeklyActiveLimit = intval($_POST['active_max'] ?? 5); // Maximum interviews per active per week
$weeklyPledgeLimit = intval($_POST['pledge_max'] ?? 5); // Maximum interviews per pledge per week

// Day exclusions (can be overridden by POST parameters)
$excludedDaysParam = $_POST['excluded_days'] ?? '';
$excludedDays = [];
if (!empty($excludedDaysParam)) {
    $excludedDays = array_map('intval', explode(',', $excludedDaysParam));
}

$debug = [];
$debug[] = "Debug mode: " . ($debug_mode ? "ON" : "OFF");
$debug[] = "Current execution time limit: " . ini_get('max_execution_time') . " seconds";
$debug[] = "Week selected: $week";
$debug[] = "Excluded days: " . (empty($excludedDays) ? "None" : implode(', ', array_map(function($day) {
    $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return $dayNames[$day];
}, $excludedDays)));

// Check if this is an AJAX request
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Calculate date range based on selected week
if ($week === 'current') {
    $base = strtotime('monday this week');
    $week_label = "Current Week";
} else {
    $base = strtotime('next Monday');
    $week_label = "Next Week";
}

$weekStart = date('Y-m-d', $base);
$weekEnd = date('Y-m-d', strtotime('+6 days', $base));

$debug[] = "Date range: $weekStart to $weekEnd";

// Fetch users - FIX: Remove is_big column reference
$users = $db->query("SELECT id, name, role FROM users ORDER BY role, name")->fetchAll(PDO::FETCH_ASSOC);
$actives = array_filter($users, fn($u) => $u['role'] === 'active');
$pledges = array_filter($users, fn($u) => $u['role'] === 'pledge');

// Randomize order for fair distribution (remove alphabetical bias)
shuffle($actives);
shuffle($pledges);

$debug[] = "Users loaded: " . count($actives) . " actives, " . count($pledges) . " pledges";

// Check for regeneration request (add variety through additional shuffling)
if (isset($_GET['regenerate']) || isset($_POST['regenerate'])) {
    shuffle($actives);
    shuffle($pledges);
    $debug[] = "Regenerating with shuffled order for variety";
}

// Fetch availability data for the selected week
$availability_stmt = $db->prepare("
    SELECT user_id, slot_start as slot_datetime 
    FROM availabilities 
    WHERE DATE(slot_start) BETWEEN ? AND ?
");
$availability_stmt->execute([$weekStart, $weekEnd]);
$availability_data = $availability_stmt->fetchAll(PDO::FETCH_ASSOC);

$debug[] = "Found " . count($availability_data) . " availability records for the selected week";

// Process availability into user-based time slots, filtering out excluded days
$availability = [];
$filtered_slots_count = 0;
foreach ($availability_data as $record) {
    $user_id = $record['user_id'];
    $slot_timestamp = strtotime($record['slot_datetime']);
    
    // Check if this slot falls on an excluded day
    $dayOfWeek = intval(date('w', $slot_timestamp)); // 0 = Sunday, 1 = Monday, etc.
    
    if (in_array($dayOfWeek, $excludedDays)) {
        $filtered_slots_count++;
        continue; // Skip this slot
    }
    
    $availability[$user_id][] = $slot_timestamp;
}

$debug[] = "Filtered out $filtered_slots_count slots on excluded days";

// Count users with availability
$actives_with_availability = count(array_filter($actives, fn($a) => isset($availability[$a['id']])));
$pledges_with_availability = count(array_filter($pledges, fn($p) => isset($availability[$p['id']])));

$debug[] = "Users with availability: $actives_with_availability actives, $pledges_with_availability pledges";

// Track completed interviews FROM DATABASE
$completed_stmt = $db->query("SELECT active_id, pledge_id FROM completed_interviews");
$completed_pairs = [];
foreach ($completed_stmt->fetchAll(PDO::FETCH_ASSOC) as $ci) {
    $completed_pairs[$ci['active_id']][$ci['pledge_id']] = true;
}

// NEW: Track pairings from CURRENT GENERATION to avoid repeats within the same week
$current_week_pairs = [];

// Helper: check if two people have already been paired (either in database OR current generation)
function haveMet($active_id, $pledge_id, $completed_pairs, $current_week_pairs) {
    // Check database records
    if (isset($completed_pairs[$active_id][$pledge_id])) {
        return true;
    }
    
    // Check current week pairings
    if (isset($current_week_pairs[$active_id][$pledge_id])) {
        return true;
    }
    
    return false;
}

function userNamesFromIds(array $ids, array $usersById) {
    $names = [];
    foreach ($ids as $id) {
        if (isset($usersById[$id])) {
            $names[] = $usersById[$id]['name'];
        }
    }
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}

// Start timing
$start_time = microtime(true);

// Configuration
$max_pairings = intval($_POST['global_max'] ?? 50); // Weekly cap on total interviews

$debug[] = "Limits: Global max = $max_pairings, Active max = $weeklyActiveLimit, Pledge max = $weeklyPledgeLimit";
writeProgress($jobId, 0, 1, 0, 'running', 'Preparing candidate slots...');

// Build lightweight user lookup maps.
$usersById = [];
foreach ($users as $user) {
    $usersById[$user['id']] = $user;
}

$activeIds = array_values(array_column($actives, 'id'));
$pledgeIds = array_values(array_column($pledges, 'id'));

// Convert each user's 30-minute slots into 1-hour start slots (slot + next slot).
$hourStartsByUser = [];
foreach ($availability as $uid => $slots) {
    if (empty($slots)) {
        continue;
    }

    sort($slots);
    $slotSet = array_fill_keys($slots, true);
    foreach ($slots as $slotStart) {
        $nextSlot = $slotStart + 1800;
        if (isset($slotSet[$nextSlot])) {
            $hourStartsByUser[$uid][$slotStart] = true;
        }
    }
}

// Build per-hour availability lists for actives and pledges.
$slotsToParticipants = [];
foreach ($activeIds as $activeId) {
    if (empty($hourStartsByUser[$activeId])) {
        continue;
    }
    foreach (array_keys($hourStartsByUser[$activeId]) as $hourStart) {
        $slotsToParticipants[$hourStart]['actives'][] = $activeId;
    }
}
foreach ($pledgeIds as $pledgeId) {
    if (empty($hourStartsByUser[$pledgeId])) {
        continue;
    }
    foreach (array_keys($hourStartsByUser[$pledgeId]) as $hourStart) {
        $slotsToParticipants[$hourStart]['pledges'][] = $pledgeId;
    }
}

$hourSlots = array_keys($slotsToParticipants);
sort($hourSlots);

$interview_opportunities_count = 0;
foreach ($hourSlots as $slotStart) {
    $aCount = count($slotsToParticipants[$slotStart]['actives'] ?? []);
    $pCount = count($slotsToParticipants[$slotStart]['pledges'] ?? []);
    $twoOnTwo = min(combinationCount($aCount, 2), 60) * min(combinationCount($pCount, 2), 60);
    $threeOnThree = min(combinationCount($aCount, 3), 40) * min(combinationCount($pCount, 3), 40);
    $interview_opportunities_count += ($twoOnTwo + $threeOnThree);
}

$total_possibilities = max(1, $interview_opportunities_count);
$explored_possibilities = 0;

$debug[] = 'Using memory-safe slot-based scheduler (no full cross-product materialization)';
$debug[] = 'One-hour occupancy enforced: users are blocked on both half-hour slices of each interview';
$debug[] = 'Optimization goal: maximize total interview count (2-on-2 prioritized, broader search enabled)';
$debug[] = 'Hour blocks with both roles available: ' . count($hourSlots);
$debug[] = 'Estimated possibilities (capped for performance): ' . $total_possibilities;

// Initialize tracking variables
$pairings = [];
$individual_counts = []; // [user_id] = interview count
$used_time_slots = []; // [slot_timestamp][user_id] = true

$flexibilityByUser = [];
foreach ($hourStartsByUser as $uid => $starts) {
    $flexibilityByUser[$uid] = count($starts);
}

foreach ($hourSlots as $slotStartTime) {
    if (count($pairings) >= $max_pairings) {
        $debug[] = "Reached weekly cap of $max_pairings interviews";
        break;
    }

    $slotEndTime = $slotStartTime + 1800;

    $slotActives = $slotsToParticipants[$slotStartTime]['actives'] ?? [];
    $slotPledges = $slotsToParticipants[$slotStartTime]['pledges'] ?? [];

    $slotActives = array_values(array_filter($slotActives, function($activeId) use ($individual_counts, $weeklyActiveLimit, $used_time_slots, $slotStartTime, $slotEndTime) {
        if (($individual_counts[$activeId] ?? 0) >= $weeklyActiveLimit) {
            return false;
        }

        // A one-hour interview occupies both half-hour slices.
        if (isset($used_time_slots[$slotStartTime][$activeId]) || isset($used_time_slots[$slotEndTime][$activeId])) {
            return false;
        }

        return true;
    }));
    $slotPledges = array_values(array_filter($slotPledges, function($pledgeId) use ($individual_counts, $weeklyPledgeLimit, $used_time_slots, $slotStartTime, $slotEndTime) {
        if (($individual_counts[$pledgeId] ?? 0) >= $weeklyPledgeLimit) {
            return false;
        }

        // A one-hour interview occupies both half-hour slices.
        if (isset($used_time_slots[$slotStartTime][$pledgeId]) || isset($used_time_slots[$slotEndTime][$pledgeId])) {
            return false;
        }

        return true;
    }));

    usort($slotActives, function($a, $b) use ($individual_counts) {
        return ($individual_counts[$a] ?? 0) <=> ($individual_counts[$b] ?? 0);
    });
    usort($slotPledges, function($a, $b) use ($individual_counts) {
        return ($individual_counts[$a] ?? 0) <=> ($individual_counts[$b] ?? 0);
    });

    while (count($slotActives) >= 2 && count($slotPledges) >= 2 && count($pairings) < $max_pairings) {
        $bestOption = null;

        foreach ([2, 3] as $groupSize) {
            if ($groupSize === 3 && (count($slotActives) < 3 || count($slotPledges) < 3)) {
                continue;
            }

            // Explore a larger search space to maximize final interview count.
            $activePoolLimit = $groupSize === 3 ? 12 : 16;
            $pledgePoolLimit = $groupSize === 3 ? 12 : 16;
            $comboCap = $groupSize === 3 ? 80 : 180;

            $candidateActives = array_slice($slotActives, 0, $activePoolLimit);
            $candidatePledges = array_slice($slotPledges, 0, $pledgePoolLimit);

            // Add small shuffle so regeneration still provides variety for similar scores.
            shuffle($candidateActives);
            shuffle($candidatePledges);

            $activeCombos = cappedCombinations($candidateActives, $groupSize, $comboCap);
            $pledgeCombos = cappedCombinations($candidatePledges, $groupSize, $comboCap);

            foreach ($activeCombos as $activeCombo) {
                foreach ($pledgeCombos as $pledgeCombo) {
                    $explored_possibilities++;
                    if ($explored_possibilities > $total_possibilities) {
                        $total_possibilities = $explored_possibilities;
                    }

                    $previousMeetings = 0;
                    $hasRepeatMeeting = false;

                    foreach ($activeCombo as $activeId) {
                        foreach ($pledgeCombo as $pledgeId) {
                            if (haveMet($activeId, $pledgeId, $completed_pairs, $current_week_pairs)) {
                                $hasRepeatMeeting = true;
                                break 2;
                            }
                        }
                    }

                    if ($hasRepeatMeeting) {
                        continue;
                    }

                    $loadScore = 0;
                    $flexibilityScore = 0;
                    foreach ($activeCombo as $activeId) {
                        $loadScore += ($individual_counts[$activeId] ?? 0);
                        $flexibilityScore += ($flexibilityByUser[$activeId] ?? 999);
                    }
                    foreach ($pledgeCombo as $pledgeId) {
                        $loadScore += ($individual_counts[$pledgeId] ?? 0);
                        $flexibilityScore += ($flexibilityByUser[$pledgeId] ?? 999);
                    }

                    // Lower score is better: first balance workload, then prioritize less-flexible people.
                    $score = ($loadScore * 100) + $flexibilityScore;

                    if ($bestOption === null ||
                        $score < $bestOption['score'] ||
                        ($score === $bestOption['score'] && $groupSize < $bestOption['group_size'])) {
                        $bestOption = [
                            'group_size' => $groupSize,
                            'active_ids' => $activeCombo,
                            'pledge_ids' => $pledgeCombo,
                            'score' => $score,
                            'previous_meetings' => $previousMeetings
                        ];

                        if ($score === 0 && $groupSize === 2) {
                            break 3;
                        }
                    }
                }
            }
        }

        if ($bestOption === null) {
            break;
        }

        $chosenActives = [];
        foreach ($bestOption['active_ids'] as $activeId) {
            $chosenActives[] = $usersById[$activeId];
            $individual_counts[$activeId] = ($individual_counts[$activeId] ?? 0) + 1;
            $used_time_slots[$slotStartTime][$activeId] = true;
            $used_time_slots[$slotEndTime][$activeId] = true;
        }

        $chosenPledges = [];
        foreach ($bestOption['pledge_ids'] as $pledgeId) {
            $chosenPledges[] = $usersById[$pledgeId];
            $individual_counts[$pledgeId] = ($individual_counts[$pledgeId] ?? 0) + 1;
            $used_time_slots[$slotStartTime][$pledgeId] = true;
            $used_time_slots[$slotEndTime][$pledgeId] = true;
        }

        foreach ($bestOption['active_ids'] as $activeId) {
            foreach ($bestOption['pledge_ids'] as $pledgeId) {
                $current_week_pairs[$activeId][$pledgeId] = true;
            }
        }

        $groupSize = $bestOption['group_size'];
        $pairings[] = [
            'actives' => $chosenActives,
            'pledges' => $chosenPledges,
            'time_slot' => date('D H:i', $slotStartTime) . '-' . date('H:i', $slotEndTime),
            'type' => "{$groupSize}-on-{$groupSize}"
        ];

        $slotActives = array_values(array_diff($slotActives, $bestOption['active_ids']));
        $slotPledges = array_values(array_diff($slotPledges, $bestOption['pledge_ids']));

        $activeNames = implode(' & ', array_column($chosenActives, 'name'));
        $pledgeNames = implode(' & ', array_column($chosenPledges, 'name'));
        $debug[] = "Scheduled {$groupSize}-on-{$groupSize}: $activeNames with $pledgeNames at " . date('D H:i', $slotStartTime) . '-' . date('H:i', $slotEndTime);

        writeProgress(
            $jobId,
            $explored_possibilities,
            $total_possibilities,
            count($pairings),
            'running',
            'Scheduling interviews...'
        );
    }

    writeProgress(
        $jobId,
        $explored_possibilities,
        $total_possibilities,
        count($pairings),
        'running',
        'Exploring remaining hour blocks...'
    );
}

// Calculate processing time and stats
$processing_time = round((microtime(true) - $start_time) * 1000, 2);

// FIX: Handle case where no pairings were generated to avoid array errors
if (!empty($pairings)) {
    $used_actives = count(array_unique(array_merge(...array_map(fn($p) => array_column($p['actives'], 'id'), $pairings))));
    $used_pledges = count(array_unique(array_merge(...array_map(fn($p) => array_column($p['pledges'], 'id'), $pairings))));
} else {
    $used_actives = 0;
    $used_pledges = 0;
}

$active_ids_all = array_column($actives, 'id');
$pledge_ids_all = array_column($pledges, 'id');

$active_ids_with_availability = array_values(array_filter($active_ids_all, fn($id) => isset($availability[$id])));
$pledge_ids_with_availability = array_values(array_filter($pledge_ids_all, fn($id) => isset($availability[$id])));

$used_active_ids = [];
$used_pledge_ids = [];
foreach ($pairings as $pairing) {
    foreach ($pairing['actives'] as $active) {
        $used_active_ids[$active['id']] = true;
    }
    foreach ($pairing['pledges'] as $pledge) {
        $used_pledge_ids[$pledge['id']] = true;
    }
}

$unused_active_with_availability_ids = array_values(array_diff($active_ids_with_availability, array_keys($used_active_ids)));
$unused_pledge_with_availability_ids = array_values(array_diff($pledge_ids_with_availability, array_keys($used_pledge_ids)));

$active_no_availability_ids = array_values(array_diff($active_ids_all, $active_ids_with_availability));
$pledge_no_availability_ids = array_values(array_diff($pledge_ids_all, $pledge_ids_with_availability));

$unused_active_with_availability_names = userNamesFromIds($unused_active_with_availability_ids, $usersById);
$unused_pledge_with_availability_names = userNamesFromIds($unused_pledge_with_availability_ids, $usersById);
$active_no_availability_names = userNamesFromIds($active_no_availability_ids, $usersById);
$pledge_no_availability_names = userNamesFromIds($pledge_no_availability_ids, $usersById);

$debug[] = "Generation completed in {$processing_time}ms";
$debug[] = "Final results: " . count($pairings) . " interviews scheduled";
$debug[] = "Individual participation: $used_actives actives used (of $actives_with_availability available), $used_pledges pledges used (of $pledges_with_availability available)";

// Count interview types
$type_counts = [];
foreach ($pairings as $pairing) {
    $type = $pairing['type'];
    $type_counts[$type] = ($type_counts[$type] ?? 0) + 1;
}

$type_breakdown = [];
foreach ($type_counts as $type => $count) {
    $type_breakdown[] = "$count × $type";
}
$debug[] = "Interview types: " . implode(', ', $type_breakdown);

writeProgress(
    $jobId,
    max($explored_possibilities, $total_possibilities),
    max($explored_possibilities, $total_possibilities),
    count($pairings),
    'done',
    'Generation complete'
);

if ($is_ajax) {
    // Return just the pairing results for AJAX
    ?>
    <div class="alert alert-success">
        <strong>✅ Pairings Generated Successfully for <?= $week_label ?>!</strong><br>
        Generated <?=count($pairings)?> pairings (max: <?=$max_pairings?>) with individual limits of <?=$weeklyActiveLimit?> per active, <?=$weeklyPledgeLimit?> per pledge.
        <?php if (!empty($excludedDays)): ?>
            <br><strong>Excluded days:</strong> <?php 
            $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $excludedDayNames = array_map(function($day) use ($dayNames) { return $dayNames[$day]; }, $excludedDays);
            echo implode(', ', $excludedDayNames);
            ?>
        <?php endif; ?>
        <br><small class="text-muted">Week: <?= date('M j', $base) ?> - <?= date('M j', strtotime('+6 days', $base)) ?></small>
    </div>
    
    <?php if (!empty($pairings)): ?>
        <div class="card mb-3">
            <div class="card-body p-2">
                <small class="fw-bold d-block mb-2">Participation Report</small>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Role</th>
                                <th>Not Used (submitted availability)</th>
                                <th>No Availability Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Actives</strong></td>
                                <td>
                                    <small>
                                        <?= count($unused_active_with_availability_names) ?>
                                        <?php if (!empty($unused_active_with_availability_names)): ?>
                                            - <?= htmlspecialchars(implode(', ', $unused_active_with_availability_names)) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <small>
                                        <?= count($active_no_availability_names) ?>
                                        <?php if (!empty($active_no_availability_names)): ?>
                                            - <?= htmlspecialchars(implode(', ', $active_no_availability_names)) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Pledges</strong></td>
                                <td>
                                    <small>
                                        <?= count($unused_pledge_with_availability_names) ?>
                                        <?php if (!empty($unused_pledge_with_availability_names)): ?>
                                            - <?= htmlspecialchars(implode(', ', $unused_pledge_with_availability_names)) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <small>
                                        <?= count($pledge_no_availability_names) ?>
                                        <?php if (!empty($pledge_no_availability_names)): ?>
                                            - <?= htmlspecialchars(implode(', ', $pledge_no_availability_names)) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Generation Stats (always visible) -->
        <div class="alert alert-light">
            <small>⏱️ Generation Stats: Took <?=$processing_time?>ms to compare <?=count($availability_data)?> availability records | <?=$used_actives?> actives used out of <?=$actives_with_availability?> with availability | <?=$used_pledges?> pledges used out of <?=$pledges_with_availability?> with availability</small>
        </div>

        <h3>📋 Suggested Coffee Chat for <?= $week_label ?> (<?= date('M j', $base) ?> - <?= date('M j', strtotime('+6 days', $base)) ?>)</h3>
        
        <div class="row">
        <?php foreach ($pairings as $i => $pairing): ?>
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">
                            Coffee Chat #<?= $i + 1 ?>
                            <span class="badge bg-<?= $pairing['type'] === '2-on-2' ? 'primary' : 'info' ?>"><?= $pairing['type'] ?></span>
                        </h6>
                        <p class="mb-1"><strong>Actives:</strong> <?= implode(' & ', array_column($pairing['actives'], 'name')) ?></p>
                        <p class="mb-1"><strong>Pledges:</strong> <?= implode(' & ', array_column($pairing['pledges'], 'name')) ?></p>
                        <p class="mb-0"><strong>Time:</strong> <?= $pairing['time_slot'] ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        
    <?php else: ?>
        <div class="alert alert-warning">
            <strong>⚠️ No pairings could be generated for <?= $week_label ?>.</strong><br>
            This could be due to:
            <ul class="mb-0 mt-2">
                <li>Limited availability overlap between actives and pledges</li>
                <li>Individual weekly limits (<?=$weeklyActiveLimit?> per active, <?=$weeklyPledgeLimit?> per pledge) already reached</li>
                <li>Most possible combinations have already been completed</li>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Debug Information (conditional) -->
    <?php if ($debug_mode): ?>
        <div class="mt-4">
            <h4>🐛 Debug Information</h4>
            <div class="alert alert-info">
                <small>To hide debug info, remove <code>?debug=1</code> from the URL</small>
            </div>
            <div class="card">
                <div class="card-body">
                    <pre style="font-size: 12px; max-height: 400px; overflow-y: auto;"><?= implode("\n", $debug) ?></pre>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php
    exit; // End AJAX response
}

// Full page version (for direct access)
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Generate Interview Pairings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Material Design Progress Bar CSS -->
    <style>
        .material-progress-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .material-progress-bar {
            width: 300px;
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .material-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #2196F3, #64B5F6);
            border-radius: 2px;
            animation: materialProgress 0.67s ease-in-out infinite;
        }
        
        @keyframes materialProgress {
            0% { transform: translateX(-100%) scaleX(0); }
            50% { transform: translateX(-100%) scaleX(1); }
            100% { transform: translateX(100%) scaleX(1); }
        }
        
        .material-progress-text {
            color: white;
            font-size: 16px;
            text-align: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
    </style>
</head>
<body>

<!-- Loading Overlay (shows immediately) -->
<div id="loadingOverlay" class="material-progress-container">
    <div class="material-progress-bar">
        <div class="material-progress-fill"></div>
    </div>
    <div class="material-progress-text">
        Generating interview pairings...<br>
        <small>This may take up to 30 seconds</small>
    </div>
</div>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>🎯 Generate Interview Pairings</h1>
        <div>
            <!-- Week Selector -->
            <a href="?<?= http_build_query(array_merge($_GET, ['week' => 'current'])) ?>" 
               class="btn btn-<?= $week === 'current' ? 'primary' : 'outline-primary' ?> btn-sm me-2">
                Current Week<br><small><?= date('M j', strtotime('monday this week')) ?> - <?= date('M j', strtotime('sunday this week')) ?></small>
            </a>
            <a href="?<?= http_build_query(array_merge($_GET, ['week' => 'next'])) ?>" 
               class="btn btn-<?= $week === 'next' ? 'primary' : 'outline-primary' ?> btn-sm me-2">
                Next Week<br><small><?= date('M j', strtotime('next monday')) ?> - <?= date('M j', strtotime('next sunday')) ?></small>
            </a>
            
            <!-- Regenerate Button -->
            <a href="?<?= http_build_query(array_merge($_GET, ['regenerate' => 1])) ?>" 
               class="btn btn-success btn-sm" id="regenerateBtn">
                🔄 Regenerate Pairings
            </a>
        </div>
    </div>
    
    <div class="alert alert-info">
        <strong>📅 Generating pairings for <?= $week_label ?></strong> (<?= date('M j', $base) ?> - <?= date('M j', strtotime('+6 days', $base)) ?>)<br>
        <small>Click "Regenerate Pairings" to get different combinations if actives don't pair well together</small>
    </div>

    <?php if (!empty($pairings)): ?>
        
        <!-- Generation Stats (always visible) -->
        <div class="alert alert-light">
            <small>⏱️ Generation Stats: Took <?=$processing_time?>ms to compare <?=count($availability_data)?> availability records | <?=$used_actives?> actives used out of <?=$actives_with_availability?> with availability | <?=$used_pledges?> pledges used out of <?=$pledges_with_availability?> with availability</small>
        </div>

        <div class="card mb-3">
            <div class="card-body p-2">
                <small class="fw-bold d-block mb-2">Participation Report</small>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Role</th>
                                <th>Not Used (submitted availability)</th>
                                <th>No Availability Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Actives</strong></td>
                                <td>
                                    <small>
                                        <?= count($unused_active_with_availability_names) ?>
                                        <?php if (!empty($unused_active_with_availability_names)): ?>
                                            - <?= htmlspecialchars(implode(', ', $unused_active_with_availability_names)) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <small>
                                        <?= count($active_no_availability_names) ?>
                                        <?php if (!empty($active_no_availability_names)): ?>
                                            - <?= htmlspecialchars(implode(', ', $active_no_availability_names)) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Pledges</strong></td>
                                <td>
                                    <small>
                                        <?= count($unused_pledge_with_availability_names) ?>
                                        <?php if (!empty($unused_pledge_with_availability_names)): ?>
                                            - <?= htmlspecialchars(implode(', ', $unused_pledge_with_availability_names)) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <small>
                                        <?= count($pledge_no_availability_names) ?>
                                        <?php if (!empty($pledge_no_availability_names)): ?>
                                            - <?= htmlspecialchars(implode(', ', $pledge_no_availability_names)) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="alert alert-success">
            <strong>✅ Successfully generated <?= count($pairings) ?> interview pairings!</strong><br>
            Found <?= $interview_opportunities_count ?> potential opportunities, scheduled <?= count($pairings) ?> (max: <?=$max_pairings?>)
        </div>

        <h3>📋 Suggested Interview Pairings</h3>
        
        <div class="row">
        <?php foreach ($pairings as $i => $pairing): ?>
            <div class="col-md-6 mb-3">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">
                            Interview #<?= $i + 1 ?>
                            <span class="badge bg-<?= $pairing['type'] === '2-on-2' ? 'primary' : 'info' ?>"><?= $pairing['type'] ?></span>
                        </h6>
                        <p class="mb-1"><strong>Actives:</strong> <?= implode(' & ', array_column($pairing['actives'], 'name')) ?></p>
                        <p class="mb-1"><strong>Pledges:</strong> <?= implode(' & ', array_column($pairing['pledges'], 'name')) ?></p>
                        <p class="mb-0"><strong>Time:</strong> <?= $pairing['time_slot'] ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        
    <?php else: ?>
        <div class="alert alert-warning">
            <strong>⚠️ No pairings could be generated for <?= $week_label ?>.</strong><br>
            This could be due to:
            <ul class="mb-0 mt-2">
                <li>Limited availability overlap between actives and pledges</li>
                <li>Individual weekly limits (<?=$weeklyActiveLimit?> per active, <?=$weeklyPledgeLimit?> per pledge) already reached</li>
                <li>Most possible combinations have already been completed</li>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Debug Information (conditional) -->
    <?php if ($debug_mode): ?>
        <div class="mt-4">
            <h4>🐛 Debug Information</h4>
            <div class="alert alert-info">
                <small>To hide debug info, remove <code>?debug=1</code> from the URL</small>
            </div>
            <div class="card">
                <div class="card-body">
                    <pre style="font-size: 12px; max-height: 400px; overflow-y: auto;"><?= implode("\n", $debug) ?></pre>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="mt-4">
        <a href="admin.php" class="btn btn-secondary">← Back to Admin</a>
    </div>
</div>

<script>
// Hide loading overlay after page loads
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        const loadingOverlay = document.getElementById('loadingOverlay');
        if (loadingOverlay) {
            loadingOverlay.style.display = 'none';
        }
    }, 1500);
});

// Add loading state to regenerate button
document.getElementById('regenerateBtn').addEventListener('click', function(e) {
    this.innerHTML = '🔄 Regenerating...';
    this.classList.add('disabled');
    
    // Show loading overlay
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.style.display = 'flex';
    }
});
</script>

</body>
</html>