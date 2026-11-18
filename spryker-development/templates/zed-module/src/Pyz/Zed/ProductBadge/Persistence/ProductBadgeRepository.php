<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Persistence;

use Generated\Shared\Transfer\ProductBadgeCollectionTransfer;
use Generated\Shared\Transfer\ProductBadgeCriteriaTransfer;
use Spryker\Zed\Kernel\Persistence\AbstractRepository;

/**
 * @method \Pyz\Zed\ProductBadge\Persistence\ProductBadgePersistenceFactory getFactory()
 */
class ProductBadgeRepository extends AbstractRepository implements ProductBadgeRepositoryInterface
{
    public function getProductBadgeCollection(ProductBadgeCriteriaTransfer $productBadgeCriteriaTransfer): ProductBadgeCollectionTransfer
    {
        $productBadgeQuery = $this->getFactory()->createProductBadgeQuery();

        if ($productBadgeCriteriaTransfer->getBadgeKeys() !== []) {
            $productBadgeQuery->filterByBadgeKey_In($productBadgeCriteriaTransfer->getBadgeKeys());
        }

        return $this->getFactory()
            ->createProductBadgeMapper()
            ->mapProductBadgeEntitiesToProductBadgeCollectionTransfer(
                $productBadgeQuery->find(),
                new ProductBadgeCollectionTransfer(),
            );
    }
}
