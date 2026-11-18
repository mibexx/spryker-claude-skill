<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesBackendApi\Processor\Creator;

use Generated\Shared\Transfer\GlueResponseTransfer;
use Generated\Shared\Transfer\ProductBadgesBackendApiAttributesTransfer;

interface ProductBadgeCreatorInterface
{
    public function createProductBadge(ProductBadgesBackendApiAttributesTransfer $attributesTransfer): GlueResponseTransfer;
}
