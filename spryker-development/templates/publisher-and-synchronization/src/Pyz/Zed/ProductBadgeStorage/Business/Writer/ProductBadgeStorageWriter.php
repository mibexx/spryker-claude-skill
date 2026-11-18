<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Business\Writer;

use ArrayObject;
use Generated\Shared\Transfer\ProductBadgeStorageTransfer;
use Pyz\Shared\ProductBadgeStorage\ProductBadgeStorageConfig;
use Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStorageEntityManagerInterface;
use Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStorageRepositoryInterface;
use Spryker\Zed\EventBehavior\Business\EventBehaviorFacadeInterface;

class ProductBadgeStorageWriter implements ProductBadgeStorageWriterInterface
{
    protected const string COL_FK_PRODUCT_ABSTRACT = 'spy_product_badge.fk_product_abstract';

    public function __construct(
        protected EventBehaviorFacadeInterface $eventBehaviorFacade,
        protected ProductBadgeStorageRepositoryInterface $productBadgeStorageRepository,
        protected ProductBadgeStorageEntityManagerInterface $productBadgeStorageEntityManager,
    ) {
    }

    /**
     * @param array<\Generated\Shared\Transfer\EventEntityTransfer> $eventEntityTransfers
     * @param string $eventName
     */
    public function writeCollectionByProductBadgeEvents(array $eventEntityTransfers, string $eventName): void
    {
        $idsProductAbstract = $this->getIdsProductAbstract($eventEntityTransfers, $eventName);

        if ($idsProductAbstract === []) {
            return;
        }

        $this->writeCollectionByIdsProductAbstract($idsProductAbstract);
    }

    /**
     * @param array<\Generated\Shared\Transfer\EventEntityTransfer> $eventEntityTransfers
     * @param string $eventName
     *
     * @return array<int>
     */
    protected function getIdsProductAbstract(array $eventEntityTransfers, string $eventName): array
    {
        // The publish event (publish:trigger-events) carries the product abstract ID as event ID,
        // Entity events carry the ID of the changed spy_product_badge row and the product abstract ID as foreign key.
        if ($eventName === ProductBadgeStorageConfig::PRODUCT_BADGE_PUBLISH) {
            return array_values(array_filter($this->eventBehaviorFacade->getEventTransferIds($eventEntityTransfers)));
        }

        return array_values(array_filter(
            $this->eventBehaviorFacade->getEventTransferForeignKeys($eventEntityTransfers, static::COL_FK_PRODUCT_ABSTRACT),
        ));
    }

    /**
     * @param array<int> $idsProductAbstract
     */
    protected function writeCollectionByIdsProductAbstract(array $idsProductAbstract): void
    {
        $productBadgeTransfersByIdProductAbstract = $this->productBadgeStorageRepository
            ->getProductBadgeTransfersGroupedByIdProductAbstract($idsProductAbstract);

        foreach ($idsProductAbstract as $idProductAbstract) {
            $productBadgeTransfers = $productBadgeTransfersByIdProductAbstract[$idProductAbstract] ?? [];

            if ($productBadgeTransfers === []) {
                $this->productBadgeStorageEntityManager->deleteProductBadgeStorageByIdProductAbstract($idProductAbstract);

                continue;
            }

            $productBadgeStorageTransfer = (new ProductBadgeStorageTransfer())
                ->setIdProductAbstract($idProductAbstract)
                ->setProductBadges(new ArrayObject($productBadgeTransfers));

            $this->productBadgeStorageEntityManager->saveProductBadgeStorage($productBadgeStorageTransfer);
        }
    }
}
