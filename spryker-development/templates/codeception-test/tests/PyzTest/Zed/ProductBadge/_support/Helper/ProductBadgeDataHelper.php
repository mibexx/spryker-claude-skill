<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace PyzTest\Zed\ProductBadge\Helper;

use Codeception\Module;
use Generated\Shared\DataBuilder\ProductBadgeBuilder;
use Generated\Shared\Transfer\ProductBadgeTransfer;
use Orm\Zed\ProductBadge\Persistence\SpyProductBadgeQuery;
use Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface;
use SprykerTest\Shared\Testify\Helper\DataCleanupHelperTrait;
use SprykerTest\Shared\Testify\Helper\LocatorHelperTrait;

class ProductBadgeDataHelper extends Module
{
    use DataCleanupHelperTrait;
    use LocatorHelperTrait;

    /**
     * @param array<string, mixed> $seedData Overrides, e.g. [ProductBadgeTransfer::FK_PRODUCT_ABSTRACT => 1].
     */
    public function haveProductBadge(array $seedData = []): ProductBadgeTransfer
    {
        $productBadgeTransfer = (new ProductBadgeBuilder($seedData))->build();
        $productBadgeTransfer = $this->getProductBadgeFacade()->createProductBadge($productBadgeTransfer);

        $this->debug(sprintf('Inserted ProductBadge: %d', $productBadgeTransfer->getIdProductBadgeOrFail()));

        $this->getDataCleanupHelper()->_addCleanup(function () use ($productBadgeTransfer): void {
            SpyProductBadgeQuery::create()
                ->filterByIdProductBadge($productBadgeTransfer->getIdProductBadgeOrFail())
                ->delete();
        });

        return $productBadgeTransfer;
    }

    public function getProductBadgeFacade(): ProductBadgeFacadeInterface
    {
        /** @var \Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface $productBadgeFacade */
        $productBadgeFacade = $this->getLocator()->productBadge()->facade();

        return $productBadgeFacade;
    }
}
