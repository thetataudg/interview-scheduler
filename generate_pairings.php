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

$week = $_GET['week'] ?? 'next'; // 'current' or 'next'
$debug_mode = $_GET['debug'] ?? false; // Add ?debug=1 to URL to show debug logs

// Individual participation limits (can be overridden by POST parameters)
$weeklyActiveLimit = intval($_POST['active_max'] ?? 5); // Maximum interviews per active per week
$weeklyPledgeLimit = intval($_POST['pledge_max'] ?? 5); // Maximum interviews per pledge per week

$debug = [];
$debug[] = "Debug mode: " . ($debug_mode ? "ON" : "OFF");
$debug[] = "Current execution time limit: " . ini_get('max_execution_time') . " seconds";
$debug[] = "Week selected: $week";

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

// Process availability into user-based time slots
$availability = [];
foreach ($availability_data as $record) {
    $user_id = $record['user_id'];
    $slot_timestamp = strtotime($record['slot_datetime']);
    $availability[$user_id][] = $slot_timestamp;
}

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

// Start timing
$start_time = microtime(true);

// Configuration
$max_pairings = intval($_POST['global_max'] ?? 50); // Weekly cap on total interviews

$debug[] = "Limits: Global max = $max_pairings, Active max = $weeklyActiveLimit, Pledge max = $weeklyPledgeLimit";

// Step 1: Generate all possible active combinations (2-person and 3-person groups)
$active_groups = [];

// 2-active combinations
for ($i = 0; $i < count($actives); $i++) {
    for ($j = $i + 1; $j < count($actives); $j++) {
        $active1 = $actives[$i];
        $active2 = $actives[$j];
        
        // Only include if both have availability data
        if (isset($availability[$active1['id']]) && isset($availability[$active2['id']])) {
            $active_groups[] = [$active1, $active2];
        }
    }
}

// 3-active combinations  
for ($i = 0; $i < count($actives); $i++) {
    for ($j = $i + 1; $j < count($actives); $j++) {
        for ($k = $j + 1; $k < count($actives); $k++) {
            $active1 = $actives[$i];
            $active2 = $actives[$j];
            $active3 = $actives[$k];
            
            // Only include if all have availability data
            if (isset($availability[$active1['id']]) && 
                isset($availability[$active2['id']]) && 
                isset($availability[$active3['id']])) {
                $active_groups[] = [$active1, $active2, $active3];
            }
        }
    }
}

$debug[] = "Generated " . count($active_groups) . " active combinations";

// Step 2: Generate all possible pledge combinations (2-person and 3-person groups)
$pledge_groups = [];

// 2-pledge combinations
for ($i = 0; $i < count($pledges); $i++) {
    for ($j = $i + 1; $j < count($pledges); $j++) {
        $pledge1 = $pledges[$i];
        $pledge2 = $pledges[$j];
        
        // Only include if both have availability data
        if (isset($availability[$pledge1['id']]) && isset($availability[$pledge2['id']])) {
            $pledge_groups[] = [$pledge1, $pledge2];
        }
    }
}

// 3-pledge combinations
for ($i = 0; $i < count($pledges); $i++) {
    for ($j = $i + 1; $j < count($pledges); $j++) {
        for ($k = $j + 1; $k < count($pledges); $k++) {
            $pledge1 = $pledges[$i];
            $pledge2 = $pledges[$j];  
            $pledge3 = $pledges[$k];
            
            // Only include if all have availability data
            if (isset($availability[$pledge1['id']]) && 
                isset($availability[$pledge2['id']]) && 
                isset($availability[$pledge3['id']])) {
                $pledge_groups[] = [$pledge1, $pledge2, $pledge3];
            }
        }
    }
}

$debug[] = "Generated " . count($pledge_groups) . " pledge combinations";

// Step 3: Find overlapping time slots for each active+pledge combination
$interview_opportunities = [];

foreach ($active_groups as $active_group) {
    foreach ($pledge_groups as $pledge_group) {
        // Only match equal-sized groups (2-on-2, 3-on-3)
        if (count($active_group) !== count($pledge_group)) continue;
        
        // Get all participant IDs
        $all_participants = array_merge($active_group, $pledge_group);
        $participant_ids = array_column($all_participants, 'id');
        
        // Find common 1-hour blocks (consecutive 30-minute slots) for all participants
        $common_slots = $availability[$participant_ids[0]] ?? [];
        foreach (array_slice($participant_ids, 1) as $pid) {
            $common_slots = array_intersect($common_slots, $availability[$pid] ?? []);
        }
        
        if (empty($common_slots)) continue;
        
        // Find consecutive slot pairs (1-hour blocks)
        $hour_blocks = [];
        sort($common_slots); // Ensure chronological order
        for ($i = 0; $i < count($common_slots) - 1; $i++) {
            $slot1 = $common_slots[$i];
            $slot2 = $common_slots[$i + 1];
            // Check if slots are exactly 30 minutes apart (1800 seconds)
            if ($slot2 - $slot1 == 1800) {
                $hour_blocks[] = [$slot1, $slot2];
            }
        }
        
        if (empty($hour_blocks)) continue;
        
        // NEW: Calculate priority based on previous meetings (including current week)
        $previous_meetings = 0;
        foreach ($active_group as $active) {
            foreach ($pledge_group as $pledge) {
                if (haveMet($active['id'], $pledge['id'], $completed_pairs, $current_week_pairs)) {
                    $previous_meetings++;
                }
            }
        }
        
        // Pick the best hour block for this pairing (earliest available)
        $best_hour_block = $hour_blocks[0]; // First (earliest) available hour block
        $interview_opportunities[] = [
            'actives' => $active_group,
            'pledges' => $pledge_group,
            'time_slots' => $best_hour_block, // Array of [start_slot, end_slot]
            'participant_ids' => $participant_ids,
            'previous_meetings' => $previous_meetings,
            'group_size' => count($active_group),
            'total_blocks_available' => count($hour_blocks)
        ];
    }
}

$debug[] = "Found " . count($interview_opportunities) . " potential interview opportunities";

// Step 5: Sort opportunities by priority (fewest previous meetings first), then by time
usort($interview_opportunities, function($a, $b) {
    // Primary sort: fewer previous meetings = higher priority  
    if ($a['previous_meetings'] != $b['previous_meetings']) {
        return $a['previous_meetings'] - $b['previous_meetings'];
    }
    // Secondary sort: earlier time slots (with some randomization for equal priority)
    if ($a['time_slots'][0] != $b['time_slots'][0]) {
        return $a['time_slots'][0] - $b['time_slots'][0];
    }
    // Tertiary sort: random for identical priority and time (fairness)
    return rand(-1, 1);
});

$debug[] = "Sorted opportunities by priority (previous meetings) and time";

// Initialize tracking variables
$pairings = [];
$used_time_slots = []; // [timestamp][user_id] = true
$individual_counts = []; // [user_id] = interview count

// Step 4 & 6: Schedule interviews avoiding conflicts, up to cap
foreach ($interview_opportunities as $opportunity) {
    if (count($pairings) >= $max_pairings) {
        $debug[] = "Reached weekly cap of $max_pairings interviews";
        break;
    }
    
    $time_slots = $opportunity['time_slots']; // Array of [start_slot, end_slot]
    $participant_ids = $opportunity['participant_ids'];
    
    // Step 4: Check if anyone is already scheduled for either time slot
    $has_conflict = false;
    foreach ($participant_ids as $pid) {
        foreach ($time_slots as $slot) {
            if (isset($used_time_slots[$slot][$pid])) {
                $has_conflict = true;
                break 2; // Break out of both loops
            }
        }
    }
    
    // Check individual weekly limits
    $exceeds_limit = false;
    foreach ($opportunity['actives'] as $active) {
        if (($individual_counts[$active['id']] ?? 0) >= $weeklyActiveLimit) {
            $exceeds_limit = true;
            break;
        }
    }
    foreach ($opportunity['pledges'] as $pledge) {
        if (($individual_counts[$pledge['id']] ?? 0) >= $weeklyPledgeLimit) {
            $exceeds_limit = true;
            break;
        }
    }
    
    // NEW: Check if this pairing has people who have already met (including current week)
    $has_repeat_meeting = false;
    foreach ($opportunity['actives'] as $active) {
        foreach ($opportunity['pledges'] as $pledge) {
            if (haveMet($active['id'], $pledge['id'], $completed_pairs, $current_week_pairs)) {
                $has_repeat_meeting = true;
                break 2;
            }
        }
    }
    
    if (!$has_conflict && !$exceeds_limit && !$has_repeat_meeting) {
        // Schedule this interview
        $group_size = $opportunity['group_size'];
        $slot_start_time = $time_slots[0];
        $slot_end_time = $time_slots[1];
        
        $pairings[] = [
            'actives' => $opportunity['actives'],
            'pledges' => $opportunity['pledges'],
            'time_slot' => date('D H:i', $slot_start_time) . '-' . date('H:i', $slot_end_time),
            'type' => "{$group_size}-on-{$group_size}"
        ];
        
        // Mark participants as used for both time slots in the hour block
        foreach ($participant_ids as $pid) {
            foreach ($time_slots as $slot) {
                $used_time_slots[$slot][$pid] = true;
            }
        }
        
        // Increment individual interview counts
        foreach ($opportunity['actives'] as $active) {
            $individual_counts[$active['id']] = ($individual_counts[$active['id']] ?? 0) + 1;
        }
        foreach ($opportunity['pledges'] as $pledge) {
            $individual_counts[$pledge['id']] = ($individual_counts[$pledge['id']] ?? 0) + 1;
        }
        
        // NEW: Track current week pairings to prevent repeats
        foreach ($opportunity['actives'] as $active) {
            foreach ($opportunity['pledges'] as $pledge) {
                $current_week_pairs[$active['id']][$pledge['id']] = true;
            }
        }
        
        $active_names = implode(' & ', array_column($opportunity['actives'], 'name'));
        $pledge_names = implode(' & ', array_column($opportunity['pledges'], 'name'));
        $blocks_available = $opportunity['total_blocks_available'] ?? 1;
        $debug[] = "Scheduled {$group_size}-on-{$group_size}: $active_names with $pledge_names at " . date('D H:i', $slot_start_time) . '-' . date('H:i', $slot_end_time) . " ({$opportunity['previous_meetings']} previous meetings, $blocks_available hour blocks available)";
    } else if ($has_repeat_meeting) {
        // NEW: Debug info for skipped repeat meetings
        $active_names = implode(' & ', array_column($opportunity['actives'], 'name'));
        $pledge_names = implode(' & ', array_column($opportunity['pledges'], 'name'));
        $debug[] = "Skipped repeat pairing: $active_names with $pledge_names (already met this week or previously)";
    }
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

if ($is_ajax) {
    // Return just the pairing results for AJAX
    ?>
    <div class="alert alert-success">
        <strong>✅ Pairings Generated Successfully for <?= $week_label ?>!</strong><br>
        Generated <?=count($pairings)?> pairings (max: <?=$max_pairings?>) with individual limits of <?=$weeklyActiveLimit?> per active, <?=$weeklyPledgeLimit?> per pledge.
        <br><small class="text-muted">Week: <?= date('M j', $base) ?> - <?= date('M j', strtotime('+6 days', $base)) ?></small>
    </div>
    
    <?php if (!empty($pairings)): ?>
        
        <!-- Generation Stats (always visible) -->
        <div class="alert alert-light">
            <small>⏱️ Generation Stats: Took <?=$processing_time?>ms to compare <?=count($availability_data)?> availability records | <?=$used_actives?> actives used out of <?=$actives_with_availability?> with availability | <?=$used_pledges?> pledges used out of <?=$pledges_with_availability?> with availability</small>
        </div>

        <h3>📋 Suggested Interview Pairings for <?= $week_label ?> (<?= date('M j', $base) ?> - <?= date('M j', strtotime('+6 days', $base)) ?>)</h3>
        
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

        <div class="alert alert-success">
            <strong>✅ Successfully generated <?= count($pairings) ?> interview pairings!</strong><br>
            Found <?= count($interview_opportunities) ?> potential opportunities, scheduled <?= count($pairings) ?> (max: <?=$max_pairings?>)
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