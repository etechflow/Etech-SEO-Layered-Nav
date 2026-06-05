<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Console\Command;

use ETechFlow\SeoLayeredNav\Model\AliasResolver;
use ETechFlow\SeoLayeredNav\Model\SlugGenerator;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the slug map for every filterable select/multiselect attribute.
 *
 *   bin/magento etechflow:seo-nav:generate-aliases [--attribute=code] [--store=0] [--dry-run]
 *
 * Strategy: per (attribute, store) we DELETE then re-INSERT the full set, so the
 * table always reflects the current option labels and stale slugs never linger.
 * Collisions within an attribute are disambiguated with a -2/-3 suffix.
 */
class GenerateAliases extends Command
{
    private const TABLE = 'etechflow_seo_filter_alias';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SlugGenerator $slugger,
        private readonly AliasResolver $resolver,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('etechflow:seo-nav:generate-aliases')
            ->setDescription('Generate human-readable URL aliases for layered-nav filter options')
            ->addOption('attribute', 'a', InputOption::VALUE_REQUIRED, 'Limit to one attribute code')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED, 'Store id for option labels', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeId = (int) $input->getOption('store');
        $dryRun = (bool) $input->getOption('dry-run');
        $onlyCode = $input->getOption('attribute');

        $connection = $this->resource->getConnection();
        $aliasTable = $this->resource->getTableName(self::TABLE);

        $attributes = $this->filterableAttributes($onlyCode);
        if (!$attributes) {
            $output->writeln('<comment>No filterable select/multiselect attributes found.</comment>');
            return Command::SUCCESS;
        }

        $totalRows = 0;
        foreach ($attributes as $attr) {
            $attributeId = (int) $attr['attribute_id'];
            $code = (string) $attr['attribute_code'];

            $options = $this->optionLabels($attributeId, $storeId);
            if (!$options) {
                $output->writeln(sprintf('  <comment>%s</comment>: no options, skipped', $code));
                continue;
            }

            [$rows, $collisions] = $this->buildRows($attributeId, $storeId, $options);

            $output->writeln(sprintf(
                '<info>%s</info> (id %d): %d aliases%s',
                $code,
                $attributeId,
                count($rows),
                $collisions ? sprintf(' — %d collision(s) suffixed', $collisions) : ''
            ));

            if ($dryRun) {
                foreach (array_slice($rows, 0, 5) as $r) {
                    $output->writeln(sprintf('    %d => %s', $r['option_id'], $r['alias']));
                }
                if (count($rows) > 5) {
                    $output->writeln(sprintf('    … +%d more', count($rows) - 5));
                }
                $totalRows += count($rows);
                continue;
            }

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
                $output->writeln(sprintf('    <error>%s failed: %s</error>', $code, $e->getMessage()));
                continue;
            }
            $totalRows += count($rows);
        }

        if (!$dryRun) {
            $this->resolver->flush();
            $output->writeln('<comment>Alias cache flushed.</comment>');
        }

        $output->writeln(sprintf(
            '%s%d alias rows for store %d.',
            $dryRun ? '[dry-run] would write ' : 'Wrote ',
            $totalRows,
            $storeId
        ));
        return Command::SUCCESS;
    }

    /** @return array<int, array{attribute_id:int, attribute_code:string}> */
    private function filterableAttributes(?string $onlyCode): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_id', 'attribute_code'])
            ->join(
                ['c' => $this->resource->getTableName('catalog_eav_attribute')],
                'c.attribute_id = a.attribute_id',
                []
            )
            ->join(
                ['et' => $this->resource->getTableName('eav_entity_type')],
                'et.entity_type_id = a.entity_type_id',
                []
            )
            ->where('et.entity_type_code = ?', \Magento\Catalog\Model\Product::ENTITY)
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
        // store-specific label if present, else fall back to the store-0 (admin) label.
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
