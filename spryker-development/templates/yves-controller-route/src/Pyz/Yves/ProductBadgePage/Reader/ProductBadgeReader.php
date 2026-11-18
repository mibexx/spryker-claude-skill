<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Yves\ProductBadgePage\Reader;

use Pyz\Client\ProductBadge\ProductBadgeClientInterface;

class ProductBadgeReader implements ProductBadgeReaderInterface
{
    public function __construct(protected ProductBadgeClientInterface $productBadgeClient)
    {
    }

    /**
     * @param int $idProductAbstract
     *
     * @return array<\Generated\Shared\Transfer\ProductBadgeTransfer>
     */
    public function getProductBadgesByIdProductAbstract(int $idProductAbstract): array
    {
        return $this->productBadgeClient->findProductBadgesByIdProductAbstract($idProductAbstract);
    }
}
