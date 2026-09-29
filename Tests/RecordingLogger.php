<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AvailableDefaultProduct\Tests;

use Psr\Log\AbstractLogger;

/**
 * Records every call instead of writing anywhere, so a test can assert whether the
 * listener logged a failure without reading log files.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    private array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message];
    }

    public function hasErrorRecords(): bool
    {
        foreach ($this->records as $record) {
            if ('error' === $record['level']) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return \count($this->records);
    }
}
