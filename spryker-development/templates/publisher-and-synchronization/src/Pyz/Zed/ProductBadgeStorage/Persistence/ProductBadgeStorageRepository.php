<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Persistence;

use Generated\Shared\Transfer\FilterTransfer;
use Generated\Shared\Transfer\ProductBadgeTransfer;
use Orm\Zed\ProductBadge\Persistence\Map\SpyProductBadgeTableMap;
use Orm\Zed\ProductBadgeStorage\Persistence\Map\SpyProductBadgeStorageTableMap;
use Spryker\Zed\Kernel\Persistence\AbstractRepository;
use Spryker\Zed\Synchronization\Persistence\Propel\Formatter\SynchronizationDataTransferObjectFormatter;

/**
 * @method \Pyz\Zed\ProductBadgeStorage\Persistence\ProductBadgeStoragePersistenceFactory getFactory()
 */
class ProductBadgeStorageRepository extends AbstractRepository implements ProductBadgeStorageRepositoryInterface
{
    /**
     * @param array<int> $idsProductAbstract
     *
     * @return array<int, list<\Generated\Shared\Transfer\ProductBadgeTransfer>>
     */
    public function getProductBadgeTransfersGroupedByIdProductAbstract(array $idsProductAbstract): array
    {
        if ($idsProductAbstract === []) {
            return [];
        }

        $productBadgeEntities = $this->getFactory()
            ->getProductBadgePropelQuery()
            ->filterByFkProductAbstract_In($idsProductAbstract)
            ->orderByIdProductBadge()
            ->find();

        $productBadgeStorageMapper = $this->getFactory()->createProductBadgeStorageMapper();
        $productBadgeTransfersByIdProductAbstract = [];

        foreach ($productBadgeEntities as $productBadgeEntity) {
            $productBadgeTransfer = $productBadgeStorageMapper->mapProductBadgeEntityToProductBadgeTransfer(
                $productBadgeEntity,
                new ProductBadgeTransfer(),
            );

            $productBadgeTransfersByIdProductAbstract[$productBadgeTransfer->getFkProductAbstractOrFail()][] = $productBadgeTransfer;
        }

        return $productBadgeTransfersByIdProductAbstract;
    }

    /**
     * Returns entity transfers (not `ProductBadgeTransfer`): the publisher trigger reads the id column
     * (`fk_product_abstract`) from `SpyProductBadgeEntityTransfer::modifiedToArray()`.
     *
     * @param int $offset
     * @param int $limit
     *
     * @return array<\Generated\Shared\Transfer\SpyProductBadgeEntityTransfer>
     */
    public function getProductBadgeEntityTransfers(int $offset, int $limit): array
    {
        $filterTransfer = (new FilterTransfer())
            ->setOrderBy(SpyProductBadgeTableMap::COL_ID_PRODUCT_BADGE)
            ->setOffset($offset)
            ->setLimit($limit);

        return $this->buildQueryFromCriteria($this->getFactory()->getProductBadgePropelQuery(), $filterTransfer)->find();
    }

    /**
     * @param int $offset
     * @param int $limit
     * @param array<int> $idsProductAbstract
     *
     * @return array<\Generated\Shared\Transfer\SynchronizationDataTransfer>
     */
    public function getSynchronizationDataTransfers(int $offset, int $limit, array $idsProductAbstract): array
    {
        $filterTransfer = (new FilterTransfer())
            ->setOrderBy(SpyProductBadgeStorageTableMap::COL_ID_PRODUCT_BADGE_STORAGE)
            ->setOffset($offset)
            ->setLimit($limit);

        $productBadgeStorageQuery = $this->getFactory()->createProductBadgeStorageQuery();

        if ($idsProductAbstract !== []) {
            $productBadgeStorageQuery->filterByFkProductAbstract_In($idsProductAbstract);
        }

        return $this->buildQueryFromCriteria($productBadgeStorageQuery, $filterTransfer)
            ->setFormatter(SynchronizationDataTransferObjectFormatter::class)
            ->find();
    }
}
