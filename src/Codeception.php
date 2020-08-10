<?php

namespace Testomatio\Reporter;

use Codeception\Event\FailEvent;
use Codeception\Event\TestEvent;
use Codeception\Test\Descriptor;

class Codeception extends \Codeception\Extension
{
    // we are listening for events
    public static $events = [
        \Codeception\Events::TEST_SUCCESS => 'passed',
        \Codeception\Events::TEST_FAIL    => 'failed',
        \Codeception\Events::TEST_ERROR   => 'failed',
        \Codeception\Events::TEST_SKIPPED => 'skipped',
        \Codeception\Events::RESULT_PRINT_AFTER => 'updateStatus'
    ];

    private static $runId;
    private $url;
    private $apiKey;
    private $hasFailed = false;

    public function _initialize()
    {
        $this->url = trim(getenv('TESTOMATIO_URL'));
        if (!$this->url) {
            $this->url = 'https://app.testomat.io';
        }
        $this->apiKey = trim(getenv('TESTOMATIO'));
        if (!$this->apiKey) return;

        if (self::$runId) {
            return;
        }
        $this->createRun();
    }

    public function passed(TestEvent $event)
    {
        $this->addTestRun(
            $event->getTest(),
            'passed',
            '',
            $event->getTime()
        );
    }


    public function skipped(FailEvent $event)
    {
        $this->addTestRun(
            $event->getTest(),
            'skipped',
            $event->getFail()->getMessage(),
            $event->getTime()
        );
    }

    public function failed(FailEvent $event)
    {
        $this->hasFailed = true;

        $this->addTestRun(
            $event->getTest(),
            'failed',
            $event->getFail()->getMessage(),
            $event->getTime()
        );
    }

    protected function createRun()
    {
        $runId = getenv('runId');
        if ($runId) {
            self::$runId = $runId;
            return;
        }

        try {
            $url = $this->url . '/api/reporter?api_key=' . $this->apiKey;
            $response = \Httpful\Request::post($url)
                ->sendsJson()
                ->expectsJson()
                ->send();
        } catch (\Exception $e) {
            $this->writeln("Couldn't start run at Testomatio: " . $e->getMessage());
            exit(1);
        }

        self::$runId = $response->body->uid;
        $this->writeln("Started Testomatio run " . self::$runId);
    }


    /**
     * Used to add a new test to Run instance
     *
     */
    public function addTestRun(\Codeception\TestInterface $test, $status, $message, $runTime)
    {
        if (!$this->apiKey) return;

        $testId = $this->getTestId($test->getMetadata()->getGroups());
        list($suite, $testTitle) = explode(':', Descriptor::getTestAsString($test));

        $runId = self::$runId;
        try {
            $url = $this->url . "/api/reporter/$runId/testrun";
            $response = \Httpful\Request::post($url)
                ->body([
                    'api_key' => $this->apiKey,
                    'status' => $status,
                    'message' => $message,
                    'run_time' => $runTime * 1000,
                    'title' => trim($testTitle),
                    'suite' => trim($suite),
                    'test_id' => $testId,
                ])
                ->sendsJson()
                ->expectsJson()
                ->send();
        } catch (\Exception $e) {
            $this->writeln("[Testomatio] Test $testId-$testTitle was not found in Testomat.io, skipping...");
        }
    }
  
    /**
     * Update run status
     *
     * @returns {Promise}
     */
    public function updateStatus()
    {
        if (!$this->apiKey) return;
        if (!self::$runId) {
            return;
        }

        try {
            $url = $this->url . "/api/reporter/" . self::$runId;
            $response = \Httpful\Request::put($url)
                ->body([
                    'api_key' => $this->apiKey,
                    'status' => $this->hasFailed ? 'failed' : 'passed',
                ])
                ->sendsJson()
                ->expectsJson()
                ->send();
        } catch (\Exception $e) {
            $this->writeln("[Testomatio] Error updating status, skipping...");
        }
    }

    private function getTestId(array $groups)
    {
        foreach ($groups as $group) {
            if (preg_match('/^T\w{8}/', $group)) {
                return substr($group, 1);
            }
        }
    }

}
