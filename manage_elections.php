<?php
require_once '../includes/auth.php';
require_once '../config/db_config.php';
requireRole('presiding_officer');

$officer_id = $_SESSION['user_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_election'])) {
    $stmt = $pdo->prepare("
        INSERT INTO elections (election_name, election_type, description, start_date, end_date, created_by, status)
        VALUES (?, ?, ?, ?, ?, ?, 'upcoming')
    ");
    $stmt->execute([
        $_POST['election_name'],
        $_POST['election_type'],
        $_POST['description'],
        $_POST['start_date'],
        $_POST['end_date'],
        $officer_id
    ]);
    
    $election_id = $pdo->lastInsertId();
    
    // Assign this officer to the election
    $stmt = $pdo->prepare("INSERT INTO election_officers (election_id, officer_id) VALUES (?, ?)");
    $stmt->execute([$election_id, $officer_id]);
    
    $success = "Election created successfully!";
}

// Get elections created by or assigned to this officer
$elections = $pdo->prepare("
    SELECT DISTINCT e.* FROM elections e
    LEFT JOIN election_officers eo ON e.id = eo.election_id
    WHERE e.created_by = ? OR eo.officer_id = ?
    ORDER BY e.created_at DESC
");
$elections->execute([$officer_id, $officer_id]);
$elections = $elections->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Elections - Presiding Officer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-primary { background: #667eea; color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; max-width: 500px; width: 90%; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .election-card {
            background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .status-upcoming { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 5px 10px; border-radius: 3px; display: inline-block; }
        .status-active { background: #c6f6d5; border-left: 4px solid #48bb78; padding: 5px 10px; border-radius: 3px; display: inline-block; }
        .status-completed { background: #e2e8f0; border-left: 4px solid #718096; padding: 5px 10px; border-radius: 3px; display: inline-block; }
        .add-btn { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🗳️ Manage Elections</h1>
        <a href="../dashboard.php" style="color: white; text-decoration: none;">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if (isset($success)): ?>
            <div style="background: #c6f6d5; padding: 10px; border-radius: 5px; margin-bottom: 20px;"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <button class="btn btn-primary add-btn" onclick="openModal()">+ Create New Election</button>
        
        <?php foreach ($elections as $election): ?>
            <div class="election-card">
                <h2><?php echo htmlspecialchars($election['election_name']); ?></h2>
                <p><?php echo htmlspecialchars($election['description']); ?></p>
                <div style="margin: 10px 0;">
                    <span class="status-<?php echo $election['status']; ?>"><?php echo ucfirst($election['status']); ?></span>
                </div>
                <p><strong>Type:</strong> <?php echo htmlspecialchars($election['election_type']); ?></p>
                <p><strong>Start:</strong> <?php echo date('F j, Y g:i A', strtotime($election['start_date'])); ?></p>
                <p><strong>End:</strong> <?php echo date('F j, Y g:i A', strtotime($election['end_date'])); ?></p>
                <a href="manage_candidates.php?election_id=<?php echo $election['id']; ?>" class="btn btn-primary" style="display: inline-block; margin-top: 10px;">Manage Candidates</a>
                <a href="view_results.php?election_id=<?php echo $election['id']; ?>" class="btn btn-primary" style="display: inline-block; margin-top: 10px;">View Results</a>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($elections)): ?>
            <div style="text-align: center; padding: 50px; background: white; border-radius: 10px;">
                No elections found. Click "Create New Election" to get started.
            </div>
        <?php endif; ?>
    </div>
    
    <div id="electionModal" class="modal">
        <div class="modal-content">
            <h2>Create New Election</h2>
            <form method="POST">
                <div class="form-group"><label>Election Name</label><input type="text" name="election_name" required></div>
                <div class="form-group">
                    <label>Election Type</label>
                    <select name="election_type" required>
                        <option value="President">President</option>
                        <option value="Vice President">Vice President</option>
                        <option value="Secretary">Secretary</option>
                        <option value="Treasurer">Treasurer</option>
                        <option value="Class Representative">Class Representative</option>
                    </select>
                </div>
                <div class="form-group"><label>Description</label><textarea name="description" rows="3"></textarea></div>
                <div class="form-group"><label>Start Date & Time</label><input type="datetime-local" name="start_date" required></div>
                <div class="form-group"><label>End Date & Time</label><input type="datetime-local" name="end_date" required></div>
                <button type="submit" name="create_election" class="btn btn-primary">Create Election</button>
                <button type="button" onclick="closeModal()" class="btn">Cancel</button>
            </form>
        </div>
    </div>
    
    <script>
        function openModal() { document.getElementById('electionModal').style.display = 'flex'; }
        function closeModal() { document.getElementById('electionModal').style.display = 'none'; }
    </script>
</body>
</html>