<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeDataImport\Business;

use Generated\Shared\Transfer\DataImporterConfigurationTransfer;
use Generated\Shared\Transfer\DataImporterReportTransfer;

interface ProductBadgeDataImportFacadeInterface
{
    /**
     * Specification:
     * - Imports product badges from the configured CSV file.
     * - Resolves `abstract_sku` to `id_product_abstract`.
     * - Creates or updates `spy_product_badge` by (fk_product_abstract, badge_key).
     *
     * @api
     */
    public function importProductBadge(?DataImporterConfigurationTransfer $dataImporterConfigurationTransfer = null): DataImporterReportTransfer;
}
