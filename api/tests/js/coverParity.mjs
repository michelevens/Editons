// Runs coverText.js on cases from stdin and prints the layouts, for
// tests/Feature/CoverTextParityTest.php to compare with the PHP.
import { pathToFileURL } from 'node:url';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const { layoutLayer } = await import(
    pathToFileURL(resolve(here, '../../resources/js/Components/Studio/cover/coverText.js')).href
);

let input = '';
for await (const chunk of process.stdin) input += chunk;

const { cases, metrics } = JSON.parse(input);

process.stdout.write(
    JSON.stringify(cases.map((c) => layoutLayer(c.layer, c.text, c.width, c.height, metrics))),
);
