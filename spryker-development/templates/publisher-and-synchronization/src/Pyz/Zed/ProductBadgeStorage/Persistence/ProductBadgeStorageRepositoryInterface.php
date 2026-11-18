<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Persistence;

interface ProductBadgeStorageRepositoryInterface
{
    /**
     * @param array<int> $idsProductAbstract
     *
     * @return array<int, list<\Generated\Shared\Transfer\ProductBadgeTransfer>>
     */
    public function getProductBadgeTransfersGroupedByIdProductAbstract(array $idsProductAbstract): array;

    /**
     * @param int $offset
     * @param int $limit
     *
     * @return array<\Generated\Shared\Transfer\SpyProductBadgeEntityTransfer>
     */
    public function getProductBadgeEntityTransfers(int $offset, int $limit): array;

    /**
     * @param int $offset
     * @param int $limit
     * @param array<int> $idsProductAbstract
     *
     * @return array<\Generated\Shared\Transfer\SynchronizationDataTransfer>
     */
    public function getSynchronizationDataTransfers(int $offset, int $limit, array $idsProductAbstract): array;
}
