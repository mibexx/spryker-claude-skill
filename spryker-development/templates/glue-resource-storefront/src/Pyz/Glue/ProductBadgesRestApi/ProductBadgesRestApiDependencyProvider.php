<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesRestApi;

use Spryker\Glue\Kernel\AbstractBundleDependencyProvider;
use Spryker\Glue\Kernel\Container;

/**
 * @method \Pyz\Glue\ProductBadgesRestApi\ProductBadgesRestApiConfig getConfig()
 */
class ProductBadgesRestApiDependencyProvider extends AbstractBundleDependencyProvider
{
    public const CLIENT_PRODUCT_BADGE = 'CLIENT_PRODUCT_BADGE';

    public function provideDependencies(Container $container): Container
    {
        $container = parent::provideDependencies($container);
        $container = $this->addProductBadgeClient($container);

        return $container;
    }

    protected function addProductBadgeClient(Container $container): Container
    {
        $container->set(static::CLIENT_PRODUCT_BADGE, function (Container $container) {
            return $container->getLocator()->productBadge()->client();
        });

        return $container;
    }
}
