<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Client\ProductBadge\Reader;

use Generated\Shared\Transfer\ProductBadgeStorageTransfer;
use Generated\Shared\Transfer\SynchronizationDataTransfer;
use Pyz\Shared\ProductBadgeStorage\ProductBadgeStorageConfig;
use Spryker\Client\Storage\StorageClientInterface;
use Spryker\Service\Synchronization\SynchronizationServiceInterface;

class ProductBadgeStorageReader implements ProductBadgeStorageReaderInterface
{
    public function __construct(
        protected StorageClientInterface $storageClient,
        protected SynchronizationServiceInterface $synchronizationService,
    ) {
    }

    /**
     * @param int $idProductAbstract
     *
     * @return array<\Generated\Shared\Transfer\ProductBadgeTransfer>
     */
    public function findProductBadgesByIdProductAbstract(int $idProductAbstract): array
    {
        $productBadgeStorageData = $this->storageClient->get($this->generateStorageKey($idProductAbstract));

        if (!is_array($productBadgeStorageData) || $productBadgeStorageData === []) {
            return [];
        }

        // ignoreMissingProperty = true: the behavior adds `_timestamp`, which is no transfer property.
        $productBadgeStorageTransfer = (new ProductBadgeStorageTransfer())->fromArray($productBadgeStorageData, true);

        return $productBadgeStorageTransfer->getProductBadges()->getArrayCopy();
    }

    protected function generateStorageKey(int $idProductAbstract): string
    {
        $synchronizationDataTransfer = (new SynchronizationDataTransfer())
            ->setReference((string)$idProductAbstract);

        return $this->synchronizationService
            ->getStorageKeyBuilder(ProductBadgeStorageConfig::PRODUCT_BADGE_RESOURCE_NAME)
            ->generateKey($synchronizationDataTransfer);
    }
}
