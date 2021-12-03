<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sidpt\CommandsBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Claroline\CoreBundle\Manager\PluginManager;
use Symfony\Component\DependencyInjection\Container;

class ExportTranslationsCommand extends Command
{
    const BASE_LANG = 'fr';
    const DEFAULT_LOCALES = ['fr','en','nl','de','es'];

    private $pluginManager;
    private $container;

    private $output;

    public function __construct(PluginManager $pluginManager, Container $container)
    {
        $this->pluginManager = $pluginManager;
        $this->container = $container;

        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('sidpt:translations:export')
            ->setDescription('Export translations fields and value per local.')
            ->addArgument('csv_path', InputArgument::REQUIRED, 'file path to save the export in.')
            ->addArgument('locale_list', InputArgument::OPTIONAL, 'list of locale to select, separated by spaces. List must be enclosed indouble quotes');

        $this->addOption(
            'undefined',
            'u',
            InputOption::VALUE_NONE,
            'When set to true, fields without any translations are exported'
        );

        $this->addOption(
            'missing',
            'm',
            InputOption::VALUE_NONE,
            'When set to true, fields with missing translations are exported'
        );
    }

    private function flatten(array $array, $separator = "", $prefix = "")
    {
        $result = [];
        foreach ($array as $key => $item) {
            $newKey = empty($prefix) ? $key : $prefix.$separator.$key;
            if (is_array($item)) {
                $result = array_merge($result, $this->flatten($item, $separator, $newKey));
            } else {
                $result[$newKey] = $item;
            }
        }

        return $result;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // TODO
        // get all translations file
        // sort translation file by Bundle, Domain and locale
        //For each bundle
        //  if(!array_key_exists(bundle, translations)) translation[bundle] = array
        //
        //  for each domain
        //     if(!array_key_exists(domain,translation[bundle])) translation[bundle][domain] = array
        //     for each targeted local, if there is a translation file
        //          $translations = json_decode(file_get_contents($translationFile), true);
        //          foreach ($translations as $key => $value)
        //              if(!array_key_exists(translation[bundle][domain][field]))translation[bundle][domain][field] = array
        //              translation[bundle][domain][field][locale] = value
        //
        //  exporting CSV
        //  first line : bundle;domain;field;...locales
        //  for each bundle,domain,field
        //      make a line with bundle, domain, field
        //      for each targeted local
        //          if(!empty(translation[bundle][domain][field][locale])) append translation[bundle][domain][field][locale];
        //          append ;
        //
        $this->output = $output;
        $filesByBundles = $this->getTranslationFilesByBundles();
        $fieldsTranslations = [];
        //$output->writeln('<comment> Files found : </comment>');
        foreach ($filesByBundles as $bundle => $filesByDomain) {
            $fieldsTranslations[$bundle] = [];

            foreach ($filesByDomain as $domain => $filesByLocale) {
                if (!array_key_exists($domain, $fieldsTranslations[$bundle])) {
                    $fieldsTranslations[$bundle][$domain] = [];
                }
                foreach ($filesByLocale as $locale => $file) {
                    $translations = json_decode(file_get_contents($file), true);
                    if (!empty($translations)) {
                        foreach ($this->flatten($translations, ".") as $field => $value) {
                            if (!array_key_exists($field, $fieldsTranslations[$bundle][$domain])) {
                                $fieldsTranslations[$bundle][$domain][$field] = [];
                            }
                            $fieldsTranslations[$bundle][$domain][$field][$locale] = $value;
                        }
                    }
                }
            }
        }
        $csv_path = $input->getArgument('csv_path');
        $csv_file = fopen($csv_path, "w");
        $localesList = $input->getArgument('locale_list');
        if($localesList){
          $locales = explode(" ", $localesList);
        } else {
          $locales = self::DEFAULT_LOCALES;
        }

        $missingsFilter = $input->getOption('missing');
        $undefinedFilter = $input->getOption('undefined');

        try {
            $headers = array_merge(["bundle","domain","field"], $locales);
            fputcsv($csv_file, $headers, ";");
            foreach ($fieldsTranslations as $bundle => $bundleTranslations) {
                foreach ($bundleTranslations as $domain => $domainTranslations) {
                    foreach ($domainTranslations as $field => $localeTranslations) {
                        $fields = [ $bundle, $domain, $field ];
                        $complete = true;
                        $hasTranslations = false;
                        foreach ($locales as $key => $locale) {
                            $complete = $complete && isset($localeTranslations[$locale]);
                            $hasTranslations = $hasTranslations || isset($localeTranslations[$locale]);
                            $fields[] = $localeTranslations[$locale] ?? " ";
                        }
                        if (!($undefinedFilter || $missingsFilter)
                            || (!$hasTranslations && $undefinedFilter)
                            || (!$complete && $missingsFilter)
                        ) {
                            fputcsv($csv_file, $fields, ";");
                        }

                        // $line = "{$bundle};{$domain};{$field};";
                        // foreach ($localeTranslations as $locale => $translation) {
                        //     $line .= "{$locale} = {$translation};";
                        // }
                        //$output->writeln("<comment>{$line}</comment>");
                    }
                }
            }
        } catch (Exception $e) {
            $output->writeln($e->getMessage());
        }
        fclose($csv_file);



        // $output->writeln('<comment> Analysing '.self::BASE_LANG.' translations... </comment>');
        // $frenchLations = $this->getLangFiles($translationFiles, self::BASE_LANG);
        // $output->writeln('<comment> Files found : </comment>');
        // if (!empty($frenchLations)) {
        //     foreach ($frenchLations as $key => $value) {
        //         $output->writeln("<comment>{$value}</comment>");
        //     }
        // }
        // $duplicates = $this->getDuplicates($frenchLations);
        // $this->displayDuplicateErrors($duplicates, $output);

        return 0;
    }

    private function getTranslationFilesByBundles()
    {
        // all bundles extending PluginBundles
        $bundles = $this->pluginManager->getInstalledBundles();
        $translationFiles = [];

        foreach ($bundles as $bundle) {
            $parts = explode('\\', get_class($bundle['instance']));
            $shortName = end($parts);

            if ($this->pluginManager->isLoaded($shortName)) {
                $translationFiles[$shortName] = $this->parseDirectoryTranslationFiles($shortName);
            }
        }
        // App bundle is not a plugin bundles but is hosting translations
        $translationFiles['ClarolineAppBundle'] = $this->parseDirectoryTranslationFiles('ClarolineAppBundle');

        return $translationFiles;
    }

    private function getDuplicates($translationFiles)
    {
        $duplicates = [];

        foreach ($translationFiles as $translationFile) {
            $translations = json_decode(file_get_contents($translationFile), true);
            $line = 0;

            if (!$translations) {
                $duplicates['empty'][] = $translationFile;
            } else {
                foreach ($translations as $key => $value) {
                    ++$line;

                    $duplicates['key'][$key][] = [
                        $line,
                        $this->getDomainFromFileName($translationFile),
                        $this->getBundleFromFileName($translationFile),
                        $key,
                    ];

                    if (is_string($value)) {
                        $duplicates['value'][$value][] = [
                            $line,
                            $this->getDomainFromFileName($translationFile),
                            $this->getBundleFromFileName($translationFile),
                            $value,
                        ];
                    } else {
                        $duplicates['array'][] = [
                            $line,
                            $this->getDomainFromFileName($translationFile),
                            $this->getBundleFromFileName($translationFile),
                            $key,
                        ];
                    }
                }
            }
        }

        return $duplicates;
    }

    private function getLangFiles($translationFiles, $lang)
    {
        $langFiles = [];

        foreach ($translationFiles as $translationFile) {
            $parts = explode('/', $translationFile);
            $end = end($parts);
            if ($lang === explode('.', $end)[1]) {
                $langFiles[] = $translationFile;
            }
        }

        return $langFiles;
    }

    private function getDomainFromFileName($file)
    {
        $parts = explode('/', $file);
        $end = end($parts);

        return explode('.', $end)[0];
    }

    private function getLocaleFromFileName($file)
    {
        $parts = explode('/', $file);
        $end = end($parts);

        return explode('.', $end)[1];
    }

    private function getBundleFromFileName($file)
    {
        $startsAt = strpos($file, '/distribution/plugin/') + strlen('/distribution/plugin/');
        $endsAt = strpos($file, '/Resources', $startsAt);
        $result = substr($file, $startsAt, $endsAt - $startsAt);

        if (strpos($result, '/')) {
            $result = 'core';
        }

        return $result;
    }

    private function parseDirectoryTranslationFiles($shortName)
    {
        try {
            $translationFiles = [];
            $translationDir = $this->container->get('kernel')->locateResource('@'.$shortName.'/Resources/translations');
            $iterator = new \DirectoryIterator($translationDir);
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $filePath = realpath($fileinfo->getPathname());
                    $domaine = $this->getDomainFromFileName($filePath);
                    if (!array_key_exists($domaine, $translationFiles)) {
                        $translationFiles[$domaine] = [];
                    }
                    $locale = $this->getLocaleFromFileName($filePath);
                    $translationFiles[$domaine][$locale] = realpath($fileinfo->getPathname());
                    //$translationFiles[] = realpath($fileinfo->getPathname());
                }
            }
        } catch (\Exception $e) {
            $this->output->writeln($shortName." - ".$e->getMessage());
        }

        return $translationFiles;
    }

    private function displayDuplicateErrors($duplicates, OutputInterface $output)
    {
        $output->writeln('<comment> Displaying duplicate keys result: </comment>');
        $totalDuplicates = 0;
        $totalLines = 0;
        if (!empty($duplicates['key'])) {
            foreach ($duplicates['key'] as $key => $values) {
                if (count($values) > 1) {
                    $output->writeln("<error>Key \"{$key}\" as duplicatas:</error>");
                    ++$totalDuplicates;
                    foreach ($values as $value) {
                        ++$totalLines;
                        $output->writeln("  <comment>{$value[2]}/translations/{$value[1]}.fr.yml line {$value[0]}</comment>");
                    }
                }
            }
        }


        $output->writeln('<comment> Displaying duplicate translations result: </comment>');

        if (!empty($duplicates['value'])) {
            foreach ($duplicates['value'] as $key => $values) {
                if (count($values) > 1) {
                    $output->writeln("<error>Translations \"{$key}\" as duplicatas:</error>");
                    ++$totalDuplicates;
                    foreach ($values as $value) {
                        ++$totalLines;
                        $output->writeln("  <comment>{$value[2]}:{$value[1]}.fr.yml line {$value[0]}</comment>");
                    }
                }
            }
        }

        $output->writeln('<comment> Displaying array translations result: </comment>');
        if (!empty($duplicates['array'])) {
            foreach ($duplicates['array'] as $key => $value) {
                $output->writeln("  <error>Array found at {$value[2]}:{$value[1]}.fr.yml line {$value[0]}</error>");
            }
        }

        $output->writeln(' ');
        $output->writeln("{$totalDuplicates} duplicates");
        $output->writeln("{$totalLines} lines to fix");
        $output->writeln('The lines indications are not accurate at all. Use ctrl+f to find what you search.');
    }
}
