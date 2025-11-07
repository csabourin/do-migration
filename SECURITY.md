# Security Policy

## Supported Versions

We actively support the following versions of this module with security updates:

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

We take security seriously. If you discover a security vulnerability, please follow these steps:

### 🔒 Private Disclosure

**DO NOT** open a public GitHub issue for security vulnerabilities. Instead:

1. **Email:** Send details to `christian@sabourin.ca`
2. **Subject:** Include "SECURITY" in the subject line
3. **Details:** Provide as much information as possible:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if you have one)

### What to Expect

- **Acknowledgment:** We'll acknowledge receipt within 48 hours
- **Investigation:** We'll investigate and assess the severity
- **Updates:** We'll keep you informed of our progress
- **Resolution:** We'll work on a fix and coordinate disclosure
- **Credit:** We'll credit you in the security advisory (if desired)

### Timeline

- **Initial Response:** Within 48 hours
- **Status Update:** Within 7 days
- **Fix Released:** As soon as possible (depends on severity)
- **Public Disclosure:** After fix is released and users have time to update

## Security Best Practices

When using this module:

### 1. Credential Management

- **Never commit** `.env` files or credentials to version control
- **Use environment variables** for all sensitive credentials
- **Rotate keys regularly** for AWS and DigitalOcean
- **Use IAM roles** with minimal required permissions

### 2. Access Control

- **Limit Control Panel access** to authorized administrators only
- **Use strong passwords** and two-factor authentication
- **Review user permissions** regularly
- **Monitor access logs** for suspicious activity

### 3. Environment Configuration

```env
# ✅ GOOD: Use environment variables
DO_S3_ACCESS_KEY=${DO_SPACES_KEY}
DO_S3_SECRET_KEY=${DO_SPACES_SECRET}

# ❌ BAD: Never hardcode credentials
DO_S3_ACCESS_KEY=AKIAIOSFODNN7EXAMPLE
```

### 4. Migration Safety

- **Always test in development** environment first
- **Use dry run mode** before executing actual migrations
- **Backup your database** before major operations
- **Verify checksums** of migrated files
- **Keep rollback logs** for recovery

### 5. Network Security

- **Use HTTPS** for all API connections
- **Verify SSL certificates** are valid
- **Configure firewall rules** appropriately
- **Monitor network traffic** during migrations

### 6. Dependency Management

- **Keep dependencies updated:** Run `composer update` regularly
- **Review security advisories:** Check for known vulnerabilities
- **Use Composer audit:** Run `composer audit` periodically

```bash
# Check for security vulnerabilities
composer audit

# Update dependencies
composer update --with-dependencies
```

### 7. Production Deployment

- **Disable debug mode** in production
- **Restrict file permissions** (644 for files, 755 for directories)
- **Remove development tools** from production servers
- **Monitor error logs** for unusual activity
- **Rate limit API endpoints** if exposed publicly

## Known Security Considerations

### 1. File System Access

This module requires read/write access to asset volumes. Ensure:
- File permissions are properly configured
- Temporary files are cleaned up
- Uploaded files are validated

### 2. Database Operations

The module performs database updates. Ensure:
- Database backups are current
- User has appropriate permissions
- Query execution is monitored

### 3. API Credentials

AWS and DigitalOcean credentials have broad access. Ensure:
- Use IAM roles with minimal permissions
- Rotate credentials regularly
- Monitor API usage for anomalies

### 4. Control Panel Endpoints

The dashboard provides command execution. Ensure:
- Only authorized users can access
- CSRF protection is enabled
- Request validation is enforced

## Security Features

This module includes:

- ✅ **No hardcoded credentials** - all via environment variables
- ✅ **Input validation** on all user-facing endpoints
- ✅ **CSRF protection** via Craft CMS built-in features
- ✅ **Secure file handling** with proper permissions
- ✅ **Audit logging** of all migration operations
- ✅ **Dry run mode** for safe testing
- ✅ **Rollback capability** for recovery

## Compliance

When using this module in regulated environments:

- **Data residency:** Be aware of where data is stored during migration
- **Encryption:** Use encrypted connections (HTTPS/TLS)
- **Audit trails:** Maintain logs of all migration activities
- **Access controls:** Implement role-based access
- **Data retention:** Follow your organization's policies

## Third-Party Dependencies

This module relies on:

- **Craft CMS:** Follow [Craft's security guidelines](https://craftcms.com/knowledge-base/security)
- **AWS SDK:** Included via Craft CMS
- **DigitalOcean Spaces API:** Compatible with S3 API

Keep all dependencies updated to receive security patches.

## Resources

- [Craft CMS Security](https://craftcms.com/knowledge-base/security)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [AWS Security Best Practices](https://docs.aws.amazon.com/security/)
- [DigitalOcean Security](https://www.digitalocean.com/security/)

## Questions?

For security-related questions (non-vulnerabilities):
- Open a [GitHub Discussion](https://github.com/csabourin/do-migration/discussions)
- Review our [documentation](https://github.com/csabourin/do-migration#readme)

---

Thank you for helping keep this project and its users safe! 🔒
