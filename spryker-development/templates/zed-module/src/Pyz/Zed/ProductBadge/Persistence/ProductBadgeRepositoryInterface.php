<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Persistence;

use Generated\Shared\Transfer\ProductBadgeCollectionTransfer;
use Generated\Shared\Transfer\ProductBadgeCriteriaTransfer;

interface ProductBadgeRepositoryInterface
{
    public function getProductBadgeCollection(ProductBadgeCriteriaTransfer $productBadgeCriteriaTransfer): ProductBadgeCollectionTransfer;
}
