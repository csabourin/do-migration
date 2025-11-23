# Contributing to Spaghetti Migrator

First off, thank you for considering contributing to this project! It's people like you that make this tool better for everyone.

## 🎯 How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the [existing issues](https://github.com/csabourin/do-migration/issues) to avoid duplicates.

When reporting a bug, please include:

- **Clear title and description**
- **Steps to reproduce** the issue
- **Expected behavior** vs actual behavior
- **Craft CMS version** and **PHP version**
- **Environment** (dev, staging, production)
- **Error messages or logs** if available
- **Screenshots** if applicable

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, please include:

- **Use case** - what problem does this solve?
- **Proposed solution** - how would you like it to work?
- **Alternatives considered** - what other approaches did you think about?
- **Additional context** - any other relevant information

### Pull Requests

1. **Fork the repository** and create your branch from `main`
2. **Make your changes** following the code style guidelines
3. **Test your changes** in a real Craft CMS environment
4. **Update documentation** if needed (README, ARCHITECTURE.md, etc.)
5. **Write clear commit messages** following conventional commits format
6. **Submit a pull request** with a clear description

#### Pull Request Guidelines

- Keep changes focused - one feature/fix per PR
- Update the CHANGELOG.md with your changes
- Add tests if applicable
- Ensure your code follows PSR-12 coding standards
- Update documentation to reflect any changes
- Link to any relevant issues

## 💻 Development Setup

### Prerequisites

- PHP 8.0 or higher
- Craft CMS 4.x or 5.x
- Composer
- AWS S3 account (for testing)
- DigitalOcean Spaces account (for testing)

### Local Development

1. **Clone your fork:**
   ```bash
   git clone https://github.com/YOUR-USERNAME/do-migration.git
   cd do-migration
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Set up test environment:**
   - Create a test Craft CMS installation
   - Add this module as a local Composer dependency
   - Configure test AWS S3 and DigitalOcean Spaces accounts

4. **Configure environment variables:**
   ```bash
   cp .env.example .env
   # Edit .env with your test credentials
   ```

### Testing

Before submitting a pull request, test your changes:

1. **Manual testing:**
   ```bash
   # Run pre-flight checks
   ./craft spaghetti-migrator/migration-check/check

   # Test your specific controller
   ./craft spaghetti-migrator/your-controller/action
   ```

2. **Dry run testing:**
   - Always test with dry run mode first
   - Verify output is as expected
   - Check for any error messages

3. **Integration testing:**
   - Test with a small dataset first
   - Verify checkpoint/resume functionality
   - Test rollback capabilities

### Code Style

This project follows PSR-12 coding standards:

- Use 4 spaces for indentation (no tabs)
- Use meaningful variable and function names
- Add PHPDoc comments for all classes and methods
- Keep methods focused and single-purpose
- Maximum line length of 120 characters

Example:
```php
/**
 * Process a batch of assets for migration
 *
 * @param array $assets Array of asset IDs to process
 * @param bool $dryRun Whether to perform a dry run
 * @return array Results of the batch processing
 */
public function processBatch(array $assets, bool $dryRun = false): array
{
    // Implementation
}
```

## 📝 Commit Messages

Follow the [Conventional Commits](https://www.conventionalcommits.org/) specification:

```
type(scope): subject

body (optional)

footer (optional)
```

**Types:**
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, etc.)
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

**Examples:**
```
feat(migration): add support for nested asset folders

fix(checkpoint): resolve issue with checkpoint resume after error

docs(readme): update installation instructions for Craft 5
```

## 🔍 Code Review Process

1. At least one maintainer will review your PR
2. Feedback may be provided via comments
3. You may need to make changes based on feedback
4. Once approved, a maintainer will merge your PR

## 📚 Documentation

When adding new features:

- Update the README.md with usage examples
- Add entries to ARCHITECTURE.md for architectural changes
- Include inline code comments for complex logic
- Update configuration documentation if needed

## 🤝 Community Guidelines

- Be respectful and constructive
- Help others in issues and discussions
- Share your use cases and experiences
- Celebrate contributions from others

## 📄 License

By contributing, you agree that your contributions will be licensed under the MIT License.

## ❓ Questions?

- Open a [GitHub Discussion](https://github.com/csabourin/do-migration/discussions)
- Check existing [documentation](https://github.com/csabourin/do-migration#readme)
- Review the [ARCHITECTURE.md](ARCHITECTURE.md) for technical details

---

Thank you for contributing! 🎉
