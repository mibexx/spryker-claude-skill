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

interface ProductBadgeMapperInterface
{
    public function mapAttributesToProductBadgeTransfer(
        ProductBadgesBackendApiAttributesTransfer $attributesTransfer,
        ProductBadgeTransfer $productBadgeTransfer,
    ): ProductBadgeTransfer;

    public function mapProductBadgeTransferToGlueResourceTransfer(
        ProductBadgeTransfer $productBadgeTransfer,
        GlueResourceTransfer $glueResourceTransfer,
    ): GlueResourceTransfer;
}
