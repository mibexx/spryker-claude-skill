<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeDataImport;

use Generated\Shared\Transfer\DataImporterConfigurationTransfer;
use Spryker\Zed\DataImport\DataImportConfig;

class ProductBadgeDataImportConfig extends DataImportConfig
{
    /**
     * Import type = value of `data_entity` in the import YAML and suffix of `data:import:<type>`.
     */
    public const string IMPORT_TYPE_PRODUCT_BADGE = 'product-badge';

    /**
     * Fallback configuration, used when the plugin is called without a YAML action (no `source`).
     */
    public function getProductBadgeDataImporterConfiguration(): DataImporterConfigurationTransfer
    {
        return $this->buildImporterConfiguration(
            'data/import/common/common/product_badge.csv',
            static::IMPORT_TYPE_PRODUCT_BADGE,
        );
    }
}
