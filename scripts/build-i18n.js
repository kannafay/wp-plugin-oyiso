const fs = require('fs');
const path = require('path');
const gettextParser = require('gettext-parser');

const repoRoot = path.resolve(__dirname, '..');
const languagesDir = path.join(repoRoot, 'languages');

function getTranslationFiles() {
  return fs
    .readdirSync(languagesDir, { withFileTypes: true })
    .filter((entry) => entry.isFile() && /^oyiso-[^.]+\.po$/i.test(entry.name))
    .map((entry) => entry.name)
    .sort();
}

function buildMoFromPo(poFilename) {
  const poPath = path.join(languagesDir, poFilename);
  const moPath = path.join(languagesDir, poFilename.replace(/\.po$/i, '.mo'));
  const poBuffer = fs.readFileSync(poPath);
  const poData = gettextParser.po.parse(poBuffer);
  const moBuffer = gettextParser.mo.compile(poData);

  fs.writeFileSync(moPath, moBuffer);

  return {
    poFilename,
    moFilename: path.basename(moPath),
    size: moBuffer.length,
  };
}

function main() {
  if (!fs.existsSync(languagesDir)) {
    throw new Error(`Languages directory not found: ${languagesDir}`);
  }

  const translationFiles = getTranslationFiles();

  if (translationFiles.length === 0) {
    console.log('No oyiso translation .po files found.');
    return;
  }

  console.log(`Building ${translationFiles.length} translation file(s)...`);

  for (const poFilename of translationFiles) {
    const result = buildMoFromPo(poFilename);
    console.log(`  ${result.poFilename} -> ${result.moFilename} (${result.size} bytes)`);
  }

  console.log('Translation build complete.');
}

main();
