<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Glue\ProductBadgesRestApi\Processor\RestResponseBuilder;

use Spryker\Glue\GlueApplication\Rest\JsonApi\RestResponseInterface;

interface ProductBadgeRestResponseBuilderInterface
{
    /**
     * @param array<string, \Generated\Shared\Transfer\RestProductBadgesAttributesTransfer> $restProductBadgesAttributesTransfers Indexed by badge key.
     */
    public function createProductBadgesRestResponse(array $restProductBadgesAttributesTransfers): RestResponseInterface;

    public function createMissingIdProductAbstractErrorResponse(): RestResponseInterface;

    public function createProductBadgeNotFoundErrorResponse(): RestResponseInterface;
}
