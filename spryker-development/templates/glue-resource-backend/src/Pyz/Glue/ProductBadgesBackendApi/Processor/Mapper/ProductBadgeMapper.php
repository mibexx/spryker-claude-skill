<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesBackendApi\Processor\Mapper;

use Generated\Shared\Transfer\GlueResourceTransfer;
use Generated\Shared\Transfer\ProductBadgesBackendApiAttributesTransfer;
use Generated\Shared\Transfer\ProductBadgeTransfer;
use Pyz\Glue\ProductBadgesBackendApi\ProductBadgesBackendApiConfig;

class ProductBadgeMapper implements ProductBadgeMapperInterface
{
    public function mapAttributesToProductBadgeTransfer(
        ProductBadgesBackendApiAttributesTransfer $attributesTransfer,
        ProductBadgeTransfer $productBadgeTransfer,
    ): ProductBadgeTransfer {
        return $productBadgeTransfer->fromArray($attributesTransfer->toArray(), true);
    }

    public function mapProductBadgeTransferToGlueResourceTransfer(
        ProductBadgeTransfer $productBadgeTransfer,
        GlueResourceTransfer $glueResourceTransfer,
    ): GlueResourceTransfer {
        $attributesTransfer = (new ProductBadgesBackendApiAttributesTransfer())
            ->fromArray($productBadgeTransfer->toArray(), true);

        return $glueResourceTransfer
            ->setType(ProductBadgesBackendApiConfig::RESOURCE_PRODUCT_BADGES)
            ->setId((string)$productBadgeTransfer->getIdProductBadge())
            ->setAttributes($attributesTransfer);
    }
}
