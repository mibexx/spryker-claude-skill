<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesBackendApi;

use Spryker\Glue\Kernel\AbstractBundleConfig;

class ProductBadgesBackendApiConfig extends AbstractBundleConfig
{
    /**
     * Resource type, also the URL segment: /product-badges.
     */
    public const RESOURCE_PRODUCT_BADGES = 'product-badges';

    public const QUERY_PARAMETER_BADGE_KEY = 'badgeKey';

    public const ERROR_CODE_INVALID_PRODUCT_BADGE = '4501';

    public const ERROR_MESSAGE_INVALID_PRODUCT_BADGE = 'Attributes "fkProductAbstract", "badgeKey" and "label" are required.';

    /**
     * Invalid badge key or unknown abstract product (InvalidProductBadgeException from the facade), HTTP 422.
     */
    public const ERROR_CODE_UNPROCESSABLE_PRODUCT_BADGE = '4502';
}
