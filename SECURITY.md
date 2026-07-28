# Security

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability or exposed reporting data.
Contact the repository owner privately and include:

- the affected component and version;
- reproduction steps;
- the expected and observed impact;
- any recommended mitigation.

Do not attach production workbooks, generated reports, credentials, player identifiers,
or transaction data to GitHub issues.

## Data handling

Uploaded workbooks and generated artifacts are stored under
`storage/app/private/reports` and are intentionally excluded from Git. Production
deployments must use private object storage, encryption, access logging, retention
controls, and non-development credentials.
