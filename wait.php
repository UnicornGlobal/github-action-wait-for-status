<?php

use ApiClients\Client\Github\AsyncClient;
use ApiClients\Client\Github\Authentication\Token;
use ApiClients\Client\Github\Resource\Async\Repository;
use ApiClients\Client\Github\Resource\Async\Repository\Commit;
use ApiClients\Client\Github\Resource\Async\User;
use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Logger;
use React\EventLoop\Factory;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Rx\Observable;
use WyriHaximus\Monolog\FormattedPsrHandler\FormattedPsrHandler;
use WyriHaximus\PSR3\CallableThrowableLogger\CallableThrowableLogger;
use WyriHaximus\React\PSR3\Stdio\StdioLogger;
use function ApiClients\Tools\Rx\observableFromArray;
use function React\Promise\all;
use function React\Promise\resolve;
use function WyriHaximus\React\timedPromise;

require __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

const REPOSITORY = 'GITHUB_REPOSITORY';
const TOKEN = 'GITHUB_TOKEN';
const SHA = 'GITHUB_SHA';
const DATA = 'INPUT_PAYLOAD';

(function () {
    $loop = Factory::create();
    $consoleHandler = new FormattedPsrHandler(StdioLogger::create($loop)->withHideLevel(true));
    $consoleHandler->setFormatter(new ColoredLineFormatter(
        null,
        '[%datetime%] %channel%.%level_name%: %message%',
        'Y-m-d H:i:s.u',
        true,
        false
    ));

    $details = getenv(DATA);
    $data = json_decode($details);
    $actions = $data->actions;
    $interval = $data->interval;

    $logger = new Logger('wait');
    $logger->pushHandler($consoleHandler);
    [$owner, $repo] = explode('/', getenv(REPOSITORY));
    $logger->debug('Looking up owner: ' . $owner);
    /** @var Repository|null $rep */
    $rep = null;
    AsyncClient::create($loop, new Token(getenv(TOKEN)))->user($owner)->then(function (User $user) use ($repo, $logger, $actions, $interval) {
        $logger->debug('Looking up repository: ' . $repo);
        return $user->repository($repo);
    })->then(function (Repository $repository) use ($logger, &$rep, $actions, $interval) {
        $rep = $repository;
        $logger->debug('Locating commit: ' . getenv(SHA));
        return $repository->specificCommit(getenv(SHA));
    })->then(function (Commit $commit) use ($logger, &$rep, $actions, $interval) {
        $commits = [];
        $commits[] = resolve($commit);
        foreach ($commit->parents() as $parent) {
            $commits[] = $rep->specificCommit($parent->sha());
        }
        $logger->debug('Locating checks: ' . var_dump($actions));
        return observableFromArray($commits)->flatMap(function (PromiseInterface $promise) use ($logger, $actions, $interval) {
            return Observable::fromPromise($promise);
        })->flatMap(function (Commit $commit) use ($logger, $actions, $interval) {
            return $commit->checks();
        })->filter(function (Commit\Check $check) use ($logger, $actions, $interval) {
            $result = in_array($check->name(), $actions);
            return $result;
        })->flatMap(function (Commit\Check $check) use ($logger, &$rep) {
            $logger->debug('Found check and commit holding relevant statuses and checks: ' . $check->headSha());
            return observableFromArray([$rep->specificCommit($check->headSha())]);
        })->take(1)->toPromise();
    })->then(function (Commit $commit) use ($loop, $logger, $actions, $interval) {
        $logger->notice('Checking statuses and checks');

        return all([
            new Promise(function (callable $resolve, callable $reject) use ($commit, $loop, $logger, $actions, $interval) {
                $checkStatuses = function (Commit\CombinedStatus $status) use (&$timer, $resolve, $loop, $logger, &$checkStatuses, $actions, $interval) {
                    if ($status->totalCount() === 0) {
                        $logger->warning('No statuses found, assuming success');
                        $resolve('success', $status);
                        return;
                    }

                    if ($status->state() === 'pending') {
                        $interval = $interval !== null ? $interval : 10;
                        $logger->warning('Statuses are pending, checking again in ' . $interval . ' seconds');
                        timedPromise($loop, $interval)->then(function () use ($status, $checkStatuses, $logger) {
                            $logger->notice('Checking statuses');
                            $status->refresh()->then($checkStatuses);
                        });
                        return;
                    }

                    $logger->info('Status resolved: ' . $status->state() . ' - ' . $status->name());
                    $resolve($status->state(), $status);
                };
                $commit->status()->then($checkStatuses);
            }),
            new Promise(function (callable $resolve, callable $reject) use ($commit, $loop, $logger, $actions, $interval) {
                $checkChecks = function (array $checks) use (&$timer, $resolve, $loop, $logger, &$checkChecks, $commit, $actions, $interval) {
                    $state = 'success';
                    /** @var Commit\Check $status */
                    foreach ($checks as $status) {
                        if ($status->status() !== 'completed') {
                            $state = 'pending';
                            break;
                        }

                        if ($status->conclusion() !== 'success') {
                            $state = 'failure';
                            break;
                        }
                    }

                    if ($state === 'pending') {
                        $interval = $interval !== null ? $interval : 10;
                        $logger->warning('Checks are pending, checking again in ' . $interval . ' seconds');
                        timedPromise($loop, $interval)->then(function () use ($commit, $checkChecks, $logger, $actions, $interval) {
                            $logger->notice('Checking statuses');
                            $commit->checks()->filter(function (Commit\Check $check) use ($logger, $actions, $interval) {
                                $result = in_array($check->name(), $actions);
                                return $result;
                            })->toArray()->toPromise()->then($checkChecks);
                        });
                        return;
                    }

                    $logger->info('Checks resolved: ' . $state);
                    echo PHP_EOL, '::set-output name=status::' . $state, PHP_EOL;
                    $resolve($state, $status);
                };
                $commit->checks()->filter(function (Commit\Check $check) use ($actions, $logger) {
                    $result = in_array($check->name(), $actions);
                    return $result;
                })->toArray()->toPromise()->then($checkChecks);
            }),
        ]);
    })->then(function (array $statuses, $x) use ($logger) {
        foreach ($statuses as $status) {
            if ($status !== 'success') {
                return 'failure';
            }
        }

        return 'success';
    })->then(function (string $status, $x) use ($loop, $logger) {
        return timedPromise($loop, 1, $status);
    })->done(function (string $state, $status) use ($logger) {
        echo PHP_EOL, '::set-output name=status::' . $state, PHP_EOL;
    }, CallableThrowableLogger::create($logger));
    $loop->run();
})();
