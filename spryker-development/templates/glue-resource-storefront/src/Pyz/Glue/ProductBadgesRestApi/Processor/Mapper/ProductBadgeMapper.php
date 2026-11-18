<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesRestApi\Processor\Mapper;

use Generated\Shared\Transfer\ProductBadgeTransfer;
use Generated\Shared\Transfer\RestProductBadgesAttributesTransfer;

class ProductBadgeMapper implements ProductBadgeMapperInterface
{
    /**
     * @param array<mixed> $productBadges
     *
     * @return array<string, \Generated\Shared\Transfer\RestProductBadgesAttributesTransfer>
     */
    public function mapProductBadgesToRestProductBadgesAttributesTransfers(array $productBadges): array
    {
        $restProductBadgesAttributesTransfers = [];

        foreach ($productBadges as $productBadge) {
            $productBadgeTransfer = $productBadge instanceof ProductBadgeTransfer
                ? $productBadge
                : (new ProductBadgeTransfer())->fromArray((array)$productBadge, true);

            $restProductBadgesAttributesTransfers[(string)$productBadgeTransfer->getBadgeKey()] = (new RestProductBadgesAttributesTransfer())
                ->fromArray($productBadgeTransfer->toArray(), true);
        }

        return $restProductBadgesAttributesTransfers;
    }
}
