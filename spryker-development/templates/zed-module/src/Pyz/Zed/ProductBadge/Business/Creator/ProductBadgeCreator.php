<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Business\Creator;

use Generated\Shared\Transfer\ProductBadgeTransfer;
use Pyz\Zed\ProductBadge\Business\Validator\ProductBadgeValidatorInterface;
use Pyz\Zed\ProductBadge\Persistence\ProductBadgeEntityManagerInterface;

class ProductBadgeCreator implements ProductBadgeCreatorInterface
{
    public function __construct(
        protected ProductBadgeValidatorInterface $productBadgeValidator,
        protected ProductBadgeEntityManagerInterface $productBadgeEntityManager,
    ) {
    }

    public function createProductBadge(ProductBadgeTransfer $productBadgeTransfer): ProductBadgeTransfer
    {
        $this->productBadgeValidator->validate($productBadgeTransfer);

        return $this->productBadgeEntityManager->createProductBadge($productBadgeTransfer);
    }
}
