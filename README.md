<picture>
  <source media="(prefers-color-scheme: dark)" srcset="public/brand/ventaship-logo-dark.png">
  <img alt="Ventaship" src="public/brand/ventaship-logo-light.png" height="72">
</picture>

# Ventaship

Ventaship books your parcels with Philippine couriers (**QuadX**, **SPX** and **LBC**) from one place. It runs on your own server.

![Shipments](docs/screenshots/shipments.png)

## What it does

- Books shipments with QuadX, SPX and LBC, by hand or from your online store.
- Prints waybills.
- Tracks every parcel until it is delivered.
- Shows what needs your attention.
- Connects your online stores through an API.

## Install

See **[docs/install.md](docs/install.md)**.

## For developers

- The API your store talks to is described in **[docs/api.md](docs/api.md)**.
- Courier API references are in [docs/couriers](docs/couriers).
- Run the tests with `php artisan test` and `npm run test:js`.
- How versions, the changelog and releases work: **[CONTRIBUTING.md](CONTRIBUTING.md)**.

## Version

The current version is in [VERSION](VERSION), and what changed in each release is in [CHANGELOG.md](CHANGELOG.md).
