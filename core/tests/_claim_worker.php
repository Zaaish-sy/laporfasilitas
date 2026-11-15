<?php

declare(strict_types=1);

// Proses anak untuk uji serempak. Pemakaian (internal): php _claim_worker.php <schema> <reportId> <userId> <role> <toStatus>
// Mencetak satu baris JSON: {"ok":true,...} atau {"ok":false,"status":409,"code":"..."}.
require __DIR__ . '/DbHarness.php';

use App\Application\Actor;
use App\Application\ReportTransitions;
use App\Domain\ReportStatus;
use App\Domain\Role;

[, $schema, $reportId, $userId, $role, $to] = $argv;
Db::$schema = $schema;
$svc = new ReportTransitions(Db::connect());

try {
    $r = $svc->transition($reportId, ReportStatus::from($to), new Actor($userId, Role::from($role)));
    echo json_encode(['ok' => true, 'transition' => $r->transitionId]) . "\n";
} catch (\App\Application\ApiException | \App\Domain\TransitionException $e) {
    echo json_encode(['ok' => false, 'status' => $e->httpStatus, 'code' => $e->errorCode]) . "\n";
}
