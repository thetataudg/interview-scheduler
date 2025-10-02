<?php require 'config.php'; ?>

<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Interview Scheduler</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .navbar-dark { background-color: #000; }
  </style>
</head>
<body>
<nav class="navbar navbar-dark">
  <div class="container">
    <a class="navbar-brand text-white" href="index.php">Interview Scheduler</a>
    <div>
      <a class="btn btn-outline-light btn-sm" href="admin.php">Admin</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <h1>Who are you?</h1>
  <p>Select whether you're an <strong>Active</strong> or a <strong>PNM</strong>, then choose your name.</p>

  <div class="row">
    <div class="col-md-6">
      <h3>Actives</h3>
      <ul class="list-group">
        <?php
        $stmt = $db->prepare("SELECT * FROM users WHERE role='active' ORDER BY name");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u): ?>
          <li class="list-group-item">
            <a href="availability.php?user_id=<?=$u['id']?>"><?=h($u['name'])?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="col-md-6">
      <h3>PNMs</h3>
      <ul class="list-group">
        <?php
        $stmt = $db->prepare("SELECT * FROM users WHERE role='pledge' ORDER BY name");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u): ?>
          <li class="list-group-item">
            <a href="availability.php?user_id=<?=$u['id']?>"><?=h($u['name'])?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>
</body>
</html>
