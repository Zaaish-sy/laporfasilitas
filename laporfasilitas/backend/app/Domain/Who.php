<?php

declare(strict_types=1);

namespace App\Domain;

/** Kapabilitas yang disyaratkan sebuah transisi. */
enum Who: string
{
    case Owner = 'pemilik_laporan';
    case ClaimHolder = 'pemegang_klaim';
    case Authenticated = 'pengguna_atau_volunteer';
    case Admin = 'admin';
    case System = 'system';
}
