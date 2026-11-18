<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesRestApi\Plugin\GlueApplication;

use Generated\Shared\Transfer\RestProductBadgesAttributesTransfer;
use Pyz\Glue\ProductBadgesRestApi\ProductBadgesRestApiConfig;
use Spryker\Glue\GlueApplicationExtension\Dependency\Plugin\ResourceRouteCollectionInterface;
use Spryker\Glue\GlueApplicationExtension\Dependency\Plugin\ResourceRoutePluginInterface;
use Spryker\Glue\Kernel\AbstractPlugin;

class ProductBadgesResourceRoutePlugin extends AbstractPlugin implements ResourceRoutePluginInterface
{
    /**
     * {@inheritDoc}
     * - Registers GET /product-badges and GET /product-badges/{badgeKey} as public (not token protected).
     *
     * @api
     */
    public function configure(ResourceRouteCollectionInterface $resourceRouteCollection): ResourceRouteCollectionInterface
    {
        $resourceRouteCollection->addGet('get', false);

        return $resourceRouteCollection;
    }

    /**
     * @api
     */
    public function getResourceType(): string
    {
        return ProductBadgesRestApiConfig::RESOURCE_PRODUCT_BADGES;
    }

    /**
     * Kebab-case without "Controller" suffix: resolves to ProductBadgesResourceController.
     *
     * @api
     */
    public function getController(): string
    {
        return 'product-badges-resource';
    }

    /**
     * @api
     */
    public function getResourceAttributesClassName(): string
    {
        return RestProductBadgesAttributesTransfer::class;
    }
}
