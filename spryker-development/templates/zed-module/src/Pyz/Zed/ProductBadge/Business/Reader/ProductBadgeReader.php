<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Business\Reader;

use Generated\Shared\Transfer\ProductBadgeCollectionTransfer;
use Generated\Shared\Transfer\ProductBadgeCriteriaTransfer;
use Pyz\Zed\ProductBadge\Persistence\ProductBadgeRepositoryInterface;

class ProductBadgeReader implements ProductBadgeReaderInterface
{
    public function __construct(protected ProductBadgeRepositoryInterface $productBadgeRepository)
    {
    }

    public function getProductBadgeCollection(ProductBadgeCriteriaTransfer $productBadgeCriteriaTransfer): ProductBadgeCollectionTransfer
    {
        return $this->productBadgeRepository->getProductBadgeCollection($productBadgeCriteriaTransfer);
    }
}
