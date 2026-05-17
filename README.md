# fabrics-assets

Public image host for WooCommerce fabric attribute imports. Files served via the
jsDelivr CDN. URL pattern:

```
https://cdn.jsdelivr.net/gh/BIKhorchef/fabrics-assets@master/<category>/<collection>/<code>.webp
```

## Layout

- `chemise-premium/`
  - `royce-vol-1/`
  - `royce-vol-2/`
- `chemise-business/`
  - `stretch-line/`
  - `cotton-blend/`
  - `solemnity/`
- `costume/`
  - `massimo-vol-1/`
  - `massimo-vol-2/`
  - `massimo-vol-3-jacketing/`
  - `roberto-bellini-x-series/`

## Cache busting

jsDelivr caches `@master` for ~12 h. To force a refresh after replacing an
image, request the file with a new query (`?v=2`) or pin to a tagged release
instead of the branch.
