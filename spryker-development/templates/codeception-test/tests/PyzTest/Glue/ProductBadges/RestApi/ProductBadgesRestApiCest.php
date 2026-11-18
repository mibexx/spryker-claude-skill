<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace PyzTest\Glue\ProductBadges\RestApi;

use Codeception\Util\HttpCode;
use Pyz\Glue\ProductBadgesRestApi\ProductBadgesRestApiConfig;
use PyzTest\Glue\ProductBadges\ProductBadgesApiTester;

/**
 * Auto-generated group annotations
 *
 * @group PyzTest
 * @group Glue
 * @group ProductBadges
 * @group RestApi
 * @group ProductBadgesRestApiCest
 * Add your own group annotations below this line
 * @group EndToEnd
 */
class ProductBadgesRestApiCest
{
    protected ProductBadgesRestApiFixtures $fixtures;

    public function loadFixtures(ProductBadgesApiTester $I): void
    {
        /** @var \PyzTest\Glue\ProductBadges\RestApi\ProductBadgesRestApiFixtures $fixtures */
        $fixtures = $I->loadFixtures(ProductBadgesRestApiFixtures::class);

        $this->fixtures = $fixtures;
    }

    /**
     * @depends loadFixtures
     */
    public function requestProductBadgesWithoutIdProductAbstractReturnsBadRequest(ProductBadgesApiTester $I): void
    {
        // Act
        $I->sendGET($I->buildProductBadgesUrl());

        // Assert
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseIsJson();
    }

    /**
     * Setzt voraus, dass die Badges nach dem Anlegen publiziert und nach Redis synchronisiert wurden
     * (CI: `codecept fixtures`, dann `queue:worker:start --stop-when-empty`, dann `codecept run`).
     *
     * @depends loadFixtures
     */
    public function requestProductBadgesByIdProductAbstractReturnsBadgeCollection(ProductBadgesApiTester $I): void
    {
        // Arrange
        $url = $I->buildProductBadgesUrl($this->fixtures->getProductBadgeTransfer()->getFkProductAbstractOrFail());

        // Act
        $I->sendGET($url);

        // Assert
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();
        $I->seeResponseMatchesOpenApiSchema();
        $I->amSure('Response data contains product-badges resources')
            ->whenI()
            ->seeResponseDataContainsResourceCollectionOfType(ProductBadgesRestApiConfig::RESOURCE_PRODUCT_BADGES);
    }
}
