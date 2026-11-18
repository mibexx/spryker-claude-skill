<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesBackendApi;

use Pyz\Glue\ProductBadgesBackendApi\Processor\Creator\ProductBadgeCreator;
use Pyz\Glue\ProductBadgesBackendApi\Processor\Creator\ProductBadgeCreatorInterface;
use Pyz\Glue\ProductBadgesBackendApi\Processor\Mapper\ProductBadgeMapper;
use Pyz\Glue\ProductBadgesBackendApi\Processor\Mapper\ProductBadgeMapperInterface;
use Pyz\Glue\ProductBadgesBackendApi\Processor\Reader\ProductBadgeReader;
use Pyz\Glue\ProductBadgesBackendApi\Processor\Reader\ProductBadgeReaderInterface;
use Pyz\Zed\ProductBadge\Business\ProductBadgeFacadeInterface;
use Spryker\Glue\Kernel\Backend\AbstractBackendApiFactory;

/**
 * @method \Pyz\Glue\ProductBadgesBackendApi\ProductBadgesBackendApiConfig getConfig()
 */
class ProductBadgesBackendApiFactory extends AbstractBackendApiFactory
{
    public function createProductBadgeReader(): ProductBadgeReaderInterface
    {
        return new ProductBadgeReader(
            $this->getProductBadgeFacade(),
            $this->createProductBadgeMapper(),
        );
    }

    public function createProductBadgeCreator(): ProductBadgeCreatorInterface
    {
        return new ProductBadgeCreator(
            $this->getProductBadgeFacade(),
            $this->createProductBadgeMapper(),
            $this->getConfig(),
        );
    }

    public function createProductBadgeMapper(): ProductBadgeMapperInterface
    {
        return new ProductBadgeMapper();
    }

    public function getProductBadgeFacade(): ProductBadgeFacadeInterface
    {
        return $this->getProvidedDependency(ProductBadgesBackendApiDependencyProvider::FACADE_PRODUCT_BADGE);
    }
}
