<?php

require_once 'vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Nyholm\Psr7\Uri;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\EventSubscriber\LoggerSubscriber;
use Terminal42\Escargot\Queue\DoctrineQueue;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Queue\LazyQueue;

$logger = new \Psr\Log\Test\TestLogger();
$baseUri = new Uri('https://www.terminal42.ch');
//$baseUri = new Uri('https://contao.org');

$connection = DriverManager::getConnection(['url' => 'mysql://root:root@127.0.0.1:3306/escargot']);
$stack = new \Doctrine\DBAL\Logging\DebugStack();
$connection->getConfiguration()->setSQLLogger($stack);

$queue =  new DoctrineQueue($connection, function () {
    return bin2hex(random_bytes(12));
    //return 'foobar';
});

$queue = new LazyQueue(new InMemoryQueue(), $queue);

//$queue->createSchema();

$escargot = Escargot::create($baseUri, $queue);
//$escargot = Escargot::createFromExistingJobId('foobar', $queue);

//$escargot->setMaxRequests(5);

//$escargot->addSubscriber(new LoggerSubscriber($logger));

$escargot->crawl();

$queue->commit($escargot->getJobId());

//print_r($logger->records);
print_r(count($stack->queries));