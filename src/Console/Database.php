<?php

namespace CreateTwillApp\Console;

use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;

trait Database {

    public function configureDatabase()
    {
        $helper = $this->getHelper('question');
        $connectionQuestion = new ChoiceQuestion('Enter your database connection, default "mysql": ', ['mysql', 'pgsql'],  0);
        $hostQuestion = new Question('Enter your database host, default "127.0.0.1":  ', '127.0.0.1');
        $usernameQuestion = new Question('Enter your database username, default "root":  ', 'root');
        $passwordQuestion = new Question('Enter your database password, default "":  ', '');
        $passwordQuestion->setHidden(true);
        $databaseQuestion = new Question('Enter your database name, default "laravel":  ', 'laravel');
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

    public function databaseConnectionValid($dbconfig)
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
}