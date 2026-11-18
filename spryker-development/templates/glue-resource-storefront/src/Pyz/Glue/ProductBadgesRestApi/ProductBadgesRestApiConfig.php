<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesRestApi;

use Spryker\Glue\Kernel\AbstractBundleConfig;

class ProductBadgesRestApiConfig extends AbstractBundleConfig
{
    public const RESOURCE_PRODUCT_BADGES = 'product-badges';

    public const PARAMETER_ID_PRODUCT_ABSTRACT = 'idProductAbstract';

    /**
     * Project-specific error codes, keep them unique per module.
     */
    public const RESPONSE_CODE_MISSING_ID_PRODUCT_ABSTRACT = '4401';

    public const RESPONSE_CODE_PRODUCT_BADGE_NOT_FOUND = '4402';

    public const RESPONSE_DETAIL_MISSING_ID_PRODUCT_ABSTRACT = 'Query parameter "idProductAbstract" is missing or invalid.';

    public const RESPONSE_DETAIL_PRODUCT_BADGE_NOT_FOUND = 'Product badge not found.';
}
