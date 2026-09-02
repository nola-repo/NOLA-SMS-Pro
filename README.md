# NOLA SMS Pro

NOLA SMS Pro is a complete SMS management platform integrated with GoHighLevel (GHL).

## Architecture

- pi/ & pages/: PHP Backend API & OAuth Marketplace Callbacks (Google Cloud Run).
- laravel/: PHPUnit test suite & backend contracts.
- user/: User subaccount React dashboard (SMS, contacts, templates, reports, billing).
- gency/: Agency React dashboard (subaccounts, wallet, subscription, agency settings).
- dmin/: Admin React dashboard (sender requests, accounts, agencies, system health).

## Getting Started

### Backend Unit Tests
`ash
php laravel/vendor/bin/phpunit
`

### Frontends
`ash
cd user && npm install && npm run dev
cd agency && npm install && npm run dev
cd admin && npm install && npm run dev
`

## Documentation
- [Staging Deployment & Branching Guide](docs/STAGING_DEPLOYMENT_AND_BRANCHING_GUIDE.md)
- [Master Staging & Branching Guide](MASTER_STAGING_AND_BRANCHING_GUIDE.md)