<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace PyzTest\Zed\ProductBadge\Business;

use Codeception\Test\Unit;
use Generated\Shared\DataBuilder\ProductBadgeBuilder;
use Generated\Shared\Transfer\ProductBadgeCriteriaTransfer;
use Generated\Shared\Transfer\ProductBadgeTransfer;
use Pyz\Zed\ProductBadge\Business\Exception\InvalidProductBadgeException;
use PyzTest\Zed\ProductBadge\ProductBadgeBusinessTester;

/**
 * Auto-generated group annotations
 *
 * @group PyzTest
 * @group Zed
 * @group ProductBadge
 * @group Business
 * @group Facade
 * @group ProductBadgeFacadeTest
 * Add your own group annotations below this line
 */
class ProductBadgeFacadeTest extends Unit
{
    protected const string BADGE_KEY_NEW = 'new';

    protected const string BADGE_KEY_SALE = 'sale';

    protected ProductBadgeBusinessTester $tester;

    public function testCreateProductBadgePersistsBadgeAndReturnsItWithId(): void
    {
        // Arrange
        $productAbstractTransfer = $this->tester->haveProductAbstract();
        $productBadgeTransfer = (new ProductBadgeBuilder([
            ProductBadgeTransfer::FK_PRODUCT_ABSTRACT => $productAbstractTransfer->getIdProductAbstractOrFail(),
            ProductBadgeTransfer::BADGE_KEY => static::BADGE_KEY_NEW,
        ]))->build();

        // Act
        $createdProductBadgeTransfer = $this->tester->getProductBadgeFacade()->createProductBadge($productBadgeTransfer);

        // Assert
        $this->assertGreaterThan(0, $createdProductBadgeTransfer->getIdProductBadgeOrFail());
        $this->assertSame($productAbstractTransfer->getIdProductAbstractOrFail(), $createdProductBadgeTransfer->getFkProductAbstractOrFail());
        $this->assertSame(static::BADGE_KEY_NEW, $createdProductBadgeTransfer->getBadgeKeyOrFail());
    }

    public function testCreateProductBadgeThrowsExceptionWhenBadgeKeyIsNotAllowed(): void
    {
        // Arrange
        $productAbstractTransfer = $this->tester->haveProductAbstract();
        $productBadgeTransfer = (new ProductBadgeBuilder([
            ProductBadgeTransfer::FK_PRODUCT_ABSTRACT => $productAbstractTransfer->getIdProductAbstractOrFail(),
            ProductBadgeTransfer::BADGE_KEY => 'not-allowed-key',
        ]))->build();

        // Assert
        $this->expectException(InvalidProductBadgeException::class);

        // Act
        $this->tester->getProductBadgeFacade()->createProductBadge($productBadgeTransfer);
    }

    public function testGetProductBadgeCollectionFiltersByBadgeKey(): void
    {
        // Arrange
        $idProductAbstract = $this->tester->haveProductAbstract()->getIdProductAbstractOrFail();
        $this->tester->haveProductBadge([
            ProductBadgeTransfer::FK_PRODUCT_ABSTRACT => $idProductAbstract,
            ProductBadgeTransfer::BADGE_KEY => static::BADGE_KEY_NEW,
        ]);
        $saleProductBadgeTransfer = $this->tester->haveProductBadge([
            ProductBadgeTransfer::FK_PRODUCT_ABSTRACT => $idProductAbstract,
            ProductBadgeTransfer::BADGE_KEY => static::BADGE_KEY_SALE,
        ]);
        $productBadgeCriteriaTransfer = (new ProductBadgeCriteriaTransfer())->addBadgeKey(static::BADGE_KEY_SALE);

        // Act
        $productBadgeCollectionTransfer = $this->tester->getProductBadgeFacade()->getProductBadgeCollection($productBadgeCriteriaTransfer);

        // Assert
        $returnedIds = [];
        foreach ($productBadgeCollectionTransfer->getProductBadges() as $productBadgeTransfer) {
            $this->assertSame(static::BADGE_KEY_SALE, $productBadgeTransfer->getBadgeKeyOrFail());
            $returnedIds[] = $productBadgeTransfer->getIdProductBadgeOrFail();
        }

        $this->assertContains($saleProductBadgeTransfer->getIdProductBadgeOrFail(), $returnedIds);
    }
}
