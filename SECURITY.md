# Security

## Supported versions

Only `main` gets security patches. Tagged releases are snapshots - if a
tagged release is affected, the fix goes to `main` and a new tag is cut.

## Reporting something

Please don't file public issues for security stuff.

Email: Adityaajaysingh0104@gmail.com

Include what you found, how to reproduce, and what you think the impact is.
Fine to include a suggested patch if you've got one.

Expect a reply within a week.

## Sensitive areas

- `bundle/` - DB dump, creds inside `opensips.cfg`
- `app/servers.json` / `app/users.json` at runtime
- `sshpass` usage in the deploy scripts
