import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const source = path.join(root, 'node_modules', 'uikit', 'dist');
const target = path.join(root, 'assets', 'vendor', 'uikit');
fs.mkdirSync(target, { recursive: true });

for (const [name, relative] of [
  ['uikit.min.css', path.join('css', 'uikit.min.css')],
  ['uikit.min.js', path.join('js', 'uikit.min.js')],
  ['uikit-icons.min.js', path.join('js', 'uikit-icons.min.js')],
]) {
  const from = path.join(source, relative);
  const to = path.join(target, name);
  if (!fs.existsSync(from)) {
    throw new Error(`Missing UIkit distribution asset: ${from}`);
  }
  fs.copyFileSync(from, to);
}

fs.writeFileSync(
  path.join(target, 'VERSION'),
  'UIkit 3.25.23\nSource: https://www.npmjs.com/package/uikit\nLicense: MIT\n'
);
console.log('Built local UIkit 3.25.23 fallback assets.');
