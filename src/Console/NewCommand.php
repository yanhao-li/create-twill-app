<?php

namespace CreateTwillApp\Console;

use ZipArchive;
use GuzzleHttp\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Question\Question;

class NewCommand extends Command
{
    private $input, $output, $directory;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Twill application')
            ->addArgument('name', InputArgument::OPTIONAL)
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

        /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->beforeRun();

        $this->downloadZip($zipFile = $this->makeFilename())
              ->extract($zipFile, $this->directory)
              ->prepareWritableDirectories($this->directory, $this->output)
              ->cleanUp($zipFile);
    }

    protected function beforeRun()
    {
        if (! extension_loaded('zip')) {
            throw new RuntimeException('The Zip PHP extension is not installed. Please install it and try again.');
        }

        if ($this->input->getArgument('name')) {
            $this->directory = getcwd().'/'.$this->input->getArgument('name');
        } else {
            $this->output->writeln('<info>Usage: create-twill-app new app-name</info>');
            return;
        }
    }

    protected function setup()
    {

        $laravelNewCommand = new LaravelNewCommand;
        
        //Install Laravel
        $laravelNewCommand->execute($this->input, $this->output);
        $this->installTwill($laravelNewCommand->findComposer());
    }

    protected function installTwill($composer)
    {

        $this->output->writeln('<info>Installing Twill...</info>');
        $commands = [
            $composer.' require area17/twill:"1.2.*"',
            $composer.' install --no-scripts'
        ];
        
        $composerProcess = new Process(implode(' && ', $commands), $this->directory, null, null, null);
        $composerProcess->run(function ($type, $line){
            $this->output->write($line);
        });

        $databaseConfig = $this->configureDatabase();
        $this->configureEnv($databaseConfig);
        passthru('cd '. $this->directory . ' && php artisan twill:install' );
        $this->configureNpm();
        
        //run the server
        $serveProcess = new Process("php artisan serve", $this->directory, null, null, null);
        $serveProcess->run(function ($type, $line){
            $this->output->write($line);
        });
    }

    protected function configureDatabase()
    {
        $helper = $this->getHelper('question');
        $connectionQuestion = new Question('Enter your database connection ["mysql" | "pgsql"]:  ', 'mysql');
        $hostQuestion = new Question('Enter your database host ["127.0.0.1"]:  ', '127.0.0.1');
        $usernameQuestion = new Question('Enter your database username ["root"]:  ', 'root');
        $passwordQuestion = new Question('Enter your database password [""]:  ', '');
        $passwordQuestion->setHidden(true);
        $databaseQuestion = new Question('Enter your database name ["laravel"]:  ', 'laravel');
        $connected = false;
        while (!$connected) {
            $this->output->writeln('<error>Cannot connect to database.</error>');
            $connection = $helper->ask($this->input, $this->output, $connectionQuestion);
            $host = $helper->ask($this->input, $this->output, $hostQuestion);
            $username = $helper->ask($this->input, $this->output, $usernameQuestion);
            $password = $helper->ask($this->input, $this->output, $passwordQuestion);
            $database = $helper->ask($this->input, $this->output, $databaseQuestion);
            $databaseConfig = [
                "connection" => $connection,
                "host" => $host,
                "username" => $username,
                "password" => $password,
                "database" => $database
            ];
            $connected = $this->databaseConnectionValid($databaseConfig);
        }

        $this->output->writeln('<info>Database configured successfully.</info>');

        return $databaseConfig;
    }

    protected function configureEnv($dbconfig)
    {
        $connection = $dbconfig["connection"];
        $host = $dbconfig["host"];
        $database = $dbconfig["database"];
        $username = $dbconfig["username"];
        $password = $dbconfig["password"];
        $port = $connection === 'mysql' ? 3306 : 5432;

        $searchDb = [
            'DB_CONNECTION=mysql',
            'DB_HOST=127.0.0.1',
            'DB_DATABASE=laravel',
            'DB_USERNAME=root',
            'DB_PASSWORD=',
            'DB_PORT=3306'
        ];

        $replaceDb = [
            "DB_CONNECTION=$connection",
            "DB_HOST=$host",
            "DB_DATABASE=$database",
            "DB_USERNAME=$username",
            "DB_PASSWORD=$password",
            "DB_PORT=$port"
        ];

        $this->replaceEnv($searchDb, $replaceDb);

        $searchApp = [
            'APP_URL=http://localhost'
        ];

        $replaceApp = [
            "APP_URL=\nADMIN_APP_URL=\nADMIN_APP_PATH=admin"
        ];

        $this->replaceEnv($searchApp, $replaceApp);
    }

    protected function replaceEnv($search, $replace)
    {
        $finder = new Finder();
        $envfile = iterator_to_array($finder->files()->ignoreDotFiles(false)->in($this->directory)->name(".env"));
        $envfileContent = reset($envfile)->getContents();

        $newEnvfile = str_replace($search, $replace, $envfileContent);

        $filesystem = new Filesystem;
        $filesystem->dumpFile($this->directory . "/.env", $newEnvfile);
    }

    protected function configureNpm()
    {
        //Add twill's npm scripts
        $finder = new Finder();
        $packagejson = iterator_to_array($finder->files()->ignoreDotFiles(false)->in($this->directory)->name("package.json"));
        $packagejsonContent = json_decode(reset($packagejson)->getContents(), true);
        $twillScripts = [
            "twill-build" => "rm -f public/hot && npm run twill-copy-blocks && cd vendor/area17/twill && npm ci && npm run prod && cp -R public/* \${INIT_CWD}/public",
            "twill-copy-blocks" => "npm run twill-clean-blocks && mkdir -p resources/assets/js/blocks/ && cp -R resources/assets/js/blocks/ vendor/area17/twill/frontend/js/components/blocks/customs/",
            "twill-clean-blocks" => "rm -rf vendor/area17/twill/frontend/js/components/blocks/customs"
        ];
        $packagejsonContent["scripts"] = array_merge($packagejsonContent["scripts"], $twillScripts);
        $filesystem = new Filesystem;
        $filesystem->dumpFile($this->directory . "/package.json", json_encode($packagejsonContent, JSON_PRETTY_PRINT));
    }

    protected function databaseConnectionValid($dbconfig)
    {
        try {
            $host = $dbconfig["host"];
            $username = $dbconfig["username"];
            $password = $dbconfig["password"];
            $database = $dbconfig["database"];
            $connection = $dbconfig["connection"];
            switch ($connection) {
              case 'mysql':
                $link = @mysqli_connect($host, $username, $password, $database);
                break;
              case 'pgsql':
                $link = @pg_connect("host={$host} port=5432 dbname={$database} user={$username} password={$password}");
                break;
              default:
                $link = false;
                break;
            }
            if (!$link) {
                return false;
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

        /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd().'/twill_'.md5(time().uniqid()).'.zip';
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string  $zipFile
     * @param  string  $version
     * @return $this
     */
    protected function downloadZip($zipFile)
    {
        $response = (new Client)->get('https://github.com/yanhao-li/twill-app/archive/0.0.0.zip');
        file_put_contents($zipFile, $response->getBody());
        return $this;
    }

     /**
     * Get the composer command for the environment.
     *
     * @return string
     */
    protected function findComposer()
    {
        $composerPath = getcwd().'/composer.phar';
        if (file_exists($composerPath)) {
            return '"'.PHP_BINARY.'" '.$composerPath;
        }
        return 'composer';
    }

        /**
     * Extract the Zip file into the given directory.
     *
     * @param  string  $zipFile
     * @param  string  $directory
     * @return $this
     */
    protected function extract($zipFile)
    {
        $archive = new ZipArchive;
        $archive->open($zipFile);
        $archive->extractTo(getcwd());
        rename(trim($archive->getNameIndex(0), '/'), $this->input->getArgument('name'));
        $archive->close();
        return $this;
    }

    /**
     * Clean-up the Zip file.
     *
     * @param  string  $zipFile
     * @return $this
     */
    protected function cleanUp($zipFile)
    {
        @chmod($zipFile, 0777);
        @unlink($zipFile);
        return $this;
    }
    /**
     * Make sure the storage and bootstrap cache directories are writable.
     *
     * @param  string  $appDirectory
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return $this
     */
    protected function prepareWritableDirectories($appDirectory, OutputInterface $output)
    {
        $filesystem = new Filesystem;
        try {
            $filesystem->chmod($appDirectory.DIRECTORY_SEPARATOR.'bootstrap/cache', 0755, 0000, true);
            $filesystem->chmod($appDirectory.DIRECTORY_SEPARATOR.'storage', 0755, 0000, true);
        } catch (IOExceptionInterface $e) {
            $output->writeln('<comment>You should verify that the "storage" and "bootstrap/cache" directories are writable.</comment>');
        }
        return $this;
    }
}
