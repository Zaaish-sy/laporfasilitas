<?php

declare(strict_types=1);

// Jalankan: php core/tests/AnalysisProcessorDbTest.php   (butuh PostgreSQL + ext pdo_pgsql)
require __DIR__ . '/DbHarness.php';

use App\Application\Actor;
use App\Application\AiClient;
use App\Application\AiResult;
use App\Application\AiUnavailable;
use App\Application\AnalysisProcessor;
use App\Application\ReportService;
use App\Application\ReportTransitions;
use App\Application\StubAiClient;
use App\Application\VerificationPolicy;
use App\Domain\Role;

$pdo = Db::boot();
$transitions = new ReportTransitions($pdo);
$reports = new ReportService($pdo, $transitions);

$ownerId = (string) Db::scalar($pdo, "INSERT INTO users (email, password_hash, display_name) VALUES ('o@x.test', 'x', 'pemilik') RETURNING id");
$owner = new Actor($ownerId, Role::User);

/** AI palsu yang jawabannya bisa diatur per kunci foto. */
function fakeAi(array $byKey): AiClient
{
    return new class($byKey) implements AiClient {
        public int $calls = 0;

        public function __construct(private array $byKey)
        {
        }

        public function analyze(string $storageKey, string $selectedCategory): AiResult
        {
            $this->calls++;
            $v = $this->byKey[$storageKey] ?? throw new AiUnavailable("tak ada jawaban untuk $storageKey");
            if ($v instanceof Throwable) {
                throw $v;
            }

            return $v;
        }
    };
}

$good = new AiResult('jalan_berlubang', 0.97, true, 'ok', 'm-uji');
$lowConf = new AiResult('jalan_berlubang', 0.40, true, 'ok', 'm-uji');
$mismatch = new AiResult('drainase', 0.99, false, 'ok', 'm-uji');
$poor = new AiResult('tidak_jelas', 0.10, false, 'poor', 'm-uji');

$n = 0;
/** Membuat laporan terkirim (menunggu_analisis) dengan foto berkunci tertentu. */
$submitted = function (array $keys, float $lat = -0.5, float $lon = 117.15) use ($reports, $owner, &$n): string {
    $n++;
    $uuid = sprintf('00000000-0000-4000-8000-%012d', $n);
    $rid = $reports->createDraft($owner, [
        'client_uuid' => $uuid, 'category_id' => 1, 'title' => "Laporan $n", 'description' => '',
        'location' => ['lat' => $lat, 'lon' => $lon, 'source' => 'gps'],
    ])['report']['id'];
    foreach ($keys as $k) {
        $reports->addImage($owner, $rid, $k, hash('sha256', $k . $n));
    }
    $reports->submit($owner, $rid);

    return $rid;
};
$status = static fn (string $id): string => (string) Db::scalar($GLOBALS['pdo'], 'SELECT status::text FROM reports WHERE id = :i', [':i' => $id]);
$chain = static fn (string $id): array => $GLOBALS['pdo']->query("SELECT transition_id FROM status_history WHERE report_id = '$id' AND to_status <> 'draft' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$analysesOf = static fn (string $id): array => $GLOBALS['pdo']->query("SELECT a.verification_result::text AS v, a.model_version, a.threshold_version FROM ai_analyses a JOIN report_images i ON i.id = a.image_id WHERE i.report_id = '$id' ORDER BY a.id")->fetchAll();

// ---------- 1. Stub + kebijakan bawaan: SEMUA ke Perlu Review (tidak ada yang lolos otomatis) ----------
$proc = new AnalysisProcessor($pdo, $transitions, new StubAiClient());
$r1 = $submitted(['k/a']);
$res = $proc->process($r1);
Db::check($res !== null && $res->transitionId === 'T-03' && $status($r1) === 'perlu_review', 'stub: laporan masuk Perlu Review (T-03)');
$a = $analysesOf($r1);
Db::check(count($a) === 1 && $a[0]['v'] === 'needs_review' && $a[0]['model_version'] === 'stub-0' && $a[0]['threshold_version'] === 'belum-ditetapkan',
    'stub: analisis tersimpan sebagai needs_review dengan versi model & ambang tercatat');
Db::check($proc->process($r1) === null && count($analysesOf($r1)) === 1, 'memproses ulang: no-op, tidak ada analisis ganda');
Db::check($chain($r1) === ['T-01', 'T-03'], 'riwayat: T-01 lalu T-03');

// Draft belum dikirim tidak diproses
$draft = $reports->createDraft($owner, ['client_uuid' => '99999999-9999-4999-8999-999999999999', 'category_id' => 1, 'title' => 'Masih draft',
    'location' => ['lat' => -0.5, 'lon' => 117.1, 'source' => 'gps']])['report']['id'];
Db::check($proc->process($draft) === null && $status($draft) === 'draft', 'draft tidak diproses');
Db::check($proc->process('00000000-0000-4000-8000-0000000000ff') === null, 'laporan tidak ada: no-op');

// ---------- 2. Ambang belum ditetapkan: AI sangat yakin pun TIDAK lolos ----------
$ai = fakeAi(['k/good' => $good]);
$procNoThreshold = new AnalysisProcessor($pdo, new ReportTransitions($pdo, autoPublishEnabled: true), $ai, new VerificationPolicy());
$r2 = $submitted(['k/good']);
$procNoThreshold->process($r2);
Db::check($status($r2) === 'perlu_review' && $analysesOf($r2)[0]['v'] === 'needs_review', 'tanpa ambang yang ditetapkan, confidence 0.97 tetap Needs Review');

// ---------- 3. Ambang ditetapkan, publikasi otomatis MATI: Passed tetap ke admin ----------
$policy = new VerificationPolicy('uji-v1', 0.90);
$procManual = new AnalysisProcessor($pdo, $transitions, fakeAi(['k/good' => $good]), $policy);
$r3 = $submitted(['k/good']);
$procManual->process($r3);
Db::check($status($r3) === 'perlu_review' && $analysesOf($r3)[0]['v'] === 'passed' && $analysesOf($r3)[0]['threshold_version'] === 'uji-v1',
    'Passed tapi auto-publish mati -> Perlu Review; versi ambang tercatat');
Db::check((string) Db::scalar($pdo, "SELECT reason FROM status_history WHERE report_id = '$r3' AND transition_id = 'T-03'") !== '', 'alasan menunggu admin tercatat');

// ---------- 4. Publikasi otomatis aktif ----------
$procAutoT = new ReportTransitions($pdo, autoPublishEnabled: true);
$procAuto = new AnalysisProcessor($pdo, $procAutoT, fakeAi(['k/good' => $good]), $policy);
$r4 = $submitted(['k/good']);
$res = $procAuto->process($r4);
Db::check($status($r4) === 'menunggu_tindak_lanjut' && $chain($r4) === ['T-01', 'T-02', 'T-07'], 'auto-publish: T-02 lalu T-07 -> menunggu_tindak_lanjut (rantai: ' . implode(',', $chain($r4)) . ')');
Db::check($res !== null && $res->transitionId === 'T-07', 'hasil terakhir = T-07');

// ---------- 5. Confidence rendah / kategori tidak cocok -> Needs Review ----------
foreach (['rendah' => $lowConf, 'tidak cocok' => $mismatch] as $label => $ar) {
    $rid = $submitted(['k/x']);
    (new AnalysisProcessor($pdo, $procAutoT, fakeAi(['k/x' => $ar]), $policy))->process($rid);
    Db::check($status($rid) === 'perlu_review', "confidence {$label}: Perlu Review");
}

// ---------- 6. Kualitas buruk -> Rejected -> Perlu Perbaikan (T-04) dengan alasan ----------
$r6 = $submitted(['k/poor']);
(new AnalysisProcessor($pdo, $transitions, fakeAi(['k/poor' => $poor]), $policy))->process($r6);
Db::check($status($r6) === 'perlu_perbaikan' && $chain($r6) === ['T-01', 'T-04'], 'foto buruk: Perlu Perbaikan (T-04)');
Db::check(str_contains((string) ($reports->get($owner, $r6)['reason'] ?? ''), 'ganti foto'), 'pemilik melihat alasan agar mengganti foto');
Db::check($analysesOf($r6)[0]['v'] === 'rejected', 'analisis tersimpan sebagai rejected');

// ---------- 7. Kirim ulang: hanya foto BARU yang dianalisis (A-16) ----------
$reports->addImage($owner, $r6, 'k/baru', hash('sha256', 'baru'));
$reports->submit($owner, $r6);
Db::check($status($r6) === 'menunggu_analisis' && $chain($r6) === ['T-01', 'T-04', 'T-05'], 'kirim ulang: T-05');
$ai7 = fakeAi(['k/baru' => $lowConf, 'k/poor' => $poor]);
(new AnalysisProcessor($pdo, $transitions, $ai7, $policy))->process($r6);
Db::check($ai7->calls === 1, 'hanya foto baru dikirim ke AI (panggilan: ' . $ai7->calls . ')');
Db::check($status($r6) === 'perlu_review' && count($analysesOf($r6)) === 2, 'foto lama tidak dihitung ulang; hasil foto baru menentukan');

// Kirim ulang tanpa foto baru -> Perlu Review
$r7 = $submitted(['k/poor']);
(new AnalysisProcessor($pdo, $transitions, fakeAi(['k/poor' => $poor]), $policy))->process($r7);
$reports->submit($owner, $r7);                                            // T-05 tanpa foto baru
$ai7b = fakeAi([]);
(new AnalysisProcessor($pdo, $transitions, $ai7b, $policy))->process($r7);
Db::check($ai7b->calls === 0 && $status($r7) === 'perlu_review', 'kirim ulang tanpa foto baru: AI tidak dipanggil, diperiksa manual');

// ---------- 8. AI tidak tersedia -> Perlu Review, TIDAK lolos (A-02) ----------
$r8 = $submitted(['k/mati']);
(new AnalysisProcessor($pdo, $procAutoT, fakeAi(['k/mati' => new AiUnavailable('timeout')]), $policy))->process($r8);
Db::check($status($r8) === 'perlu_review' && $analysesOf($r8) === [], 'AI mati: Perlu Review, tidak ada analisis palsu tersimpan');
Db::check(str_contains((string) Db::scalar($pdo, "SELECT reason FROM status_history WHERE report_id = '$r8' AND transition_id = 'T-03'"), 'AI tidak tersedia'), 'alasan AI tidak tersedia tercatat');

// ---------- 9. Dua foto: kasus terburuk menentukan ----------
$r9 = $submitted(['k/ok1', 'k/poor2']);
(new AnalysisProcessor($pdo, $procAutoT, fakeAi(['k/ok1' => $good, 'k/poor2' => $poor]), $policy))->process($r9);
Db::check($status($r9) === 'perlu_perbaikan' && count($analysesOf($r9)) === 2, 'satu foto buruk menjatuhkan seluruh laporan ke Perlu Perbaikan');
$r9b = $submitted(['k/ok1', 'k/lemah']);
(new AnalysisProcessor($pdo, $procAutoT, fakeAi(['k/ok1' => $good, 'k/lemah' => $lowConf]), $policy))->process($r9b);
Db::check($status($r9b) === 'perlu_review', 'satu foto lemah -> Perlu Review, tidak diterbitkan');

// ---------- 10. Area pilot (A-09) ----------
$bounds = ['minLat' => -1.0, 'minLon' => 116.9, 'maxLat' => 0.0, 'maxLon' => 117.4];           // HANYA untuk uji
$procArea = new AnalysisProcessor($pdo, $procAutoT, fakeAi(['k/good' => $good]), $policy, pilotBounds: $bounds);
$inside = $submitted(['k/good'], -0.5, 117.15);
$outside = $submitted(['k/good'], 3.0, 98.0);
$procArea->process($inside);
$procArea->process($outside);
Db::check($status($inside) === 'menunggu_tindak_lanjut', 'di dalam area pilot: diterbitkan');
Db::check($status($outside) === 'perlu_review', 'di luar area pilot: Perlu Review meski AI Passed');

// ---------- 11. Keburu diproses pihak lain di tengah jalan: tidak ada yang tersimpan ----------
$racer = null;
$rRace = $submitted(['k/race']);
$racing = new class($pdo, $rRace) implements AiClient {
    public function __construct(private PDO $pdo, private string $rid)
    {
    }

    public function analyze(string $storageKey, string $selectedCategory): AiResult
    {
        // Saat AI "berpikir", pihak lain memindahkan laporan (mis. admin menariknya atau proses ganda selesai lebih dulu).
        $other = Db::connect();
        $other->exec("UPDATE reports SET status = 'perlu_review' WHERE id = '{$this->rid}'");

        return new AiResult('jalan_berlubang', 0.97, true, 'ok', 'm-uji');
    }
};
$out = (new AnalysisProcessor($pdo, $procAutoT, $racing, $policy))->process($rRace);
Db::check($out === null && $status($rRace) === 'perlu_review' && $analysesOf($rRace) === [],
    'status berubah saat AI berjalan: tidak menimpa, tidak ada analisis tersimpan');
Db::check($chain($rRace) === ['T-01'], 'riwayat tidak bertambah akibat balapan');

Db::done();
