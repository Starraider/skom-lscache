<?php

declare(strict_types=1);

namespace Skom\Lscache\Http;

use InvalidArgumentException;

/**
 * Immutable reference to a database record that should contribute an LSCache tag.
 */
final readonly class RecordTagReference
{
    public string $table;

    public int $uid;

    /**
     * @param string $table Database table name for the referenced record.
     * @param int $uid Positive UID of the referenced record.
     */
    public function __construct(string $table, int $uid)
    {
        $table = trim($table);
        if ($table === '') {
            throw new InvalidArgumentException('Record table name must not be empty.', 1089946999);
        }

        if ($uid <= 0) {
            throw new InvalidArgumentException('Record uid must be greater than zero.', 2465660500);
        }

        $this->table = $table;
        $this->uid = $uid;
    }
}
