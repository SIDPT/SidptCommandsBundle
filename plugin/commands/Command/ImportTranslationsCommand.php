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

class ImportTranslationsCommand extends Command
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
        $this->setName('sidpt:translations:import')
            ->setDescription('Import translations fields from csv')
            ->addArgument('csv_path', InputArgument::REQUIRED, 'file path to save the export in.');

    }

    private function flatten(array $array, $separator = "", $prefix = "")
    {
        $result = [];
        foreach ($array as $key => $item) {
            $newKey = mb_convert_encoding(empty($prefix) ? $key : $prefix.$separator.$key,'UTF-8','UTF-8');
            if (is_array($item)) {
                $result = array_merge($result, $this->flatten($item, $separator, $newKey));
            } else {
                $result[$newKey] = mb_convert_encoding($item,'UTF-8','UTF-8');
            }
        }

        return $result;
    }

    /**
 * Merges any number of arrays / parameters recursively, replacing
 * entries with string keys with values from latter arrays.
 * If the entry or the next value to be assigned is an array, then it
 * automagically treats both arguments as an array.
 * Numeric entries are appended, not replaced, but only if they are
 * unique
 *
 * calling: result = array_merge_recursive_distinct(a1, a2, ... aN)
**/
private function array_merge_recursive_distinct()
{
    $arrays = func_get_args();
    $base = array_shift($arrays);
    if (!is_array($base)) {
        $base = empty($base) ? array() : array($base);
    }
    foreach ($arrays as $append) {
        if (!is_array($append)) {
            $append = array($append);
        }
        foreach ($append as $key => $value) {
            if (!array_key_exists($key, $base) and !is_numeric($key)) {
                $base[$key] = $append[$key];
                continue;
            }
            if (is_array($value) or is_array($base[$key])) {
                $base[$key] = $this->array_merge_recursive_distinct($base[$key], $append[$key]);
            } else {
                if (is_numeric($key)) {
                    if (!in_array($value, $base)) {
                        $base[] = $value;
                    }
                } else {
                    $base[$key] = $value;
                }
            }
        }
    }
    return $base;
}

    private function unflatten($fieldPath, $separator, $value){
      $result = [];
      $fields = explode($separator,$fieldPath);
      if(count($fields) == 0) throw new \Exception('empty field path while unflattening');
      $result[$fields[0]] = count($fields) > 1 ?
        $this->unflatten(implode($separator,array_slice($fields,1)),$separator,$value) :
        $value;
      return $result;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $csv_path = $input->getArgument('csv_path');
        $rows = array();
        if (($handle = fopen($csv_path, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 0)) !== FALSE) {
                $rows[] = $data;
            }
            fclose($handle);
        }
        $header = array_shift($rows);


        $csv    = array();

        foreach($rows as $row) {
           $csv[] = array_combine($header, $row);
        }
        $localesList = array_diff($header, ['bundle','domain', 'field']);

        // current translations available by
        // bundle/domain/locale/field
        $filesByBundles = $this->getTranslationFilesByBundles();
        $fieldsTranslations = [];
        $this->output->writeln("Retrieve existing translations");
        foreach ($filesByBundles as $bundle => $filesByDomain) {
            $fieldsTranslations[$bundle] = [];

            foreach ($filesByDomain as $domain => $filesByLocale) {
                if (!array_key_exists($domain, $fieldsTranslations[$bundle])) {
                    $fieldsTranslations[$bundle][$domain] = [];
                }
                foreach ($filesByLocale as $locale => $file) {
                    //$this->output->writeln($bundle." - ". $domain.' - '.$locale);
                    if (!array_key_exists($locale, $fieldsTranslations[$bundle][$domain])) {
                        $fieldsTranslations[$bundle][$domain][$locale] = [];
                    }
                    try{
                      $translations = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

                      if (!empty($translations)) {
                        $flattenedFields = $this->flatten($translations, ".");
                        foreach ($flattenedFields as $field => $value) {
                          if($value !=""){ // avoid empty fields
                            $fieldsTranslations[$bundle][$domain][$locale][$field] = $value;
                          }
                        }
                      }
                    } catch (\Exception $e){
                      $this->output->writeln("An error was raised while parsing json file ".$file.' : '.$e->getMessage());
                    }
                }
            }
        }
        $this->output->writeln("Updating translations");
        // update translations of fields using the csv
        foreach($csv as $row) {
            // get bundle domain and field of each row
            $bundle = $row['bundle'];
            $domain = $row['domain'];
            $field = $row['field'];
            $this->output->writeln(
               $bundle." - ". $domain.' - '.$field
            );
            if(array_key_exists($bundle, $fieldsTranslations)){
                foreach($localesList as $locale){
                    $rowLocaledata = str_replace('\\\\','\\',$row[$locale]);//trim(, " \t\n\r\0\x0B\xC2\xA0");
                    if(!empty($rowLocaledata)){
                        if (!array_key_exists($locale, $fieldsTranslations[$bundle][$domain])) {
                            $fieldsTranslations[$bundle][$domain][$locale] = [];
                        }
                        $fieldsTranslations[$bundle][$domain][$locale][$field] = $rowLocaledata;
                    }
                }
            }
        }
        $this->output->writeln("Saving back translations");
        // write back echo bundle/domain/local translations to its own file
        foreach ($fieldsTranslations as $bundle => $fieldsInBundle) {
            try{
              $translationDir = realpath($this->getTranslationsDirectory($bundle));
              foreach ($fieldsInBundle as $domain => $fieldsInDomain) {
                  foreach ($fieldsInDomain as $locale => $fieldsInLocale) {
                      try{
                        $jsonData = json_encode($fieldsInLocale, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);

                        if(false === file_put_contents(
                            $translationDir.'/'.$domain.'.'.$locale.'.json',
                            $jsonData
                          )
                        ){
                          $this->output->writeln(
                            $bundle
                            ." - could not write translations to "
                            .$translationDir.'/'.$domain.'.'.$locale.'.json');
                        }
                      }catch(\Exception $e){
                        $this->output->writeln($translationDir.'/'.$domain.'.'.$locale.'.json'." - ".$e->getMessage());
                        $this->output->writeln("Data not written : \r\n".print_r($fieldsInLocale,true));
                      }
                  }
              }
            } catch (\Exception $e) {
                $this->output->writeln($bundle." - ".$e->getMessage());

            }

        }

        // Update translation

        //
        // if($localesList){
        //   $locales = explode(" ", $localesList);
        // } else {
        //   $locales = self::DEFAULT_LOCALES;
        // }
        //
        // $missingsFilter = $input->getOption('missing');
        // $undefinedFilter = $input->getOption('undefined');
        //
        // try {
        //     $headers = array_merge(["bundle","domain","field"], $locales);
        //     fputcsv($csv_file, $headers, ";");
        //     foreach ($fieldsTranslations as $bundle => $bundleTranslations) {
        //         foreach ($bundleTranslations as $domain => $domainTranslations) {
        //             foreach ($domainTranslations as $field => $localeTranslations) {
        //                 $fields = [ $bundle, $domain, $field ];
        //                 $complete = true;
        //                 $hasTranslations = false;
        //                 foreach ($locales as $key => $locale) {
        //                     $complete = $complete && isset($localeTranslations[$locale]);
        //                     $hasTranslations = $hasTranslations || isset($localeTranslations[$locale]);
        //                     $fields[] = $localeTranslations[$locale] ?? " ";
        //                 }
        //                 if (!($undefinedFilter || $missingsFilter)
        //                     || (!$hasTranslations && $undefinedFilter)
        //                     || (!$complete && $missingsFilter)
        //                 ) {
        //                     fputcsv($csv_file, $fields, ";");
        //                 }
        //
        //                 // $line = "{$bundle};{$domain};{$field};";
        //                 // foreach ($localeTranslations as $locale => $translation) {
        //                 //     $line .= "{$locale} = {$translation};";
        //                 // }
        //                 //$output->writeln("<comment>{$line}</comment>");
        //             }
        //         }
        //     }
        // } catch (Exception $e) {
        //     $output->writeln($e->getMessage());
        // }
        // fclose($csv_file);
        //


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
            $parts = explode('\\', get_class($bundle));
            $shortName = end($parts);

            if ($this->pluginManager->isLoaded($shortName)) {
                $translationFiles[$shortName] = $this->parseDirectoryTranslationFiles($shortName);
            }
        }
        // App bundle is not a plugin bundles but is hosting translations
        $translationFiles['ClarolineAppBundle'] = $this->parseDirectoryTranslationFiles('ClarolineAppBundle');

        return $translationFiles;
    }

    private function getTranslationsDirectory($bundleShortName){
        return $this->container->get('kernel')->locateResource('@'.$bundleShortName.'/Resources/translations');
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
                if ($fileinfo->isFile() && $fileinfo->getExtension() == "json") {
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
