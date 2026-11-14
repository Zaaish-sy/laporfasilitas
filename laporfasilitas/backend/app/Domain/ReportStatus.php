<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status laporan (PRD 6.1) + "Perlu Perbaikan" (A-03).
 * Nilai string = nilai enum report_status di db/schema.sql.
 */
enum ReportStatus: string
{
    case Draft = 'draft';
    case MenungguAnalisis = 'menunggu_analisis';
    case PerluReview = 'perlu_review';
    case PerluPerbaikan = 'perlu_perbaikan';
    case Ditolak = 'ditolak';
    case Dipublikasikan = 'dipublikasikan';
    case MenungguTindakLanjut = 'menunggu_tindak_lanjut';
    case DalamPenanganan = 'dalam_penanganan';
    case MenungguKonfirmasi = 'menunggu_konfirmasi';
    case Selesai = 'selesai';
    case DibukaKembali = 'dibuka_kembali';

    /** Status yang boleh tampil di daftar dan peta publik. */
    public function isPublic(): bool
    {
        return in_array($this, self::publicStatuses(), true);
    }

    /** @return list<self> */
    public static function publicStatuses(): array
    {
        return [
            self::Dipublikasikan,
            self::MenungguTindakLanjut,
            self::DalamPenanganan,
            self::MenungguKonfirmasi,
            self::Selesai,
            self::DibukaKembali,
        ];
    }
}
