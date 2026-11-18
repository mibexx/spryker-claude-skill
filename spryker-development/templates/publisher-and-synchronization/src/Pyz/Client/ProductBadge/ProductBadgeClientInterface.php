<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Client\ProductBadge;

interface ProductBadgeClientInterface
{
    /**
     * Specification:
     * - Reads the key `product_badge:<idProductAbstract>` from the key-value storage (Redis/Valkey), no Zed request.
     * - Returns an empty array if the key does not exist.
     *
     * @api
     *
     * @param int $idProductAbstract
     *
     * @return array<\Generated\Shared\Transfer\ProductBadgeTransfer>
     */
    public function findProductBadgesByIdProductAbstract(int $idProductAbstract): array;
}
