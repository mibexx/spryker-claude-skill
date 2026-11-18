<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Yves\ProductBadgePage\Plugin\Router;

use Spryker\Yves\Router\Plugin\RouteProvider\AbstractRouteProviderPlugin;
use Spryker\Yves\Router\Route\RouteCollection;

class ProductBadgePageRouteProviderPlugin extends AbstractRouteProviderPlugin
{
    public const ROUTE_NAME_PRODUCT_BADGE = 'product-badge';

    protected const PARAMETER_ID_PRODUCT_ABSTRACT = 'idProductAbstract';

    public function addRoutes(RouteCollection $routeCollection): RouteCollection
    {
        $route = $this->buildGetRoute('/product-badge/{idProductAbstract}', 'ProductBadgePage', 'ProductBadge', 'indexAction')
            ->setRequirement(static::PARAMETER_ID_PRODUCT_ABSTRACT, '\d+');

        $routeCollection->add(static::ROUTE_NAME_PRODUCT_BADGE, $route);

        return $routeCollection;
    }
}
