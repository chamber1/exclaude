<?php

namespace App\Downloads;

enum DownloadStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    /** @return list<self> statuses that occupy a worker slot or wait for one */
    public static function active(): array
    {
        return [self::Pending, self::Processing];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }
}
