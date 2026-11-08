# PSGC address data

Source: `~/venta/database/data/ph/{provinces,cities,districts,barangays,city-zip-map}.json`, copied byte-for-byte on 2026-11-09.

Built by Venta's `ph:build-address-data` from the raw PSGC files, with its remapping:

- a synthetic Metro Manila province
- highly urbanized cities (HUCs) as cities
- Manila's districts as their own level, with Manila's barangays under them

Shapes: provinces `{code,name}`; cities `{code,name,parent,zip_code}`; districts `{code,name,parent}`; barangays `{code,name,parent}` where the parent is a city or district code.
