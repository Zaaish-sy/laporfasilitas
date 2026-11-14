<?php

declare(strict_types=1);

namespace App\Domain;

/** Peran akun. System = proses backend (bukan akun pengguna). */
enum Role: string
{
    case User = 'pengguna';
    case Volunteer = 'volunteer';
    case Admin = 'admin';
    case System = 'system';
}
