<?php
function calculateCosineSimilarity(array $vecA, array $vecB): float {
    $dotProduct = 0.0;
    $magnitudeA = 0.0;
    $magnitudeB = 0.0;
    
    foreach ($vecA as $i => $value) {
        $dotProduct += $value * $vecB[$i];
        $magnitudeA += $value * $value;
        $magnitudeB += $vecB[$i] * $vecB[$i];
    }
    
    $magnitudeA = sqrt($magnitudeA);
    $magnitudeB = sqrt($magnitudeB);
    
    if ($magnitudeA == 0 || $magnitudeB == 0) {
        return 0.0;
    }
    
    return $dotProduct / ($magnitudeA * $magnitudeB);
}

function getHourTime($hour) {
    $times = [
        1 => '9:00 AM - 10:00 AM',
        2 => '10:00 AM - 11:00 AM',
        3 => '11:00 AM - 12:00 PM',
        4 => '12:00 PM - 1:00 PM',
        5 => '2:00 PM - 3:00 PM',
        6 => '3:00 PM - 4:00 PM'
    ];
    return $times[$hour] ?? 'Time not set';
}

function getActiveElections($pdo) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        SELECT * FROM elections 
        WHERE status = 'active' AND start_date <= ? AND end_date >= ?
        ORDER BY end_date ASC
    ");
    $stmt->execute([$now, $now]);
    return $stmt->fetchAll();
}

function getUpcomingElections($pdo) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        SELECT * FROM elections 
        WHERE status = 'upcoming' AND start_date > ?
        ORDER BY start_date ASC
    ");
    $stmt->execute([$now]);
    return $stmt->fetchAll();
}

function getElectionResults($pdo, $election_id) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name as candidate_name, u.username, 
               COUNT(v.id) as vote_count
        FROM candidates c
        JOIN users u ON c.student_id = u.id
        LEFT JOIN votes v ON c.id = v.candidate_id
        WHERE c.election_id = ? AND c.status = 'approved'
        GROUP BY c.id
        ORDER BY vote_count DESC
    ");
    $stmt->execute([$election_id]);
    return $stmt->fetchAll();
}
?>