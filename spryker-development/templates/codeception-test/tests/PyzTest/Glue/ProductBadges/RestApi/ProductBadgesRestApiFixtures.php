<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace PyzTest\Glue\ProductBadges\RestApi;

use Generated\Shared\Transfer\ProductBadgeTransfer;
use Generated\Shared\Transfer\ProductConcreteTransfer;
use PyzTest\Glue\ProductBadges\ProductBadgesApiTester;
use SprykerTest\Shared\Testify\Fixtures\FixturesBuilderInterface;
use SprykerTest\Shared\Testify\Fixtures\FixturesContainerInterface;

/**
 * Auto-generated group annotations
 *
 * @group PyzTest
 * @group Glue
 * @group ProductBadges
 * @group RestApi
 * @group ProductBadgesRestApiFixtures
 * Add your own group annotations below this line
 * @group EndToEnd
 */
class ProductBadgesRestApiFixtures implements FixturesBuilderInterface, FixturesContainerInterface
{
    public const string BADGE_KEY = 'new';

    protected ProductConcreteTransfer $productConcreteTransfer;

    protected ProductBadgeTransfer $productBadgeTransfer;

    public function getProductConcreteTransfer(): ProductConcreteTransfer
    {
        return $this->productConcreteTransfer;
    }

    public function getProductBadgeTransfer(): ProductBadgeTransfer
    {
        return $this->productBadgeTransfer;
    }

    public function buildFixtures(ProductBadgesApiTester $I): FixturesContainerInterface
    {
        $this->productConcreteTransfer = $I->haveFullProduct();
        $this->productBadgeTransfer = $I->haveProductBadge([
            ProductBadgeTransfer::FK_PRODUCT_ABSTRACT => $this->productConcreteTransfer->getFkProductAbstractOrFail(),
            ProductBadgeTransfer::BADGE_KEY => static::BADGE_KEY,
        ]);

        return $this;
    }
}
