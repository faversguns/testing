#!/usr/bin/env python3
"""Read-only official retail price snapshot; not a dealer inventory feed."""
import csv
import json
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path
from urllib.request import Request, urlopen

BASE = 'https://www.cobragolf.com'
OUT = Path(__file__).resolve().parents[2] / 'dist'

def fetch(path):
    with urlopen(Request(BASE + path, headers={'User-Agent': 'FaversCatalogReview/1.0'}), timeout=40) as response:
        return json.load(response)

def main():
    if fetch('/cart.js').get('currency') != 'USD':
        raise RuntimeError('Expected USD retail prices. No files written.')
    timestamp = datetime.now(timezone.utc).isoformat()
    date = timestamp[:10]
    outputs = {}
    manifest = {'retrieved_at_utc': timestamp, 'currency': 'USD', 'price_type': 'public retail, not dealer cost',
                'policy': 'Currently available non-custom variants; iron sets only; stock zero denotes no local inventory. Each configuration is a separate simple product. Confirm supplier fulfillment separately.', 'products': []}
    for kind in ('drivers', 'irons'):
        source = '/collections/golf-clubs-' + kind + '/products.json?limit=250'
        products = fetch(source)['products']
        if len(products) >= 250:
            raise RuntimeError('Collection may require pagination; no incomplete export will be written.')
        rows = []
        for product in products:
            for variant in product['variants']:
                values = [variant.get('option' + str(i)) or '' for i in range(1, 4)]
                if not variant.get('available') or any('custom' in value.lower() for value in values):
                    continue
                if kind == 'irons':
                    comp = next((variant.get('option' + str(option['position'])) or '' for option in product['options'] if 'comp' in option['name'].lower()), '')
                    if not any(character in comp for character in ('-', ',')):
                        continue
                price = Decimal(variant['price'])
                if not price.is_finite() or price <= 0 or price != price.quantize(Decimal('.01')):
                    raise RuntimeError('Unexpected price for variant ' + str(variant['id']))
                specs = '; '.join(option['name'] + ': ' + (variant.get('option' + str(option['position'])) or '') for option in product['options'])
                url = BASE + '/products/' + product['handle'] + '?variant=' + str(variant['id'])
                name = product['title'] + ' — ' + ' / '.join(value.replace('//', ' / ') for value in values if value)
                if len(name.encode()) > 200:
                    raise RuntimeError('Product name exceeds importer limit: ' + name)
                sku = 'CB-' + str(variant['id'])
                rows.append({'sku': sku, 'name': name, 'price': format(price, '.2f'), 'stock': '0',
                    'description': specs + '. Manufacturer SKU: ' + str(variant.get('sku') or 'not supplied') + '. Special order; not held in stock. Contact Favers for current delivery timing. Public Cobra US retail price checked ' + date + ' (USD); not a live feed. Configuration reference: ' + url})
                manifest['products'].append({'sku': sku, 'model': product['title'], 'variant_id': variant['id'], 'manufacturer_sku': variant.get('sku'), 'specifications': specs, 'price_usd': format(price, '.2f'), 'source_url': url, 'collection_url': BASE + source, 'retail_available_at_snapshot': True})
        if not rows:
            raise RuntimeError('No qualifying variants for ' + kind)
        if len({row['sku'] for row in rows}) != len(rows):
            raise RuntimeError('Duplicate variant IDs')
        outputs[kind] = rows
    OUT.mkdir(exist_ok=True)
    for kind, rows in outputs.items():
        for start in range(0, len(rows), 200):
            suffix = '' if len(rows) <= 200 else '-' + str(start // 200 + 1)
            path = OUT / ('cobra-' + kind + '-' + date + suffix + '.csv')
            with path.open('w', encoding='utf-8-sig', newline='') as stream:
                writer = csv.DictWriter(stream, fieldnames=['sku', 'name', 'price', 'stock', 'description'], lineterminator='\n')
                writer.writeheader()
                writer.writerows(rows[start:start + 200])
            print(str(path) + ': ' + str(len(rows[start:start + 200])) + ' configurations')
    (OUT / ('cobra-price-sources-' + date + '.json')).write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + '\n')

if __name__ == '__main__':
    main()
