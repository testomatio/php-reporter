<?php
namespace Testomatio\Reporter;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;

class PHPUnit implements TestListener
{
    use TestListenerDefaultImplementation;

    private static $runId;
    private $url;

    private $suiteName;
    private $apiKey;
    private $hasFailed = false;

    public function __construct()
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

    public function __destruct()
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
            // Handle the error here
        }
    }

    public function startTest(Test $test): void
    {
    }

    public function startTestSuite(TestSuite $suite): void
    {
        $this->suiteName = null;
        $pattern = '/\\\\([^\\\\]+)Test::/';
        preg_match($pattern, $suite->getName(), $matches);

        if (isset($matches[1])) {
            $result = $matches[1];
            $this->suiteName = $result;
        }
    }

    public function endTest(Test $test, float $time): void
    {
        // Code to execute after each individual test ends
        if ($test->hasFailed()) return;
        $status = 'passed';
        $this->addTestRun($test, $status, '', $time);
    }


    public function addFailure(Test $test, AssertionFailedError $t, float $time): void
    {
        $this->hasFailed = true;

        $this->addTestRun($test, 'failed', $t->getMessage(), $time, $t->getTraceAsString());
    }

    public function addError(Test $test, \Throwable $t, float $time): void
    {
        $this->hasFailed = true;
        $this->addTestRun($test, 'failed', $t->getMessage(), $time, $t->getTraceAsString());
    }

    public function addWarning(Test $test, \Throwable $e, float $time): void
    {
        // Code to handle test warnings
    }

    public function addIncompleteTest(Test $test, \Throwable $t, float $time): void
    {
        // Code to handle incomplete tests
    }

    public function addRiskyTest(Test $test, \Throwable $t, float $time): void
    {
        // Code to handle risky tests
    }

    public function addSkippedTest(Test $test, \Throwable $t, float $time): void
    {
        // Code to handle skipped tests
        $this->addTestRun($test, 'skipped', $t->getMessage(), $time);
    }

    protected function createRun()
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
            $req = \Httpful\Request::post($url);
            if (!empty($params)) {
                $req = $req->body($params);
            }
            $response = $req
                ->sendsJson()
                ->expectsJson()
                ->send();
        } catch (\Exception $e) {
            // Handle the error here
        }

        self::$runId = $response->body->uid;
    }

    public function addTestRun(Test $test, $status, $message, $runTime, $trace = null)
    {
        /** @var $test TestCase */
        if (!$this->apiKey) {
            return;
        }

        $testTitle = $this->humanize($test->getName(false));

        $body = [
            'api_key' => $this->apiKey,
            'status' => $status,
            'message' => $message,
            'run_time' => $runTime * 1000,
            'title' => $testTitle,
            'suite_title' => trim($this->suiteName),
        ];


        if (trim(getenv('TESTOMATIO_CREATE'))) {
            $body['create'] = true;
        }

        if ($trace) {
            $body['stack'] = $trace;
        }

        if ($test instanceof \PHPUnit\Framework\TestCase) {
            $testId = $this->getTestId($test->getGroups());

            if ($testId) {
                $body['test_id'] = $testId;
            }

            $data = $test->getProvidedData();
            if ($data) {
                $values = $data;
                $keys = array_map(fn ($i) => "p$i", array_keys($values));

                $body['example'] = array_combine($keys, $values);
            }
        }


        $runId = self::$runId;
        try {
            $url = $this->url . "/api/reporter/$runId/testrun";
            $response = \Httpful\Request::post($url)
                ->body($body)
                ->sendsJson()
                ->expectsJson()
                ->send();
        } catch (\Exception $e) {
            // Handle the error here
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

    private function humanize($name)
    {
        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '\\1 \\2', $name);
        $name = preg_replace('/([a-z\d])([A-Z])/', '\\1 \\2', $name);
        $name = strtolower($name);

        // remove test word from name
        $name = preg_replace('/^test /', '', $name);

        return ucfirst($name);
    }

}