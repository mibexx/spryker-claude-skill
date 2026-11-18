<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeGui\Communication\Form\DataProvider;

use Generated\Shared\Transfer\ProductBadgeTransfer;

class ProductBadgeFormDataProvider
{
    public function getData(): ProductBadgeTransfer
    {
        return new ProductBadgeTransfer();
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return [
            'data_class' => ProductBadgeTransfer::class,
        ];
    }
}
