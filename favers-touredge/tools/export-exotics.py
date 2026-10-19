#!/usr/bin/env python3
"""Export the full public Tour Edge Exotics catalog without editing WordPress."""
import csv,json,re
from pathlib import Path
from decimal import Decimal
from datetime import datetime,timezone
from urllib.request import Request,urlopen
from urllib.parse import urlsplit
ROOT=Path(__file__).resolve().parents[2]
BASE='https://www.touredge.com'
def fetch(path):
 with urlopen(Request(BASE+path,headers={'User-Agent':'FaversCatalogReview/1.0'}),timeout=40) as response:return json.load(response)
def kind(title):
 s=title.lower()
 if any(k in s for k in ['headcover','weights','weight kit','accessor','glove','bag','umbrella','hat']):return 'accessories'
 for word,category in [('fairway','fairways'),('hybrid','hybrids'),('utility','utilities'),('driver','drivers'),('iron','irons'),('wedge','wedges'),('putter','putters'),('ball','balls')]:
  if word in s:return category
 return 'other'
def main():
 if fetch('/cart.js').get('currency')!='USD':raise RuntimeError('USD currency could not be verified')
 products=[];seen_pages=set()
 for page in range(1,21):
  batch=fetch('/products.json?limit=250&page='+str(page))['products']
  signature=tuple(str(p['id']) for p in batch)
  if batch and signature in seen_pages:raise RuntimeError('Repeated catalog page; refusing partial export')
  seen_pages.add(signature);products.extend(batch)
  if len(batch)<250:break
 else:raise RuntimeError('Catalog exceeded page limit')
 selected={};aliases={};when=datetime.now(timezone.utc).isoformat()
 # Prefer complete parent listings over left/right catalog aliases.
 for p in sorted(products,key=lambda p:(-len(p['variants']),p['handle'])):
  if not re.search(r'\bexotics\b',p['title'],re.I):continue
  for v in p['variants']:
   if v.get('available') is not True:continue
   key=str(v['id']);price=Decimal(v['price'])
   if not price.is_finite() or price<=0 or price!=price.quantize(Decimal('.01')):raise RuntimeError('Invalid price '+key)
   url=BASE+'/products/'+p['handle']+'?variant='+key
   aliases.setdefault(key,[]).append(url)
   if key in selected:
    if selected[key]['price']!=format(price,'.2f'):raise RuntimeError('Conflicting alias prices '+key)
    continue
   condition='preowned' if re.search(r'pre[ -]?owned',p['title'],re.I) else ('demo' if 'demo' in p['title'].lower() else 'new')
   image=v.get('featured_image') or next(iter(p.get('images',[])),{})
   raw=image.get('src','');parts=urlsplit(raw)
   match=re.match(r'/s/files/(?:[^/]+/){4}((?:files|products)/.+)',parts.path)
   if parts.hostname=='cdn.shopify.com' and match:raw=BASE+'/cdn/shop/'+match[1]+('?' + parts.query if parts.query else '')
   parsed=urlsplit(raw)
   if parsed.scheme!='https' or parsed.hostname!='www.touredge.com' or not parsed.path.startswith(('/cdn/shop/files/','/cdn/shop/products/')):raise RuntimeError('Unsupported photo '+key)
   name=p['title']+(' — '+v['title'] if v['title']!='Default Title' else '')
   if len(name.encode())>200:raise RuntimeError('Oversized name '+key)
   description='Configuration: '+v['title']+'. Manufacturer SKU: '+str(v.get('sku',''))+'. Condition: '+condition+'. Special order; not held in stock. Contact Favers for delivery timing. Public Tour Edge USD retail price checked '+when+'. Reference: '+url
   selected[key]={'sku':'TE-'+key,'name':name,'price':format(price,'.2f'),'stock':'0','description':description,'image_url':raw,'product_type':kind(p['title']),'condition':condition,'source_url':url,'manufacturer_sku':v.get('sku',''),'variant_id':key,'model':p['title']}
 data=ROOT/'favers-touredge/data';data.mkdir(exist_ok=True)
 output=ROOT/'dist';output.mkdir(exist_ok=True)
 manifest={'fetched_at':when,'currency':'USD','source':BASE,'all_catalog_parent_count':len(products),'unique_available_variants':len(selected),'duplicate_aliases_removed':sum(len(v)-1 for v in aliases.values()),'groups':{},'sources':[]}
 catalogs={};photos={}
 for condition,label in [('new','NEW'),('preowned','CERTIFIED PRE-OWNED'),('demo','DEMO OFFERS')]:
  rows=sorted([r for r in selected.values() if r['condition']==condition],key=lambda r:(r['product_type'],r['model'],r['name']))
  manifest['groups'][condition]=len(rows)
  for start in range(0,len(rows),200):
   key='exotics-'+condition+'-'+str(start//200+1);filename=key+'.csv';chunk=rows[start:start+200]
   with (data/filename).open('w',encoding='utf-8-sig',newline='') as stream:
    headers=['sku','name','price','stock','description','image_url','product_type'];writer=csv.DictWriter(stream,fieldnames=headers,extrasaction='ignore',lineterminator='\n');writer.writeheader();writer.writerows(chunk)
   (output/filename).write_bytes((data/filename).read_bytes())
   catalogs[key]={'file':filename,'label':'Exotics '+label+' — batch '+str(start//200+1)+' ('+str(len(chunk))+')','currency':'USD','fetched_at':when}
 for key,row in selected.items():
  manifest['sources'].append({**row,'aliases':aliases[key]})
  photos['touredge-'+row['sku']]={'url':row['image_url'],'alt':row['model']+' product photo','source_url':row['source_url']}
 (data/'catalogs.json').write_text(json.dumps(catalogs,indent=2)+'\n')
 (data/'product-images.json').write_text(json.dumps(photos,indent=2,ensure_ascii=False)+'\n')
 (data/'exotics-sources.json').write_text(json.dumps(manifest,indent=2,ensure_ascii=False)+'\n')
 (output/'exotics-catalog-summary.json').write_text(json.dumps({k:v for k,v in manifest.items() if k!='sources'},indent=2)+'\n')
 print(json.dumps({k:v for k,v in manifest.items() if k!='sources'},indent=2))
if __name__=='__main__':main()
