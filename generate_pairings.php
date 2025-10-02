<?php
require 'config.php';

session_start();

// Require admin authentication
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

$week = $_GET['week'] ?? 'next'; // 'current' or 'next'
$debug_mode = $_GET['debug'] ?? false; // Add ?debug=1 to URL to show debug logs

// Fetch users
$users = $db->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
$actives = array_filter($users, fn($u) => $u['role'] === 'active');
$pledges = array_filter($users, fn($u) => $u['role'] === 'pledge');

// Debug: add user count info
$debug[] = "Found " . count($actives) . " actives and " . count($pledges) . " pledges";

// Load availabilities based on selected week
if ($week === 'current') {
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd   = date('Y-m-d', strtotime('sunday this week'));
    $week_label = "Current Week";
} else {
    $weekStart = date('Y-m-d', strtotime('next monday'));
    $weekEnd   = date('Y-m-d', strtotime('next sunday'));
    $week_label = "Next Week";
}
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

// Helper: check exact 1-hour overlap
function has_overlap($aSlots, $pSlots) {
    return count(array_intersect($aSlots, $pSlots)) > 0;
}

// Prioritize bigs
usort($actives, fn($a, $b) => $b['is_big'] <=> $a['is_big']);

// Add randomization when regenerating to get different combinations
if (isset($_GET['regenerate'])) {
    // Shuffle within big/non-big groups to get different pairings
    $bigs = array_filter($actives, fn($a) => $a['is_big']);
    $non_bigs = array_filter($actives, fn($a) => !$a['is_big']);
    shuffle($bigs);
    shuffle($non_bigs);
    shuffle($pledges); // Also shuffle pledges for variety
    $actives = array_merge($bigs, $non_bigs);
    $debug[] = "Regenerating with shuffled order for variety";
}

// Generate 2-on-2 suggested pairings
$pairings = [];
$used_pledges = [];
$used_actives = [];
$debug = [];
$max_pairings = 30; // Limit to prevent too many results
$start_time = microtime(true); // Track processing time

foreach ($actives as $i => $a1) {
    // Performance check: stop if taking too long or have enough pairings
    if (microtime(true) - $start_time > 10 || count($pairings) >= $max_pairings) {
        $debug[] = "Stopping early - reached time limit or max pairings";
        break;
    }
    
    for ($j = $i + 1; $j < count($actives); $j++) {
        $a2 = $actives[$j];
        $active_group = [$a1, $a2];
        
        // Check if either active is already used
        if (in_array($a1['id'], $used_actives) || in_array($a2['id'], $used_actives)) continue;

        // Find pledge pairs that overlap with both actives
        foreach ($pledges as $p1) {
            foreach ($pledges as $p2) {
                // Performance check within inner loop
                if (microtime(true) - $start_time > 10 || count($pairings) >= $max_pairings) {
                    break 3; // Break out of all loops
                }
                
                if ($p1['id'] >= $p2['id']) continue; // avoid duplicates
                if (in_array($p1['id'], $used_pledges) || in_array($p2['id'], $used_pledges)) continue;

                // Check exact hour overlap
                if (!isset($availability[$a1['id']], $availability[$a2['id']], $availability[$p1['id']], $availability[$p2['id']])) {
                    $debug[] = "Skipping {$p1['name']} & {$p2['name']} — missing availability data";
                    continue;
                }

                $common_hours = array_intersect(
                    $availability[$a1['id']],
                    $availability[$a2['id']],
                    $availability[$p1['id']],
                    $availability[$p2['id']]
                );

                if (!$common_hours) {
                    $debug[] = "Skipping {$p1['name']} & {$p2['name']} — no overlapping hours";
                    continue;
                }

                // Check if actives already met pledges
                $p1_met = isset($completed_pairs[$a1['id']][$p1['id']]) || isset($completed_pairs[$a2['id']][$p1['id']]);
                $p2_met = isset($completed_pairs[$a1['id']][$p2['id']]) || isset($completed_pairs[$a2['id']][$p2['id']]);
                if ($p1_met && $p2_met) {
                    $debug[] = "Skipping {$p1['name']} & {$p2['name']} — both pledges already met both actives";
                    continue;
                }

                // Accept this pairing
                $pairings[] = [
                    'actives' => $active_group,
                    'pledges' => [$p1, $p2],
                    'hour'    => date('D H:i', min($common_hours))
                ];

                // Mark all participants as used to prevent double-booking
                $used_pledges[] = $p1['id'];
                $used_pledges[] = $p2['id'];
                $used_actives[] = $a1['id'];
                $used_actives[] = $a2['id'];
            }
        }
    }
}

// Add performance debug info
$processing_time = round((microtime(true) - $start_time) * 1000, 2);
$debug[] = "Processing completed in {$processing_time}ms";
$debug[] = "Generated " . count($pairings) . " pairings (limit: $max_pairings)";
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

// Progress bar (21 interviews/week)
$percent = min(100, (count($pairings)/21)*100);

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Generate Pairings</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .navbar-dark { background-color: #000; }

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
    <a href="generate_pairings.php?week=current" 
       class="btn <?= $week === 'current' ? 'btn-primary' : 'btn-outline-primary' ?>">
       Current Week (<?= date('M j', strtotime('monday this week')) ?> - <?= date('M j', strtotime('sunday this week')) ?>)
    </a>
    <a href="generate_pairings.php?week=next" 
       class="btn <?= $week === 'next' ? 'btn-primary' : 'btn-outline-primary' ?>">
       Next Week (<?= date('M j', strtotime('next monday')) ?> - <?= date('M j', strtotime('next sunday')) ?>)
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
            <strong>Hour:</strong> <?=htmlspecialchars($g['hour'])?><br>
            <strong>Actives:</strong> <?=implode(', ', array_column($g['actives'],'name'))?><br>
            <strong>Pledges:</strong> <?=implode(', ', array_column($g['pledges'],'name'))?>
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

<h5>Interview Pacing Goal (21 interviews)</h5>
<div class="progress mb-3">
  <div class="progress-bar" role="progressbar" style="width: <?=$percent?>%;" aria-valuenow="<?=$percent?>" aria-valuemin="0" aria-valuemax="100">
      <?=round($percent)?>%
  </div>
</div>
<p><?=count($pairings)?> / 21 pairings scheduled</p>

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
