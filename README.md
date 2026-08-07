# Blue Collar Crypto – Search

Headless search engine for the BCC platform: REST-only, no rendered UI. (The
plugin once shipped a `[bcc_search]` shortcode + frontend assets; those were
removed in the 2026-05-03 headless cleanup — the Next.js app in
`bcc-frontend/` is the only renderer.) Serves PeepSo project pages, users,
and groups, filterable by type, with trust scores coloured by reputation
tier.

Three public routes, all under `/wp-json/bcc/v1` (registered in
`app/Controllers/{Search,UserSearch,GroupSearch}Controller.php`; response
shapes in the umbrella `docs/api-contract-v1.md`):

| Route | Purpose | Params |
|---|---|---|
| `GET /search` | Project/page search (claim-verified ranking, lookalike demotion) | `q` (required), `type` (category slug, optional) |
| `GET /search/users` | User search — routed through the PeepSo privacy filter set (fail-closed; anonymous privacy leak fixed 2026-07-09, bcc-search #4) | `q` (required), `limit` (optional) |
| `GET /search/groups` | Group search (secret/closed groups excluded) | `q` (required), `limit` (optional) |

---

## REST API — `GET /search` detail

You can query it directly:

```
GET /wp-json/bcc/v1/search?q=bitcoin&type=validators
```

**Parameters**

| Parameter | Required | Description |
|---|---|---|
| `q` | Yes | Search term. Minimum 2 characters. |
| `type` | No | Category slug to filter by (e.g. `validators`, `builders`, `nft-creators`). Omit for all types. |

**Response**

```json
{
  "results": [
    {
      "id": 42,
      "title": "Bitcoin Builders Co",
      "url": "https://example.com/pages/bitcoin-builders-co/",
      "avatar": "https://example.com/wp-content/peepso/pages/42/avatar-full.jpg",
      "score": 187,
      "tier": "trusted",
      "category": "Builders",
      "category_slug": "builders"
    }
  ],
  "categories": [
    { "slug": "", "name": "All Types" },
    { "slug": "validators", "name": "Validators" },
    { "slug": "builders", "name": "Builders" },
    { "slug": "nft-creators", "name": "NFT Creators" }
  ]
}
```

The `categories` list is cached for 12 hours and automatically invalidated when a PeepSo page category is created, updated, or deleted.
