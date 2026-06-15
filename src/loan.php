<?php
// src/loan.php
require_once __DIR__ . '/db.php';

function create_application($farmer_id, $agent_id, $title, $amount, $purpose, $stages) {
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO loan_applications (farmer_id, agent_id, title, amount, purpose) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$farmer_id, $agent_id, $title, $amount, $purpose]);
    $app_id = $pdo->lastInsertId();

    $insertStage = $pdo->prepare("INSERT INTO loan_stages (application_id, stage_number, required_amount) VALUES (?, ?, ?)");
    foreach ($stages as $s) {
        $insertStage->execute([$app_id, $s['stage_number'], $s['required_amount']]);
    }
    $pdo->commit();
    return $app_id;
}

function get_application($id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("
        SELECT la.*, 
               COALESCE(f.name, 'Unknown Farmer') AS farmer_name, 
               COALESCE(a.name, 'Unknown Agent') AS agent_name
        FROM loan_applications la
        LEFT JOIN users f ON la.farmer_id = f.id
        LEFT JOIN users a ON la.agent_id = a.id
        WHERE la.id = ?
    ");
    $stmt->execute([$id]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($app) {
        $stmt2 = $pdo->prepare("SELECT * FROM loan_stages WHERE application_id = ? ORDER BY stage_number ASC");
        $stmt2->execute([$id]);
        $app['stages'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }

    return $app;
}

function set_stage_status($stage_id, $status) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("UPDATE loan_stages SET status = ? WHERE id = ?");
    $stmt->execute([$status, $stage_id]);
}

function disburse_stage($stage_id, $amount) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("UPDATE loan_stages SET disbursed_amount = disbursed_amount + ?, status = 'awaiting_proof' WHERE id = ?");
    $stmt->execute([$amount, $stage_id]);
}

function approve_stage_by_agent($agent_id, $stage_id, $action, $notes = null) {
    $pdo = getPDO();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO agent_actions (agent_id, stage_id, action, notes) VALUES (?, ?, ?, ?)");
    $stmt->execute([$agent_id, $stage_id, $action, $notes]);

    if ($action === 'approved') {
        $stmt2 = $pdo->prepare("UPDATE loan_stages SET status = 'approved' WHERE id = ?");
        $stmt2->execute([$stage_id]);
        $stmt3 = $pdo->prepare("SELECT application_id, stage_number FROM loan_stages WHERE id = ?");
        $stmt3->execute([$stage_id]);
        $s = $stmt3->fetch();
        if ($s) {
            $app_id = $s['application_id'];
            $nextStageNum = $s['stage_number'] + 1;
            $stmt4 = $pdo->prepare("SELECT id FROM loan_stages WHERE application_id = ? AND stage_number = ?");
            $stmt4->execute([$app_id, $nextStageNum]);
            $next = $stmt4->fetch();
            if ($next) {
                $stmt5 = $pdo->prepare("UPDATE loan_applications SET current_stage = ? WHERE id = ?");
                $stmt5->execute([$nextStageNum, $app_id]);
            } else {
                $stmt6 = $pdo->prepare("UPDATE loan_applications SET status = 'completed' WHERE id = ?");
                $stmt6->execute([$app_id]);
            }
        }
    } else {
        $stmt2 = $pdo->prepare("UPDATE loan_stages SET status = 'rejected' WHERE id = ?");
        $stmt2->execute([$stage_id]);
    }
    $pdo->commit();
}
