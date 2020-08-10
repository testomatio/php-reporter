# [Testomatio](https://testomat.io) Reporter for PHP testing frameworks

## Installation

```
composer require testomatio/reporter --dev
```

### Codeception

Enable Codeception extension for in a suite config or globally:

```
extensions:
  enabled:
    - Testomatio\Reporter\Codeception
```

The reporting will only happen when `TESTOMATIO` environment variable is set.
Get API key from Testomatio application, set an environment var for it and run tests:

On Linux/MacOS:

```
TESTOMATIO={apiKey} php vendor/bin/codecept run
```

On Windows

```
set TESTOMATIO={apiKey}&& php vendor/bin/codecept run
```



