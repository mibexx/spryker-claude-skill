<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Yves\ProductBadgeWidget;

use Pyz\Client\ProductBadge\ProductBadgeClientInterface;
use Spryker\Yves\Kernel\AbstractFactory;

class ProductBadgeWidgetFactory extends AbstractFactory
{
    public function getProductBadgeClient(): ProductBadgeClientInterface
    {
        return $this->getProvidedDependency(ProductBadgeWidgetDependencyProvider::CLIENT_PRODUCT_BADGE);
    }
}
