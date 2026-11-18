<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesRestApi;

use Pyz\Client\ProductBadge\ProductBadgeClientInterface;
use Pyz\Glue\ProductBadgesRestApi\Processor\Mapper\ProductBadgeMapper;
use Pyz\Glue\ProductBadgesRestApi\Processor\Mapper\ProductBadgeMapperInterface;
use Pyz\Glue\ProductBadgesRestApi\Processor\Reader\ProductBadgeReader;
use Pyz\Glue\ProductBadgesRestApi\Processor\Reader\ProductBadgeReaderInterface;
use Pyz\Glue\ProductBadgesRestApi\Processor\RestResponseBuilder\ProductBadgeRestResponseBuilder;
use Pyz\Glue\ProductBadgesRestApi\Processor\RestResponseBuilder\ProductBadgeRestResponseBuilderInterface;
use Spryker\Glue\Kernel\AbstractFactory;

/**
 * @method \Pyz\Glue\ProductBadgesRestApi\ProductBadgesRestApiConfig getConfig()
 */
class ProductBadgesRestApiFactory extends AbstractFactory
{
    public function createProductBadgeReader(): ProductBadgeReaderInterface
    {
        return new ProductBadgeReader(
            $this->getProductBadgeClient(),
            $this->createProductBadgeMapper(),
            $this->createProductBadgeRestResponseBuilder(),
        );
    }

    public function createProductBadgeMapper(): ProductBadgeMapperInterface
    {
        return new ProductBadgeMapper();
    }

    public function createProductBadgeRestResponseBuilder(): ProductBadgeRestResponseBuilderInterface
    {
        return new ProductBadgeRestResponseBuilder($this->getResourceBuilder());
    }

    public function getProductBadgeClient(): ProductBadgeClientInterface
    {
        return $this->getProvidedDependency(ProductBadgesRestApiDependencyProvider::CLIENT_PRODUCT_BADGE);
    }
}
