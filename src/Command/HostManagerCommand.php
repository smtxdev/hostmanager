<?php

declare(strict_types=1);

namespace SmtXDev\DockerHostManager\Command;

use Exception;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function pcntl_signal;

class HostManagerCommand extends Command
{
    const START_TAG = '### hostmanager-start';
    const END_TAG = '### hostmanager-end';


    /**
     * @var string
     */
    protected static $defaultName = 'run';


    /**
     * @var bool
     */
    protected $shutdown = false;


    /**
     * @var bool
     */
    protected $runonce = true;


    /**
     * @var array
     */
    protected $lastHosts = [];


    /**
     * @var int
     */
    protected $polling = 2;


    /**
     * @var int
     */
    protected $returnCode = 0;


    /**
     * @var string
     */
    protected $hostsfile = 'c:/Windows/System32/drivers/etc/hosts,/etc/hosts';


    /**
     * @var string
     */
    protected $ip = '127.0.0.1';


    /**
     * @var array
     */
    protected $hostnames = [];


    /**
     * @var string
     */
    protected $hostnameMatchPattern = '/^[a-zA-Z]+_{1}NAME+_?\d*?$/smi';


    /**
     * @var string
     */
    protected $scope = 'default';


    protected function configure()
    {
        $this
            ->addOption(
                'hostsfile',
                null,
                InputOption::VALUE_REQUIRED,
                '',
                getenv('HOSTMANAGER_HOSTSFILE') ?: $this->hostsfile
            )
            ->addOption(
                'ip',
                null,
                InputOption::VALUE_REQUIRED,
                '',
                getenv('HOSTMANAGER_IP') ?: $this->ip
            )
            ->addOption(
                'hostnames',
                null,
                InputOption::VALUE_REQUIRED,
                '',
                getenv('HOSTMANAGER_HOSTNAMES') ?: $this->hostnames
            )
            ->addOption(
                'hostname-match-pattern',
                null,
                InputOption::VALUE_REQUIRED,
                '',
                getenv('HOSTMANAGER_HOSTNAME_MATCH_PATTERN') ?: $this->hostnameMatchPattern
            )
            ->addOption(
                'polling',
                null,
                InputOption::VALUE_REQUIRED,
                '',
                getenv('HOSTMANAGER_POLLING') ?: $this->polling
            )
            ->addOption(
                'returncode',
                null,
                InputOption::VALUE_REQUIRED,
                '',
                getenv('HOSTMANAGER_RETURNCODE') ?: $this->returnCode
            )
            ->addOption(
                'scope',
                null,
                InputOption::VALUE_REQUIRED,
                '',
                getenv('HOSTMANAGER_SCOPE') ?: $this->scope
            )
            ->addOption(
                'runonce',
                null,
                InputOption::VALUE_REQUIRED,
                '',
                getenv('HOSTMANAGER_RUNONCE') ?: $this->runonce
            )
        ;
    }


    /**
     * Overwritten to register signal handler.
     *
     * @see Command::run()
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Exception
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        declare(ticks=1);
        pcntl_signal(SIGTERM,[$this, 'handleSignal']);
        pcntl_signal(SIGINT, [$this, 'handleSignal']);
        return parent::run($input, $output);
    }


    /**
     * Main
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hostsfiles = explode(',', (string)$input->getOption('hostsfile'));
        $pickedHostfile = null;
        foreach ($hostsfiles as $file) {
            if (file_exists($file)) {
                $pickedHostfile = $file;
                break;
            }
        }
        if (!$pickedHostfile) {
            throw new RuntimeException('No hostfile found');
        }
        $this->hostsfile = $pickedHostfile;
        $this->polling = (int)$input->getOption('polling');
        $this->returnCode = (int)$input->getOption('returncode');
        $this->scope = (string)$input->getOption('scope');
        $this->runonce = (bool)$input->getOption('runonce');
        $this->ip = (string)$input->getOption('ip');
        $hostnames = $input->getOption('hostnames');
        $this->hostnames = is_array($hostnames) ? $hostnames : explode(',', $hostnames);
        $this->hostnameMatchPattern = (string)$input->getOption('hostname-match-pattern');

        while (!$this->shutdown) {
            if (!$this->checkAndWriteHosts()) {
                throw new RuntimeException();
            }

            if ($this->runonce) {
                $this->shutdown();
            } else {
                sleep($this->polling);
            }
        }
        return $this->returnCode;
    }


    /**
     * Change the hostsfile accordingly...
     *
     * @return bool
     */
    protected function checkAndWriteHosts(): bool
    {
        $hosts = $this->getHosts();
        if ($hosts === $this->lastHosts) {
            return true;
        }
        $this->lastHosts = $hosts;

        $start = self::START_TAG . '-' . $this->scope;
        $end = self::END_TAG . '-' . $this->scope;

        $content = @file_get_contents($this->hostsfile);
        if ($content === false) {
            return false;
        }

        // Remove all entries written by this command/shell
        while (true) {
            $newContent = preg_replace("/(.*?)($start)\n(.*?)\s(.*?)($end)\n?(.*)/smi",'$1$6', $content);
            if ($newContent === $content) {
                $content = $newContent;
                break;
            }
            $content = $newContent;
        }

        if ($hosts) {
            $content .= "$start\n";
            $content .= "{$this->ip} ";
            $content .= implode(' ', $hosts);
            $content .= "\n$end\n";
        }
        return @file_put_contents($this->hostsfile, $content) !== false;
    }


    /**
     * Returns a list of hostnames which will be written to the os hosts-file.
     * Checks --hostnames argument if empty then try to determine hostnames from env-vars.
     *
     * @return array
     */
    protected function getHosts(): array
    {
        if ($this->hostnames) {
            return $this->hostnames;
        }

        $pattern = $this->hostnameMatchPattern;
        $hostnames = array_intersect_key($_ENV, array_flip(preg_grep($pattern, array_keys($_ENV))));

        $entries = [];
        foreach ($hostnames as $name) {
            $entries[] = $name;
        }
        return $entries;
    }


    /**
     * Handle signals.
     *
     * @param int $signal
     * @param mixed $signinfo
     */
    protected function handleSignal(int $signal, $signinfo ): void
    {
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                $this->shutdown();
                break;
        }
    }


    /**
     * Start/Invoke graceful shutdown.
     */
    protected function shutdown(): void
    {
        $this->shutdown = true;
    }
}
