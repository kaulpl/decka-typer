const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const js = fs.readFileSync(path.join(root, 'assets/js/frontend.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/css/frontend.css'), 'utf8');

assert.match(js, /progressComplete=progress\.total>0&&progress\.remaining===0/);
assert.match(js, /progressComplete\?'is-success':'is-danger'/);
assert.match(js, /progressComplete\?'check':'alert'/);
assert.match(css, /\.dt-pick-progress\.is-danger\{background:#c91f36!important\}/);
assert.match(css, /\.dt-pick-progress\.is-success\{background:#138a55!important\}/);
assert.match(css, /\.dt-pick-progress\{[^}]*color:#fff!important/);

console.log('Frontend prediction progress styles: OK');
