<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeDataImport\Business\Writer\ProductBadge\DataSet;

interface ProductBadgeDataSetInterface
{
    /**
     * CSV columns (header row of product_badge.csv).
     */
    public const string COLUMN_ABSTRACT_SKU = 'abstract_sku';

    public const string COLUMN_BADGE_KEY = 'badge_key';

    public const string COLUMN_LABEL = 'label';

    /**
     * Keys added by steps to the DataSet.
     */
    public const string ID_PRODUCT_ABSTRACT = 'id_product_abstract';
}
