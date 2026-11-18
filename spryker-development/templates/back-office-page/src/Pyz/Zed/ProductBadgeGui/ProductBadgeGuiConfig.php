<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeGui;

use Spryker\Zed\Kernel\AbstractBundleConfig;

class ProductBadgeGuiConfig extends AbstractBundleConfig
{
    public const URL_PRODUCT_BADGE_LIST = '/product-badge-gui/list';

    public const URL_PRODUCT_BADGE_LIST_TABLE = '/product-badge-gui/list/table';

    public const URL_PRODUCT_BADGE_CREATE = '/product-badge-gui/create';
}
