<?php
require_once '../includes/auth.php';
require_once '../config/db_config.php';
requireRole('presiding_officer');

$officer_id = $_SESSION['user_id'];

// Get elections assigned to this officer
$elections = $pdo->prepare("
    SELECT e.* FROM elections e
    JOIN election_officers eo ON e.id = eo.election_id
    WHERE eo.officer_id = ? AND e.status IN ('upcoming', 'active')
    ORDER BY e.start_date
");
$elections->execute([$officer_id]);
$elections = $elections->fetchAll();

// Get students for candidate selection
$students = $pdo->query("SELECT id, full_name, username FROM users WHERE role = 'student' AND is_verified = 1 ORDER BY full_name")->fetchAll();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_candidate'])) {
        $stmt = $pdo->prepare("INSERT INTO candidates (election_id, student_id, manifesto, symbol) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_POST['election_id'], $_POST['student_id'], $_POST['manifesto'], $_POST['symbol']]);
        $success = "Candidate added successfully";
    } elseif (isset($_POST['approve_candidate'])) {
        $stmt = $pdo->prepare("UPDATE candidates SET status = 'approved' WHERE id = ?");
        $stmt->execute([$_POST['candidate_id']]);
        $success = "Candidate approved successfully";
    } elseif (isset($_POST['reject_candidate'])) {
        $stmt = $pdo->prepare("UPDATE candidates SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$_POST['candidate_id']]);
        $success = "Candidate rejected";
    }
}

// Get candidates for selected election
$selected_election = $_GET['election_id'] ?? ($elections[0]['id'] ?? null);
$candidates = [];
if ($selected_election) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name, u.username, u.email 
        FROM candidates c
        JOIN users u ON c.student_id = u.id
        WHERE c.election_id = ?
        ORDER BY c.status, c.id
    ");
    $stmt->execute([$selected_election]);
    $candidates = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Candidates - Presiding Officer</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .btn-primary { background: #667eea; color: white; }
        .btn-success { background: #48bb78; color: white; }
        .btn-danger { background: #f56565; color: white; }
        .btn-warning { background: #ed8936; color: white; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 30px; border-radius: 10px; max-width: 500px; width: 90%; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        table { width: 100%; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .status-approved { background: #c6f6d5; color: #22543d; }
        .status-pending { background: #feebc8; color: #975a16; }
        .status-rejected { background: #fed7d7; color: #c53030; }
        .filter-group { margin-bottom: 20px; }
        .filter-group select { padding: 8px; min-width: 250px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>👥 Manage Candidates</h1>
        <a href="../dashboard.php" style="color: white; text-decoration: none;">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if (isset($success)): ?>
            <div style="background: #c6f6d5; padding: 10px; border-radius: 5px; margin-bottom: 20px;"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="filter-group">
            <label>Select Election</label>
            <select onchange="window.location.href='?election_id='+this.value">
                <option value="">Select Election</option>
                <?php foreach ($elections as $election): ?>
                    <option value="<?php echo $election['id']; ?>" <?php echo $selected_election == $election['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($election['election_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if ($selected_election): ?>
            <button class="btn btn-primary add-btn" onclick="openModal()">+ Add Candidate</button>
            
            <table>
                <thead>
                    <tr>
                        <th>Candidate Name</th>
                        <th>Username</th>
                        <th>Symbol</th>
                        <th>Manifesto</th>
                        <th>Status</th>
                        <th>Votes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($candidates as $candidate): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($candidate['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($candidate['username']); ?></td>
                            <td><?php echo htmlspecialchars($candidate['symbol'] ?: '🗳️'); ?></td>
                            <td><?php echo htmlspecialchars(substr($candidate['manifesto'], 0, 50)); ?></td>
                            <td><span class="status-badge status-<?php echo $candidate['status']; ?>"><?php echo ucfirst($candidate['status']); ?></span></td>
                            <td><?php echo $candidate['vote_count']; ?></td>
                            <td>
                                <?php if ($candidate['status'] == 'pending'): ?>
                                    <form method="POST" style="display: inline-block;">
                                        <input type="hidden" name="candidate_id" value="<?php echo $candidate['id']; ?>">
                                        <button type="submit" name="approve_candidate" class="btn btn-success">Approve</button>
                                    </form>
                                    <form method="POST" style="display: inline-block;">
                                        <input type="hidden" name="candidate_id" value="<?php echo $candidate['id']; ?>">
                                        <button type="submit" name="reject_candidate" class="btn btn-danger">Reject</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <div id="candidateModal" class="modal">
        <div class="modal-content">
            <h2>Add Candidate</h2>
            <form method="POST">
                <input type="hidden" name="election_id" value="<?php echo $selected_election; ?>">
                <div class="form-group">
                    <label>Select Student</label>
                    <select name="student_id" required>
                        <option value="">Select Student</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['full_name'] . ' (' . $student['username'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Symbol/Icon</label><input type="text" name="symbol" placeholder="🗳️"></div>
                <div class="form-group"><label>Manifesto</label><textarea name="manifesto" rows="4" placeholder="Describe their goals and promises..."></textarea></div>
                <button type="submit" name="add_candidate" class="btn btn-primary">Add Candidate</button>
                <button type="button" onclick="closeModal()" class="btn">Cancel</button>
            </form>
        </div>
    </div>
    
    <script>
        function openModal() { document.getElementById('candidateModal').style.display = 'flex'; }
        function closeModal() { document.getElementById('candidateModal').style.display = 'none'; }
    </script>
</body>
</html>