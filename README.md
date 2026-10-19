# silvershop/api-graphql

A **GraphQL overlay** for [`silvershop/api`](https://github.com/RVXD/silvershop-api) — a public `/graphql`
endpoint for the SilverShop catalog, cart, checkout hand-off, orders and customer self-service on SilverStripe
CMS 6.

It is **thin by design**: it ships the public schema (types + queries + mutations) and a handful of resolvers, and
reuses the REST base wholesale — the same `ApiToken` bearer auth and `read`/`write`/`admin` scopes, and the same
`SilverShop\Api\Serializer\*` serializers. Because both surfaces serialise through the same serializers, a product,
cart or order looks **identical** over `/graphql` and over `/api/v1`, field-for-field.

> **Why a separate module?** The REST base `silvershop/api` is deliberately GraphQL-free, so REST installs with no
> `silverstripe/graphql` dependency or build step. This overlay hard-requires `silverstripe/graphql` and holds the
> only code that references GraphQL classes (resolvers + middleware), so it is installed only when a client wants a
> headless/PWA schema.

## Requirements

- `silvershop/api` (the REST base), `silvershop/core` ^6, `silverstripe/framework` / `silverstripe/cms` ^6
- `silverstripe/graphql` ^6.1
- PHP 8.3 / 8.4

## Installation

`silvershop/api` is published on the RVXD GitHub org (not Packagist), so add its repository, then require this
module:

```jsonc
// composer.json
"repositories": [
    { "type": "vcs", "url": "https://github.com/RVXD/silvershop-api.git" }
]
```

```bash
composer require silvershop/api-graphql
```

Then **build the schema** and flush:

```bash
vendor/bin/sake graphql:build --schema=default --flush
```

`dev/build` also rebuilds the schema. The endpoint is then served at **`POST /graphql`**.

## The endpoint

A single schema — the public (`default`) schema — is mounted at `/graphql`. (GraphQL was removed from the CMS 6
admin, so this is a frontend-only schema; it does not touch the CMS.) Send queries as a JSON `POST`:

```bash
curl -X POST https://your-shop.example.com/graphql \
  -H 'Content-Type: application/json' \
  -d '{"query":"{ products(limit: 3) { id title price currency } }"}'
```

## Authentication

Authentication reuses the REST base exactly. Present an **API token** (created in the CMS under *API*) as a bearer
header — the token is the credential, so bearer requests skip CSRF:

```
Authorization: Bearer sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

The token's `Member` and scopes drive authorisation:

| Scope   | Grants |
|---------|--------|
| `read`  | read catalog, cart, own orders and profile |
| `write` | cart + profile/address mutations (implies `read`) |
| `admin` | reserved for catalog writes (implies `write`) |

Same-origin storefront JS can instead use the logged-in session; session **mutations** then require a valid
`X-CSRF-TOKEN` header (the stock GraphQL CSRF protection), exactly as core.

**Public read:** the live catalog is readable without a token by default. Set
`SilverShop\Api\Control\ApiController.public_read: false` to require a `read`-scope token for catalog reads too —
the same flag the REST base uses.

Tokens are read from the `Authorization` header only, never the query string.

## Example queries & mutations

**Read the catalog** (public when `public_read` is on):

```graphql
query {
  products(limit: 10, sort: "-Created", category: 5, featured: true) {
    id
    title
    price
    currency
    available
    image { url alt }
    priceRange { hasRange min max }
  }
  categoryTree {
    id
    title
    children { id title }
  }
}
```

**Drive the cart** (needs the `write` scope, or a same-origin session + CSRF):

```graphql
mutation {
  cartAdd(productID: 12, quantity: 2) {
    success
    message
    cart { reference itemCount total items { title quantity total } }
  }
}
```

Add a specific **variation** by passing its buyable class:

```graphql
mutation {
  cartAdd(productID: 34, buyable: "SilverShop\\Model\\Variation\\Variation", quantity: 1) {
    success
    cart { total }
  }
}
```

**Checkout hand-off** — returns the hosted checkout URL (no payment is captured over the API):

```graphql
mutation {
  checkout { checkoutUrl cartUrl reference }
}
```

**Customer self-service** (needs an authenticated member):

```graphql
query {
  me { firstName surname email addresses { id city country } }
  myOrders(limit: 5) { reference status total paid }
}
```

## Schema at a glance

Queries: `products`, `product`, `categories`, `category`, `categoryTree`, `variations`, `variation`, `cart`,
`me`, `myOrders`, `order`.

Mutations: `cartAdd`, `cartUpdate`, `cartRemove`, `cartClear`, `checkout`, `updateCustomer`, `addAddress`,
`updateAddress`.

Catalog types (`Product`, `ProductCategory`, `Variation`, `Order`, `Address`, `Customer`, …) are defined in
[`_graphql/types.yml`](_graphql/types.yml); their fields mirror the REST serializers key-for-key.

## Extending — feature-module types

GraphQL in CMS 6 is distributed: every module's `_graphql/` config is merged into the one `default` schema. A
feature module can therefore contribute its own DataObject types with a **pure `_graphql/` YAML** fragment (inert
without this overlay installed), e.g. a brands module adding a `Brand` model type, a reviews module adding
`Review`. Those fragments need no PHP and are picked up automatically when this overlay is present. (Any custom
resolvers such a type needs must live in a GraphQL-required module, like this one, for the same reason this overlay
is separate from the REST base.)

## License

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
