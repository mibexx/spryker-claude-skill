<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Yves\ProductBadgePage;

use Pyz\Client\ProductBadge\ProductBadgeClientInterface;
use Pyz\Yves\ProductBadgePage\Reader\ProductBadgeReader;
use Pyz\Yves\ProductBadgePage\Reader\ProductBadgeReaderInterface;
use Spryker\Yves\Kernel\AbstractFactory;

class ProductBadgePageFactory extends AbstractFactory
{
    public function createProductBadgeReader(): ProductBadgeReaderInterface
    {
        return new ProductBadgeReader($this->getProductBadgeClient());
    }

    public function getProductBadgeClient(): ProductBadgeClientInterface
    {
        return $this->getProvidedDependency(ProductBadgePageDependencyProvider::CLIENT_PRODUCT_BADGE);
    }
}
