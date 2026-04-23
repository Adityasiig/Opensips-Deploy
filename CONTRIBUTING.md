# Contributing

Thanks for considering a contribution! This project is small; help is welcome.

## Development setup

1. Clone the repo.
2. Drop a bundle into `bundle/` (see [`examples/`](./examples) for templates).
3. `docker compose up -d --build`
4. Open <http://localhost:8080/opensips-deploy/>.

## Commit style

Follow [Conventional Commits](https://www.conventionalcommits.org/):

| Prefix | Use |
|---|---|
| `feat:` | New feature |
| `fix:` | Bug fix |
| `docs:` | Documentation only |
| `chore:` | Tooling / config |
| `ci:` | CI workflow changes |
| `refactor:` | Refactor without behavior change |
| `test:` | Tests only |

## Pull requests

1. Branch from `main`.
2. One logical change per PR.
3. CI (shellcheck + hadolint + PHP syntax + docker build) must pass.
4. Link the issue in the PR body: `Closes #42`.
5. Rebase on `main` before requesting merge if it's been open a while.

## Reporting issues

Use the issue templates (`.github/ISSUE_TEMPLATE/`):
- **Bug report** - things that are broken
- **Feature request** - things to add or change

## Security

Security issues should be reported via the process in [`SECURITY.md`](./SECURITY.md),
**not** via public issues.
