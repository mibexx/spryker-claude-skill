<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Business;

use Generated\Shared\Transfer\ProductBadgeCollectionTransfer;
use Generated\Shared\Transfer\ProductBadgeCriteriaTransfer;
use Generated\Shared\Transfer\ProductBadgeTransfer;

interface ProductBadgeFacadeInterface
{
    /**
     * Specification:
     * - Requires `ProductBadgeTransfer.fkProductAbstract`, `ProductBadgeTransfer.badgeKey` and `ProductBadgeTransfer.label`.
     * - Validates that the product abstract exists and that the badge key is allowed by `ProductBadgeConfig::getAllowedBadgeKeys()`.
     * - Throws `InvalidProductBadgeException` if the validation fails.
     * - Persists the badge into `spy_product_badge`.
     * - Returns the persisted badge with `ProductBadgeTransfer.idProductBadge` set.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\ProductBadgeTransfer $productBadgeTransfer
     *
     * @throws \Pyz\Zed\ProductBadge\Business\Exception\InvalidProductBadgeException
     */
    public function createProductBadge(ProductBadgeTransfer $productBadgeTransfer): ProductBadgeTransfer;

    /**
     * Specification:
     * - Returns the badges matching the given criteria.
     * - Filters by `ProductBadgeCriteriaTransfer.badgeKeys` if it is not empty.
     * - Returns all badges if no filter is set.
     *
     * @api
     *
     * @param \Generated\Shared\Transfer\ProductBadgeCriteriaTransfer $productBadgeCriteriaTransfer
     */
    public function getProductBadgeCollection(ProductBadgeCriteriaTransfer $productBadgeCriteriaTransfer): ProductBadgeCollectionTransfer;
}
