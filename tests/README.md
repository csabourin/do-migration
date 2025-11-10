# Migration Module Test Suite

This directory contains unit and integration tests for the S3 Spaces Migration module.

## Requirements

- PHP 7.4+
- PHPUnit 9.5+
- Composer (for PHPUnit installation)

## Installation

Install PHPUnit via Composer:

```bash
composer require --dev phpunit/phpunit ^9.5
```

## Running Tests

### Run All Tests

```bash
vendor/bin/phpunit
```

### Run Specific Test Suite

```bash
# Unit tests only
vendor/bin/phpunit --testsuite "Unit Tests"

# Integration tests only
vendor/bin/phpunit --testsuite "Integration Tests"
```

### Run Specific Test File

```bash
vendor/bin/phpunit tests/Unit/services/ProgressTrackerTest.php
```

### Run with Coverage Report

```bash
vendor/bin/phpunit --coverage-html coverage-report
```

Then open `coverage-report/index.html` in your browser.

## Test Organization

```
tests/
├── bootstrap.php              # Test bootstrap file
├── Unit/                      # Unit tests (isolated, no dependencies)
│   └── services/              # Service class tests
│       ├── ProgressTrackerTest.php
│       ├── ErrorRecoveryManagerTest.php
│       ├── CheckpointManagerTest.php (TODO)
│       ├── ChangeLogManagerTest.php (TODO)
│       └── MigrationLockTest.php (TODO)
└── Integration/               # Integration tests (require Craft CMS)
    └── (TODO)
```

## Test Status

### ✅ Completed Tests

- **ProgressTracker:** 10/10 tests passing
  - Constructor initialization
  - Progress increment
  - Report interval behavior
  - Completion detection
  - Performance metrics
  - Time formatting

- **ErrorRecoveryManager:** 9/9 tests passing
  - Successful operations
  - Retry on failure
  - Max retries limit
  - Fatal error detection
  - Retry statistics
  - Reset on success

### 🚧 In Progress Tests

- **CheckpointManager:** Not yet implemented
- **ChangeLogManager:** Not yet implemented
- **MigrationLock:** Not yet implemented
- **RollbackEngine:** Not yet implemented

### ⏳ Planned Tests

- Integration tests requiring Craft CMS setup
- End-to-end migration tests
- Rollback verification tests
- Performance benchmarks

## Writing New Tests

### Unit Test Template

```php
<?php

namespace tests\Unit\services;

use PHPUnit\Framework\TestCase;
use modules\services\YourServiceClass;

class YourServiceClassTest extends TestCase
{
    public function testYourFeature()
    {
        $service = new YourServiceClass();

        $result = $service->yourMethod();

        $this->assertEquals('expected', $result);
    }
}
```

### Best Practices

1. **Test Isolation:** Each test should be independent
2. **Clear Names:** Use descriptive test method names
3. **Arrange-Act-Assert:** Structure tests clearly
4. **Mock External Dependencies:** Use mocks for database, filesystem, etc.
5. **Test Edge Cases:** Include boundary conditions and error paths

## Coverage Goals

| Component | Current Coverage | Target |
|-----------|-----------------|--------|
| ProgressTracker | ~90% | 95% |
| ErrorRecoveryManager | ~85% | 95% |
| CheckpointManager | 0% | 80% |
| ChangeLogManager | 0% | 80% |
| MigrationLock | 0% | 80% |
| RollbackEngine | 0% | 70% |
| **Overall** | ~15% | **70%+** |

## Continuous Integration

To integrate with CI/CD pipelines:

```yaml
# Example GitHub Actions
- name: Run Tests
  run: vendor/bin/phpunit --coverage-text

- name: Check Coverage
  run: vendor/bin/phpunit --coverage-clover coverage.xml
```

## Known Limitations

1. **Craft CMS Dependency:** Some components require Craft to be fully bootstrapped
   - Current tests focus on service classes that can be tested in isolation
   - Full integration tests require Craft CMS test environment

2. **External Dependencies:** Tests mock:
   - Database connections (Craft::$app->getDb())
   - Filesystem operations (Craft::getAlias(), FileHelper)
   - Asset queries

3. **Performance Tests:** Not yet implemented
   - Would benefit from dedicated performance test suite
   - Should measure migration speed, memory usage, etc.

## Contributing

When adding new features to the migration module:

1. **Write tests first** (TDD approach preferred)
2. **Maintain coverage** above 70% for new code
3. **Run full test suite** before committing
4. **Update this README** with new test information

## Troubleshooting

### "Class not found" Errors

Ensure Composer autoloader is installed:
```bash
composer install
```

### "Cannot find Craft" Errors

Some tests require Craft CMS to be installed. Either:
- Install Craft CMS in the project
- Mock Craft dependencies in tests
- Skip integration tests: `vendor/bin/phpunit --testsuite "Unit Tests"`

### Slow Tests

If tests are slow:
- Check for unnecessary sleep() calls
- Reduce retry delays in ErrorRecoveryManager tests
- Use test doubles instead of real operations

## Future Improvements

- [ ] Add integration tests with Craft CMS test environment
- [ ] Implement performance benchmarks
- [ ] Add mutation testing for better test quality
- [ ] Set up code coverage thresholds in CI
- [ ] Add visual regression tests for dashboard
- [ ] Create test fixtures for realistic migration scenarios

---

**Last Updated:** 2025-11-10
**Test Coverage:** ~15% (Target: 70%+)
**Status:** Foundation established, expansion needed
