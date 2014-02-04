<?php

namespace Openl10n\Cli\Command;

use Guzzle\Http\Exception\ClientErrorResponseException;
use Openl10n\Sdk\Client;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Glob;
use Symfony\Component\Yaml\Yaml;

class PushCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('push')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $data = $this->getConfig();

        $client = new Client(array(
            'hostname' => $data['server']['hostname'],
            'login' => $data['server']['username'],
            'password' => $data['server']['password'],
            'scheme' => $data['server']['use_ssl'] ? 'https' : 'http',
        ));

        // Get project
        try {
            $command = $client->getCommand('GetProject', array(
                'slug' => $data['project']['slug'],
            ));
            $client->execute($command);
        } catch (ClientErrorResponseException $e) {
            if (404 !== $e->getResponse()->getStatusCode()) {
                throw $e;
            }

            $output->writeln(sprintf(
                '<info>Creating project <comment>%s</comment></info>',
                $data['project']['slug']
            ));

            $command = $client->getCommand('CreateProject', array(
                'slug' => $data['project']['slug'],
                'name' => ucfirst($data['project']['slug']),
            ));
            $client->execute($command);
        }

        // Ensure locales are present
        $command = $client->getCommand('ListLanguages', array(
            'project' => $data['project']['slug'],
        ));
        $response = $client->execute($command);
        $locales = array();
        foreach ($response as $language) {
            $locales[] = $language['locale'];
        }

        $localesToCreate = array_diff($data['project']['locales'], $locales);

        foreach ($localesToCreate as $locale) {
            $output->writeln(sprintf(
                '<info>Adding locale <comment>%s</comment></info>',
                $locale
            ));

            $command = $client->getCommand('CreateLanguage', array(
                'project' => $data['project']['slug'],
                'locale' => $locale,
            ));
            $response = $client->execute($command);
        }

        // Import files
        foreach ($data['files'] as $file) {
            $pattern = $file['source'];
            $pattern = str_replace('<domain>', '___DOMAIN_PLACEHOLDER___', $pattern);
            $pattern = str_replace('<locale>', '___LOCALE_PLACEHOLDER___', $pattern);
            $pattern = Glob::toRegex($pattern);

            $pattern = str_replace('___DOMAIN_PLACEHOLDER___', '(?P<domain>\w+)', $pattern);
            $pattern = str_replace('___LOCALE_PLACEHOLDER___', '(?P<locale>\w+)', $pattern);

            $finder = new Finder();
            $finder->in(getcwd())->path($pattern);
            foreach ($finder->files() as $file) {
                if (!preg_match($pattern, $file->getRelativePathname(), $matches)) {
                    $output->writeln(sprintf(
                        'File %s does match pattern %s',
                        $file->getRelativePathname(),
                        $pattern
                    ));
                    continue;
                }

                $output->writeln(sprintf(
                    '<info>Importing file <comment>%s</comment></info>',
                    $file->getRelativePathname()
                ));

                $command = $client->getCommand('ImportDomain', array(
                    'project' => $data['project']['slug'],
                    'slug' => $matches['domain'],
                    'locale' => $matches['locale'],
                    'file' => '@'.$file->getRealPath()
                ));
                $client->execute($command);
            }
        }
    }
}
