<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Command;

use Supertext\PrestaShop\Api\SupertextException;
use Supertext\PrestaShop\Settings;
use Supertext\PrestaShop\Translation\EntityTranslator;
use Supertext\PrestaShop\Translation\EntityTypes;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * php bin/console supertext:translate product 1 2 --to=de --to=fr [--from=en] [--overwrite]
 *
 * Same translation as the back office, for scripts and CI. Without --to, translates into
 * every other language of the shop.
 */
class TranslateCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('supertext:translate')
            ->setDescription('Translate products, categories or CMS pages with Supertext')
            ->addArgument('type', InputArgument::REQUIRED, 'product, category or cms')
            ->addArgument('ids', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Item ids')
            ->addOption('to', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Target language ISO code (de, fr, …); default: all other languages')
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Source language ISO code; default: the shop default language')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Replace existing translations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string) $input->getArgument('type');

        if (!isset(EntityTypes::TYPES[$type])) {
            $output->writeln('<error>Type must be product, category or cms.</error>');

            return Command::INVALID;
        }

        $context = \Context::getContext();

        if ($context->shop === null || !$context->shop->id) {
            $context->shop = new \Shop((int) \Configuration::get('PS_SHOP_DEFAULT'));
        }

        $from   = $input->getOption('from');
        $source = $from ? new \Language((int) \Language::getIdByIso((string) $from)) : new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));

        if (!\Validate::isLoadedObject($source)) {
            $output->writeln(sprintf('<error>Unknown source language "%s".</error>', $from));

            return Command::INVALID;
        }

        $targets = [];

        foreach ($input->getOption('to') ?: array_column(\Language::getLanguages(false), 'iso_code') as $iso) {
            $language = new \Language((int) \Language::getIdByIso((string) $iso));

            if (!\Validate::isLoadedObject($language)) {
                $output->writeln(sprintf('<error>Unknown target language "%s".</error>', $iso));

                return Command::INVALID;
            }

            if ((int) $language->id !== (int) $source->id) {
                $targets[] = $language;
            }
        }

        if (Settings::apiKey() === '') {
            $output->writeln('<error>No Supertext API key. Set it in the module settings or SUPERTEXT_API_KEY. Create an account at '
                . Settings::SIGNUP_URL . ' and generate the key at ' . Settings::API_KEY_URL . ' (requires the Admin role).</error>');

            return Command::FAILURE;
        }

        $translator = EntityTranslator::create();
        $failed     = 0;

        foreach (EntityTypes::ids($input->getArgument('ids')) as $id) {
            foreach ($targets as $target) {
                $label = sprintf('%s %d → %s', $type, $id, Settings::targetCode($target));

                try {
                    $result = $translator->translate($type, $id, $source, $target, (bool) $input->getOption('overwrite'), (int) $context->shop->id);
                    $output->writeln($result['status'] === 'unchanged'
                        ? sprintf('%s: skipped, already translated (use --overwrite)', $label)
                        : sprintf('%s: translated %d fields, kept %d', $label, \count($result['translated']), \count($result['kept'])));
                } catch (SupertextException|\PrestaShopException $e) {
                    ++$failed;
                    $output->writeln(sprintf('<error>%s: %s</error>', $label, $e->getMessage()));
                }
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
