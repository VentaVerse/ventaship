# PSGC address data

Files: `{provinces,cities,districts,barangays,city-zip-map}.json`, built from the raw PSGC (Philippine Standard Geographic Code) files with this remapping:

- a synthetic Metro Manila province
- highly urbanized cities (HUCs) as cities
- Manila's districts as their own level, with Manila's barangays under them

Shapes: provinces `{code,name}`; cities `{code,name,parent,zip_code}`; districts `{code,name,parent}`; barangays `{code,name,parent}` where the parent is a city or district code.

`App\Support\PhAddress` compiles each file to `storage/framework/cache/ph/{level}-{mtime}.php` on first use, so opcache serves it; the cache store is not used. Safe to delete: it rebuilds on the next request.
