<?php
require_once '../includes/auth.php';
require_once '../config/db_config.php';
require_once '../includes/functions.php';
requireRole('presiding_officer');

$officer_id = $_SESSION['user_id'];
$election_id = $_GET['election_id'] ?? null;

// Get elections assigned to this officer
$elections = $pdo->prepare("
    SELECT DISTINCT e.* FROM elections e
    JOIN election_officers eo ON e.id = eo.election_id
    WHERE eo.officer_id = ?
    ORDER BY e.created_at DESC
");
$elections->execute([$officer_id]);
$elections = $elections->fetchAll();

$results = [];
$total_votes = 0;
$election_info = null;
$is_completed = false;

if ($election_id) {
    $stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ?");
    $stmt->execute([$election_id]);
    $election_info = $stmt->fetch();
    
    $results = getElectionResults($pdo, $election_id);
    $total_votes = array_sum(array_column($results, 'vote_count'));
    $is_completed = ($election_info['status'] == 'completed');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Results - Presiding Officer</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .filter-group { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .filter-group label { display: block; margin-bottom: 10px; font-weight: bold; }
        .filter-group select { padding: 10px; min-width: 250px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px; }
        .results-card { background: white; border-radius: 10px; padding: 25px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .winner-card { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 3px solid #f59e0b; }
        .active-card { border: 2px solid #667eea; }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        .status-completed { background: #48bb78; color: white; }
        .status-active { background: #ed8936; color: white; }
        .status-upcoming { background: #4299e1; color: white; }
        .live-badge { 
            background: #f56565; color: white; padding: 5px 10px; border-radius: 5px; 
            display: inline-block; animation: pulse 1s infinite; margin-left: 10px;
        }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
        
        .candidate-list { margin-top: 20px; }
        .candidate-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: background 0.3s;
        }
        .candidate-item:hover { background: #f9f9f9; }
        .candidate-item.winner-item { background: #fef3c7; border-left: 4px solid #f59e0b; }
        .candidate-item.leading-item { background: #e0f2fe; border-left: 4px solid #38bdf8; }
        .candidate-name { font-size: 18px; font-weight: bold; }
        .candidate-votes { font-size: 24px; font-weight: bold; color: #667eea; }
        .vote-bar {
            background: #e0e0e0; border-radius: 10px; height: 30px; overflow: hidden; margin: 10px 0;
        }
        .vote-fill {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            height: 100%; display: flex; align-items: center; justify-content: flex-end;
            padding-right: 10px; color: white; font-weight: bold; transition: width 0.5s ease;
        }
        .chart-container { max-width: 400px; margin: 20px auto; }
        .total-votes { text-align: center; font-size: 18px; margin: 20px 0; padding: 15px; background: #f0f0f0; border-radius: 10px; }
        .winner-announcement {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .winner-announcement h2 { color: #d97706; font-size: 28px; margin-bottom: 10px; }
        .winner-announcement .winner-name { font-size: 24px; font-weight: bold; color: #b45309; }
        .leading-announcement {
            text-align: center;
            padding: 20px;
            background: #e0f2fe;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .leading-announcement h2 { color: #0284c7; font-size: 28px; margin-bottom: 10px; }
        .crown { font-size: 32px; }
        .refresh-info {
            text-align: center;
            font-size: 12px;
            color: #666;
            margin-top: 10px;
        }
        @media (max-width: 768px) {
            .candidate-item { flex-direction: column; text-align: center; gap: 10px; }
            .candidate-votes { text-align: center; }
            .vote-bar { width: 100%; }
            .winner-announcement h2, .leading-announcement h2 { font-size: 22px; }
            .winner-name { font-size: 18px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Live Election Results</h1>
        <a href="../dashboard.php" style="color: white; text-decoration: none;">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <div class="filter-group">
            <label>📋 Select Election</label>
            <select id="electionSelect" onchange="window.location.href='?election_id='+this.value">
                <option value="">-- Select an election --</option>
                <?php foreach ($elections as $election): ?>
                    <option value="<?php echo $election['id']; ?>" <?php echo $election_id == $election['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($election['election_name']); ?> 
                        (<?php echo ucfirst($election['status']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if ($election_info && $results): 
            $max_votes = !empty($results) ? max(array_column($results, 'vote_count')) : 0;
            $winners = [];
            $leading = [];
            
            foreach ($results as $candidate) {
                if ($candidate['vote_count'] == $max_votes && $max_votes > 0) {
                    if ($is_completed) {
                        $winners[] = $candidate;
                    } else {
                        $leading[] = $candidate;
                    }
                }
            }
        ?>
            <!-- Winner/Leading Announcement -->
            <?php if ($is_completed && !empty($winners)): ?>
                <div class="winner-announcement">
                    <div class="crown">🏆 👑 🏆</div>
                    <h2>WINNER ANNOUNCEMENT</h2>
                    <div class="winner-name">
                        <?php echo htmlspecialchars($winners[0]['candidate_name']); ?>
                    </div>
                    <p>with <strong><?php echo $winners[0]['vote_count']; ?></strong> votes 
                       (<?php echo $total_votes > 0 ? round(($winners[0]['vote_count'] / $total_votes) * 100, 1) : 0; ?>% of total votes)</p>
                    <div class="crown">🏆 👑 🏆</div>
                </div>
            <?php elseif (!$is_completed && !empty($leading)): ?>
                <div class="leading-announcement">
                    <h2>📈 CURRENTLY LEADING</h2>
                    <div class="winner-name">
                        <?php echo htmlspecialchars($leading[0]['candidate_name']); ?>
                    </div>
                    <p>with <strong><?php echo $leading[0]['vote_count']; ?></strong> votes 
                       (<?php echo $total_votes > 0 ? round(($leading[0]['vote_count'] / $total_votes) * 100, 1) : 0; ?>% of total votes)</p>
                    <p style="font-size: 14px; margin-top: 10px;">⏳ Election still in progress - Final results will be announced after voting ends</p>
                </div>
            <?php endif; ?>
            
            <div class="results-card <?php echo $is_completed ? 'winner-card' : 'active-card'; ?>">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <h2><?php echo htmlspecialchars($election_info['election_name']); ?></h2>
                    <div>
                        <span class="status-badge status-<?php echo $election_info['status']; ?>">
                            <?php echo strtoupper($election_info['status']); ?>
                        </span>
                        <?php if ($election_info['status'] == 'active'): ?>
                            <span class="live-badge">🔴 LIVE</span>
                        <?php endif; ?>
                    </div>
                </div>
                <p style="margin-top: 10px;"><?php echo htmlspecialchars($election_info['description']); ?></p>
                <p><strong>📅 Voting Period:</strong> <?php echo date('F j, Y', strtotime($election_info['start_date'])); ?> - <?php echo date('F j, Y', strtotime($election_info['end_date'])); ?></p>
                
                <div class="total-votes">
                    📊 Total Votes Cast: <strong><?php echo $total_votes; ?></strong>
                </div>
                
                <div class="chart-container">
                    <canvas id="resultsChart"></canvas>
                </div>
                
                <div class="candidate-list">
                    <h3>📋 Candidate Results</h3>
                    <?php foreach ($results as $candidate): 
                        $percentage = $total_votes > 0 ? round(($candidate['vote_count'] / $total_votes) * 100, 1) : 0;
                        $is_winner = ($is_completed && $candidate['vote_count'] == $max_votes && $max_votes > 0);
                        $is_leading = (!$is_completed && $candidate['vote_count'] == $max_votes && $max_votes > 0);
                    ?>
                        <div class="candidate-item <?php echo $is_winner ? 'winner-item' : ($is_leading ? 'leading-item' : ''); ?>">
                            <div style="flex: 2;">
                                <div class="candidate-name">
                                    <?php echo htmlspecialchars($candidate['candidate_name']); ?>
                                    <?php if ($is_winner): ?> 
                                        <span style="background: #f59e0b; color: white; padding: 2px 8px; border-radius: 20px; font-size: 11px; margin-left: 10px;">🏆 WINNER</span>
                                    <?php elseif ($is_leading): ?> 
                                        <span style="background: #38bdf8; color: white; padding: 2px 8px; border-radius: 20px; font-size: 11px; margin-left: 10px;">📈 LEADING</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 14px; color: #666;"><?php echo htmlspecialchars($candidate['symbol'] ?: '🗳️'); ?></div>
                            </div>
                            <div style="flex: 3;">
                                <div class="vote-bar">
                                    <div class="vote-fill" style="width: <?php echo $percentage; ?>%">
                                        <?php if ($percentage > 10): ?><?php echo $percentage; ?>%<?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="candidate-votes" style="flex: 1; text-align: right;">
                                <?php echo $candidate['vote_count']; ?> votes
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (!$is_completed): ?>
                    <div style="margin-top: 20px; padding: 15px; background: #f0f7ff; border-radius: 10px; text-align: center;">
                        ⏳ <strong>Election In Progress</strong><br>
                        Results are updated in real-time. Final winners will be announced when the election ends.
                    </div>
                    <div class="refresh-info">
                                        🔄 Page auto-refreshes every 10 seconds for live updates
                    </div>
                <?php else: ?>
                    <div style="margin-top: 20px; padding: 15px; background: #f0fdf4; border-radius: 10px; text-align: center;">
                        ✅ <strong>Election Completed</strong><br>
                        Final results are displayed above. Congratulations to the winner! 🎉
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($election_id): ?>
            <div style="text-align: center; padding: 50px; background: white; border-radius: 10px;">
                <p>❌ No candidates found for this election.</p>
                <p style="margin-top: 10px; color: #666;">Please add candidates and approve them to see results.</p>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 50px; background: white; border-radius: 10px;">
                <p>📭 Please select an election to view results.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        <?php if ($results): ?>
        const ctx = document.getElementById('resultsChart').getContext('2d');
        const candidateNames = <?php echo json_encode(array_column($results, 'candidate_name')); ?>;
        const voteCounts = <?php echo json_encode(array_column($results, 'vote_count')); ?>;
        
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: candidateNames,
                datasets: [{
                    data: voteCounts,
                    backgroundColor: ['#667eea', '#48bb78', '#ed8936', '#f56565', '#4299e1', '#9f7aea', '#fbbf24', '#38bdf8', '#a855f7', '#ec4899'],
                    borderWidth: 1,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { 
                        position: 'bottom',
                        labels: { font: { size: 12 } }
                    },
                    title: { 
                        display: true, 
                        text: '<?php echo $is_completed ? "Final Vote Distribution" : "Current Vote Distribution"; ?>',
                        font: { size: 16 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                let value = context.raw || 0;
                                let total = <?php echo $total_votes; ?>;
                                let percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${label}: ${value} votes (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Auto-refresh for active elections every 10 seconds
        <?php if ($election_info && $election_info['status'] == 'active'): ?>
        setTimeout(function() {
            location.reload();
        }, 10000);
        console.log('Auto-refresh enabled for live results (10s interval)');
        
        // Show refresh countdown
        let seconds = 10;
        setInterval(function() {
            seconds--;
            if (seconds <= 0) seconds = 10;
        }, 1000);
        <?php endif; ?>
    </script>
</body>
</html>