<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeDataImport\Business\Writer\ProductBadge;

use Orm\Zed\Product\Persistence\Map\SpyProductAbstractTableMap;
use Orm\Zed\Product\Persistence\SpyProductAbstractQuery;
use Pyz\Zed\ProductBadgeDataImport\Business\Writer\ProductBadge\DataSet\ProductBadgeDataSetInterface;
use Spryker\Zed\DataImport\Business\Exception\EntityNotFoundException;
use Spryker\Zed\DataImport\Business\Exception\InvalidDataException;
use Spryker\Zed\DataImport\Business\Model\DataImportStep\DataImportStepInterface;
use Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface;

/**
 * Lookup step: resolves `abstract_sku` to `id_product_abstract` once per SKU (in-memory cache).
 */
class AbstractSkuToIdProductAbstractStep implements DataImportStepInterface
{
    /**
     * @var array<string, int>
     */
    protected array $idProductAbstractCache = [];

    public function execute(DataSetInterface $dataSet): void
    {
        $abstractSku = $dataSet[ProductBadgeDataSetInterface::COLUMN_ABSTRACT_SKU];

        if (!$abstractSku) {
            throw new InvalidDataException(sprintf('"%s" is required.', ProductBadgeDataSetInterface::COLUMN_ABSTRACT_SKU));
        }

        if (!isset($this->idProductAbstractCache[$abstractSku])) {
            /** @var int|null $idProductAbstract */
            $idProductAbstract = SpyProductAbstractQuery::create()
                ->select(SpyProductAbstractTableMap::COL_ID_PRODUCT_ABSTRACT)
                ->findOneBySku($abstractSku);

            if (!$idProductAbstract) {
                throw new EntityNotFoundException(sprintf('Could not find abstract product by sku "%s".', $abstractSku));
            }

            $this->idProductAbstractCache[$abstractSku] = (int)$idProductAbstract;
        }

        $dataSet[ProductBadgeDataSetInterface::ID_PRODUCT_ABSTRACT] = $this->idProductAbstractCache[$abstractSku];
    }
}
