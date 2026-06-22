<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\Product;

/**
 * Rebuilds the human-readable slug map for every filterable select/multiselect
 * attribute. Shared by the CLI (etechflow:seo-nav:generate-aliases) and the
 * admin "Rebuild SEO URLs" button so both run identical logic.
 *
 * Strategy: per (attribute, store) DELETE then re-INSERT the full set, so the
 * table always reflects the current option labels and stale slugs never linger.
 * Collisions within an attribute are disambiguated with a -2/-3 suffix.
 */
class AliasRebuilder
{
    private const TABLE = 'etechflow_seo_filter_alias';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SlugGenerator $slugger,
        private readonly AliasResolver $resolver
    ) {
    }

    /**
     * @return array{
     *   attributes: array<int,array{code:string,attribute_id:int,count:int,collisions:int}>,
     *   total:int, store_id:int, dry_run:bool
     * }
     */
    public function rebuild(int $storeId = 0, ?string $onlyCode = null, bool $dryRun = false): array
    {
        $connection = $this->resource->getConnection();
        $aliasTable = $this->resource->getTableName(self::TABLE);

        $summary = ['attributes' => [], 'total' => 0, 'store_id' => $storeId, 'dry_run' => $dryRun];

        foreach ($this->filterableAttributes($onlyCode) as $attr) {
            $attributeId = (int) $attr['attribute_id'];
            $code = (string) $attr['attribute_code'];

            $options = $this->optionLabels($attributeId, $storeId);
            if (!$options) {
                continue;
            }

            [$rows, $collisions] = $this->buildRows($attributeId, $storeId, $options);

            if (!$dryRun) {
                $connection->beginTransaction();
                try {
                    $connection->delete($aliasTable, [
                        'attribute_id = ?' => $attributeId,
                        'store_id = ?'     => $storeId,
                    ]);
                    $connection->insertMultiple($aliasTable, $rows);
                    $connection->commit();
                } catch (\Throwable $e) {
                    $connection->rollBack();
                    throw $e;
                }
            }

            $summary['attributes'][] = [
                'code'         => $code,
                'attribute_id' => $attributeId,
                'count'        => count($rows),
                'collisions'   => $collisions,
            ];
            $summary['total'] += count($rows);
        }

        if (!$dryRun) {
            $this->resolver->flush();
        }

        return $summary;
    }

    /** Current number of alias rows on disk (for the admin page summary). */
    public function currentAliasCount(): int
    {
        $connection = $this->resource->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()->from($this->resource->getTableName(self::TABLE), 'COUNT(*)')
        );
    }

    /** @return array<int, array{attribute_id:int, attribute_code:string}> */
    private function filterableAttributes(?string $onlyCode): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id', 'attribute_code'])
            ->join(['c' => $this->resource->getTableName('catalog_eav_attribute')], 'c.attribute_id = a.attribute_id', [])
            ->join(['et' => $this->resource->getTableName('eav_entity_type')], 'et.entity_type_id = a.entity_type_id', [])
            ->where('et.entity_type_code = ?', Product::ENTITY)
            ->where('c.is_filterable > 0')
            ->where('a.frontend_input IN (?)', ['select', 'multiselect']);

        if ($onlyCode) {
            $select->where('a.attribute_code = ?', $onlyCode);
        }

        return $connection->fetchAll($select);
    }

    /** @return array<int, string> option_id => label */
    private function optionLabels(int $attributeId, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->resource->getTableName('eav_attribute_option')], ['option_id'])
            ->joinLeft(
                ['ov0' => $this->resource->getTableName('eav_attribute_option_value')],
                'ov0.option_id = o.option_id AND ov0.store_id = 0',
                []
            )
            ->joinLeft(
                ['ovs' => $this->resource->getTableName('eav_attribute_option_value')],
                $connection->quoteInto('ovs.option_id = o.option_id AND ovs.store_id = ?', $storeId),
                []
            )
            ->where('o.attribute_id = ?', $attributeId)
            ->columns(['label' => new \Zend_Db_Expr('COALESCE(ovs.value, ov0.value)')]);

        $labels = [];
        foreach ($connection->fetchAll($select) as $row) {
            $labels[(int) $row['option_id']] = (string) $row['label'];
        }
        return $labels;
    }

    /**
     * @param array<int,string> $options
     * @return array{0: array<int,array<string,mixed>>, 1: int} [rows, collisionCount]
     */
    private function buildRows(int $attributeId, int $storeId, array $options): array
    {
        $rows = [];
        $used = [];
        $collisions = 0;

        foreach ($options as $optionId => $label) {
            $slug = $this->slugger->slugify($label);
            if ($slug === '') {
                $slug = 'opt-' . $optionId;
            }
            $candidate = $slug;
            $i = 1;
            while (isset($used[$candidate])) {
                $i++;
                $candidate = $slug . '-' . $i;
                $collisions++;
            }
            $used[$candidate] = true;

            $rows[] = [
                'attribute_id' => $attributeId,
                'option_id'    => $optionId,
                'store_id'     => $storeId,
                'alias'        => $candidate,
            ];
        }

        return [$rows, $collisions];
    }
}
