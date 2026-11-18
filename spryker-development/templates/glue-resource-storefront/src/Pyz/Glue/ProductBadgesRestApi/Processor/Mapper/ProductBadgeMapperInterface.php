<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesRestApi\Processor\Mapper;

interface ProductBadgeMapperInterface
{
    /**
     * @param array<mixed> $productBadges Items of the client: ProductBadgeTransfer or storage arrays.
     *
     * @return array<string, \Generated\Shared\Transfer\RestProductBadgesAttributesTransfer> Indexed by badge key.
     */
    public function mapProductBadgesToRestProductBadgesAttributesTransfers(array $productBadges): array;
}
