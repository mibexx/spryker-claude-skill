<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeGui\Communication;

use Generated\Shared\Transfer\ProductBadgeTransfer;
use Orm\Zed\ProductBadge\Persistence\SpyProductBadgeQuery;
use Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface;
use Pyz\Zed\ProductBadgeGui\Communication\Form\DataProvider\ProductBadgeFormDataProvider;
use Pyz\Zed\ProductBadgeGui\Communication\Form\ProductBadgeForm;
use Pyz\Zed\ProductBadgeGui\Communication\Table\ProductBadgeTable;
use Pyz\Zed\ProductBadgeGui\ProductBadgeGuiDependencyProvider;
use Spryker\Zed\Kernel\Communication\AbstractCommunicationFactory;
use Symfony\Component\Form\FormInterface;

/**
 * @method \Pyz\Zed\ProductBadgeGui\ProductBadgeGuiConfig getConfig()
 */
class ProductBadgeGuiCommunicationFactory extends AbstractCommunicationFactory
{
    public function createProductBadgeTable(): ProductBadgeTable
    {
        return new ProductBadgeTable($this->getProductBadgePropelQuery());
    }

    public function createProductBadgeFormDataProvider(): ProductBadgeFormDataProvider
    {
        return new ProductBadgeFormDataProvider();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function getProductBadgeForm(ProductBadgeTransfer $productBadgeTransfer, array $options = []): FormInterface
    {
        return $this->getFormFactory()->create(ProductBadgeForm::class, $productBadgeTransfer, $options);
    }

    public function getProductBadgeFacade(): ProductBadgeFacadeInterface
    {
        return $this->getProvidedDependency(ProductBadgeGuiDependencyProvider::FACADE_PRODUCT_BADGE);
    }

    public function getProductBadgePropelQuery(): SpyProductBadgeQuery
    {
        return $this->getProvidedDependency(ProductBadgeGuiDependencyProvider::PROPEL_QUERY_PRODUCT_BADGE);
    }
}
