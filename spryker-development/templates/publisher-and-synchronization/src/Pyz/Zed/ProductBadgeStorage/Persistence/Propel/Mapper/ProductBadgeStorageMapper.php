<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeStorage\Persistence\Propel\Mapper;

use Generated\Shared\Transfer\ProductBadgeTransfer;
use Orm\Zed\ProductBadge\Persistence\SpyProductBadge;

class ProductBadgeStorageMapper
{
    public function mapProductBadgeEntityToProductBadgeTransfer(
        SpyProductBadge $productBadgeEntity,
        ProductBadgeTransfer $productBadgeTransfer,
    ): ProductBadgeTransfer {
        return $productBadgeTransfer
            ->setIdProductBadge($productBadgeEntity->getIdProductBadge())
            ->setFkProductAbstract($productBadgeEntity->getFkProductAbstract())
            ->setBadgeKey($productBadgeEntity->getBadgeKey())
            ->setLabel($productBadgeEntity->getLabel());
    }
}
