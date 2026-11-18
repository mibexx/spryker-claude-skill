<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge;

use Spryker\Zed\Kernel\AbstractBundleConfig;

class ProductBadgeConfig extends AbstractBundleConfig
{
    protected const ALLOWED_BADGE_KEYS = [
        'new',
        'sale',
        'bestseller',
    ];

    /**
     * Specification:
     * - Returns the badge keys that may be assigned to a product abstract.
     *
     * @api
     *
     * @return array<string>
     */
    public function getAllowedBadgeKeys(): array
    {
        return static::ALLOWED_BADGE_KEYS;
    }
}
