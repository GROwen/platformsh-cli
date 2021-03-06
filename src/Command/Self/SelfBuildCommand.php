<?php
namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfBuildCommand extends CommandBase
{
    protected $hiddenInList = true;
    protected $local = true;

    protected function configure()
    {
        $this
            ->setName('self:build')
            ->setDescription('Build a new package of the CLI')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The path to a private key')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'The output filename', $this->config()->get('application.executable') . '.phar')
            ->addOption('no-composer-rebuild', null, InputOption::VALUE_NONE, 'Skip rebuilding Composer dependencies')
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'The manifest file to update')
            ->addOption('manifest-mode', null, InputOption::VALUE_REQUIRED, 'How to update the manifest file', 'update-latest');
    }

    public function isEnabled()
    {
        // You can't build a Phar from another Phar.
        return !extension_loaded('Phar') || !\Phar::running(false);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!file_exists(CLI_ROOT . '/vendor')) {
            $this->stdErr->writeln('Directory not found: <error>' . CLI_ROOT . '/vendor</error>');
            $this->stdErr->writeln('Cannot build from a global install');
            return 1;
        }

        if (ini_get('phar.readonly')) {
            $this->stdErr->writeln('The <error>phar.readonly</error> PHP setting is enabled.');
            $this->stdErr->writeln('Disable it in your php.ini configuration.');
            return 1;
        }

        $outputFilename = $input->getOption('output');
        if ($outputFilename && !is_writable(dirname($outputFilename))) {
            $this->stdErr->writeln("Not writable: <error>$outputFilename</error>");
            return 1;
        }

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');
        if (!$shell->commandExists('box')) {
            $this->stdErr->writeln('Command not found: <error>box</error>');
            $this->stdErr->writeln('The Box utility is required to build new CLI packages. Try:');
            $this->stdErr->writeln('  composer global require kherge/box:~2.5');
            return 1;
        }

        $keyFilename = $input->getOption('key');
        if ($keyFilename && !file_exists($keyFilename)) {
            $this->stdErr->writeln("File not found: <error>$keyFilename</error>");
            return 1;
        }

        $version = $this->config()->get('application.version');

        $boxConfig = [];
        if ($outputFilename) {
            /** @var \Platformsh\Cli\Service\Filesystem $fs */
            $fs = $this->getService('fs');
            $boxConfig['output'] = $fs->makePathAbsolute($outputFilename);
            $phar = $boxConfig['output'];
        } else {
            // Default output: cli-VERSION.phar in the current directory.
            $boxConfig['output'] = getcwd() . '/cli-' . $version . '.phar';
            $phar = $boxConfig['output'];
        }
        if ($keyFilename) {
            $boxConfig['key'] = realpath($keyFilename);
        }

        if (file_exists($phar)) {
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            if (!$questionHelper->confirm("File exists: <comment>$phar</comment>. Overwrite?")) {
                return 1;
            }
        }

        if (!$input->getOption('no-composer-rebuild')) {
            $this->stdErr->writeln('Ensuring correct composer dependencies');

            // Remove the 'vendor' directory, in case the developer has incorporated
            // their own version of dependencies locally.
            $shell->execute(['rm', '-r', 'vendor'], CLI_ROOT, true, false);

            $shell->execute([
                $shell->resolveCommand('composer'),
                'install',
                '--no-dev',
                '--classmap-authoritative',
                '--no-interaction',
                '--no-progress',
            ], CLI_ROOT, true, false);
        }

        $boxArgs = [$shell->resolveCommand('box'), 'build', '--no-interaction'];

        // Create a temporary box.json file for this build.
        if (!empty($boxConfig)) {
            $originalConfig = json_decode(file_get_contents(CLI_ROOT . '/box.json'), true);
            $boxConfig = array_merge($originalConfig, $boxConfig);
            $boxConfig['base-path'] = CLI_ROOT;
            $tmpJson = tempnam('/tmp', 'box_json');
            file_put_contents($tmpJson, json_encode($boxConfig));
            $boxArgs[] = '--configuration=' . $tmpJson;
        }

        $this->stdErr->writeln('Building Phar package using Box');
        $result = $shell->execute($boxArgs, CLI_ROOT, false, true);

        // Clean up the temporary file, regardless of errors.
        if (!empty($tmpJson)) {
            unlink($tmpJson);
        }

        if ($result === false) {
            return 1;
        }

        if (!file_exists($phar)) {
            $this->stdErr->writeln(sprintf('Build failed: file not found: <error>%s</error>', $phar));
            return 1;
        }

        $sha1 = sha1_file($phar);
        $sha256 = hash_file('sha256', $phar);
        $size = filesize($phar);

        $this->stdErr->writeln('The package was built successfully');
        $output->writeln($phar);
        $this->stdErr->writeln([
            sprintf('Size: %s', FormatterHelper::formatMemory($size)),
            sprintf('SHA-1: %s', $sha1),
            sprintf('SHA-256: %s', $sha256),
            sprintf('Version: %s', $version),
        ]);

        // Write to the manifest file.
        $manifestFile = $input->getOption('manifest') ?: CLI_ROOT . '/dist/manifest.json';
        $contents = file_get_contents($manifestFile);
        if ($contents === false) {
            throw new \RuntimeException('Manifest file not readable: ' . $manifestFile);
        }
        if (!is_writable($manifestFile)) {
            throw new \RuntimeException('Manifest file not writable: ' . $manifestFile);
        }
        $this->stdErr->writeln('Updating manifest file: ' . $manifestFile);
        $manifest = json_decode($contents, true);
        if ($manifest === null && json_last_error()) {
            throw new \RuntimeException('Failed to decode manifest file: ' . $manifestFile);
        }
        $latestItem = null;
        foreach ($manifest as $key => $item) {
            if ($latestItem === null || version_compare($item['version'], $latestItem['version'], '>')) {
                $latestItem = &$manifest[$key];
            }
        }

        switch ($input->getOption('manifest-mode')) {
            case 'update-latest':
                $manifestItem = &$latestItem;
                break;

            case 'add':
                array_unshift($manifest, []);
                $manifestItem = &$manifest[0];
                break;

            default:
                throw new \RuntimeException('Unrecognised --manifest-mode: ' . $input->getOption('manifest-mode'));
        }

        if (isset($latestItem)) {
            $oldVersion = $latestItem['version'];
            $this->stdErr->writeln('  Found latest version: v' . $oldVersion);
            if (isset($latestItem['url'])) {
                $manifestItem['url'] = str_replace($oldVersion, $version, $latestItem['url']);
            }
            $changelog = $shell->execute([
                'git',
                'log',
                '--pretty=format:* %s',
                '--no-merges',
                '--invert-grep',
                '--grep=(Release v|\[skip changelog\])',
                '--perl-regexp',
                '--regexp-ignore-case',
                'v' . $oldVersion . '...master'
            ]);
            $changelog = is_string($changelog) ? $changelog : '';
        }
        $manifestItem['version'] = $version;
        $manifestItem['sha1'] = $sha1;
        $manifestItem['sha256'] = $sha256;
        $manifestItem['name'] = basename($phar);
        $manifestItem['php']['min'] = '5.5.9';
        if (!empty($changelog) && !empty($oldVersion)) {
            $manifestItem['updating'][] = [
                'notes' => $changelog,
                'show from' => $oldVersion,
                'hide from' => $version,
            ];
            $this->stdErr->writeln('<info>Changes:</info>');
            $this->stdErr->writeln($changelog);
            $this->stdErr->writeln('');
        }
        $result = file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($result !== false) {
            $this->stdErr->writeln('Updated manifest file: ' . $manifestFile);
        }
        else {
            $this->stdErr->writeln('Failed to update manifest file: ' . $manifestFile);

            return 1;
        }

        return 0;
    }
}
