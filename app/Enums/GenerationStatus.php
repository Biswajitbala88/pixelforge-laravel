<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Submitting = 'submitting';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Monotonic rank: a generation may only move to a strictly higher rank. */
    public function rank(): int
    {
        return match ($this) {
            self::Submitting => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this->rank() === 3;
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Submitting->value, self::Queued->value, self::Processing->value];
    }
}
