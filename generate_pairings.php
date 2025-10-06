<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Increase execution time limit for complex pairing generation
ini_set('max_execution_time', 300); // 5 minutes

require 'config.php';

session_start();

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

// Fetch users
$users = $db->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
$actives = array_filter($users, fn($u) => $u['role'] === 'active');
$pledges = array_filter($users, fn($u) => $u['role'] === 'pledge');

// Shuffle users to eliminate alphabetical bias and ensure fair distribution
shuffle($actives);
shuffle($pledges);

// Debug: add user count info
$debug[] = "Found " . count($actives) . " actives and " . count($pledges) . " pledges (randomized order)";

// Load availabilities based on selected week (match availability.php logic)
if ($week === 'current') {
    $base = strtotime('monday this week');
    $week_label = "Current Week";
} else {
    $base = strtotime('next Monday');
    $week_label = "Next Week";
}
$weekStart = date('Y-m-d', $base);
$weekEnd = date('Y-m-d', strtotime('+6 days', $base));
$avail_stmt = $db->prepare("SELECT user_id, slot_start FROM availabilities WHERE date(slot_start) BETWEEN ? AND ?");
$avail_stmt->execute([$weekStart, $weekEnd]);
$avail_rows = $avail_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build availability map (each slot as exact timestamp)
$availability = [];
foreach ($avail_rows as $r) {
    $ts = strtotime($r['slot_start']);
    $availability[$r['user_id']][] = $ts;
}

// Debug: add availability count info
$debug[] = "Found availability for " . count($availability) . " users";
$debug[] = "Date range: $weekStart to $weekEnd";
$debug[] = "Total availability records: " . count($avail_rows);

// Track completed interviews
$completed_stmt = $db->query("SELECT active_id, pledge_id FROM completed_interviews");
$completed_pairs = [];
foreach ($completed_stmt->fetchAll(PDO::FETCH_ASSOC) as $ci) {
    $completed_pairs[$ci['active_id']][$ci['pledge_id']] = true;
}

// Helper: check exact time slot overlap (30-minute intervals)
function has_overlap($aSlots, $pSlots) {
    return count(array_intersect($aSlots, $pSlots)) > 0;
}

// Helper: generate combinations iteratively to avoid recursion issues
function generateCombinations($array, $size) {
    if ($size <= 0 || $size > count($array)) return [];
    if ($size == 1) return array_map(fn($item) => [$item], $array);
    
    $combinations = [];
    $total = count($array);
    
    // Use iterative approach for better performance
    if ($size == 2) {
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                $combinations[] = [$array[$i], $array[$j]];
            }
        }
    } elseif ($size == 3) {
        for ($i = 0; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                for ($k = $j + 1; $k < $total; $k++) {
                    $combinations[] = [$array[$i], $array[$j], $array[$k]];
                }
            }
        }
    } else {
        // For larger sizes, limit to avoid performance issues
        // Only generate 2-on-2 and 3-on-3 for now
        return [];
    }
    
    return $combinations;
}

// Additional randomization for regeneration requests (more variety each time)
if (isset($_GET['regenerate'])) {
    shuffle($actives);
    shuffle($pledges);
    $debug[] = "Regenerating with additional randomization for variety";
}

// Clean implementation following user's 6-step process
$pairings = [];
$used_time_slots = []; // Track who's scheduled when
$individual_counts = []; // Track interview count per person
$debug = [];
$max_pairings = intval($_POST['global_max'] ?? 40); // Weekly cap (can be overridden by POST)
$start_time = microtime(true);

// Debug: add limits info now that all variables are defined
$debug[] = "Limits: Global max = $max_pairings, Active max = $weeklyActiveLimit, Pledge max = $weeklyPledgeLimit";
$debug[] = "PHP max execution time: " . ini_get('max_execution_time') . " seconds, Memory limit: " . ini_get('memory_limit');

// Step 1: Find every active pair/triple that can interview together
$active_groups = [];

// 2-on-2 combinations
for ($i = 0; $i < count($actives); $i++) {
    for ($j = $i + 1; $j < count($actives); $j++) {
        if (isset($availability[$actives[$i]['id']]) && isset($availability[$actives[$j]['id']])) {
            $active_groups[] = [$actives[$i], $actives[$j]];
        }
    }
}

// 3-on-3 combinations (if desired)
for ($i = 0; $i < count($actives); $i++) {
    for ($j = $i + 1; $j < count($actives); $j++) {
        for ($k = $j + 1; $k < count($actives); $k++) {
            if (isset($availability[$actives[$i]['id']]) && isset($availability[$actives[$j]['id']]) && isset($availability[$actives[$k]['id']])) {
                $active_groups[] = [$actives[$i], $actives[$j], $actives[$k]];
            }
        }
    }
}

$debug[] = "Found " . count($active_groups) . " active groups with availability";

// Step 2: Find every pledge pair/triple that can interview together
$pledge_groups = [];

// 2-pledge combinations
for ($i = 0; $i < count($pledges); $i++) {
    for ($j = $i + 1; $j < count($pledges); $j++) {
        if (isset($availability[$pledges[$i]['id']]) && isset($availability[$pledges[$j]['id']])) {
            $pledge_groups[] = [$pledges[$i], $pledges[$j]];
        }
    }
}

// 3-pledge combinations (if desired)  
for ($i = 0; $i < count($pledges); $i++) {
    for ($j = $i + 1; $j < count($pledges); $j++) {
        for ($k = $j + 1; $k < count($pledges); $k++) {
            if (isset($availability[$pledges[$i]['id']]) && isset($availability[$pledges[$j]['id']]) && isset($availability[$pledges[$k]['id']])) {
                $pledge_groups[] = [$pledges[$i], $pledges[$j], $pledges[$k]];
            }
        }
    }
}

$debug[] = "Found " . count($pledge_groups) . " pledge groups with availability";

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
        $common_slots = $availability[$participant_ids[0]];
        foreach (array_slice($participant_ids, 1) as $pid) {
            $common_slots = array_intersect($common_slots, $availability[$pid]);
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
        
        // Step 5: Calculate priority based on previous meetings
        $previous_meetings = 0;
        foreach ($active_group as $active) {
            foreach ($pledge_group as $pledge) {
                if (isset($completed_pairs[$active['id']][$pledge['id']])) {
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

$debug[] = "Found " . count($interview_opportunities) . " total interview opportunities";

// Step 5: Sort by priority (fewer previous meetings = higher priority)
// Then shuffle within each priority group to randomize selection
usort($interview_opportunities, function($a, $b) {
    $priority_diff = $a['previous_meetings'] <=> $b['previous_meetings'];
    if ($priority_diff === 0) {
        // Same priority level - randomize order
        return rand(-1, 1);
    }
    return $priority_diff;
});

$debug[] = "Prioritized and randomized " . count($interview_opportunities) . " opportunities for fair selection";

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
    
    if (!$has_conflict && !$exceeds_limit) {
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
        
        $active_names = implode(' & ', array_column($opportunity['actives'], 'name'));
        $pledge_names = implode(' & ', array_column($opportunity['pledges'], 'name'));
        $blocks_available = $opportunity['total_blocks_available'] ?? 1;
        $debug[] = "Scheduled {$group_size}-on-{$group_size}: $active_names with $pledge_names at " . date('D H:i', $slot_start_time) . '-' . date('H:i', $slot_end_time) . " ({$opportunity['previous_meetings']} previous meetings, $blocks_available hour blocks available)";
    }
}

// Add performance debug info
$processing_time = round((microtime(true) - $start_time) * 1000, 2);
$debug[] = "Processing completed in {$processing_time}ms";
$debug[] = "Generated " . count($pairings) . " pairings (limit: $max_pairings)";

// Calculate how many unique people were used
$used_actives = [];
$used_pledges = [];  
foreach ($pairings as $pairing) {
    foreach ($pairing['actives'] as $active) $used_actives[$active['id']] = true;
    foreach ($pairing['pledges'] as $pledge) $used_pledges[$pledge['id']] = true;
}
$debug[] = "Used " . count($used_actives) . " actives, " . count($used_pledges) . " pledges";

// Compute zero-overlap list
$no_overlap = [];
foreach ($actives as $a) {
    foreach ($pledges as $p) {
        if (!isset($availability[$a['id']], $availability[$p['id']]) || !has_overlap($availability[$a['id']], $availability[$p['id']])) {
            $no_overlap[] = ['active'=>$a['name'],'pledge'=>$p['name']];
        }
    }
}

// Progress bar (based on global max)
$percent = min(100, (count($pairings)/$max_pairings)*100);

// Check if this is an AJAX request (return just the content, not full page)
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!$is_ajax) {
    // Alternative check for AJAX - check if Accept header contains 'json' or if it's a fetch request
    $is_ajax = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'text/html') === false);
}

if ($is_ajax) {
    // Return just the pairing results for AJAX
    ?>
    <div class="alert alert-success">
        <strong>✅ Pairings Generated Successfully for <?= $week_label ?>!</strong><br>
        Generated <?=count($pairings)?> pairings (max: <?=$max_pairings?>) with individual limits of <?=$weeklyActiveLimit?> per active, <?=$weeklyPledgeLimit?> per pledge.
        <br><small class="text-muted">Week: <?= date('M j', $base) ?> - <?= date('M j', strtotime('+6 days', $base)) ?></small>
    </div>
    
    <?php if (!empty($pairings)): ?>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Interview Type</th>
                        <th>Actives</th>
                        <th>Pledges</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pairings as $pairing): ?>
                        <tr>
                            <td><span class="badge bg-primary"><?=$pairing['type']?></span></td>
                            <td><?=implode(' & ', array_column($pairing['actives'], 'name'))?></td>
                            <td><?=implode(' & ', array_column($pairing['pledges'], 'name'))?></td>
                            <td><?=$pairing['time_slot']?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Progress bar -->
        <div class="progress mb-3">
            <div class="progress-bar" role="progressbar" style="width: <?=round((count($pairings)/$max_pairings)*100)?>%">
                <?=round((count($pairings)/$max_pairings)*100)?>%
            </div>
        </div>
        <p><?=count($pairings)?> / <?=$max_pairings?> pairings scheduled</p>
        
        <!-- Timing Information -->
        <div class="alert alert-light mt-3">
            <small class="text-muted" style="font-family: monospace;">
                <strong>⏱️ Generation Stats:</strong> 
                Took <?=round((microtime(true) - $start_time) * 1000, 1)?>ms to process <?=count($avail_rows)?> records 
                | <?=count($used_actives)?> actives used out of <?=count(array_filter($actives, fn($a) => isset($availability[$a['id']])))?> with availability 
                | <?=count($used_pledges)?> pledges used out of <?=count(array_filter($pledges, fn($p) => isset($availability[$p['id']])))?> with availability
                | Individual limits: <?=$weeklyActiveLimit?> per active, <?=$weeklyPledgeLimit?> per pledge
            </small>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <strong>⚠️ No Pairings Generated</strong><br>
            No valid interview combinations found for the selected week.
        </div>
    <?php endif; ?>
    
    <?php
    exit; // Don't render the full page
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Generate Pairings</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .navbar-dark { background-color: #000 !important; }

  /* Material Design Progress Bar */
  .loading-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(255,255,255,0.9);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    z-index: 9999;
  }
  
  .material-progress {
    width: 300px;
    height: 4px;
    background: #e0e0e0;
    border-radius: 2px;
    overflow: hidden;
    position: relative;
  }
  
  .material-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #2196F3, #21CBF3);
    border-radius: 2px;
    position: absolute;
    left: -100%;
    animation: material-indeterminate 0.8375s infinite;
  }
  
  @keyframes material-indeterminate {
    0% { left: -100%; width: 100%; }
    50% { left: 107%; width: 100%; }
    100% { left: 107%; width: 0%; }
  }
  
  .loading-text {
    margin-top: 20px;
    font-size: 16px;
    color: #666;
    text-align: center;
  }
  
  /* Button loading state */
  .btn-loading {
    position: relative;
    pointer-events: none;
    opacity: 0.7;
  }
  
  .btn-loading::after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    margin: auto;
    border: 2px solid transparent;
    border-top-color: currentColor;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    top: 0; left: 0; bottom: 0; right: 0;
  }
  
  @keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
  }
</style>
</head>
<body>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay" style="display: none;">
  <div class="material-progress">
    <div class="material-progress-bar"></div>
  </div>
  <div class="loading-text">
    <strong>Generating Pairings</strong><br>
    <small>This may take a moment while we find the best combinations</small>
  </div>
</div>

<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand text-white" href="index.php">Interview Scheduler</a>
    <div>
      <a class="btn btn-outline-light btn-sm" href="admin.php">Admin</a>
    </div>
  </div>
</nav>

<div class="container py-4">

<h2>Suggested Weekly Pairings - <?= $week_label ?></h2>

<!-- Week Selector -->
<div class="mb-3">
  <div class="btn-group" role="group" aria-label="Week selector">
    <?php 
    // Calculate correct date ranges for display (consistent with week logic)
    $current_base = strtotime('monday this week');
    $next_base = strtotime('next Monday');
    ?>
    <a href="generate_pairings.php?week=current" 
       class="btn <?= $week === 'current' ? 'btn-primary' : 'btn-outline-primary' ?>">
       Current Week (<?= date('M j', $current_base) ?> - <?= date('M j', strtotime('+6 days', $current_base)) ?>)
    </a>
    <a href="generate_pairings.php?week=next" 
       class="btn <?= $week === 'next' ? 'btn-primary' : 'btn-outline-primary' ?>">
       Next Week (<?= date('M j', $next_base) ?> - <?= date('M j', strtotime('+6 days', $next_base)) ?>)
    </a>
  </div>
</div>

<!-- Regenerate Button -->
<div class="mb-3">
  <button id="regenerateBtn" class="btn btn-success btn-sm" onclick="regeneratePairings()">
    Regenerate Pairings
  </button>
  <small class="text-muted ms-2">Click to generate new combinations if actives don't pair well together</small>
</div>

<?php if ($pairings): ?>
    <ul class="list-group mb-4">
    <?php foreach ($pairings as $g): ?>
        <li class="list-group-item">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <strong>Time Slot:</strong> <?=htmlspecialchars($g['time_slot'])?><br>
                    <strong>Actives (<?=count($g['actives'])?>):</strong> <?=implode(', ', array_column($g['actives'],'name'))?><br>
                    <strong>Pledges (<?=count($g['pledges'])?>):</strong> <?=implode(', ', array_column($g['pledges'],'name'))?>
                </div>
                <span class="badge bg-<?= count($g['actives']) == 2 && count($g['pledges']) == 2 ? 'primary' : 'info' ?> ms-2">
                    <?= $g['type'] ?? count($g['actives']) . '-on-' . count($g['pledges']) ?>
                </span>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
<?php else: ?>
    <p>No suggested pairings could be generated with current availability.</p>
<?php endif; ?>

<?php if (count($pairings) >= $max_pairings): ?>
<div class="alert alert-info mt-3">
    <strong>Note:</strong> Pairing generation was limited to <?=$max_pairings?> results for performance. 
    Many more combinations may be possible.
</div>
<?php endif; ?>

<h5>Interview Pacing Goal (<?=$max_pairings?> interviews)</h5>
<div class="progress mb-3">
  <div class="progress-bar" role="progressbar" style="width: <?=$percent?>%;" aria-valuenow="<?=$percent?>" aria-valuemin="0" aria-valuemax="100">
      <?=round($percent)?>%
  </div>
</div>
<p><?=count($pairings)?> / <?=$max_pairings?> pairings scheduled</p>

<!-- Timing Information -->
<div class="alert alert-light mt-3">
    <small class="text-muted" style="font-family: monospace;">
        <strong>⏱️ Generation Stats:</strong> 
        Took <?=round((microtime(true) - $start_time) * 1000, 1)?>ms to process <?=count($avail_rows)?> records 
        | <?=count($used_actives)?> actives used out of <?=count(array_filter($actives, fn($a) => isset($availability[$a['id']])))?> with availability 
        | <?=count($used_pledges)?> pledges used out of <?=count(array_filter($pledges, fn($p) => isset($availability[$p['id']])))?> with availability
        <?php if (count($pairings) >= $max_pairings): ?>
        | Limited to <?=$max_pairings?> results
        <?php endif; ?>
        <?php 
        // Show interview type breakdown
        $type_counts = [];
        foreach ($pairings as $pairing) {
            $type = $pairing['type'] ?? count($pairing['actives']) . '-on-' . count($pairing['pledges']);
            $type_counts[$type] = ($type_counts[$type] ?? 0) + 1;
        }
        if (!empty($type_counts)): ?>
        <br><strong>📊 Interview Types:</strong> <?=implode(', ', array_map(fn($type, $count) => "$count × $type", array_keys($type_counts), $type_counts))?>
        <?php endif; ?>
    </small>
</div>

<?php if ($no_overlap): ?>
<div class="alert alert-warning mt-4">
    <h5>Actives/Pledges with no overlapping availability:</h5>
    <ul>
    <?php foreach($no_overlap as $o): ?>
        <li><?=htmlspecialchars($o['active'])?> &mdash; <?=htmlspecialchars($o['pledge'])?></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($debug_mode && $debug): ?>
<div class="alert alert-secondary mt-4">
<h5>Debug logs <small class="text-muted">(add ?debug=1 to URL to show)</small></h5>
<ul>
<?php foreach ($debug as $d): ?>
<li><?=htmlspecialchars($d)?></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>

<script>
// Show loading overlay when navigating to this page
document.addEventListener('DOMContentLoaded', function() {
    // Show loading overlay
    document.getElementById('loadingOverlay').style.display = 'flex';
    
    // Hide after a short delay to show the generation process
    setTimeout(function() {
        document.getElementById('loadingOverlay').style.display = 'none';
    }, 1500);
});

// Show loading overlay when clicking week selection buttons
document.addEventListener('DOMContentLoaded', function() {
    const weekButtons = document.querySelectorAll('a[href*="generate_pairings.php"]');
    weekButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            // Add loading state to button
            this.classList.add('btn-loading');
            this.innerHTML = this.innerHTML.replace(/Week.*?\)/, 'Loading...');
            
            // Show loading overlay
            document.getElementById('loadingOverlay').style.display = 'flex';
        });
    });
});

// Regenerate pairings function
function regeneratePairings() {
    const btn = document.getElementById('regenerateBtn');
    const currentWeek = '<?= $week ?>';
    
    // Add loading state to button
    btn.classList.add('btn-loading');
    btn.innerHTML = '🔄 Regenerating...';
    btn.disabled = true;
    
    // Show loading overlay
    document.getElementById('loadingOverlay').style.display = 'flex';
    
    // Add a small random parameter to force regeneration with different results
    const randomSeed = Math.floor(Math.random() * 10000);
    window.location.href = `generate_pairings.php?week=${currentWeek}&regenerate=${randomSeed}`;
}
</script>

</div>

</body>
</html>
