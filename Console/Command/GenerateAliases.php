<?php
declare(strict_types=1);

namespace ETechFlow\SeoLayeredNav\Console\Command;

use ETechFlow\SeoLayeredNav\Model\AliasRebuilder;
use ETechFlow\SeoLayeredNav\Model\LicenseValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the slug map for every filterable select/multiselect attribute.
 *
 *   bin/magento etechflow:seo-nav:generate-aliases [--attribute=code] [--store=0] [--dry-run]
 *
 * The actual work lives in {@see AliasRebuilder} so the admin "Rebuild SEO URLs"
 * button and this command run identical logic.
 */
class GenerateAliases extends Command
{
    public function __construct(
        private readonly AliasRebuilder $rebuilder,
        private readonly LicenseValidator $licenseValidator,
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
        if (!$this->licenseValidator->isValid()) {
            $output->writeln('<error>SEO Layered Navigation is not licensed for this host. Enter a valid licence key in Stores > Config > eTechFlow > SEO Layered Navigation.</error>');
            return Command::FAILURE;
        }
        $storeId = (int) $input->getOption('store');
        $dryRun = (bool) $input->getOption('dry-run');
        $onlyCode = $input->getOption('attribute') ?: null;

        try {
            $summary = $this->rebuilder->rebuild($storeId, $onlyCode, $dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (!$summary['attributes']) {
            $output->writeln('<comment>No filterable select/multiselect attributes found.</comment>');
            return Command::SUCCESS;
        }

        foreach ($summary['attributes'] as $a) {
            $output->writeln(sprintf(
                '<info>%s</info> (id %d): %d aliases%s',
                $a['code'],
                $a['attribute_id'],
                $a['count'],
                $a['collisions'] ? sprintf(' — %d collision(s) suffixed', $a['collisions']) : ''
            ));
        }

        $output->writeln(sprintf(
            '%s%d alias rows for store %d.%s',
            $dryRun ? '[dry-run] would write ' : 'Wrote ',
            $summary['total'],
            $storeId,
            $dryRun ? '' : ' Alias cache flushed.'
        ));

        return Command::SUCCESS;
    }
}
