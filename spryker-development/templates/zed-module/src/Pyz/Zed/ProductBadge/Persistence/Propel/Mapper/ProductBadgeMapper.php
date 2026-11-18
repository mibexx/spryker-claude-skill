<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Persistence\Propel\Mapper;

use Generated\Shared\Transfer\ProductBadgeCollectionTransfer;
use Generated\Shared\Transfer\ProductBadgeTransfer;
use Orm\Zed\ProductBadge\Persistence\SpyProductBadge;
use Propel\Runtime\Collection\Collection;

class ProductBadgeMapper
{
    /**
     * @param \Propel\Runtime\Collection\Collection<\Orm\Zed\ProductBadge\Persistence\SpyProductBadge> $productBadgeEntities
     * @param \Generated\Shared\Transfer\ProductBadgeCollectionTransfer $productBadgeCollectionTransfer
     */
    public function mapProductBadgeEntitiesToProductBadgeCollectionTransfer(
        Collection $productBadgeEntities,
        ProductBadgeCollectionTransfer $productBadgeCollectionTransfer,
    ): ProductBadgeCollectionTransfer {
        foreach ($productBadgeEntities as $productBadgeEntity) {
            $productBadgeCollectionTransfer->addProductBadge(
                $this->mapProductBadgeEntityToProductBadgeTransfer($productBadgeEntity, new ProductBadgeTransfer()),
            );
        }

        return $productBadgeCollectionTransfer;
    }

    public function mapProductBadgeEntityToProductBadgeTransfer(
        SpyProductBadge $productBadgeEntity,
        ProductBadgeTransfer $productBadgeTransfer,
    ): ProductBadgeTransfer {
        return $productBadgeTransfer->fromArray($productBadgeEntity->toArray(), true);
    }

    public function mapProductBadgeTransferToProductBadgeEntity(
        ProductBadgeTransfer $productBadgeTransfer,
        SpyProductBadge $productBadgeEntity,
    ): SpyProductBadge {
        $productBadgeEntity->fromArray($productBadgeTransfer->toArray());

        return $productBadgeEntity;
    }
}
