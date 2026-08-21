/* Converts assets/js/catalog.js into db/catalog-seed.json, which db/seed.php
   imports. Run with:  node db/dump-catalog.js

   Kept in the repo so the demo catalogue can be regenerated after an edit,
   rather than letting the JSON drift out of step with the JavaScript.

   Evaluated in a vm context whose global IS `window`, because catalog.js is a
   browser file: it assigns window.SUPPEX and then refers to bare `SUPPEX`,
   which only resolves if window and the global object are the same thing. */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const context = vm.createContext({});
context.window = context;

const source = fs.readFileSync(path.join(__dirname, '../assets/js/catalog.js'), 'utf8');
vm.runInContext(source, context, { filename: 'catalog.js' });

const c = context.SUPPEX.catalog;
fs.writeFileSync(
  path.join(__dirname, 'catalog-seed.json'),
  JSON.stringify({ categories: c.categories, products: c.products }, null, 2),
  'utf8'
);
console.log('categories:', c.categories.length, ' products:', c.products.length);
