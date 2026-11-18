<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Business\Writer;

interface ProductBadgeStorageWriterInterface
{
    /**
     * @param array<\Generated\Shared\Transfer\EventEntityTransfer> $eventEntityTransfers
     * @param string $eventName
     */
    public function writeCollectionByProductBadgeEvents(array $eventEntityTransfers, string $eventName): void;
}
