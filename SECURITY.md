# Security Policy

## Supported versions

Only the `main` branch receives security updates. Tagged releases are
snapshots - if a tagged release is affected by an issue, the fix goes to
`main` and a new tag is cut.

## Reporting a vulnerability

**Please do not open a public issue for security vulnerabilities.**

Email: <Adityaajaysingh0104@gmail.com>

Include in your report:

1. Component affected (e.g. `deploy.sh`, Docker image, bundle handling)
2. Steps to reproduce
3. Expected vs. actual behavior
4. Potential impact (credential leak, RCE, data exposure, etc.)
5. Suggested fix if you have one

You should receive an acknowledgment within 7 days. A coordinated
disclosure timeline is agreed before the fix lands on `main`.

## Known-sensitive areas

- `bundle/` contents (DB dumps, credentials in `opensips.cfg`)
- `app/servers.json` and `app/users.json` at runtime
- SSH/sshpass invocations in the deploy scripts
