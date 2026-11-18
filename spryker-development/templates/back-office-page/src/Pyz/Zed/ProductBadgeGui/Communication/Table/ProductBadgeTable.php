<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Pyz\Zed\ProductBadgeGui\Communication\Table;

use Orm\Zed\ProductBadge\Persistence\Map\SpyProductBadgeTableMap;
use Orm\Zed\ProductBadge\Persistence\SpyProductBadge;
use Orm\Zed\ProductBadge\Persistence\SpyProductBadgeQuery;
use Pyz\Zed\ProductBadgeGui\ProductBadgeGuiConfig;
use Spryker\Zed\Gui\Communication\Table\AbstractTable;
use Spryker\Zed\Gui\Communication\Table\TableConfiguration;

class ProductBadgeTable extends AbstractTable
{
    public function __construct(protected SpyProductBadgeQuery $productBadgeQuery)
    {
    }

    protected function configure(TableConfiguration $config): TableConfiguration
    {
        // Absolute URL: calls ListController::tableAction()
        $config->setUrl(ProductBadgeGuiConfig::URL_PRODUCT_BADGE_LIST_TABLE);
        $config->setHeader([
            SpyProductBadgeTableMap::COL_ID_PRODUCT_BADGE => 'ID',
            SpyProductBadgeTableMap::COL_FK_PRODUCT_ABSTRACT => 'Product Abstract ID',
            SpyProductBadgeTableMap::COL_BADGE_KEY => 'Badge Key',
            SpyProductBadgeTableMap::COL_LABEL => 'Label',
        ]);
        $config->setSearchable([
            SpyProductBadgeTableMap::COL_BADGE_KEY,
            SpyProductBadgeTableMap::COL_LABEL,
        ]);
        $config->setSortable([
            SpyProductBadgeTableMap::COL_ID_PRODUCT_BADGE,
            SpyProductBadgeTableMap::COL_FK_PRODUCT_ABSTRACT,
            SpyProductBadgeTableMap::COL_BADGE_KEY,
            SpyProductBadgeTableMap::COL_LABEL,
        ]);
        $config->setDefaultSortColumnIndex(0);
        $config->setDefaultSortDirection(TableConfiguration::SORT_DESC);

        return $config;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function prepareData(TableConfiguration $config): array
    {
        $rows = [];

        /** @var iterable<\Orm\Zed\ProductBadge\Persistence\SpyProductBadge> $productBadgeEntities */
        $productBadgeEntities = $this->runQuery($this->productBadgeQuery, $config, true);

        foreach ($productBadgeEntities as $productBadgeEntity) {
            $rows[] = $this->mapRow($productBadgeEntity);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapRow(SpyProductBadge $productBadgeEntity): array
    {
        return [
            SpyProductBadgeTableMap::COL_ID_PRODUCT_BADGE => $productBadgeEntity->getIdProductBadge(),
            SpyProductBadgeTableMap::COL_FK_PRODUCT_ABSTRACT => $productBadgeEntity->getFkProductAbstract(),
            SpyProductBadgeTableMap::COL_BADGE_KEY => $productBadgeEntity->getBadgeKey(),
            SpyProductBadgeTableMap::COL_LABEL => $productBadgeEntity->getLabel(),
        ];
    }
}
