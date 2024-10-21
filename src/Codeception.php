<?php

namespace Testomatio\Reporter;

use Codeception\Event\FailEvent;
use Codeception\Event\TestEvent;
use Codeception\Test\Descriptor;

class Codeception extends \Codeception\Extension
{
    // we are listening for events
    public static array $events = [
        \Codeception\Events::TEST_SUCCESS => 'passed',
        \Codeception\Events::TEST_FAIL    => 'failed',
        \Codeception\Events::TEST_ERROR   => 'failed',
        \Codeception\Events::TEST_SKIPPED => 'skipped',
        \Codeception\Events::RESULT_PRINT_AFTER => 'updateStatus'
    ];

    private static $runId;
    private string $url;
    private string $apiKey;
    private bool $hasFailed = false;

    public function _initialize(): void
    {
        error_reporting(E_ALL & ~E_DEPRECATED); // http library incompatible
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

    public function passed(TestEvent $event): void
    {
        $this->addTestRun(
            $event->getTest(),
            'passed',
            '',
            $event->getTime()
        );
    }


    public function skipped(FailEvent $event): void
    {
        $this->addTestRun(
            $event->getTest(),
            'skipped',
            $event->getFail()->getMessage(),
            $event->getTime()
        );
    }

    public function failed(FailEvent $event): void
    {
        $this->hasFailed = true;

        $this->addTestRun(
            $event->getTest(),
            'failed',
            $event->getFail()->getMessage(),
            $event->getTime()
        );
    }

    protected function createRun(): void
    {
        $runId = getenv('runId');
        if ($runId) {
            self::$runId = $runId;
            return;
        }

        $params = [];

        if (getenv('TESTOMATIO_RUNGROUP_TITLE')) {
            $params['group_title'] = trim(getenv('TESTOMATIO_RUNGROUP_TITLE'));
        }

        if (getenv('TESTOMATIO_ENV')) {
            $params['env'] = trim(getenv('TESTOMATIO_ENV'));
        }

        if (getenv('TESTOMATIO_TITLE')) {
            $params['title'] = trim(getenv('TESTOMATIO_TITLE'));
        }

        if (getenv('TESTOMATIO_SHARED_RUN')) {
            $params['shared_run'] = trim(getenv('TESTOMATIO_SHARED_RUN'));
        }

        try {
            $url = $this->url . '/api/reporter?api_key=' . $this->apiKey;
            $request = \Httpful\Request::post($url)
                ->sendsJson()
                ->expectsJson();

            if (!empty($params)) {
                $request = $request->body($params);
            }
            $response = $request->send();
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
    public function addTestRun($test, $status, $message, $runTime): void
    {
        if (!$this->apiKey) {
            return;
        }

        $testId = null;
        if ($test instanceof \Codeception\TestInterface) {
            $testId = $this->getTestId($test->getMetadata()->getGroups());
        }

        list($suite, $testTitle) = explode(':', Descriptor::getTestAsString($test));

        $testTitle = preg_replace('/^Test\s/', '', trim($testTitle)); // remove "test" prefix

        $body = [
            'api_key' => $this->apiKey,
            'status' => $status,
            'message' => $message,
            'run_time' => $runTime * 1000,
            'title' => trim($testTitle),
            'suite_title' => trim($suite),
            'test_id' => $testId,
        ];

        if (trim(getenv('TESTOMATIO_CREATE'))) {
            $body['create'] = true;
        }

        $runId = self::$runId;
        try {
            $url = $this->url . "/api/reporter/$runId/testrun";
            $response = \Httpful\Request::post($url)
                ->body($body)
                ->sendsJson()
                ->expectsJson()
                ->send();
            if (isset($response->body->message)) {
                codecept_debug("Testomatio: " . $response->body->message);
            }
        } catch (\Exception $e) {
            $this->writeln("[Testomatio] Test $testId-$testTitle was not found in Testomat.io, skipping...");
        }
    }

    /**
     * Update run status
     *
     * @returns {Promise}
     */
    public function updateStatus(): void
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
                    'status_event' => $this->hasFailed ? 'fail' : 'pass',
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
