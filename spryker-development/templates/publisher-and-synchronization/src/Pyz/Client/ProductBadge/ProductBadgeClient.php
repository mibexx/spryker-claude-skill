<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Client\ProductBadge;

use Spryker\Client\Kernel\AbstractClient;

/**
 * @method \Pyz\Client\ProductBadge\ProductBadgeFactory getFactory()
 */
class ProductBadgeClient extends AbstractClient implements ProductBadgeClientInterface
{
    /**
     * {@inheritDoc}
     *
     * @api
     *
     * @param int $idProductAbstract
     *
     * @return array<\Generated\Shared\Transfer\ProductBadgeTransfer>
     */
    public function findProductBadgesByIdProductAbstract(int $idProductAbstract): array
    {
        return $this->getFactory()
            ->createProductBadgeStorageReader()
            ->findProductBadgesByIdProductAbstract($idProductAbstract);
    }
}
