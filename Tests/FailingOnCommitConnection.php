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

use Propel\Runtime\Connection\ConnectionWrapper;

/**
 * Wraps a real connection and fails on commit, to force a failure at the end of a
 * genuine, outermost transaction without corrupting any data beforehand.
 */
final class FailingOnCommitConnection extends ConnectionWrapper
{
    public function commit(): bool
    {
        throw new \RuntimeException('Forced failure for the test.');
    }
}
