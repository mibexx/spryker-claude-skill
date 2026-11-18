<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Business\Validator;

use Generated\Shared\Transfer\ProductAbstractConditionsTransfer;
use Generated\Shared\Transfer\ProductAbstractCriteriaTransfer;
use Generated\Shared\Transfer\ProductBadgeTransfer;
use Pyz\Zed\ProductBadge\Business\Exception\InvalidProductBadgeException;
use Pyz\Zed\ProductBadge\ProductBadgeConfig;
use Spryker\Zed\Product\Business\ProductFacadeInterface;

class ProductBadgeValidator implements ProductBadgeValidatorInterface
{
    public function __construct(
        protected ProductFacadeInterface $productFacade,
        protected ProductBadgeConfig $productBadgeConfig,
    ) {
    }

    /**
     * @param \Generated\Shared\Transfer\ProductBadgeTransfer $productBadgeTransfer
     *
     * @throws \Pyz\Zed\ProductBadge\Business\Exception\InvalidProductBadgeException
     */
    public function validate(ProductBadgeTransfer $productBadgeTransfer): void
    {
        $productBadgeTransfer->requireFkProductAbstract();
        $productBadgeTransfer->requireBadgeKey();
        $productBadgeTransfer->requireLabel();

        if (!in_array($productBadgeTransfer->getBadgeKeyOrFail(), $this->productBadgeConfig->getAllowedBadgeKeys(), true)) {
            throw new InvalidProductBadgeException(
                sprintf('Badge key "%s" is not allowed.', $productBadgeTransfer->getBadgeKeyOrFail()),
            );
        }

        // Not findProductAbstractById(): its read observers (e.g. TaxSetProductAbstractReadPlugin) throw for abstracts without tax set.
        $productAbstractCriteriaTransfer = (new ProductAbstractCriteriaTransfer())
            ->setProductAbstractConditions(
                (new ProductAbstractConditionsTransfer())->addId($productBadgeTransfer->getFkProductAbstractOrFail()),
            );

        $productAbstractCollectionTransfer = $this->productFacade->getProductAbstractCollection($productAbstractCriteriaTransfer);

        if ($productAbstractCollectionTransfer->getProductAbstracts()->count() === 0) {
            throw new InvalidProductBadgeException(
                sprintf('Product abstract with id "%d" does not exist.', $productBadgeTransfer->getFkProductAbstractOrFail()),
            );
        }
    }
}
