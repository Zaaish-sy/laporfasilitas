<?php

declare(strict_types=1);

// Menghasilkan state machine Mermaid dari aturan di kode (sumber kebenaran tunggal).
// Pemakaian: php backend/bin/export-mermaid.php > docs/state-machine.mmd
require __DIR__ . "/../bootstrap.php";

use App\Domain\ReportStateMachine;

echo "stateDiagram-v2\n    [*] --> draft\n";
foreach (ReportStateMachine::rules() as $t) {
    foreach ($t->from as $from) {
        echo "    {$from->value} --> {$t->to->value}: {$t->id}\n";
    }
}
echo "    ditolak --> [*]\n";
