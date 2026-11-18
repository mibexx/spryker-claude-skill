<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadge\Business\Validator;

use Generated\Shared\Transfer\ProductBadgeTransfer;

interface ProductBadgeValidatorInterface
{
    /**
     * @param \Generated\Shared\Transfer\ProductBadgeTransfer $productBadgeTransfer
     *
     * @throws \Pyz\Zed\ProductBadge\Business\Exception\InvalidProductBadgeException
     */
    public function validate(ProductBadgeTransfer $productBadgeTransfer): void;
}
