<?php
require 'config.php';

session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

$per_person_weekly_cap = max(1, intval($_GET['per_person_cap'] ?? 5));
$global_weekly_cap = max(1, intval($_GET['global_cap'] ?? 50));

$users_stmt = $db->query("SELECT id, name, role, COALESCE(exclude_from_pairings, 0) AS exclude_from_pairings FROM users ORDER BY role, name");
$all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

$all_actives = array_values(array_filter($all_users, fn($u) => $u['role'] === 'active' && empty($u['exclude_from_pairings'])));
$all_pledges = array_values(array_filter($all_users, fn($u) => $u['role'] === 'pledge' && empty($u['exclude_from_pairings'])));
$excluded_users = array_values(array_filter($all_users, fn($u) => !empty($u['exclude_from_pairings'])));

$current_base = strtotime('monday this week');
$next_base = strtotime('next Monday');
$window_start = date('Y-m-d', $current_base);
$window_end = date('Y-m-d', strtotime('+6 days', $next_base));

$availability_stmt = $db->prepare("\n    SELECT user_id, COUNT(*) AS slot_count\n    FROM availabilities\n    WHERE date(slot_start) BETWEEN ? AND ?\n    GROUP BY user_id\n");
$availability_stmt->execute([$window_start, $window_end]);
$slot_counts = [];
foreach ($availability_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $slot_counts[intval($row['user_id'])] = intval($row['slot_count']);
}

$actives = array_values(array_filter($all_actives, fn($a) => (($slot_counts[$a['id']] ?? 0) > 0)));
$pledges = array_values(array_filter($all_pledges, fn($p) => (($slot_counts[$p['id']] ?? 0) > 0)));

$actives_zero_avail = array_values(array_filter($all_actives, fn($a) => (($slot_counts[$a['id']] ?? 0) === 0)));
$pledges_zero_avail = array_values(array_filter($all_pledges, fn($p) => (($slot_counts[$p['id']] ?? 0) === 0)));

$active_ids_set = array_flip(array_column($actives, 'id'));
$pledge_ids_set = array_flip(array_column($pledges, 'id'));

$pair_overlap_stmt = $db->prepare("\n    SELECT\n        a1.user_id AS active_id,\n        a2.user_id AS pledge_id,\n        COUNT(DISTINCT datetime(a1.slot_start)) AS overlap_slots\n    FROM availabilities a1\n    JOIN users ua ON ua.id = a1.user_id\n        AND ua.role = 'active'\n        AND COALESCE(ua.exclude_from_pairings, 0) = 0\n    JOIN availabilities a2 ON datetime(a1.slot_start) = datetime(a2.slot_start)\n    JOIN users up ON up.id = a2.user_id\n        AND up.role = 'pledge'\n        AND COALESCE(up.exclude_from_pairings, 0) = 0\n    WHERE date(a1.slot_start) BETWEEN ? AND ?\n      AND date(a2.slot_start) BETWEEN ? AND ?\n    GROUP BY a1.user_id, a2.user_id\n");
$pair_overlap_stmt->execute([$window_start, $window_end, $window_start, $window_end]);

$overlap_map = [];
$max_overlap = 0;
foreach ($pair_overlap_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $aid = intval($row['active_id']);
    $pid = intval($row['pledge_id']);
    $slots = intval($row['overlap_slots']);

    if (!isset($active_ids_set[$aid]) || !isset($pledge_ids_set[$pid])) {
        continue;
    }

    if (!isset($overlap_map[$aid])) {
        $overlap_map[$aid] = [];
    }
    $overlap_map[$aid][$pid] = $slots;
    if ($slots > $max_overlap) {
        $max_overlap = $slots;
    }
}

$active_stats = [];
$pledge_stats = [];
$impossible_pairs = [];

$meetable_pairs = 0;
$blocked_pairs = 0;
$fragile_pairs = 0;
$healthy_pairs = 0;

foreach ($actives as $a) {
    $partners = 0;
    $fragile = 0;
    $overlap_slots = 0;

    foreach ($pledges as $p) {
        $slots = intval($overlap_map[$a['id']][$p['id']] ?? 0);
        if ($slots > 0) {
            $partners++;
            $overlap_slots += $slots;
            $meetable_pairs++;
            if ($slots === 1) {
                $fragile_pairs++;
                $fragile++;
            } else {
                $healthy_pairs++;
            }
        } else {
            $blocked_pairs++;
            $impossible_pairs[] = [
                'active_name' => $a['name'],
                'pledge_name' => $p['name']
            ];
        }
    }

    $active_stats[$a['id']] = [
        'partners' => $partners,
        'fragile' => $fragile,
        'overlap_slots' => $overlap_slots,
        'availability_slots' => intval($slot_counts[$a['id']] ?? 0)
    ];
}

foreach ($pledges as $p) {
    $partners = 0;
    $fragile = 0;
    $overlap_slots = 0;

    foreach ($actives as $a) {
        $slots = intval($overlap_map[$a['id']][$p['id']] ?? 0);
        if ($slots > 0) {
            $partners++;
            $overlap_slots += $slots;
            if ($slots === 1) {
                $fragile++;
            }
        }
    }

    $pledge_stats[$p['id']] = [
        'partners' => $partners,
        'fragile' => $fragile,
        'overlap_slots' => $overlap_slots,
        'availability_slots' => intval($slot_counts[$p['id']] ?? 0)
    ];
}

$total_pairs = count($actives) * count($pledges);
$coverage_percent = $total_pairs > 0 ? round(($meetable_pairs / $total_pairs) * 100, 1) : 0;

$max_active_partners = 0;
foreach ($active_stats as $s) {
    $max_active_partners = max($max_active_partners, intval($s['partners']));
}
$max_pledge_partners = 0;
foreach ($pledge_stats as $s) {
    $max_pledge_partners = max($max_pledge_partners, intval($s['partners']));
}

$weeks_by_active_capacity = $max_active_partners > 0 ? (int)ceil($max_active_partners / $per_person_weekly_cap) : 0;
$weeks_by_pledge_capacity = $max_pledge_partners > 0 ? (int)ceil($max_pledge_partners / $per_person_weekly_cap) : 0;
$weeks_by_global_capacity = $meetable_pairs > 0 ? (int)ceil($meetable_pairs / $global_weekly_cap) : 0;
$weeks_needed = max($weeks_by_active_capacity, $weeks_by_pledge_capacity, $weeks_by_global_capacity);

$actives_with_path = count(array_filter($active_stats, fn($s) => intval($s['partners']) > 0));
$pledges_with_path = count(array_filter($pledge_stats, fn($s) => intval($s['partners']) > 0));
$members_total = count($actives) + count($pledges);
$members_with_any_path = $actives_with_path + $pledges_with_path;
$members_without_path = $members_total - $members_with_any_path;

$bottlenecks = [];
foreach ($actives as $a) {
    $s = $active_stats[$a['id']];
    $bottlenecks[] = [
        'name' => $a['name'],
        'role' => 'Active',
        'partners' => intval($s['partners']),
        'max_partners' => count($pledges),
        'overlap_slots' => intval($s['overlap_slots'])
    ];
}
foreach ($pledges as $p) {
    $s = $pledge_stats[$p['id']];
    $bottlenecks[] = [
        'name' => $p['name'],
        'role' => 'Pledge',
        'partners' => intval($s['partners']),
        'max_partners' => count($actives),
        'overlap_slots' => intval($s['overlap_slots'])
    ];
}

usort($bottlenecks, function($a, $b) {
    if ($a['partners'] !== $b['partners']) {
        return $a['partners'] <=> $b['partners'];
    }
    if ($a['overlap_slots'] !== $b['overlap_slots']) {
        return $a['overlap_slots'] <=> $b['overlap_slots'];
    }
    return strcmp($a['name'], $b['name']);
});
$top_bottlenecks = array_slice($bottlenecks, 0, 10);

$chart_labels = array_map(fn($m) => $m['name'] . ' (' . $m['role'] . ')', $top_bottlenecks);
$chart_values = array_map(fn($m) => intval($m['partners']), $top_bottlenecks);
$chart_max_values = array_map(fn($m) => intval($m['max_partners']), $top_bottlenecks);

$completed_pairs_stmt = $db->query("SELECT DISTINCT active_id, pledge_id FROM completed_interviews");
$completed_pair_map = [];
foreach ($completed_pairs_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $completed_pair_map[intval($row['active_id']) . '_' . intval($row['pledge_id'])] = true;
}

$blocked_by_active = [];
foreach ($impossible_pairs as $pair) {
  $blocked_by_active[$pair['active_name']][] = $pair['pledge_name'];
}
ksort($blocked_by_active);

function matrix_color($slots, $max_overlap) {
    if ($slots <= 0) {
        return '#f8d7da';
    }
    $ratio = $max_overlap > 0 ? ($slots / $max_overlap) : 1;
    $hue = 45 + (int)round(90 * $ratio);
    $lightness = 93 - (int)round(40 * $ratio);
    return 'hsl(' . $hue . ', 82%, ' . $lightness . '%)';
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Coverage Report - Interview Scheduler</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .navbar-dark { background-color: #000 !important; }
    .kpi-card .card-body { min-height: 140px; }
    .matrix-wrap {
      overflow: auto;
      max-height: 70vh;
      border: 1px solid var(--bs-border-color);
      border-radius: 0.375rem;
      background: #fff;
    }
    .coverage-matrix {
      border-collapse: separate;
      border-spacing: 0;
      min-width: 820px;
    }
    .coverage-matrix th,
    .coverage-matrix td {
      border: 1px solid #e5e7eb;
      text-align: center;
      padding: 0.38rem;
      white-space: nowrap;
      font-size: 0.80rem;
    }
    .coverage-matrix thead th {
      position: sticky;
      top: 0;
      background: #0f172a;
      color: #fff;
      z-index: 3;
      writing-mode: vertical-rl;
      text-orientation: mixed;
      transform: rotate(180deg);
      min-width: 48px;
      max-width: 48px;
      height: 175px;
      font-size: 0.74rem;
      letter-spacing: 0.02em;
    }
    .coverage-matrix thead th.row-label-head {
      writing-mode: horizontal-tb;
      transform: none;
      min-width: 240px;
      max-width: 240px;
      height: auto;
      z-index: 4;
      text-align: left;
      padding-left: 0.7rem;
      background: #020617;
    }
    .coverage-matrix tbody th {
      position: sticky;
      left: 0;
      z-index: 2;
      background: #f8fafc;
      min-width: 240px;
      max-width: 240px;
      text-align: left;
    }
    .matrix-cell.blocked { color: #9f1239; font-weight: 700; }
    .matrix-cell.fragile { color: #92400e; font-weight: 700; }
    .matrix-cell.completed {
      background-color: #166534 !important;
      color: #fff;
      font-weight: 700;
    }
    .legend-dot {
      display: inline-block;
      width: 12px;
      height: 12px;
      border-radius: 2px;
      margin-right: 6px;
      vertical-align: middle;
    }
    .jump a {
      text-decoration: none;
      margin-right: 8px;
    }
  </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand text-white" href="index.php">Interview Scheduler</a>
    <div>
      <a class="btn btn-outline-light btn-sm" href="index.php">Home</a>
      <a class="btn btn-outline-light btn-sm" href="admin.php">Admin</a>
      <a class="btn btn-outline-light btn-sm" href="manage_users.php">Manage Users</a>
      <span class="text-white me-2">
        <i class="fas fa-user-shield"></i> <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?>
      </span>
      <a class="btn btn-danger btn-sm" href="logout.php">
        <i class="fas fa-sign-out-alt"></i> Logout
      </a>
    </div>
  </div>
</nav>

<div class="container-fluid py-4 px-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Coverage Command Center</h2>
    <a href="admin.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Admin</a>
  </div>

  <div class="alert alert-primary mb-4">
    <p class="mb-2"><strong>Window:</strong> <?= date('M j', $current_base) ?> to <?= date('M j', strtotime('+6 days', $next_base)) ?>.</p>
    <p class="mb-2">This report is built around a matrix and charts so you can spot blocked pairs, fragile overlap, and bottlenecks fast.</p>
    <div class="jump small">
      Jump:
      <a href="#stats">Stats</a>
      <a href="#charts">Charts</a>
      <a href="#matrix">Matrix</a>
      <a href="#blocked">Blocked Pairs</a>
    </div>
  </div>

  <div id="stats" class="row g-3 mb-4">
    <div class="col-lg-3 col-md-6">
      <div class="card text-bg-primary kpi-card">
        <div class="card-body">
          <h6 class="mb-1">Coverage</h6>
          <div class="display-6"><?= $coverage_percent ?>%</div>
          <small><?= intval($meetable_pairs) ?> / <?= intval($total_pairs) ?> pairs meetable</small>
        </div>
      </div>
    </div>
    <div class="col-lg-3 col-md-6">
      <div class="card text-bg-danger kpi-card">
        <div class="card-body">
          <h6 class="mb-1">Blocked Pairs</h6>
          <div class="display-6"><?= intval($blocked_pairs) ?></div>
          <small>Zero overlap slots</small>
        </div>
      </div>
    </div>
    <div class="col-lg-3 col-md-6">
      <div class="card text-bg-warning kpi-card">
        <div class="card-body">
          <h6 class="mb-1">Fragile Pairs</h6>
          <div class="display-6"><?= intval($fragile_pairs) ?></div>
          <small>Only one shared slot</small>
        </div>
      </div>
    </div>
    <div class="col-lg-3 col-md-6">
      <div class="card text-bg-success kpi-card">
        <div class="card-body">
          <h6 class="mb-1">Weeks Needed</h6>
          <div class="display-6"><?= $meetable_pairs > 0 ? intval($weeks_needed) : 'N/A' ?></div>
          <small>For all pairs that can meet</small>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header">Capacity Controls</div>
    <div class="card-body">
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-4">
          <label class="form-label">Interviews per person per week</label>
          <input class="form-control" type="number" min="1" name="per_person_cap" value="<?= intval($per_person_weekly_cap) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Global interviews per week</label>
          <input class="form-control" type="number" min="1" name="global_cap" value="<?= intval($global_weekly_cap) ?>">
        </div>
        <div class="col-md-4">
          <button class="btn btn-primary w-100" type="submit">Recalculate</button>
        </div>
      </form>
      <hr>
      <p class="mb-1"><strong>Members with any path:</strong> <?= intval($members_with_any_path) ?> of <?= intval($members_total) ?></p>
      <small class="text-muted">A member has a path if they can meet at least one person on the opposite side. It does not mean they can meet everyone.</small>
    </div>
  </div>

  <div id="charts" class="row g-3 mb-4">
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header">Pair Health Mix</div>
        <div class="card-body">
          <canvas id="pairMixChart" height="260"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header">Top Bottlenecks (Fewest Opposite-Side Matches)</div>
        <div class="card-body">
          <canvas id="bottleneckChart" height="260"></canvas>
        </div>
      </div>
    </div>
  </div>

  <div id="matrix" class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Active x Pledge Overlap Matrix</span>
      <small class="text-muted"><?= count($actives) ?> actives x <?= count($pledges) ?> pledges</small>
    </div>
    <div class="card-body">
      <div class="mb-2 small">
        <span class="me-3"><span class="legend-dot" style="background:#166534"></span>Completed Pair (check mark)</span>
        <span class="me-3"><span class="legend-dot" style="background:#f8d7da"></span>Blocked (x)</span>
        <span class="me-3"><span class="legend-dot" style="background:#fff3cd"></span>Fragile (1)</span>
        <span><span class="legend-dot" style="background:hsl(120,75%,68%)"></span>Healthy (2+)</span>
      </div>

      <?php if (count($actives) === 0 || count($pledges) === 0): ?>
        <div class="alert alert-warning mb-0">Not enough users with availability to draw the matrix.</div>
      <?php else: ?>
        <div class="matrix-wrap">
          <table class="table coverage-matrix mb-0">
            <thead>
              <tr>
                <th class="row-label-head">Active \ Pledge</th>
                <?php foreach ($pledges as $p): ?>
                  <th title="<?= h($p['name']) ?>"><?= h($p['name']) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($actives as $a): ?>
                <?php $as = $active_stats[$a['id']]; ?>
                <tr>
                  <th>
                    <div><strong><?= h($a['name']) ?></strong></div>
                    <small class="text-muted"><?= intval($as['partners']) ?> possible pledge partners, <?= intval($as['availability_slots']) ?> availability slots</small>
                  </th>
                  <?php foreach ($pledges as $p): ?>
                    <?php
                      $slots = intval($overlap_map[$a['id']][$p['id']] ?? 0);
                      $pair_key = intval($a['id']) . '_' . intval($p['id']);
                      $is_completed_pair = isset($completed_pair_map[$pair_key]);
                      $cls = 'matrix-cell';
                      if ($is_completed_pair) {
                          $cls .= ' completed';
                      } elseif ($slots === 0) {
                          $cls .= ' blocked';
                      } elseif ($slots === 1) {
                          $cls .= ' fragile';
                      }
                      $bg = $is_completed_pair ? '#166534' : matrix_color($slots, $max_overlap);
                    ?>
                    <td class="<?= $cls ?>" style="background-color: <?= h($bg) ?>" title="<?= h($a['name']) ?> x <?= h($p['name']) ?>: <?= $is_completed_pair ? 'completed interview pair' : intval($slots) . ' overlapping slots' ?>">
                      <?php if ($is_completed_pair): ?>
                        &#10003;
                      <?php else: ?>
                        <?= $slots > 0 ? intval($slots) : 'x' ?>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header">Top Bottlenecks Table</div>
        <div class="card-body p-0">
          <?php if (count($top_bottlenecks) === 0): ?>
            <div class="p-3 text-muted">No bottleneck data available.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-striped mb-0">
                <thead>
                  <tr>
                    <th>Member</th>
                    <th>Role</th>
                    <th>Possible Match Type</th>
                    <th>Possible Matches</th>
                    <th>Overlap Slots</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($top_bottlenecks as $m): ?>
                    <tr>
                      <td><?= h($m['name']) ?></td>
                      <td><?= h($m['role']) ?></td>
                      <td>
                        <?= $m['role'] === 'Active' ? 'Pledge partners' : 'Active partners' ?>
                      </td>
                      <td>
                        <?= intval($m['partners']) ?> / <?= intval($m['max_partners']) ?>
                        <?php if (intval($m['max_partners']) > 0): ?>
                          (<?= round((intval($m['partners']) / intval($m['max_partners'])) * 100, 1) ?>%)
                        <?php endif; ?>
                      </td>
                      <td><?= intval($m['overlap_slots']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header">Data Exclusions</div>
        <div class="card-body">
          <p class="mb-1"><strong>Zero-availability actives hidden:</strong> <?= count($actives_zero_avail) ?></p>
          <p class="mb-1"><strong>Zero-availability pledges hidden:</strong> <?= count($pledges_zero_avail) ?></p>
          <p class="mb-1"><strong>Excluded-from-pairings users hidden:</strong> <?= count($excluded_users) ?></p>
          <small class="text-muted">Hidden users are not included in stats, charts, matrix, or blocked pair calculations.</small>
        </div>
      </div>
    </div>
  </div>

  <div id="blocked" class="card mb-4">
    <div class="card-header">Blocked Pair List</div>
    <div class="card-body">
      <?php if (count($impossible_pairs) === 0): ?>
        <p class="text-success mb-0">All included pairs have at least one overlap slot.</p>
      <?php else: ?>
        <p class="mb-2"><?= count($impossible_pairs) ?> blocked pairs found.</p>
        <div class="table-responsive" style="max-height: 280px; overflow: auto;">
          <table class="table table-sm table-bordered mb-0">
            <thead>
              <tr>
                <th>Active</th>
                <th>Blocked Pledges</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($blocked_by_active as $active_name => $pledge_list): ?>
                <tr>
                  <td><?= h($active_name) ?></td>
                  <td>
                    <?php foreach ($pledge_list as $idx => $pledge_name): ?>
                      <span class="badge text-bg-secondary mb-1"><?= h($pledge_name) ?></span><?= $idx < count($pledge_list) - 1 ? ' ' : '' ?>
                    <?php endforeach; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const pairMixCtx = document.getElementById('pairMixChart');
if (pairMixCtx) {
  new Chart(pairMixCtx, {
    type: 'doughnut',
    data: {
      labels: ['Healthy (2+ slots)', 'Fragile (1 slot)', 'Blocked (0 slots)'],
      datasets: [{
        data: [<?= intval($healthy_pairs) ?>, <?= intval($fragile_pairs) ?>, <?= intval($blocked_pairs) ?>],
        backgroundColor: ['#22c55e', '#f59e0b', '#f43f5e'],
        borderWidth: 0
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom' }
      }
    }
  });
}

const bottleneckCtx = document.getElementById('bottleneckChart');
if (bottleneckCtx) {
  const labels = <?= json_encode($chart_labels) ?>;
  const partners = <?= json_encode($chart_values) ?>;
  const maxPartners = <?= json_encode($chart_max_values) ?>;

  new Chart(bottleneckCtx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: 'Possible Opposite-Side Partners',
          data: partners,
          backgroundColor: '#2563eb'
        },
        {
          label: 'Total Available Opposite-Side Members',
          data: maxPartners,
          backgroundColor: '#94a3b8'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      indexAxis: 'y',
      scales: {
        x: {
          beginAtZero: true,
          ticks: { precision: 0 }
        }
      },
      plugins: {
        legend: { position: 'bottom' }
      }
    }
  });
}
</script>
</body>
</html>