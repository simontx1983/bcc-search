# Blue Collar Crypto – Search

Live search bar for PeepSo project pages, filterable by type (Validators, Builders, NFT Creators). Results show the project avatar, name, category badge, and trust score coloured by reputation tier.

Results are fetched via the REST endpoint `GET /wp-json/bcc/v1/search?q=&type=`.

---

## Shortcodes

### `[bcc_search]`

Embeds the live search bar with an optional type dropdown. Keyboard navigable — use `↑` `↓` to move through results, `Enter` to navigate, `Escape` to close.

**Attributes**

| Attribute | Default | Description |
|---|---|---|
| `placeholder` | `Search projects…` | Placeholder text shown inside the input field. |
| `show_type` | `1` | Set to `0` to hide the type filter dropdown and show a plain text input only. |

**Examples**

```
[bcc_search]
[bcc_search placeholder="Find a validator…"]
[bcc_search show_type="0"]
[bcc_search placeholder="Search builders…" show_type="1"]
```

---

## REST API

The search bar uses a public REST endpoint. You can query it directly:

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
      "tier": "gold",
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
